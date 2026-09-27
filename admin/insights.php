<?php
/** The AI Insights page has been removed from the admin dashboard; old links go to the dashboard. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_admin_page();
redirect('admin/dashboard.php');
