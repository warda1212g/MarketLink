<?php
require_once __DIR__ . '/../includes/bootstrap.php';

// Logout is POST + CSRF so a third-party page can't sign users out.
$wasAdmin = ($u = current_user()) && $u['role'] === 'admin';
if (is_post() && csrf_valid()) {
    logout_user();
    start_secure_session();
    flash('success', 'You have been signed out.');
}
redirect($wasAdmin ? 'admin/login.php' : 'index.php');
