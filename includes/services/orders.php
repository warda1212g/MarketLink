<?php
/**
 * Pre-order lifecycle. All writes run in transactions; notifications are sent after commit.
 *
 *   cart -> checkout (one order per stall, status "pending")
 *   vendor: pending -> accepted | declined
 *           accepted -> preparing | ready | cancelled
 *           preparing -> ready | cancelled
 *           ready -> cancelled   (customer confirms pickup: ready -> picked_up)
 *           picked_up -> completed
 *   customer: modify (pending/accepted), cancel (pending/accepted),
 *             choose pickup slot once accepted (accepted/preparing/ready)
 * Declined/cancelled orders return their reserved stock.
 */
declare(strict_types=1);

require_once __DIR__ . '/schedule.php';
require_once __DIR__ . '/cart.php';
require_once __DIR__ . '/notifications.php';

const CUSTOMER_MODIFIABLE = ['pending', 'accepted'];
const CUSTOMER_CANCELLABLE = ['pending', 'accepted'];
const PICKUP_SELECTABLE = ['accepted', 'preparing', 'ready'];
const PICKUP_SLOT_MINUTES = 30;

/** Order steps shown on the customer tracking timeline. */
const ORDER_TIMELINE = [
    'pending'   => 'Order placed',
    'accepted'  => 'Vendor accepted',
    'preparing' => 'Preparing',
    'ready'     => 'Ready for pickup',
    'picked_up' => 'Picked up',
    'completed' => 'Completed',
];

/* checkout */

/**
 * Turn the customer's cart into pre-orders (one per stall) in a single transaction.
 * @param array<int,string> $notes vendor_id => note for that stall
 * @return array list of ['id','order_number','total','stall_name']
 */
function checkout_cart(array $customer, array $notes = []): array
{
    $customerId = (int) $customer['id'];
    $groups = cart_groups($customerId);
    if (!$groups) {
        throw new ValidationException([], 'Your cart is empty.');
    }
    foreach ($groups as $g) {
        if (!$g['status']['accepting']) {
            throw new ValidationException([], $g['vendor']['stall_name'] . ' is not taking pre-orders right now. Remove its items to continue.');
        }
        foreach ($g['items'] as $i) {
            if ($i['problem'] !== '') {
                throw new ValidationException([], $i['name'] . ': ' . $i['problem'] . '. Update your cart to continue.');
            }
        }
    }

    $created = db_transaction(function (PDO $pdo) use ($customerId, $groups, $notes) {
        $out = [];
        foreach ($groups as $g) {
            $items = [];
            foreach ($g['items'] as $i) {
                $items[(int) $i['product_id']] = (float) $i['quantity'];
            }
            $note = mb_substr(trim((string) ($notes[$g['vendor']['id']] ?? '')), 0, 500);
            $order = insert_order($pdo, $customerId, $g['vendor'], $items, $note);
            $out[] = $order + ['stall_name' => $g['vendor']['stall_name'], 'vendor_id' => (int) $g['vendor']['id']];
        }
        $pdo->prepare('DELETE FROM cart_items WHERE customer_id = ?')->execute([$customerId]);
        return $out;
    });

    foreach ($created as $o) {
        notify($customerId, 'order_placed', 'Order #' . $o['order_number'] . ' has been placed',
            'Waiting for ' . $o['stall_name'] . ' to accept it. Total ' . money($o['total']) . ', pay at pickup.',
            'order.php?id=' . $o['id'], 'order', $o['id']);
        notify_vendor($o['vendor_id'], 'order_new', 'New order #' . $o['order_number'] . ' received',
            $customer['name'] . ' pre-ordered ' . money($o['total']) . '. Accept or reject it.',
            'vendor/orders.php?status=pending&open=' . $o['id'], 'order', $o['id']);
    }
    return $created;
}

/**
 * Insert one order + items and reserve stock (caller owns the transaction).
 * @param array<int,float> $items product_id => quantity
 */
function insert_order(PDO $pdo, int $customerId, array $vendor, array $items, string $note): array
{
    $ids = array_keys($items);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, name, unit, price, quantity, status FROM products
                           WHERE vendor_id = ? AND deleted_at IS NULL AND moderation_status = 'active' AND id IN ($in) FOR UPDATE");
    $stmt->execute(array_merge([$vendor['id']], $ids));
    $products = [];
    foreach ($stmt->fetchAll() as $p) {
        $products[(int) $p['id']] = $p;
    }

    $total = 0.0;
    foreach ($items as $pid => $q) {
        $p = $products[$pid] ?? null;
        if (!$p || $p['status'] !== 'available') {
            throw new ValidationException([], 'One of the products from ' . $vendor['stall_name'] . ' is no longer available.');
        }
        if ($q > (float) $p['quantity']) {
            throw new ValidationException([], 'Only ' . qty($p['quantity']) . ' ' . $p['unit'] . ' of ' . $p['name'] . ' left.');
        }
        $total += round($q * (float) $p['price'], 2);
    }

    $pdo->prepare('INSERT INTO orders (customer_id, vendor_id, total_amount, status, customer_note, status_changed_at) VALUES (?, ?, ?, "pending", ?, NOW())')
        ->execute([$customerId, $vendor['id'], $total, $note !== '' ? $note : null]);
    $orderId = (int) $pdo->lastInsertId();
    $number = order_number($orderId);
    $pdo->prepare('UPDATE orders SET order_number = ? WHERE id = ?')->execute([$number, $orderId]);

    $ins = $pdo->prepare('INSERT INTO order_items (order_id, product_id, product_name, unit, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($items as $pid => $q) {
        $p = $products[$pid];
        $ins->execute([$orderId, $pid, $p['name'], $p['unit'], $q, $p['price'], round($q * (float) $p['price'], 2)]);
        adjust_stock($pdo, $pid, -$q);
    }
    log_order_status($pdo, $orderId, null, 'pending', $customerId);

    return ['id' => $orderId, 'order_number' => $number, 'total' => $total];
}

/** Move stock by $delta (negative reserves) and keep available/sold_out in sync. */
function adjust_stock(PDO $pdo, int $productId, float $delta): void
{
    $pdo->prepare('UPDATE products SET quantity = GREATEST(quantity + ?, 0),
                          status = CASE WHEN quantity <= 0 THEN "sold_out" WHEN status = "sold_out" AND deleted_at IS NULL THEN "available" ELSE status END
                   WHERE id = ?')->execute([$delta, $productId]);
}

function log_order_status(PDO $pdo, int $orderId, ?string $from, string $to, int $actorId): void
{
    $pdo->prepare('INSERT INTO order_status_log (order_id, from_status, to_status, changed_by) VALUES (?, ?, ?, ?)')
        ->execute([$orderId, $from, $to, $actorId]);
}

/** Return an order's reserved quantities to live stock (inside a transaction). */
function restore_order_stock(PDO $pdo, int $orderId): void
{
    $pdo->prepare('UPDATE products p JOIN order_items oi ON oi.product_id = p.id
                   SET p.status = IF(p.status = "sold_out" AND p.deleted_at IS NULL, "available", p.status),
                       p.quantity = p.quantity + oi.quantity
                   WHERE oi.order_id = ?')->execute([$orderId]);
}

/* vendor actions */

/**
 * Move a vendor's order to a new status, validating the transition, then tell the customer.
 */
function change_order_status(int $vendorId, int $orderId, string $to, int $actorId, string $reason = ''): array
{
    if (!in_array($to, ORDER_STATUSES, true)) {
        throw new ValidationException(['status' => 'Unknown status.'], 'Unknown status.');
    }

    $result = db_transaction(function (PDO $pdo) use ($vendorId, $orderId, $to, $actorId, $reason) {
        $stmt = $pdo->prepare('SELECT id, status, customer_id, order_number FROM orders WHERE id = ? AND vendor_id = ? FOR UPDATE');
        $stmt->execute([$orderId, $vendorId]);
        $order = $stmt->fetch();
        if (!$order) {
            throw new ValidationException([], 'Order not found.');
        }
        $from = $order['status'];
        if (!in_array($to, ORDER_TRANSITIONS[$from] ?? [], true)) {
            throw new ValidationException([], sprintf('This order is %s and cannot be marked %s.', status_label($from), status_label($to)));
        }

        $stop = in_array($to, ['declined', 'cancelled'], true);
        $pdo->prepare('UPDATE orders SET status = ?, status_changed_at = NOW(),
                              cancel_reason = IF(?, ?, cancel_reason), cancelled_by = IF(?, "vendor", cancelled_by) WHERE id = ?')
            ->execute([$to, $stop ? 1 : 0, $reason !== '' ? $reason : null, $stop ? 1 : 0, $orderId]);
        log_order_status($pdo, $orderId, $from, $to, $actorId);
        if ($stop) {
            restore_order_stock($pdo, $orderId);
        }
        return ['id' => $orderId, 'from' => $from, 'status' => $to, 'customer_id' => (int) $order['customer_id'], 'order_number' => $order['order_number']];
    });

    $stall = (string) db_value('SELECT stall_name FROM vendors WHERE id = ?', [$vendorId]);
    $n = $result['order_number'];
    $copy = [
        'accepted'  => [$stall . ' accepted order #' . $n, 'You can now choose a pickup slot for your order.'],
        'declined'  => [$stall . ' rejected order #' . $n, $reason !== '' ? 'Reason: ' . $reason : 'The reserved stock has been released.'],
        'preparing' => ['Your order #' . $n . ' is being prepared', $stall . ' has started preparing your order.'],
        'ready'     => ['Your order #' . $n . ' is ready for pickup', 'Choose or confirm your pickup slot and collect it from ' . $stall . '.'],
        'completed' => ['Order #' . $n . ' completed', $stall . ' confirmed your pickup. Thanks for shopping local - you can now leave a review.'],
        'cancelled' => ['Order #' . $n . ' was cancelled by ' . $stall, $reason !== '' ? 'Reason: ' . $reason : 'Message the vendor if you have questions.'],
    ];
    if (isset($copy[$to])) {
        notify($result['customer_id'], 'order_' . $to, $copy[$to][0], $copy[$to][1], 'order.php?id=' . $orderId, 'order', $orderId);
    }
    return $result;
}

/** Orders with items for a vendor (optionally filtered). */
function vendor_orders(int $vendorId, array $opts = []): array
{
    $where = ['o.vendor_id = ?'];
    $params = [$vendorId];
    if (!empty($opts['status']) && in_array($opts['status'], ORDER_STATUSES, true)) {
        $where[] = 'o.status = ?';
        $params[] = $opts['status'];
    }
    if (!empty($opts['id'])) {
        $where[] = 'o.id = ?';
        $params[] = (int) $opts['id'];
    }
    if (!empty($opts['q'])) {
        $where[] = '(o.order_number LIKE ? OR u.name LIKE ?)';
        $like = '%' . $opts['q'] . '%';
        array_push($params, $like, $like);
    }
    $limit = max(1, min(200, (int) ($opts['limit'] ?? 50)));
    $offset = max(0, (int) ($opts['offset'] ?? 0));

    $orders = db_all('SELECT o.*, u.name AS customer_name, u.phone AS customer_phone, u.email AS customer_email,
                             (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS item_count
                      FROM orders o JOIN users u ON u.id = o.customer_id
                      WHERE ' . implode(' AND ', $where) . "
                      ORDER BY o.created_at DESC, o.id DESC LIMIT $limit OFFSET $offset", $params);

    if ($orders && !empty($opts['with_items'])) {
        $ids = array_column($orders, 'id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $items = db_all("SELECT order_id, product_id, product_name, unit, quantity, unit_price, subtotal FROM order_items WHERE order_id IN ($in) ORDER BY id", $ids);
        $grouped = [];
        foreach ($items as $it) {
            $grouped[$it['order_id']][] = $it;
        }
        foreach ($orders as &$o) {
            $o['items'] = $grouped[$o['id']] ?? [];
        }
        unset($o);
    }
    return $orders;
}

/** Serialize an order row for JSON responses (escaping happens client-side via textContent). */
function order_to_array(array $o): array
{
    return [
        'id'            => (int) $o['id'],
        'order_number'  => $o['order_number'],
        'customer'      => $o['customer_name'],
        'customer_phone'=> $o['customer_phone'] ?? null,
        'item_count'    => (int) ($o['item_count'] ?? 0),
        'total'         => (float) $o['total_amount'],
        'total_label'   => money($o['total_amount']),
        'status'        => $o['status'],
        'status_label'  => status_label($o['status']),
        'pickup_date'   => $o['pickup_date'],
        'pickup_label'  => $o['pickup_date'] ? pickup_label($o['pickup_date'], $o['pickup_start'], $o['pickup_end']) : 'Customer has not chosen a slot yet',
        'note'          => $o['customer_note'],
        'cancel_reason' => $o['cancel_reason'] ?? null,
        'modified'      => !empty($o['modified_at']),
        'created_at'    => $o['created_at'],
        'created_label' => fmt_date($o['created_at'], 'M j, Y, g:i A'),
        'next'          => ORDER_TRANSITIONS[$o['status']] ?? [],
        'message_link'  => url('vendor/messages.php?order=' . (int) $o['id']),
        'items'         => array_map(fn($i) => [
            'name'     => $i['product_name'],
            'quantity' => qty($i['quantity']),
            'unit'     => $i['unit'],
            'price'    => money($i['unit_price']),
            'subtotal' => money($i['subtotal']),
        ], $o['items'] ?? []),
    ];
}

/* customer actions */

/** One of the customer's orders with stall, items and review, or null if it is not theirs. */
function customer_order(int $customerId, int $orderId): ?array
{
    $o = db_one('SELECT o.*, v.stall_name, v.phone AS vendor_phone, v.image AS vendor_image, v.location_address, v.address AS vendor_address,
                        r.id AS review_id, r.rating AS review_rating
                   FROM orders o JOIN vendors v ON v.id = o.vendor_id LEFT JOIN reviews r ON r.order_id = o.id
                  WHERE o.id = ? AND o.customer_id = ?', [$orderId, $customerId]);
    if (!$o) {
        return null;
    }
    $o['items'] = db_all('SELECT oi.*, p.quantity AS stock, p.status AS product_status, p.moderation_status, p.deleted_at
                            FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ? ORDER BY oi.id', [$orderId]);
    return $o;
}

function customer_status_label(string $status): string
{
    return [
        'pending' => 'Pending', 'accepted' => 'Accepted', 'preparing' => 'Preparing', 'ready' => 'Ready for Pickup', 'picked_up' => 'Picked Up',
        'completed' => 'Completed', 'declined' => 'Rejected', 'cancelled' => 'Cancelled',
    ][$status] ?? ucfirst($status);
}

/**
 * Tracking timeline: each step with done/current flags and the time it happened.
 * @return array<int, array{key:string,label:string,state:string,time:string}>
 */
function order_timeline(array $order): array
{
    $times = [];
    foreach (db_all('SELECT to_status, created_at FROM order_status_log WHERE order_id = ? ORDER BY id', [$order['id']]) as $l) {
        $times[$l['to_status']] = $l['created_at'];
    }
    $keys = array_keys(ORDER_TIMELINE);
    $stopped = in_array($order['status'], ['declined', 'cancelled'], true);
    // For stopped orders, progress is where it was before it stopped.
    $reached = $order['status'];
    if ($stopped) {
        $reached = 'pending';
        foreach ($keys as $k) {
            if (isset($times[$k])) $reached = $k;
        }
    }
    $idx = array_search($reached, $keys, true);
    $steps = [];
    foreach ($keys as $i => $k) {
        if ($k === 'preparing' && !isset($times['preparing']) && $idx > $i) {
            continue; // vendor went straight from accepted to ready
        }
        $state = $i < $idx ? 'done' : ($i === $idx ? ($stopped || $k === 'completed' ? 'done' : 'current') : 'todo');
        $steps[] = ['key' => $k, 'label' => ORDER_TIMELINE[$k], 'state' => $state, 'time' => isset($times[$k]) ? fmt_date($times[$k], 'M j, g:i A') : ''];
    }
    if ($stopped) {
        $steps = array_values(array_filter($steps, fn($s) => $s['state'] === 'done'));
        $steps[] = ['key' => $order['status'], 'label' => $order['status'] === 'declined' ? 'Rejected by vendor' : 'Cancelled', 'state' => 'stopped',
                    'time' => isset($times[$order['status']]) ? fmt_date($times[$order['status']], 'M j, g:i A') : ''];
    }
    return $steps;
}

/** Customer cancels their own order while it is still pending or accepted (before preparation starts). */
function customer_cancel_order(int $customerId, int $orderId, string $reason = ''): array
{
    $reason = mb_substr(trim($reason), 0, 255);
    $order = db_transaction(function (PDO $pdo) use ($customerId, $orderId, $reason) {
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? AND customer_id = ? FOR UPDATE');
        $stmt->execute([$orderId, $customerId]);
        $order = $stmt->fetch();
        if (!$order) {
            throw new ValidationException([], 'Order not found.');
        }
        if (!in_array($order['status'], CUSTOMER_CANCELLABLE, true)) {
            throw new ValidationException([], $order['status'] === 'preparing' || $order['status'] === 'ready'
                ? 'The vendor is already preparing this order. Message them if you need to cancel.'
                : 'This order can no longer be cancelled.');
        }
        $pdo->prepare('UPDATE orders SET status = "cancelled", status_changed_at = NOW(), cancel_reason = ?, cancelled_by = "customer" WHERE id = ?')
            ->execute([$reason !== '' ? $reason : null, $orderId]);
        log_order_status($pdo, $orderId, $order['status'], 'cancelled', $customerId);
        restore_order_stock($pdo, $orderId);
        return $order;
    });
    $name = (string) db_value('SELECT name FROM users WHERE id = ?', [$customerId]);
    notify_vendor((int) $order['vendor_id'], 'order_cancelled_customer', 'Order #' . $order['order_number'] . ' was cancelled by the customer',
        $name . ' cancelled the order' . ($reason !== '' ? ': "' . $reason . '"' : '.') . ' Reserved stock was returned.',
        'vendor/orders.php?open=' . $orderId, 'order', $orderId);
    return ['id' => $orderId, 'status' => 'cancelled'];
}

/**
 * Customer changes quantities / removes lines / edits the note while the order is pending or accepted.
 * Stock is re-reserved by the difference and the total is recalculated at the original unit prices.
 * @param array<int,float> $quantities order_items.id => new quantity (0 removes the line)
 */
function customer_modify_order(int $customerId, int $orderId, array $quantities, string $note): array
{
    $note = mb_substr(trim($note), 0, 500);
    $result = db_transaction(function (PDO $pdo) use ($customerId, $orderId, $quantities, $note) {
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? AND customer_id = ? FOR UPDATE');
        $stmt->execute([$orderId, $customerId]);
        $order = $stmt->fetch();
        if (!$order) {
            throw new ValidationException([], 'Order not found.');
        }
        if (!in_array($order['status'], CUSTOMER_MODIFIABLE, true)) {
            throw new ValidationException([], 'This order can no longer be changed.');
        }
        $stmt = $pdo->prepare('SELECT oi.*, p.quantity AS stock, p.name AS live_name FROM order_items oi JOIN products p ON p.id = oi.product_id
                               WHERE oi.order_id = ? FOR UPDATE');
        $stmt->execute([$orderId]);
        $items = $stmt->fetchAll();

        $changes = [];
        $remaining = 0;
        foreach ($items as $it) {
            $id = (int) $it['id'];
            $new = array_key_exists($id, $quantities) ? round(max(0.0, (float) $quantities[$id]), 2) : (float) $it['quantity'];
            $old = (float) $it['quantity'];
            if ($new > 0) $remaining++;
            if (abs($new - $old) < 0.001) continue;
            if ($new - $old > (float) $it['stock']) {
                throw new ValidationException([], 'Only ' . qty((float) $it['stock'] + $old) . ' ' . $it['unit'] . ' of ' . $it['product_name'] . ' available for this order.');
            }
            $changes[] = [$it, $new, $old];
        }
        if ($remaining === 0) {
            throw new ValidationException([], 'An order needs at least one product. Cancel the order instead.');
        }
        $noteChanged = $note !== (string) $order['customer_note'];
        if (!$changes && !$noteChanged) {
            throw new ValidationException([], 'Nothing was changed.');
        }

        foreach ($changes as [$it, $new, $old]) {
            adjust_stock($pdo, (int) $it['product_id'], $old - $new);
            if ($new <= 0) {
                $pdo->prepare('DELETE FROM order_items WHERE id = ?')->execute([$it['id']]);
            } else {
                $pdo->prepare('UPDATE order_items SET quantity = ?, subtotal = ROUND(? * unit_price, 2) WHERE id = ?')->execute([$new, $new, $it['id']]);
            }
        }
        $pdo->prepare('UPDATE orders SET total_amount = (SELECT COALESCE(SUM(subtotal), 0) FROM order_items WHERE order_id = ?),
                              customer_note = ?, modified_at = NOW() WHERE id = ?')->execute([$orderId, $note !== '' ? $note : null, $orderId]);
        log_order_status($pdo, $orderId, $order['status'], 'modified', $customerId);
        $total = (float) $pdo->query('SELECT total_amount FROM orders WHERE id = ' . (int) $orderId)->fetchColumn();
        return ['order' => $order, 'total' => $total, 'lines' => count($changes)];
    });

    $o = $result['order'];
    $name = (string) db_value('SELECT name FROM users WHERE id = ?', [$customerId]);
    notify_vendor((int) $o['vendor_id'], 'order_modified', 'Order #' . $o['order_number'] . ' was modified',
        $name . ' updated the order' . ($result['lines'] ? ' (' . $result['lines'] . ' item' . ($result['lines'] > 1 ? 's' : '') . ' changed)' : ' note')
        . '. New total ' . money($result['total']) . '.', 'vendor/orders.php?open=' . $orderId, 'order', $orderId);
    return ['id' => $orderId, 'total' => money($result['total'])];
}

/**
 * Customer confirms they collected a "ready" order. The vendor then marks it completed.
 */
function customer_confirm_pickup(int $customerId, int $orderId): array
{
    $order = db_transaction(function (PDO $pdo) use ($customerId, $orderId) {
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? AND customer_id = ? FOR UPDATE');
        $stmt->execute([$orderId, $customerId]);
        $order = $stmt->fetch();
        if (!$order) {
            throw new ValidationException([], 'Order not found.');
        }
        if ($order['status'] !== 'ready') {
            throw new ValidationException([], $order['status'] === 'picked_up' ? 'You already confirmed this pickup.' : 'You can confirm pickup once the vendor marks the order ready.');
        }
        $pdo->prepare('UPDATE orders SET status = "picked_up", status_changed_at = NOW(), picked_up_at = NOW() WHERE id = ?')->execute([$orderId]);
        log_order_status($pdo, $orderId, 'ready', 'picked_up', $customerId);
        return $order;
    });
    $name = (string) db_value('SELECT name FROM users WHERE id = ?', [$customerId]);
    notify_vendor((int) $order['vendor_id'], 'order_picked_up', 'Order #' . $order['order_number'] . ' picked up',
        $name . ' confirmed they collected the order. Mark it completed once payment is received.',
        'vendor/orders.php?status=picked_up&open=' . $orderId, 'order', $orderId);
    return ['id' => $orderId, 'status' => 'picked_up'];
}

/* pickup slots */

/**
 * Pickup slots from the vendor's schedule, in PICKUP_SLOT_MINUTES blocks.
 */
function pickup_slots(array $vendor, int $days = 7, ?DateTimeImmutable $now = null): array
{
    $now = $now ?? new DateTimeImmutable('now');
    $out = [];
    foreach (vendor_market_windows($vendor, $days, $now) as $w) {
        $slots = [];
        $start = new DateTimeImmutable($w['market_date'] . ' ' . $w['pickup_start']);
        $end = new DateTimeImmutable($w['market_date'] . ' ' . $w['pickup_end']);
        for ($s = $start; $s < $end; $s = $s->modify('+' . PICKUP_SLOT_MINUTES . ' minutes')) {
            $e = min($s->modify('+' . PICKUP_SLOT_MINUTES . ' minutes'), $end);
            if ($s <= $now) continue;
            $slots[] = ['start' => $s->format('H:i'), 'end' => $e->format('H:i'), 'label' => $s->format('g:i A') . ' - ' . $e->format('g:i A')];
        }
        if ($slots) {
            $out[] = ['date' => $w['market_date'], 'label' => DAYS[$w['day']] . ', ' . date('j F Y', strtotime($w['market_date'])), 'slots' => $slots];
        }
    }
    return $out;
}

/** Customer picks (or changes) the pickup slot once the vendor has accepted the order. */
function select_pickup_slot(int $customerId, int $orderId, string $date, string $start): array
{
    $order = db_one('SELECT * FROM orders WHERE id = ? AND customer_id = ?', [$orderId, $customerId]);
    if (!$order) {
        throw new ValidationException([], 'Order not found.');
    }
    if (!in_array($order['status'], PICKUP_SELECTABLE, true)) {
        throw new ValidationException([], $order['status'] === 'pending'
            ? 'You can choose a pickup slot once the vendor accepts your order.'
            : 'Pickup can no longer be changed for this order.');
    }
    $vendor = db_one('SELECT * FROM vendors WHERE id = ?', [$order['vendor_id']]);
    $slot = null;
    foreach (pickup_slots($vendor, 14) as $day) {
        if ($day['date'] !== $date) continue;
        foreach ($day['slots'] as $s) {
            if ($s['start'] === substr($start, 0, 5)) $slot = $s;
        }
    }
    if (!$slot) {
        throw new ValidationException(['slot' => 'That pickup slot is no longer available.'], 'That pickup slot is no longer available. Choose another.');
    }
    db_query('UPDATE orders SET pickup_date = ?, pickup_start = ?, pickup_end = ?, pickup_selected_at = NOW(), reminder_sent_at = NULL WHERE id = ?',
        [$date, $slot['start'], $slot['end'], $orderId]);

    $label = pickup_label($date, $slot['start'], $slot['end']);
    $name = (string) db_value('SELECT name FROM users WHERE id = ?', [$customerId]);
    notify_vendor((int) $order['vendor_id'], 'pickup_selected', 'Pickup slot chosen for order #' . $order['order_number'],
        $name . ' will collect ' . $label . '.', 'vendor/orders.php?open=' . $orderId, 'order', $orderId);
    return ['label' => $label];
}
