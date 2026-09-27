<?php
/**
 * Notifications for customers, vendors and admins.
 * link is a page like "order.php?id=5".
 */
declare(strict_types=1);

/** Icon (i-* sprite id) per notification type prefix. */
const NOTIFICATION_ICONS = [
    'order'    => 'i-cart',
    'pickup'   => 'i-clock',
    'message'  => 'i-chat',
    'review'   => 'i-star',
    'account'  => 'i-user',
    'vendor'   => 'i-store',
    'product'  => 'i-box',
    'report'   => 'i-flag',
    'moderation' => 'i-flag',
    'favorite' => 'i-heart',
];

function notify(int $userId, string $type, string $title, string $message = '', ?string $link = null, ?string $relatedType = null, ?int $relatedId = null): void
{
    try {
        db_query(
            'INSERT INTO notifications (user_id, type, title, message, link, related_type, related_id) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$userId, mb_substr($type, 0, 40), mb_substr($title, 0, 160), $message !== '' ? mb_substr($message, 0, 500) : null,
             $link ? mb_substr($link, 0, 255) : null, $relatedType, $relatedId]
        );
    } catch (Throwable $ex) {
        error_log('[MarketLink notify] ' . $ex->getMessage());
    }
}

/**
 * Like notify() but updates the unread one if it exists (so chats don't spam the bell).
 */
function notify_coalesce(int $userId, string $type, string $title, string $message, ?string $link, string $relatedType, int $relatedId): void
{
    try {
        $id = db_value('SELECT id FROM notifications WHERE user_id = ? AND type = ? AND related_type = ? AND related_id = ? AND is_read = 0 ORDER BY id DESC LIMIT 1',
            [$userId, $type, $relatedType, $relatedId]);
        if ($id) {
            db_query('UPDATE notifications SET title = ?, message = ?, link = ?, created_at = NOW() WHERE id = ?',
                [mb_substr($title, 0, 160), mb_substr($message, 0, 500), $link, $id]);
            return;
        }
    } catch (Throwable $ex) {
        error_log('[MarketLink notify] ' . $ex->getMessage());
    }
    notify($userId, $type, $title, $message, $link, $relatedType, $relatedId);
}

function notify_vendor(int $vendorId, string $type, string $title, string $message = '', ?string $link = null, ?string $relatedType = null, ?int $relatedId = null): void
{
    $userId = (int) db_value('SELECT user_id FROM vendors WHERE id = ?', [$vendorId]);
    if ($userId) {
        notify($userId, $type, $title, $message, $link, $relatedType, $relatedId);
    }
}

function notify_admins(string $type, string $title, string $message = '', ?string $link = null, ?string $relatedType = null, ?int $relatedId = null): void
{
    foreach (db_all("SELECT id FROM users WHERE role = 'admin' AND is_active = 1") as $a) {
        notify((int) $a['id'], $type, $title, $message, $link, $relatedType, $relatedId);
    }
}

/** Admin alert when someone signs up (vendors need approval; customers are informational). */
function notify_new_account(int $userId, string $role, string $name, string $stallName = ''): void
{
    if ($role === 'vendor') {
        notify_admins('vendor_registered', 'New vendor waiting for approval',
            $name . ($stallName !== '' ? ' registered "' . $stallName . '"' : ' registered as a vendor') . '. Review and approve the stall.',
            'admin/farmers.php?status=pending&id=' . $userId, 'user', $userId);
    } else {
        notify_admins('account_customer', 'New customer registered', $name . ' created a customer account.',
            'admin/farmers.php?role=customer&id=' . $userId, 'user', $userId);
    }
}

function notification_icon(string $type): string
{
    $prefix = explode('_', $type)[0];
    return NOTIFICATION_ICONS[$prefix] ?? 'i-bell';
}

function notification_unread_count(int $userId): int
{
    return (int) db_value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [$userId]);
}

/** @param string $filter all|unread|read */
function notifications_list(int $userId, string $filter = 'all', int $limit = 20, int $offset = 0): array
{
    $where = 'user_id = ?';
    if ($filter === 'unread') $where .= ' AND is_read = 0';
    if ($filter === 'read') $where .= ' AND is_read = 1';
    $limit = max(1, min(100, $limit));
    $offset = max(0, $offset);
    return db_all("SELECT * FROM notifications WHERE $where ORDER BY id DESC LIMIT $limit OFFSET $offset", [$userId]);
}

function notification_to_array(array $n): array
{
    return [
        'id'       => (int) $n['id'],
        'type'     => $n['type'],
        'icon'     => notification_icon($n['type']),
        'title'    => $n['title'],
        'message'  => $n['message'],
        'link'     => $n['link'] ? url($n['link']) : null,
        'is_read'  => (bool) $n['is_read'],
        'time'     => time_ago($n['created_at']),
        'date'     => fmt_date($n['created_at'], 'M j, Y, g:i A'),
    ];
}

/**
 * Pickup reminders. Runs when the bell is checked, reminds both sides 2 hours before pickup.
 */
function run_pickup_reminders(): void
{
    try {
        $due = db_all("SELECT o.id, o.order_number, o.customer_id, o.vendor_id, o.pickup_date, o.pickup_start, o.pickup_end, v.stall_name, u.name AS customer
                         FROM orders o JOIN vendors v ON v.id = o.vendor_id JOIN users u ON u.id = o.customer_id
                        WHERE o.status IN ('accepted','preparing','ready') AND o.reminder_sent_at IS NULL
                          AND o.pickup_date = CURDATE() AND o.pickup_start IS NOT NULL
                          AND o.pickup_start BETWEEN CURTIME() AND ADDTIME(CURTIME(), '02:00:00')
                        LIMIT 50");
        foreach ($due as $o) {
            $claimed = db_query('UPDATE orders SET reminder_sent_at = NOW() WHERE id = ? AND reminder_sent_at IS NULL', [$o['id']])->rowCount();
            if (!$claimed) continue;
            $when = pickup_label($o['pickup_date'], $o['pickup_start'], $o['pickup_end']);
            notify((int) $o['customer_id'], 'pickup_reminder', 'Pickup reminder: order #' . $o['order_number'],
                'Collect your order from ' . $o['stall_name'] . ' ' . $when . '.', 'order.php?id=' . $o['id'], 'order', (int) $o['id']);
            notify_vendor((int) $o['vendor_id'], 'pickup_approaching', 'Pickup approaching: order #' . $o['order_number'],
                $o['customer'] . ' is collecting ' . $when . '.', 'vendor/orders.php?open=' . $o['id'], 'order', (int) $o['id']);
        }
    } catch (Throwable $ex) {
        error_log('[MarketLink reminders] ' . $ex->getMessage());
    }
}
