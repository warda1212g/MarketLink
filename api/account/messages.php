<?php
/**
 * Customer <-> vendor messaging (signed-in customer or approved vendor, CSRF on POST).
 * GET  ?action=conversations&page=                           -> inbox
 * GET  ?action=messages&conversation_id=&after_id=&before_id= -> thread (after_id for polling)
 * POST {action:"send", conversation_id, body, order_id?}
 * POST {action:"read", conversation_id}
 * Every conversation is loaded through conversation_for_user(), so users only reach their own threads.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/messages.php';

api_run(function () {
    $user = require_user_api(['customer', 'vendor']);
    $role = $user['role'];

    $load = function (array $src) use ($user): array {
        $c = conversation_for_user($user, int_input($src, 'conversation_id', 0));
        if (!$c) {
            json_error('Conversation not found.', 404);
        }
        return $c;
    };

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        switch ($_GET['action'] ?? '') {
            case 'conversations':
                $page = max(1, int_input($_GET, 'page', 1));
                $list = conversations_for_user($user, 31, ($page - 1) * 30, int_input($_GET, 'include'));
                json_ok(['conversations' => array_slice($list, 0, 30), 'has_more' => count($list) > 30, 'unread' => unread_message_count($user)]);

            case 'messages':
                $c = $load($_GET);
                $after = int_input($_GET, 'after_id', 0);
                $before = int_input($_GET, 'before_id', 0);
                $messages = conversation_messages((int) $c['id'], $role, $after, $before, 40);
                if ($after >= 0 && !$before) {
                    conversation_mark_read($c, $role);
                }
                json_ok([
                    'conversation' => conversation_summary($c, $role),
                    'messages'     => $messages,
                    'has_older'    => !$after && count($messages) === 40,
                    'unread'       => unread_message_count($user),
                ]);

            default:
                json_error('Unknown action.');
        }
    }

    require_method('POST');
    $in = request_data();
    switch ($in['action'] ?? '') {
        case 'send':
            $c = $load($in);
            $msg = send_message($user, $c, (string) ($in['body'] ?? ''), int_input($in, 'order_id'));
            json_ok(['message' => $msg]);

        case 'read':
            conversation_mark_read($load($in), $role);
            json_ok(['unread' => unread_message_count($user)]);

        default:
            json_error('Unknown action.');
    }
});
