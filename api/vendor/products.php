<?php
/**
 * Vendor product management.
 * GET  ?q=&category=&status=&stock=low     -> list
 * POST action=save (multipart, optional id) -> create / update (+ image)
 * POST action=delete {id}                   -> soft delete
 * POST action=status {id, status}           -> available / sold_out
 * POST action=quantity {id, quantity}       -> update stock
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/markets.php';

function product_to_array(array $p): array
{
    return [
        'id'          => (int) $p['id'],
        'name'        => $p['name'],
        'description' => $p['description'],
        'category_id' => $p['category_id'] ? (int) $p['category_id'] : null,
        'category'    => $p['category_name'] ?? null,
        'subcategory_id' => $p['subcategory_id'] ? (int) $p['subcategory_id'] : null,
        'subcategory' => $p['subcategory_name'] ?? null,
        'market_id'   => $p['market_id'] ? (int) $p['market_id'] : null,
        'market'      => $p['market_name'] ?? null,
        'price'       => (float) $p['price'],
        'price_label' => money($p['price']),
        'unit'        => $p['unit'],
        'quantity'    => (float) $p['quantity'],
        'quantity_label' => qty($p['quantity']),
        'harvest_date' => $p['harvest_date'] ?? null,
        'image'       => upload_url($p['image']),
        'status'      => $p['status'],
        'moderation'  => $p['moderation_status'] ?? 'active',
        'updated_at'  => $p['updated_at'],
    ];
}

function find_vendor_product(int $vendorId, int $id): array
{
    $p = db_one('SELECT p.*, c.name AS category_name, sc.name AS subcategory_name, m.name AS market_name FROM products p LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN categories sc ON sc.id = p.subcategory_id LEFT JOIN markets m ON m.id = p.market_id
                 WHERE p.id = ? AND p.vendor_id = ? AND p.deleted_at IS NULL', [$id, $vendorId]);
    if (!$p) {
        throw new ValidationException([], 'Product not found.');
    }
    return $p;
}

api_run(function () {
    $vendor = require_vendor_api();
    $vid = (int) $vendor['id'];

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $where = ['p.vendor_id = ?', 'p.deleted_at IS NULL'];
        $params = [$vid];
        if ($q = str_input($_GET, 'q', 80)) {
            $where[] = '(p.name LIKE ? OR p.description LIKE ?)';
            array_push($params, "%$q%", "%$q%");
        }
        if ($cat = int_input($_GET, 'category')) {
            $where[] = 'p.category_id = ?';
            $params[] = $cat;
        }
        $status = $_GET['status'] ?? '';
        if (in_array($status, ['available', 'sold_out'], true)) {
            $where[] = 'p.status = ?';
            $params[] = $status;
        }
        if (($_GET['stock'] ?? '') === 'low') {
            $where[] = 'p.quantity <= ?';
            $params[] = $vendor['low_stock_threshold'];
        }
        $sort = [
            'newest' => 'p.created_at DESC', 'name' => 'p.name ASC', 'price' => 'p.price DESC', 'stock' => 'p.quantity ASC',
        ][$_GET['sort'] ?? 'newest'] ?? 'p.created_at DESC';

        $rows = db_all('SELECT p.*, c.name AS category_name, sc.name AS subcategory_name, m.name AS market_name FROM products p LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN categories sc ON sc.id = p.subcategory_id LEFT JOIN markets m ON m.id = p.market_id
                        WHERE ' . implode(' AND ', $where) . " ORDER BY $sort, p.id DESC", $params);
        json_ok([
            'products'  => array_map('product_to_array', $rows),
            'threshold' => (float) $vendor['low_stock_threshold'],
            'total'     => (int) db_value('SELECT COUNT(*) FROM products WHERE vendor_id = ? AND deleted_at IS NULL', [$vid]),
        ]);
    }

    require_method('POST');
    $in = request_data();
    $action = $in['action'] ?? '';

    switch ($action) {
        case 'save':
            $id = int_input($in, 'id');
            $existing = $id ? find_vendor_product($vid, $id) : null;

            $data = [
                'name'        => str_input($in, 'name', 150),
                'category_id' => int_input($in, 'category_id'),
                'description' => str_input($in, 'description', 2000),
                'price'       => float_input($in, 'price'),
                'unit'        => str_input($in, 'unit', 20),
                'quantity'    => float_input($in, 'quantity'),
                'status'      => ($in['status'] ?? 'available') === 'sold_out' ? 'sold_out' : 'available',
            ];
            $harvest = str_input($in, 'harvest_date', 10);
            $data['harvest_date'] = $harvest !== '' && valid_date($harvest) ? $harvest : null;
            $errors = [];
            if (mb_strlen($data['name']) < 2) $errors['name'] = 'Enter a product name.';
            if (!$data['category_id'] || !db_value('SELECT 1 FROM categories WHERE id = ? AND parent_id IS NULL', [$data['category_id']])) $errors['category_id'] = 'Choose a category.';

            // Market: must be one the vendor is actively assigned to (required once they have any).
            $data['market_id'] = int_input($in, 'market_id');
            $myMarketIds = array_map(fn($m) => (int) $m['id'], vendor_markets($vid));
            if ($data['market_id'] && !in_array($data['market_id'], $myMarketIds, true)) {
                $errors['market_id'] = 'You are not assigned to that market.';
            } elseif (!$data['market_id'] && $myMarketIds) {
                $errors['market_id'] = 'Choose a market.';
            }
            // Subcategory is optional but must sit under the chosen category.
            $data['subcategory_id'] = int_input($in, 'subcategory_id');
            if ($data['subcategory_id'] && !db_value('SELECT 1 FROM categories WHERE id = ? AND parent_id = ?', [$data['subcategory_id'], $data['category_id']])) {
                $errors['subcategory_id'] = 'Choose a subcategory of the selected category.';
            }
            if ($data['price'] === null || $data['price'] <= 0 || $data['price'] > 10000000) $errors['price'] = 'Enter a price greater than 0.';
            if (!in_array($data['unit'], PRODUCT_UNITS, true)) $errors['unit'] = 'Choose a unit.';
            if ($data['quantity'] === null || $data['quantity'] < 0 || $data['quantity'] > 1000000) $errors['quantity'] = 'Enter a quantity of 0 or more.';
            if ($errors) throw new ValidationException($errors);

            $image = store_uploaded_image('image', 'products');

            if ($existing) {
                db_query('UPDATE products SET name=?, category_id=?, subcategory_id=?, market_id=?, description=?, price=?, unit=?, quantity=?, status=?, harvest_date=?, image=COALESCE(?, image)
                          WHERE id = ? AND vendor_id = ?', [
                    $data['name'], $data['category_id'], $data['subcategory_id'], $data['market_id'], $data['description'] ?: null, $data['price'], $data['unit'],
                    $data['quantity'], $data['status'], $data['harvest_date'], $image, $id, $vid,
                ]);
                if ($image) delete_upload($existing['image']);
                $msg = 'Product updated successfully.';
            } else {
                db_query('INSERT INTO products (vendor_id, market_id, name, category_id, subcategory_id, description, price, unit, quantity, status, harvest_date, image) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', [
                    $vid, $data['market_id'], $data['name'], $data['category_id'], $data['subcategory_id'], $data['description'] ?: null, $data['price'], $data['unit'],
                    $data['quantity'], $data['status'], $data['harvest_date'], $image,
                ]);
                $id = (int) db()->lastInsertId();
                $msg = 'Product saved successfully.';
                // customers who saved this stall as a favourite hear about new stock
                require_once APP_ROOT . '/includes/services/notifications.php';
                foreach (db_all("SELECT customer_id FROM favorites WHERE item_type = 'vendor' AND item_id = ? LIMIT 500", [$vid]) as $f) {
                    notify((int) $f['customer_id'], 'favorite_new_product', $vendor['stall_name'] . ' listed a new product',
                        $data['name'] . ' - ' . money($data['price']) . ' / ' . $data['unit'], 'market.php?id=' . $vid, 'product', $id);
                }
            }
            json_ok(['product' => product_to_array(find_vendor_product($vid, $id))], $msg);

        case 'delete':
            $p = find_vendor_product($vid, (int) ($in['id'] ?? 0));
            // Soft delete keeps order history and analytics intact.
            db_transaction(function (PDO $pdo) use ($p, $vid) {
                $pdo->prepare('UPDATE products SET deleted_at = NOW(), status = "sold_out" WHERE id = ? AND vendor_id = ?')->execute([$p['id'], $vid]);
                $pdo->prepare('DELETE FROM weekly_stock WHERE product_id = ? AND vendor_id = ?')->execute([$p['id'], $vid]);
            });
            json_ok(['id' => (int) $p['id']], 'Product deleted.');

        case 'status':
            $p = find_vendor_product($vid, (int) ($in['id'] ?? 0));
            $status = ($in['status'] ?? '') === 'sold_out' ? 'sold_out' : 'available';
            db_query('UPDATE products SET status = ? WHERE id = ? AND vendor_id = ?', [$status, $p['id'], $vid]);
            json_ok(['product' => product_to_array(find_vendor_product($vid, (int) $p['id']))],
                $status === 'available' ? 'Marked as available.' : 'Marked as sold out.');

        case 'quantity':
            $p = find_vendor_product($vid, (int) ($in['id'] ?? 0));
            $q = float_input($in, 'quantity');
            if ($q === null || $q < 0 || $q > 1000000) {
                throw new ValidationException(['quantity' => 'Enter a quantity of 0 or more.'], 'Enter a quantity of 0 or more.');
            }
            db_query('UPDATE products SET quantity = ?, status = IF(? <= 0, "sold_out", status) WHERE id = ? AND vendor_id = ?', [$q, $q, $p['id'], $vid]);
            json_ok(['product' => product_to_array(find_vendor_product($vid, (int) $p['id']))], 'Stock updated.');

        default:
            json_error('Unknown action.');
    }
});
