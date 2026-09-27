<?php
/**
 * Reports and moderation. Nothing gets deleted, things are hidden or suspended
 * and every action is saved in moderation_logs.
 */
declare(strict_types=1);

require_once __DIR__ . '/notifications.php';

const REPORT_REASONS = [
    'spam'          => 'Spam or misleading',
    'inappropriate' => 'Inappropriate or offensive',
    'abusive'       => 'Abusive language or harassment',
    'fake'          => 'Fake, fraudulent or unsafe',
    'wrong_info'    => 'Wrong product information',
    'other'         => 'Something else',
];

/** What each role may report. */
const REPORTABLE = [
    'customer' => ['product', 'review', 'vendor'],
    'vendor'   => ['message', 'review'],
];

const PRODUCT_MODERATION = ['active', 'inactive', 'flagged', 'removed'];

/** Check that the reporter can actually see the content they are reporting. Returns a short label. */
function report_target_label(array $user, string $type, int $id): ?string
{
    switch ($type) {
        case 'product':
            $r = db_one('SELECT p.name, v.stall_name FROM products p JOIN vendors v ON v.id = p.vendor_id WHERE p.id = ? AND p.deleted_at IS NULL', [$id]);
            return $r ? $r['name'] . ' (' . $r['stall_name'] . ')' : null;
        case 'vendor':
            $r = db_one('SELECT stall_name FROM vendors WHERE id = ?', [$id]);
            return $r ? $r['stall_name'] : null;
        case 'review':
            $sql = "SELECT r.rating, v.stall_name FROM reviews r JOIN vendors v ON v.id = r.vendor_id WHERE r.id = ? AND r.status = 'visible'";
            $params = [$id];
            if ($user['role'] === 'vendor') {
                $sql .= ' AND v.user_id = ?';
                $params[] = $user['id'];
            }
            $r = db_one($sql, $params);
            return $r ? $r['rating'] . '-star review of ' . $r['stall_name'] : null;
        case 'message':
            // vendors may report customer messages in their own conversations
            $r = db_one("SELECT u.name FROM messages m JOIN conversations c ON c.id = m.conversation_id JOIN vendors v ON v.id = c.vendor_id
                          JOIN users u ON u.id = m.sender_id
                         WHERE m.id = ? AND v.user_id = ? AND m.sender_role = 'customer'", [$id, $user['id']]);
            return $r ? 'message from ' . $r['name'] : null;
    }
    return null;
}

function create_report(array $user, string $type, int $id, string $reason, string $details): int
{
    if (!in_array($type, REPORTABLE[$user['role']] ?? [], true)) {
        throw new ValidationException([], 'You cannot report this kind of content.');
    }
    if (!isset(REPORT_REASONS[$reason])) {
        throw new ValidationException(['reason' => 'Choose a reason.'], 'Choose a reason.');
    }
    $label = report_target_label($user, $type, $id);
    if ($label === null) {
        throw new ValidationException([], 'That content could not be found.');
    }
    if (db_value("SELECT 1 FROM reports WHERE reporter_id = ? AND content_type = ? AND content_id = ? AND status = 'open'", [$user['id'], $type, $id])) {
        throw new ValidationException([], 'You have already reported this. Our team is reviewing it.');
    }
    db_query('INSERT INTO reports (reporter_id, reporter_role, content_type, content_id, reason, details) VALUES (?, ?, ?, ?, ?, ?)',
        [$user['id'], $user['role'], $type, $id, $reason, $details !== '' ? mb_substr($details, 0, 1000) : null]);
    $reportId = (int) db()->lastInsertId();
    notify_admins('report_' . $type, 'Reported ' . $type . ': ' . mb_strimwidth($label, 0, 80, '...'),
        $user['name'] . ' reported this as "' . REPORT_REASONS[$reason] . '".', 'admin/moderation.php?tab=reports&report=' . $reportId, 'report', $reportId);
    return $reportId;
}

function moderation_log(int $adminId, string $type, int $id, string $action, string $reason = '', ?int $reportId = null): void
{
    db_query('INSERT INTO moderation_logs (admin_id, content_type, content_id, action, reason, report_id) VALUES (?, ?, ?, ?, ?, ?)',
        [$adminId, $type, $id, $action, $reason !== '' ? mb_substr($reason, 0, 500) : null, $reportId]);
    log_activity('moderation', ucfirst($type) . ' #' . $id . ' ' . $action . ($reason !== '' ? ' - ' . mb_substr($reason, 0, 120) : ''), $type, $id, $adminId);
}

/** Close any open reports on the same content once an admin has acted on it. */
function close_reports_for(string $type, int $id, int $adminId, string $action): void
{
    db_query("UPDATE reports SET status = 'resolved', action_taken = ?, resolved_by = ?, resolved_at = NOW()
               WHERE content_type = ? AND content_id = ? AND status = 'open'", [$action, $adminId, $type, $id]);
}

function moderate_product(int $adminId, int $productId, string $status, string $reason, ?int $reportId = null): string
{
    if (!in_array($status, PRODUCT_MODERATION, true)) {
        throw new ValidationException([], 'Unknown product status.');
    }
    $p = db_one('SELECT id, name, vendor_id, moderation_status FROM products WHERE id = ?', [$productId]);
    if (!$p) {
        throw new ValidationException([], 'Product not found.');
    }
    if (in_array($status, ['removed', 'flagged'], true) && trim($reason) === '') {
        throw new ValidationException(['reason' => 'Give a reason - the vendor will see it.'], 'Give a reason - the vendor will see it.');
    }
    db_query('UPDATE products SET moderation_status = ? WHERE id = ?', [$status, $productId]);
    $action = ['active' => 'activated', 'inactive' => 'deactivated', 'flagged' => 'flagged', 'removed' => 'removed'][$status];
    moderation_log($adminId, 'product', $productId, $action, $reason, $reportId);
    if ($status !== 'active') {
        close_reports_for('product', $productId, $adminId, 'product ' . $action);
    }
    // products that leave the catalogue also leave carts
    if ($status !== 'active') {
        db_query('DELETE FROM cart_items WHERE product_id = ?', [$productId]);
    }
    notify_vendor((int) $p['vendor_id'], 'product_moderated', 'Admin ' . $action . ' your product "' . $p['name'] . '"',
        $status === 'active' ? 'It is visible to customers again.' : ('It is hidden from customers.' . ($reason !== '' ? ' Reason: ' . $reason : '')),
        'vendor/products.php', 'product', $productId);
    return $p['name'] . ' ' . $action . '.';
}

function moderate_review(int $adminId, int $reviewId, string $op, string $reason, ?int $reportId = null): string
{
    $r = db_one('SELECT r.*, v.stall_name FROM reviews r JOIN vendors v ON v.id = r.vendor_id WHERE r.id = ?', [$reviewId]);
    if (!$r) {
        throw new ValidationException([], 'Review not found.');
    }
    if ($op === 'remove') {
        if (trim($reason) === '') {
            throw new ValidationException(['reason' => 'Give a reason for removing the review.'], 'Give a reason for removing the review.');
        }
        db_query("UPDATE reviews SET status = 'removed', moderated_at = NOW(), moderated_by = ? WHERE id = ?", [$adminId, $reviewId]);
        moderation_log($adminId, 'review', $reviewId, 'removed', $reason, $reportId);
        close_reports_for('review', $reviewId, $adminId, 'review removed');
        notify((int) $r['customer_id'], 'review_removed', 'Your review of ' . $r['stall_name'] . ' was removed',
            'It broke the MarketLink community guidelines. Reason: ' . $reason, 'my-orders.php', 'review', $reviewId);
        return 'Review removed.';
    }
    if ($op === 'restore') {
        db_query("UPDATE reviews SET status = 'visible', moderated_at = NOW(), moderated_by = ? WHERE id = ?", [$adminId, $reviewId]);
        moderation_log($adminId, 'review', $reviewId, 'restored', $reason, $reportId);
        return 'Review restored.';
    }
    throw new ValidationException([], 'Unknown action.');
}

function moderate_vendor(int $adminId, int $vendorId, string $op, string $reason, ?int $reportId = null): string
{
    $v = db_one('SELECT v.id, v.user_id, v.stall_name FROM vendors v WHERE v.id = ?', [$vendorId]);
    if (!$v) {
        throw new ValidationException([], 'Vendor not found.');
    }
    if ($op === 'suspend') {
        if (trim($reason) === '') {
            throw new ValidationException(['reason' => 'Give a reason for the suspension.'], 'Give a reason for the suspension.');
        }
        db_transaction(function () use ($v) {
            db_query("UPDATE users SET approval_status = 'suspended' WHERE id = ?", [$v['user_id']]);
            db_query('UPDATE vendors SET is_active = 0 WHERE id = ?', [$v['id']]);
        });
        moderation_log($adminId, 'vendor', $vendorId, 'suspended', $reason, $reportId);
        close_reports_for('vendor', $vendorId, $adminId, 'vendor suspended');
        notify((int) $v['user_id'], 'account_suspended', 'Your stall has been suspended', 'Reason: ' . $reason . ' Contact MarketLink support to appeal.', 'auth/pending.php', 'vendor', $vendorId);
        return $v['stall_name'] . ' suspended.';
    }
    if ($op === 'restore') {
        db_transaction(function () use ($v) {
            db_query("UPDATE users SET approval_status = 'approved' WHERE id = ?", [$v['user_id']]);
            db_query('UPDATE vendors SET is_active = 1 WHERE id = ?', [$v['id']]);
        });
        moderation_log($adminId, 'vendor', $vendorId, 'restored', $reason, $reportId);
        notify((int) $v['user_id'], 'account_approved', 'Your stall is active again', 'An admin restored ' . $v['stall_name'] . '. It is visible to customers.', 'vendor/dashboard.php', 'vendor', $vendorId);
        return $v['stall_name'] . ' restored.';
    }
    throw new ValidationException([], 'Unknown action.');
}

function moderate_message(int $adminId, int $messageId, string $op, string $reason, ?int $reportId = null): string
{
    if (!db_value('SELECT 1 FROM messages WHERE id = ?', [$messageId])) {
        throw new ValidationException([], 'Message not found.');
    }
    $removed = $op === 'remove' ? 1 : ($op === 'restore' ? 0 : null);
    if ($removed === null) {
        throw new ValidationException([], 'Unknown action.');
    }
    db_query('UPDATE messages SET is_removed = ? WHERE id = ?', [$removed, $messageId]);
    moderation_log($adminId, 'message', $messageId, $removed ? 'removed' : 'restored', $reason, $reportId);
    if ($removed) {
        close_reports_for('message', $messageId, $adminId, 'message removed');
    }
    return $removed ? 'Message hidden.' : 'Message restored.';
}

function resolve_report(int $adminId, int $reportId, string $op, string $note): string
{
    $r = db_one('SELECT * FROM reports WHERE id = ?', [$reportId]);
    if (!$r) {
        throw new ValidationException([], 'Report not found.');
    }
    $status = $op === 'dismiss' ? 'dismissed' : 'resolved';
    db_query('UPDATE reports SET status = ?, action_taken = ?, resolved_by = ?, resolved_at = NOW() WHERE id = ?',
        [$status, $op === 'dismiss' ? 'dismissed' : ($note !== '' ? mb_substr($note, 0, 60) : 'reviewed'), $adminId, $reportId]);
    moderation_log($adminId, 'report', $reportId, $status, $note);
    return $status === 'dismissed' ? 'Report dismissed.' : 'Report marked resolved.';
}
