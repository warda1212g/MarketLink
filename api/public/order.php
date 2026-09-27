<?php
/**
 * Customer pre-orders (customer session + CSRF).
 * GET  ?action=slots&id=                              -> pickup slots for an accepted order
 * POST {action:"checkout", notes:{vendor_id: note}}   -> cart -> one pre-order per stall
 * POST {action:"cancel", id, reason}
 * POST {action:"modify", id, items:{order_item_id: qty}, note}
 * POST {action:"pickup", id, date, start}             -> choose / change pickup slot
 * POST {action:"picked_up", id}                       -> customer confirms they collected a ready order
 * Ownership, allowed states, stock and slots are enforced in includes/services/orders.php.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/orders.php';

api_run(function () {
    if (!current_user()) {
        json_error('Please sign in to place a pre-order.', 401);
    }
    $user = require_user_api(['customer']);
    $uid = (int) $user['id'];

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (($_GET['action'] ?? '') !== 'slots') {
            json_error('Unknown action.');
        }
        $order = customer_order($uid, int_input($_GET, 'id', 0));
        if (!$order) {
            json_error('Order not found.', 404);
        }
        $vendor = db_one('SELECT * FROM vendors WHERE id = ?', [$order['vendor_id']]);
        json_ok(['days' => pickup_slots($vendor), 'selectable' => in_array($order['status'], PICKUP_SELECTABLE, true)]);
    }

    require_method('POST');
    $in = request_data();
    $id = int_input($in, 'id', 0);

    switch ($in['action'] ?? '') {
        case 'checkout':
            $notes = [];
            foreach ((array) ($in['notes'] ?? []) as $vid => $note) {
                if (is_scalar($note)) $notes[(int) $vid] = (string) $note;
            }
            $orders = checkout_cart($user, $notes);
            $first = $orders[0];
            json_ok([
                'orders'   => array_map(fn($o) => ['id' => $o['id'], 'order_number' => $o['order_number'], 'total' => money($o['total'])], $orders),
                'redirect' => url(count($orders) === 1 ? 'order.php?id=' . $first['id'] . '&placed=1' : 'my-orders.php?placed=' . $first['id']),
            ], count($orders) === 1
                ? 'Pre-order #' . $first['order_number'] . ' placed. The vendor will confirm it shortly.'
                : count($orders) . ' pre-orders placed - one per stall.');

        case 'cancel':
            customer_cancel_order($uid, $id, str_input($in, 'reason', 255));
            json_ok([], 'Order cancelled. The vendor has been notified.');

        case 'modify':
            $qty = [];
            foreach ((array) ($in['items'] ?? []) as $itemId => $q) {
                if (is_numeric($q)) $qty[(int) $itemId] = (float) $q;
            }
            $r = customer_modify_order($uid, $id, $qty, str_input($in, 'note', 500));
            json_ok(['total' => $r['total']], 'Order updated. The vendor has been notified.');

        case 'picked_up':
            customer_confirm_pickup($uid, $id);
            json_ok([], 'Thanks! The vendor has been told you collected the order.');

        case 'pickup':
            $r = select_pickup_slot($uid, $id, str_input($in, 'date', 10), str_input($in, 'start', 5));
            json_ok(['label' => $r['label']], 'Pickup slot confirmed: ' . $r['label'] . '.');

        default:
            json_error('Unknown action.');
    }
});
