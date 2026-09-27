<?php
/**
 * Messages between customers and vendors.
 * One conversation per customer and vendor, only after they have an order together.
 * conversation_for_user() checks the user is part of the conversation.
 */
declare(strict_types=1);

require_once __DIR__ . '/notifications.php';

const MESSAGE_MAX_LENGTH = 2000;

/** 'customer' or 'vendor' side for the signed-in user, or null for anyone else. */
function message_side(array $user): ?string
{
    return in_array($user['role'], ['customer', 'vendor'], true) ? $user['role'] : null;
}

/** Latest order between a customer and a vendor (the relationship that allows messaging). */
function latest_order_between(int $customerId, int $vendorId): ?array
{
    return db_one('SELECT id, order_number FROM orders WHERE customer_id = ? AND vendor_id = ? ORDER BY id DESC LIMIT 1', [$customerId, $vendorId]);
}

/**
 * Get or create a conversation. Returns null if they have no order together.
 */
function conversation_open(int $customerId, int $vendorId, ?int $orderId = null): ?array
{
    $order = null;
    if ($orderId) {
        $order = db_one('SELECT id FROM orders WHERE id = ? AND customer_id = ? AND vendor_id = ?', [$orderId, $customerId, $vendorId]);
    }
    $order = $order ?? latest_order_between($customerId, $vendorId);
    if (!$order) {
        return null;
    }
    db_query('INSERT INTO conversations (customer_id, vendor_id, order_id) VALUES (?, ?, ?)
              ON DUPLICATE KEY UPDATE order_id = IF(? IS NULL, order_id, VALUES(order_id))',
        [$customerId, $vendorId, $order['id'], $orderId]);
    return db_one('SELECT * FROM conversations WHERE customer_id = ? AND vendor_id = ?', [$customerId, $vendorId]);
}

/** Conversation row if $user takes part in it, else null (server-side authorisation). */
function conversation_for_user(array $user, int $conversationId): ?array
{
    if ($user['role'] === 'customer') {
        return db_one('SELECT * FROM conversations WHERE id = ? AND customer_id = ?', [$conversationId, $user['id']]);
    }
    if ($user['role'] === 'vendor') {
        return db_one('SELECT c.* FROM conversations c JOIN vendors v ON v.id = c.vendor_id WHERE c.id = ? AND v.user_id = ?', [$conversationId, $user['id']]);
    }
    return null;
}

/** Conversation list for the inbox (only threads that have messages, plus $includeId). */
function conversations_for_user(array $user, int $limit = 30, int $offset = 0, ?int $includeId = null): array
{
    $isCustomer = $user['role'] === 'customer';
    $mine = $isCustomer ? 'c.customer_id = ?' : 'v.user_id = ?';
    $other = $isCustomer ? 'vendor' : 'customer';
    $limit = max(1, min(100, $limit));
    $offset = max(0, $offset);
    $rows = db_all(
        "SELECT c.id, c.order_id, c.last_message_at, c.created_at, o.order_number,
                v.id AS vendor_id, v.stall_name, v.image AS vendor_image, cu.id AS customer_id, cu.name AS customer_name,
                (SELECT IF(m.is_removed, 'Message removed', m.body) FROM messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_body,
                (SELECT m.sender_role FROM messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_sender,
                (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id AND m.sender_role = ? AND m.is_read = 0) AS unread
           FROM conversations c
           JOIN vendors v   ON v.id = c.vendor_id
           JOIN users cu    ON cu.id = c.customer_id
           LEFT JOIN orders o ON o.id = c.order_id
          WHERE $mine AND (c.last_message_at IS NOT NULL OR c.id = ?)
          ORDER BY COALESCE(c.last_message_at, c.created_at) DESC, c.id DESC
          LIMIT $limit OFFSET $offset",
        [$other, $user['id'], $includeId ?? 0]
    );
    return array_map(fn($r) => conversation_to_array($r, $user['role']), $rows);
}

function conversation_to_array(array $r, string $viewerRole): array
{
    $isCustomer = $viewerRole === 'customer';
    $name = $isCustomer ? $r['stall_name'] : $r['customer_name'];
    return [
        'id'           => (int) $r['id'],
        'name'         => $name,
        'initial'      => mb_strtoupper(mb_substr((string) $name, 0, 1)),
        'image'        => $isCustomer ? upload_url($r['vendor_image']) : null,
        'order_id'     => $r['order_id'] !== null ? (int) $r['order_id'] : null,
        'order_number' => $r['order_number'],
        'order_link'   => $r['order_id'] ? url($isCustomer ? 'order.php?id=' . $r['order_id'] : 'vendor/orders.php?open=' . $r['order_id']) : null,
        'profile_link' => $isCustomer ? url('market.php?id=' . $r['vendor_id']) : null,
        'last'         => $r['last_body'] !== null ? mb_strimwidth($r['last_body'], 0, 90, '...') : null,
        'last_mine'    => $r['last_sender'] === $viewerRole,
        'unread'       => (int) $r['unread'],
        'time'         => $r['last_message_at'] ? time_ago($r['last_message_at']) : '',
    ];
}

/** Header info for one conversation, from the viewer's point of view. */
function conversation_summary(array $conversation, string $viewerRole): array
{
    $row = db_one(
        'SELECT c.id, c.order_id, c.last_message_at, c.created_at, o.order_number, v.id AS vendor_id, v.stall_name, v.image AS vendor_image,
                cu.id AS customer_id, cu.name AS customer_name, NULL AS last_body, NULL AS last_sender, 0 AS unread
           FROM conversations c JOIN vendors v ON v.id = c.vendor_id JOIN users cu ON cu.id = c.customer_id
           LEFT JOIN orders o ON o.id = c.order_id WHERE c.id = ?', [$conversation['id']]
    );
    return conversation_to_array($row, $viewerRole);
}

/** Messages in a conversation. after_id -> newer ones (polling); before_id -> older page. */
function conversation_messages(int $conversationId, string $viewerRole, int $afterId = 0, int $beforeId = 0, int $limit = 40): array
{
    $limit = max(1, min(100, $limit));
    if ($afterId > 0) {
        $rows = db_all("SELECT m.*, o.order_number FROM messages m LEFT JOIN orders o ON o.id = m.order_id
                         WHERE m.conversation_id = ? AND m.id > ? ORDER BY m.id ASC LIMIT $limit", [$conversationId, $afterId]);
    } else {
        $rows = array_reverse(db_all("SELECT m.*, o.order_number FROM messages m LEFT JOIN orders o ON o.id = m.order_id
                         WHERE m.conversation_id = ?" . ($beforeId > 0 ? ' AND m.id < ?' : '') . " ORDER BY m.id DESC LIMIT $limit",
            $beforeId > 0 ? [$conversationId, $beforeId] : [$conversationId]));
    }
    return array_map(fn($m) => [
        'id'           => (int) $m['id'],
        'body'         => $m['is_removed'] ? 'This message was removed by a MarketLink moderator.' : $m['body'],
        'removed'      => (bool) $m['is_removed'],
        'mine'         => $m['sender_role'] === $viewerRole,
        'sender_role'  => $m['sender_role'],
        'order_number' => $m['order_number'],
        'is_read'      => (bool) $m['is_read'],
        'time'         => fmt_date($m['created_at'], 'M j, g:i A'),
        'ago'          => time_ago($m['created_at']),
    ], $rows);
}

/** Mark the other side's messages in a conversation as read; returns rows changed. */
function conversation_mark_read(array $conversation, string $viewerRole): int
{
    $other = $viewerRole === 'customer' ? 'vendor' : 'customer';
    $n = db_query('UPDATE messages SET is_read = 1 WHERE conversation_id = ? AND sender_role = ? AND is_read = 0', [$conversation['id'], $other])->rowCount();
    // the bell entry for this thread is no longer news either
    $userId = $viewerRole === 'customer' ? (int) $conversation['customer_id'] : (int) db_value('SELECT user_id FROM vendors WHERE id = ?', [$conversation['vendor_id']]);
    db_query("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND related_type = 'conversation' AND related_id = ? AND is_read = 0", [$userId, $conversation['id']]);
    return $n;
}

/** Store a message and notify the other participant. */
function send_message(array $user, array $conversation, string $body, ?int $orderId = null): array
{
    $body = trim(preg_replace("/\r\n?/", "\n", $body));
    if ($body === '') {
        throw new ValidationException(['body' => 'Type a message first.'], 'Type a message first.');
    }
    if (mb_strlen($body) > MESSAGE_MAX_LENGTH) {
        throw new ValidationException(['body' => 'Keep messages under ' . MESSAGE_MAX_LENGTH . ' characters.'], 'Message is too long.');
    }
    $role = $user['role'];
    if ($orderId && !db_value('SELECT 1 FROM orders WHERE id = ? AND customer_id = ? AND vendor_id = ?', [$orderId, $conversation['customer_id'], $conversation['vendor_id']])) {
        $orderId = null;
    }
    $orderId = $orderId ?? ($conversation['order_id'] !== null ? (int) $conversation['order_id'] : null);

    $id = db_transaction(function (PDO $pdo) use ($conversation, $user, $role, $body, $orderId) {
        $pdo->prepare('INSERT INTO messages (conversation_id, sender_id, sender_role, order_id, body) VALUES (?, ?, ?, ?, ?)')
            ->execute([$conversation['id'], $user['id'], $role, $orderId, $body]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE conversations SET last_message_at = NOW(), order_id = COALESCE(?, order_id) WHERE id = ?')->execute([$orderId, $conversation['id']]);
        return $id;
    });

    $preview = mb_strimwidth($body, 0, 120, '...');
    $firstMessage = (int) db_value('SELECT COUNT(*) FROM messages WHERE conversation_id = ?', [$conversation['id']]) === 1;
    if ($role === 'customer') {
        notify_coalesce((int) db_value('SELECT user_id FROM vendors WHERE id = ?', [$conversation['vendor_id']]),
            $firstMessage ? 'message_new' : 'message_customer', 'New message from ' . $user['name'], $preview,
            'vendor/messages.php?c=' . $conversation['id'], 'conversation', (int) $conversation['id']);
    } else {
        $stall = (string) db_value('SELECT stall_name FROM vendors WHERE id = ?', [$conversation['vendor_id']]);
        $hasCustomerMessages = (bool) db_value("SELECT 1 FROM messages WHERE conversation_id = ? AND sender_role = 'customer' LIMIT 1", [$conversation['id']]);
        notify_coalesce((int) $conversation['customer_id'], $hasCustomerMessages ? 'message_reply' : 'message_new',
            $stall . ($hasCustomerMessages ? ' replied to your message' : ' sent you a message'), $preview,
            'messages.php?c=' . $conversation['id'], 'conversation', (int) $conversation['id']);
    }
    return conversation_messages((int) $conversation['id'], $role, $id - 1, 0, 1)[0];
}

/** Unread messages addressed to this user (for the Messages badge). */
function unread_message_count(array $user): int
{
    if ($user['role'] === 'customer') {
        return (int) db_value("SELECT COUNT(*) FROM messages m JOIN conversations c ON c.id = m.conversation_id
                               WHERE c.customer_id = ? AND m.sender_role = 'vendor' AND m.is_read = 0", [$user['id']]);
    }
    if ($user['role'] === 'vendor') {
        return (int) db_value("SELECT COUNT(*) FROM messages m JOIN conversations c ON c.id = m.conversation_id JOIN vendors v ON v.id = c.vendor_id
                               WHERE v.user_id = ? AND m.sender_role = 'customer' AND m.is_read = 0", [$user['id']]);
    }
    return 0;
}
