<?php
/**
 * Persistent shopping cart for signed-in customers (cart_items table).
 * A cart can hold products from several stalls; checkout creates one pre-order per stall.
 */
declare(strict_types=1);

require_once __DIR__ . '/schedule.php';

/** SQL condition for a product customers may see and order (alias p = products, v = vendors, u = vendor user). */
const PUBLIC_PRODUCT_SQL = "p.deleted_at IS NULL AND p.moderation_status = 'active' AND v.is_active = 1 AND u.is_active = 1 AND u.approval_status = 'approved'";

function cart_count(int $customerId): int
{
    return (int) db_value('SELECT COUNT(*) FROM cart_items WHERE customer_id = ?', [$customerId]);
}

/** Quantity step for a unit: loose produce in halves, everything else whole. */
function unit_step(string $unit): float
{
    return in_array($unit, ['kg', 'g', 'litre'], true) ? 0.5 : 1.0;
}

/** Load a product that customers are allowed to order. */
function orderable_product(int $productId): ?array
{
    return db_one('SELECT p.*, v.stall_name FROM products p JOIN vendors v ON v.id = p.vendor_id JOIN users u ON u.id = v.user_id
                   WHERE p.id = ? AND ' . PUBLIC_PRODUCT_SQL, [$productId]);
}

function cart_validate_qty(array $product, float $qty): float
{
    $qty = round($qty, 2);
    if ($qty <= 0) {
        throw new ValidationException(['quantity' => 'Choose a quantity.'], 'Choose a quantity.');
    }
    if ($product['status'] !== 'available' || (float) $product['quantity'] <= 0) {
        throw new ValidationException(['quantity' => $product['name'] . ' is sold out.'], $product['name'] . ' is sold out.');
    }
    if ($qty > (float) $product['quantity']) {
        $msg = 'Only ' . qty($product['quantity']) . ' ' . $product['unit'] . ' of ' . $product['name'] . ' left.';
        throw new ValidationException(['quantity' => $msg], $msg);
    }
    return $qty;
}

/** Add to the cart (adds to any quantity already there). */
function cart_add(int $customerId, int $productId, float $qty): void
{
    $p = orderable_product($productId);
    if (!$p) {
        throw new ValidationException([], 'This product is no longer available.');
    }
    $existing = (float) db_value('SELECT quantity FROM cart_items WHERE customer_id = ? AND product_id = ?', [$customerId, $productId]);
    $total = cart_validate_qty($p, $existing + $qty);
    db_query('INSERT INTO cart_items (customer_id, product_id, quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)',
        [$customerId, $productId, $total]);
}

/** Set an exact quantity; 0 removes the line. */
function cart_update(int $customerId, int $productId, float $qty): void
{
    if ($qty <= 0) {
        cart_remove($customerId, $productId);
        return;
    }
    if (!db_value('SELECT 1 FROM cart_items WHERE customer_id = ? AND product_id = ?', [$customerId, $productId])) {
        throw new ValidationException([], 'That item is not in your cart.');
    }
    $p = orderable_product($productId);
    if (!$p) {
        throw new ValidationException([], 'This product is no longer available.');
    }
    db_query('UPDATE cart_items SET quantity = ? WHERE customer_id = ? AND product_id = ?', [cart_validate_qty($p, $qty), $customerId, $productId]);
}

function cart_remove(int $customerId, int $productId): void
{
    db_query('DELETE FROM cart_items WHERE customer_id = ? AND product_id = ?', [$customerId, $productId]);
}

/**
 * Cart items grouped by stall. Unavailable items stay in the list but are marked.
 */
function cart_groups(int $customerId): array
{
    $rows = db_all(
        "SELECT ci.product_id, ci.quantity, p.name, p.unit, p.price, p.image, p.status, p.quantity AS stock, p.vendor_id,
                (p.deleted_at IS NULL AND p.moderation_status = 'active' AND v.is_active = 1 AND u.is_active = 1 AND u.approval_status = 'approved') AS listed
           FROM cart_items ci
           JOIN products p ON p.id = ci.product_id
           JOIN vendors v  ON v.id = p.vendor_id
           JOIN users u    ON u.id = v.user_id
          WHERE ci.customer_id = ?
          ORDER BY v.stall_name, ci.id", [$customerId]
    );
    $groups = [];
    foreach ($rows as $r) {
        $vid = (int) $r['vendor_id'];
        if (!isset($groups[$vid])) {
            $vendor = db_one('SELECT * FROM vendors WHERE id = ?', [$vid]);
            $groups[$vid] = ['vendor' => $vendor, 'status' => vendor_order_status($vendor), 'items' => [], 'subtotal' => 0.0, 'can_order' => true];
        }
        $problem = '';
        if (!$r['listed']) {
            $problem = 'No longer available';
        } elseif ($r['status'] !== 'available' || (float) $r['stock'] <= 0) {
            $problem = 'Sold out';
        } elseif ((float) $r['quantity'] > (float) $r['stock']) {
            $problem = 'Only ' . qty($r['stock']) . ' ' . $r['unit'] . ' left';
        }
        $line = round((float) $r['quantity'] * (float) $r['price'], 2);
        $groups[$vid]['items'][] = $r + ['line_total' => $line, 'problem' => $problem, 'step' => unit_step($r['unit'])];
        if ($problem === '') {
            $groups[$vid]['subtotal'] += $line;
        } else {
            $groups[$vid]['can_order'] = false;
        }
    }
    foreach ($groups as &$g) {
        if (!$g['status']['accepting']) {
            $g['can_order'] = false;
        }
    }
    unset($g);
    return array_values($groups);
}

function cart_to_array(array $groups): array
{
    $total = 0.0;
    $count = 0;
    $out = [];
    foreach ($groups as $g) {
        $total += $g['subtotal'];
        $count += count($g['items']);
        $out[] = [
            'vendor_id'  => (int) $g['vendor']['id'],
            'stall_name' => $g['vendor']['stall_name'],
            'accepting'  => $g['status']['accepting'],
            'notice'     => $g['status']['accepting'] ? '' : ($g['status']['message'] ?: 'Not taking pre-orders right now.'),
            'can_order'  => $g['can_order'],
            'subtotal'   => money($g['subtotal']),
            'items'      => array_map(fn($i) => [
                'product_id' => (int) $i['product_id'],
                'name'       => $i['name'],
                'unit'       => $i['unit'],
                'price'      => money($i['price']),
                'quantity'   => (float) $i['quantity'],
                'stock'      => (float) $i['stock'],
                'step'       => $i['step'],
                'line_total' => money($i['line_total']),
                'problem'    => $i['problem'],
                'image'      => upload_url($i['image']),
            ], $g['items']),
        ];
    }
    return ['groups' => $out, 'total' => money($total), 'count' => $count];
}
