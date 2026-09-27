<?php
/** Old page, redirects to the right notifications page */
require_once __DIR__ . '/includes/bootstrap.php';
require_once APP_ROOT . '/includes/partials/navbar.php';

$user = require_login();
redirect(notifications_page_path($user));
