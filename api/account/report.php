<?php
/**
 * POST {content_type, content_id, reason, details} -> report content to the admins.
 * Customers: product | review | vendor. Vendors: message | review (on their own stall).
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/moderation.php';

api_run(function () {
    require_method('POST');
    $user = require_user_api(['customer', 'vendor']);
    $in = request_data();
    create_report($user, str_input($in, 'content_type', 20), int_input($in, 'content_id', 0), str_input($in, 'reason', 40), str_input($in, 'details', 1000));
    json_ok([], 'Thanks - our moderators will review this report.');
});
