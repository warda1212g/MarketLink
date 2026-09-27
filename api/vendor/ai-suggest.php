<?php
/**
 * Vendor AI suggestions.
 * GET  -> discount suggestions + market gap opportunities
 * POST action=apply_discount {product_id, discount} -> apply a suggested price cut
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/ai_features.php';

api_run(function () {
    $vendor = require_vendor_api();
    $vid = (int) $vendor['id'];

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        json_ok([
            'discounts' => vendor_discount_suggestions($vendor),
            'gaps'      => vendor_category_gaps($vid),
        ]);
    }

    require_method('POST');
    $in = request_data();

    switch ($in['action'] ?? '') {
        case 'apply_discount':
            $pid = (int) ($in['product_id'] ?? 0);
            $discount = (int) ($in['discount'] ?? 0);
            $p = db_one('SELECT * FROM products WHERE id = ? AND vendor_id = ? AND deleted_at IS NULL', [$pid, $vid]);
            if (!$p) {
                json_error('Product not found.', 404);
            }
            if ($discount < 5 || $discount > 50) {
                json_error('Choose a discount between 5% and 50%.', 422);
            }
            $new = round((float) $p['price'] * (1 - $discount / 100), 2);
            db_query('UPDATE products SET price = ? WHERE id = ? AND vendor_id = ?', [$new, $pid, $vid]);
            json_ok([
                'product_id' => $pid,
                'price'      => $new,
                'price_label' => money($new),
            ], 'Discount applied - ' . $p['name'] . ' is now ' . money($new) . '.');
        default:
            json_error('Unknown action.', 422);
    }
});
