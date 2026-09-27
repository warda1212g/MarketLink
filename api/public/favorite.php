<?php
/** POST {type: vendor|product, id} -> toggles a customer's favourite. */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/favorites.php';

api_run(function () {
    require_method('POST');
    $user = current_user();
    if (!$user || $user['role'] !== 'customer') {
        json_error('Please sign in with a customer account to save favourites.', 401);
    }
    if (!is_approved($user)) {
        json_error('Your account is waiting for admin approval.', 403);
    }
    if (!csrf_valid()) {
        json_error('Security token mismatch. Refresh the page and try again.', 419);
    }
    $in = request_data();
    $type = (string) ($in['type'] ?? '');
    $id = (int) ($in['id'] ?? 0);
    if (!in_array($type, ['vendor', 'product'], true) || $id <= 0) {
        json_error('Unknown item.', 422);
    }
    $exists = $type === 'vendor'
        ? db_value('SELECT 1 FROM vendors WHERE id = ? AND is_active = 1', [$id])
        : db_value('SELECT 1 FROM products WHERE id = ? AND deleted_at IS NULL', [$id]);
    if (!$exists) {
        json_error('That item is no longer available.', 404);
    }
    $saved = favorite_toggle((int) $user['id'], $type, $id);
    json_ok(['saved' => $saved], $saved ? 'Saved to your favourites.' : 'Removed from favourites.');
});
