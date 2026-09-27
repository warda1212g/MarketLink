<?php
/**
 * Admin page checks and activity log.
 */
declare(strict_types=1);

/** Page guard: only admins may continue. */
function require_admin_page(): array
{
    $user = current_user();
    if (!$user) {
        $_SESSION['_intended'] = $_SERVER['REQUEST_URI'] ?? null;
        redirect('admin/login.php');
    }
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        exit('Access denied. This area is restricted to MarketLink administrators.');
    }
    return $user;
}

/** API guard: admin session, JSON errors, CSRF on anything that is not GET. */
function require_admin_api(): array
{
    header('Cache-Control: no-store');
    $user = current_user();
    if (!$user) {
        json_error('Not authenticated', 401);
    }
    if ($user['role'] !== 'admin') {
        json_error('Forbidden', 403);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' && !csrf_valid()) {
        json_error('Invalid or expired session token', 419);
    }
    header('Content-Type: application/json; charset=utf-8');
    return $user;
}

function log_activity(string $type, string $message, ?string $entityType = null, ?int $entityId = null, ?int $actorUserId = null): void
{
    db_query(
        'INSERT INTO activity_log (type, message, entity_type, entity_id, actor_user_id) VALUES (?, ?, ?, ?, ?)',
        [$type, mb_substr($message, 0, 255), $entityType, $entityId, $actorUserId]
    );
}
