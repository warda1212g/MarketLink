<?php
/**
 * Sessions, CSRF protection and role-based access control.
 */
declare(strict_types=1);

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name('MLSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => base_url() === '' ? '/' : base_url() . '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

/* Csrf */

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_valid(): bool
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? (request_data()['_csrf'] ?? ''));
    return is_string($sent) && $sent !== '' && hash_equals(csrf_token(), $sent);
}

/** For HTML form handlers. */
function verify_csrf_or_fail(): void
{
    if (!csrf_valid()) {
        http_response_code(419);
        flash('error', 'Your session expired. Please try again.');
        redirect($_SERVER['HTTP_REFERER'] ?? '/');
    }
}

/* users */

function current_user(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $id = $_SESSION['user_id'] ?? null;
    if (!$id) {
        return $cache = null;
    }
    $user = db_one('SELECT id, name, email, role, phone, is_active, approval_status FROM users WHERE id = ?', [$id]);
    if (!$user || !$user['is_active']) {
        logout_user();
        return $cache = null;
    }
    return $cache = $user;
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['role'] = $user['role'];
    unset($_SESSION['_csrf']);
    db_query('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$user['id']]);
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function attempt_login(string $email, string $password): ?array
{
    $user = db_one('SELECT * FROM users WHERE email = ?', [mb_strtolower($email)]);
    if (!$user || !$user['is_active'] || !password_verify($password, $user['password_hash'])) {
        return null;
    }
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        db_query('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }
    return $user;
}

/** Throttle login attempts per session to slow brute force. */
function login_throttled(): bool
{
    $a = $_SESSION['_login_attempts'] ?? ['n' => 0, 't' => time()];
    if (time() - $a['t'] > 900) {
        $a = ['n' => 0, 't' => time()];
    }
    $_SESSION['_login_attempts'] = $a;
    return $a['n'] >= 8;
}

function record_login_failure(): void
{
    $a = $_SESSION['_login_attempts'] ?? ['n' => 0, 't' => time()];
    $a['n']++;
    $_SESSION['_login_attempts'] = $a;
}

/* guards */

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        $_SESSION['_intended'] = $_SERVER['REQUEST_URI'] ?? null;
        flash('info', 'Please sign in to continue.');
        redirect('auth/login.php');
    }
    return $user;
}

/** Vendor row for the logged-in vendor (creates an empty profile if missing). */
function current_vendor(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $user = current_user();
    if (!$user || $user['role'] !== 'vendor') {
        return $cache = null;
    }
    $vendor = db_one('SELECT * FROM vendors WHERE user_id = ?', [$user['id']]);
    if (!$vendor) {
        db_query('INSERT INTO vendors (user_id, stall_name, contact_person, email, phone) VALUES (?, ?, ?, ?, ?)', [
            $user['id'], $user['name'] . "'s Stall", $user['name'], $user['email'], $user['phone'],
        ]);
        $vendor = db_one('SELECT * FROM vendors WHERE user_id = ?', [$user['id']]);
    }
    return $cache = $vendor;
}

/* admin approval */

/**
 * Admins are always approved. Customers are approved on sign-up,
 * vendors need an admin to approve them.
 */
function is_approved(?array $user): bool
{
    if ($user === null) {
        return false;
    }
    $status = $user['approval_status'] ?? 'pending';
    if ($user['role'] === 'admin') {
        return true;
    }
    if ($user['role'] === 'customer') {
        return !in_array($status, ['suspended', 'rejected'], true);
    }
    return $status === 'approved';
}

/** approval_status a brand-new account starts with. */
function initial_approval_status(string $role): string
{
    if ($role === 'customer') {
        return 'approved';
    }
    // Admin -> Settings "Approve new farmers automatically"
    return $role === 'vendor' && app_setting_on('vendors.auto_approve') ? 'approved' : 'pending';
}

/** Page guard: signed in AND approved, otherwise the "waiting for approval" page. */
function require_approved(): array
{
    $user = require_login();
    if (!is_approved($user)) {
        redirect('auth/pending.php');
    }
    return $user;
}

/** Where a user lands after signing in. */
function home_for(array $user): string
{
    if ($user['role'] === 'admin') {
        return 'admin/dashboard.php';
    }
    if (!is_approved($user)) {
        return 'auth/pending.php';
    }
    // customers and vendors go to the home page
    return 'index.php';
}

/** Page guard: only approved vendors may continue. */
function require_vendor(): array
{
    $user = require_login();
    if ($user['role'] !== 'vendor') {
        http_response_code(403);
        flash('error', 'That area is only available to vendor accounts.');
        redirect('index.php');
    }
    if (!is_approved($user)) {
        redirect('auth/pending.php');
    }
    return current_vendor();
}

/** API guard: vendor session + CSRF on state-changing requests. */
function require_vendor_api(bool $mutating = false): array
{
    $user = current_user();
    if (!$user) {
        json_error('Your session has expired. Please sign in again.', 401);
    }
    if ($user['role'] !== 'vendor') {
        json_error('Forbidden.', 403);
    }
    if (!is_approved($user)) {
        json_error('Your account is waiting for admin approval.', 403);
    }
    if ($mutating || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        if (!csrf_valid()) {
            json_error('Security token mismatch. Refresh the page and try again.', 419);
        }
    }
    return current_vendor();
}

/**
 * API check for any logged in user. Needs CSRF on POST. $roles limits who can call it.
 */
function require_user_api(array $roles = ['customer', 'vendor', 'admin']): array
{
    $user = current_user();
    if (!$user) {
        json_error('Your session has expired. Please sign in again.', 401);
    }
    if (!in_array($user['role'], $roles, true)) {
        json_error('Forbidden.', 403);
    }
    if (!is_approved($user)) {
        json_error($user['role'] === 'vendor' ? 'Your account is waiting for admin approval.' : 'Your account is on hold. Contact MarketLink support.', 403);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' && !csrf_valid()) {
        json_error('Security token mismatch. Refresh the page and try again.', 419);
    }
    return $user;
}

/** Page guard for customer-only pages. */
function require_customer(): array
{
    $user = require_login();
    if ($user['role'] !== 'customer') {
        redirect(home_for($user));
    }
    if (!is_approved($user)) {
        redirect('auth/pending.php');
    }
    return $user;
}

function require_method(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
        header('Allow: ' . $method);
        json_error('Method not allowed.', 405);
    }
}
