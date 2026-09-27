<?php
/** "Continue with Google": remembers Customer / Vendor choice, then goes to Google. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/google.php';

$from = ($_GET['from'] ?? '') === 'register' ? 'register' : 'login';
$role = ($_GET['role'] ?? '') === 'vendor' ? 'vendor' : 'customer';

if ($u = current_user()) {
    redirect(home_for($u));
}
if (!google_configured()) {
    google_fail($from, 'Google sign-in is not set up yet. Add the Google client ID and secret in config/config.php.');
}

header('Location: ' . google_begin($from, $role));
exit;
