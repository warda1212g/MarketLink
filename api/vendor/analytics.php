<?php
/** GET ?range=&from=&to=&granularity= -> full analytics bundle for the vendor. */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/analytics.php';

api_run(function () {
    require_method('GET');
    $vendor = require_vendor_api();
    $vid = (int) $vendor['id'];
    $r = range_from_request($_GET);

    json_ok([
        'range'      => ['key' => $r['key'], 'label' => $r['label'], 'granularity' => $r['granularity'], 'days' => $r['days']],
        'kpis'       => analytics_kpis($vid, $r),
        'series'     => sales_series($vid, $r),
        'statuses'   => status_breakdown($vid, $r),
        'products'   => best_sellers($vid, $r, 10),
        'categories' => category_performance($vid, $r),
        'weekdays'   => orders_by_weekday($vid, $r),
    ]);
});
