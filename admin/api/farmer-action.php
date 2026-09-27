<?php
/**
 * POST admin/api/farmer-action.php   body: farmer_id, action (approve|suspend|reject)
 * Header X-CSRF-Token must match the session token. Admin-only.
 * farmer_id = users.id of a customer or vendor. Sets users.approval_status.
 * The change is written to activity_log by the trg_users_au trigger.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_admin_api();

require_method('POST');

$ACTIONS = ['approve' => 'approved', 'suspend' => 'suspended', 'reject' => 'rejected'];
$action = (string) ($_POST['action'] ?? '');
$userId = filter_input(INPUT_POST, 'farmer_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if (!$userId || !isset($ACTIONS[$action])) {
    json_error('Invalid account or action', 422);
}

$target = db_one("SELECT id, role FROM users WHERE id = ? AND role IN ('vendor','customer')", [$userId]);
if (!$target) {
    json_error('Account not found', 404);
}

db_transaction(function () use ($ACTIONS, $action, $target) {
    db_query('UPDATE users SET approval_status = ? WHERE id = ?', [$ACTIONS[$action], $target['id']]);
    if ($target['role'] === 'vendor') {
        // keep the stall visible only while the vendor is approved
        db_query('UPDATE vendors SET is_active = ? WHERE user_id = ?', [$action === 'approve' ? 1 : 0, $target['id']]);
    }
});

require_once APP_ROOT . '/includes/services/notifications.php';
$copy = [
    'approve' => ['account_approved', $target['role'] === 'vendor' ? 'Your stall has been approved' : 'Your account is active',
                  $target['role'] === 'vendor' ? 'Welcome to MarketLink! Your stall is now visible to customers.' : 'You can place pre-orders again.'],
    'suspend' => ['account_suspended', 'Your account has been suspended', 'Contact MarketLink support if you think this is a mistake.'],
    'reject'  => ['account_rejected', 'Your application was not approved', 'Contact MarketLink support if you would like it reconsidered.'],
][$action];
notify((int) $target['id'], $copy[0], $copy[1], $copy[2], $target['role'] === 'vendor' ? 'vendor/dashboard.php' : 'customer/dashboard.php', 'user', (int) $target['id']);
db_query("UPDATE notifications SET is_read = 1 WHERE related_type = 'user' AND related_id = ? AND type = 'vendor_registered'", [$target['id']]);

echo json_encode(['ok' => true, 'farmer_id' => (int) $target['id'], 'approval_status' => $ACTIONS[$action]]);
