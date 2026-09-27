<?php
/** Old page, redirects to customer/orders.php */
require_once __DIR__ . '/includes/bootstrap.php';

$qs = array_intersect_key($_GET, array_flip(['status', 'page', 'placed', 'q']));
redirect('customer/orders.php' . ($qs ? '?' . http_build_query($qs) : ''));
