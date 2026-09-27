<?php
/**
 * Notifications for the signed-in user (customer, vendor or admin). CSRF on POST.
 * GET  ?action=summary                          -> unread notification / message / cart counts (polled)
 * GET  ?action=list&filter=all|unread|read&page= -> notifications, newest first
 * POST {action:"read", id} | {action:"read_all"} | {action:"delete", id}
 * Every query is scoped to user_id = the session user.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/notifications.php';
require_once APP_ROOT . '/includes/services/messages.php';
require_once APP_ROOT . '/includes/services/cart.php';

api_run(function () {
    $user = require_user_api();
    $uid = (int) $user['id'];

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        switch ($_GET['action'] ?? 'summary') {
            case 'summary':
                run_pickup_reminders();
                $latest = !empty($_GET['with_latest']) ? array_map('notification_to_array', notifications_list($uid, 'all', 8)) : null;
                json_ok([
                    'notifications' => notification_unread_count($uid),
                    'messages'      => unread_message_count($user),
                    'cart'          => $user['role'] === 'customer' ? cart_count($uid) : 0,
                    'latest'        => $latest,
                ]);

            case 'list':
                $filter = in_array($_GET['filter'] ?? '', ['unread', 'read'], true) ? $_GET['filter'] : 'all';
                $page = max(1, int_input($_GET, 'page', 1));
                $rows = notifications_list($uid, $filter, 21, ($page - 1) * 20);
                json_ok([
                    'items'    => array_map('notification_to_array', array_slice($rows, 0, 20)),
                    'has_more' => count($rows) > 20,
                    'unread'   => notification_unread_count($uid),
                ]);

            default:
                json_error('Unknown action.');
        }
    }

    require_method('POST');
    $in = request_data();
    $id = int_input($in, 'id', 0);
    switch ($in['action'] ?? '') {
        case 'read':
            db_query('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?', [$id, $uid]);
            break;
        case 'unread':
            db_query('UPDATE notifications SET is_read = 0 WHERE id = ? AND user_id = ?', [$id, $uid]);
            break;
        case 'read_all':
            db_query('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0', [$uid]);
            break;
        case 'delete':
            db_query('DELETE FROM notifications WHERE id = ? AND user_id = ?', [$id, $uid]);
            break;
        default:
            json_error('Unknown action.');
    }
    json_ok(['unread' => notification_unread_count($uid)]);
});
