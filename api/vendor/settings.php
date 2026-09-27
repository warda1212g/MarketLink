<?php
/**
 * POST {action:"password", current_password, new_password, confirm_password}
 * POST {action:"inventory", low_stock_threshold}
 */
require_once __DIR__ . '/../../includes/bootstrap.php';

api_run(function () {
    require_method('POST');
    $vendor = require_vendor_api();
    $user = current_user();
    $in = request_data();

    switch ($in['action'] ?? '') {
        case 'password':
            $current = (string) ($in['current_password'] ?? '');
            $new = (string) ($in['new_password'] ?? '');
            $confirm = (string) ($in['confirm_password'] ?? '');
            $hash = db_value('SELECT password_hash FROM users WHERE id = ?', [$user['id']]);
            $e = [];
            if (!password_verify($current, (string) $hash)) $e['current_password'] = 'Your current password is incorrect.';
            if (strlen($new) < 8) $e['new_password'] = 'Use at least 8 characters.';
            if ($new !== $confirm) $e['confirm_password'] = 'Passwords do not match.';
            if ($e) throw new ValidationException($e);
            db_query('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            session_regenerate_id(true);
            json_ok([], 'Password updated.');

        case 'inventory':
            $t = float_input($in, 'low_stock_threshold');
            if ($t === null || $t < 0 || $t > 100000) {
                throw new ValidationException(['low_stock_threshold' => 'Enter 0 or more.']);
            }
            db_query('UPDATE vendors SET low_stock_threshold = ? WHERE id = ?', [$t, $vendor['id']]);
            json_ok([], 'Inventory settings saved.');

        default:
            json_error('Unknown action.');
    }
});
