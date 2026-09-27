<?php
/**
 * Customer shopping cart (persistent, per account).
 * GET                                         -> cart grouped by stall + totals
 * POST {action:"add", product_id, quantity}   -> add (adds to existing quantity)
 * POST {action:"update", product_id, quantity} -> set quantity (0 removes)
 * POST {action:"remove", product_id}
 * Stock, availability and ownership are checked server-side in includes/services/cart.php.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/cart.php';

api_run(function () {
    $user = current_user();
    if (!$user) {
        json_error('Please sign in to use the cart.', 401);
    }
    $user = require_user_api(['customer']);
    $uid = (int) $user['id'];

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        json_ok(['cart' => cart_to_array(cart_groups($uid))]);
    }

    require_method('POST');
    $in = request_data();
    $pid = int_input($in, 'product_id', 0);
    $q = (float) (float_input($in, 'quantity', 0) ?? 0);

    switch ($in['action'] ?? '') {
        case 'add':
            cart_add($uid, $pid, $q);
            $msg = 'Added to your cart.';
            break;
        case 'update':
            cart_update($uid, $pid, $q);
            $msg = $q > 0 ? 'Cart updated.' : 'Item removed.';
            break;
        case 'remove':
            cart_remove($uid, $pid);
            $msg = 'Item removed.';
            break;
        default:
            json_error('Unknown action.');
    }
    json_ok(['cart' => cart_to_array(cart_groups($uid)), 'count' => cart_count($uid)], $msg);
});
