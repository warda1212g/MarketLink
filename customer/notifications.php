<?php
/** Customer notification centre (driven by assets/js/notifications.js). */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once APP_ROOT . '/includes/partials/customer_layout.php';
require_once APP_ROOT . '/includes/partials/notification_center.php';

require_customer_dashboard();

customer_layout_start([
    'title' => 'Notifications', 'active' => 'notifications',
    'heading' => 'Notifications', 'subheading' => 'Order updates, pickup reminders, messages and review activity.',
]);
notification_center();
customer_layout_end();
