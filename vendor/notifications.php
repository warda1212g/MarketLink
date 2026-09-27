<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once APP_ROOT . '/includes/partials/vendor_layout.php';
require_once APP_ROOT . '/includes/partials/notification_center.php';

require_vendor();

vendor_layout_start([
    'title' => 'Notifications', 'active' => 'notifications',
    'heading' => 'Notifications', 'subheading' => 'New orders, customer messages, reviews and admin actions.',
]);
notification_center();
vendor_layout_end();
