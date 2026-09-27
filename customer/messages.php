<?php
/** Customer messages inside the dashboard (same inbox as the website's messages.php). */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once APP_ROOT . '/includes/partials/customer_layout.php';
require_once APP_ROOT . '/includes/partials/chat_view.php';

$user = require_customer_dashboard();
$initial = customer_chat_initial($user, 'customer/messages.php');

customer_layout_start([
    'title' => 'Messages', 'active' => 'messages',
    'heading' => 'Messages', 'subheading' => 'Ask your farmers about orders, pickup times or products.',
]);
customer_chat_view($initial);
customer_layout_end(['scripts' => ['assets/js/messages.js']]);
