<?php
/** GET -> live homepage numbers (stats, product stock/prices, category counts) polled by assets/js/home.js. */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/home.php';

api_run(function () {
    header('Cache-Control: no-store');
    json_ok(home_live());
});
