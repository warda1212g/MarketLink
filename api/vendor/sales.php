<?php
/** GET: revenue/orders time series + totals for the dashboard Sales Overview. */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/analytics.php';

api_run(function () {
    require_method('GET');
    $vendor = require_vendor_api();
    $r = range_from_request($_GET);
    $kpis = analytics_kpis((int) $vendor['id'], $r);

    json_ok([
        'range'  => ['key' => $r['key'], 'label' => $r['label'], 'granularity' => $r['granularity']],
        'series' => sales_series((int) $vendor['id'], $r),
        'totals' => ['revenue' => $kpis['revenue'], 'orders' => $kpis['orders'], 'aov' => $kpis['aov']],
    ]);
});
