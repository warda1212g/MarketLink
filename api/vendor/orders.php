<?php
/**
 * GET  ?status=&q=&page=   -> vendor's orders with items + per-status counts
 * POST {action:"status", id, status} -> validated status transition
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/orders.php';
require_once APP_ROOT . '/includes/partials/components.php';

api_run(function () {
    $vendor = require_vendor_api();
    $vid = (int) $vendor['id'];

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $perPage = 20;
        $page = max(1, int_input($_GET, 'page', 1));
        $status = $_GET['status'] ?? '';
        $q = str_input($_GET, 'q', 60);
        $orders = !empty($_GET["counts_only"]) ? [] : vendor_orders($vid, [
            'status' => $status, 'q' => $q, 'id' => int_input($_GET, 'id'), 'with_items' => true,
            'limit' => $perPage + 1, 'offset' => ($page - 1) * $perPage,
        ]);
        $hasMore = count($orders) > $perPage;
        $orders = array_slice($orders, 0, $perPage);

        $counts = array_fill_keys(ORDER_STATUSES, 0);
        foreach (db_all('SELECT status, COUNT(*) n FROM orders WHERE vendor_id = ? GROUP BY status', [$vid]) as $row) {
            $counts[$row['status']] = (int) $row['n'];
        }
        $counts['all'] = array_sum($counts);

        json_ok([
            'orders'   => array_map('order_to_array', $orders),
            'counts'   => $counts,
            'page'     => $page,
            'has_more' => $hasMore,
        ]);
    }

    require_method('POST');
    $in = request_data();
    $action = $in['action'] ?? '';
    if ($action !== 'status') {
        json_error('Unknown action.');
    }
    $id = (int) ($in['id'] ?? 0);
    $to = (string) ($in['status'] ?? '');
    change_order_status($vid, $id, $to, (int) current_user()['id'], str_input($in, 'reason', 255));

    $order = vendor_orders($vid, ['id' => $id, 'with_items' => true, 'limit' => 1])[0];

    json_ok([
        'order'        => order_to_array($order),
        'actions_html' => order_action_buttons($order),
    ], 'Order updated.');
});
