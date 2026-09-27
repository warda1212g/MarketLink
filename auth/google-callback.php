<?php
/**
 * Google login callback.
 * Signs in an existing account, or creates a new customer/vendor account.
 * New vendors wait for admin approval. Admins can't log in with Google.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/google.php';
require_once APP_ROOT . '/includes/services/notifications.php';

$flow = $_SESSION['google_oauth'] ?? null;
unset($_SESSION['google_oauth']);
$from = $flow['from'] ?? 'login';
$debug = (bool) config('app.debug');

if (isset($_GET['error'])) {
    google_fail($from, 'Google sign-in was cancelled.');
}
$code = (string) ($_GET['code'] ?? '');
if (!$flow || $code === '' || !hash_equals((string) $flow['state'], (string) ($_GET['state'] ?? '')) || time() - (int) $flow['time'] > 600) {
    google_fail($from, 'Your sign-in session expired. Please try again.');
}

[$tokens, $err] = google_exchange_code($code, (string) $flow['verifier']);
if (!$tokens) {
    error_log('[MarketLink Google] ' . $err);
    google_fail($from, $debug ? $err : 'Google sign-in failed. Please try again.');
}
[$profile, $err] = google_userinfo((string) $tokens['access_token']);
if (!$profile) {
    error_log('[MarketLink Google] ' . $err);
    google_fail($from, $debug ? $err : 'Google sign-in failed. Please try again.');
}

$email = mb_strtolower(trim((string) $profile['email']));
$verified = ($profile['email_verified'] ?? false) === true || ($profile['email_verified'] ?? '') === 'true';
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !$verified) {
    google_fail($from, 'Your Google email is not verified.');
}
$googleId = mb_substr((string) $profile['sub'], 0, 64);
$name = mb_substr(trim((string) ($profile['name'] ?? '')) ?: ucfirst((string) strstr($email, '@', true)), 0, 120);
$role = $flow['role'] === 'vendor' ? 'vendor' : 'customer';

try {
    $user = db_one('SELECT * FROM users WHERE google_id = ? OR email = ? ORDER BY google_id = ? DESC LIMIT 1', [$googleId, $email, $googleId]);

    if ($user && $user['role'] === 'admin') {
        google_fail($from, 'Admin accounts cannot use Google sign-in. Use the admin login page.');
    }

    if ($user) {
        if (empty($user['google_id'])) {
            db_query('UPDATE users SET google_id = ? WHERE id = ?', [$googleId, $user['id']]);
        }
        if ($user['role'] !== $role) {
            flash('info', 'This Google account is registered as a ' . $user['role'] . ', so you were signed in as a ' . $user['role'] . '.');
        }
    } else {
        // Admin -> Settings can pause new sign-ups per role.
        if (!app_setting_on($role === 'vendor' ? 'registration.vendors' : 'registration.customers')) {
            google_fail($from, $role === 'vendor' ? 'New farmer sign-ups are paused right now. Please try again later.' : 'New customer sign-ups are paused right now. Please try again later.');
        }
        // new account gets a random password, they log in with Google
        $userId = db_transaction(function (PDO $pdo) use ($name, $email, $role, $googleId) {
            $pdo->prepare('INSERT INTO users (name, email, password_hash, role, google_id, approval_status) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$name, $email, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), $role, $googleId, initial_approval_status($role)]);
            $id = (int) $pdo->lastInsertId();
            if ($role === 'vendor') {
                $pdo->prepare('INSERT INTO vendors (user_id, stall_name, contact_person, email) VALUES (?, ?, ?, ?)')
                    ->execute([$id, $name . "'s Stall", $name, $email]);
            }
            return $id;
        });
        $user = db_one('SELECT * FROM users WHERE id = ?', [$userId]);
        notify_new_account($userId, $role, $name, $role === 'vendor' ? $name . "'s Stall" : '');
    }
} catch (Throwable $ex) {
    error_log('[MarketLink Google] ' . $ex->getMessage());
    google_fail($from, $debug ? 'Database error: ' . $ex->getMessage() : 'Could not complete sign-in. Please try again.');
}

if (!$user['is_active']) {
    google_fail($from, 'This account has been deactivated.');
}

login_user($user);
unset($_SESSION['_intended']);
redirect(home_for($user));
