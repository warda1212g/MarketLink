<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once APP_ROOT . '/includes/partials/vendor_layout.php';
require_once APP_ROOT . '/includes/partials/components.php';

$vendor = require_vendor();

vendor_layout_start([
    'title' => 'Analytics', 'active' => 'analytics',
    'heading' => 'Analytics', 'subheading' => 'Sales, orders and product performance from your real order history.',
]);

$kpis = [
    ['revenue', 'Total Revenue', 'i-money', true],
    ['orders', 'Total Orders', 'i-cart', false],
    ['aov', 'Average Order Value', 'i-bag', false],
    ['sold', 'Products Sold', 'i-box', false],
];
?>
<div class="page-head">
  <div><h2>Performance</h2><p data-range-label>Loading...</p></div>
  <div id="rangeRoot"><?= range_filter(['today' => 'Today', '7d' => '7 Days', '30d' => '30 Days', '3m' => '3 Months', '6m' => '6 Months', '1y' => '1 Year', 'custom' => 'Custom'], '30d') ?></div>
</div>

<section class="grid grid-4" aria-label="Key figures">
  <?php foreach ($kpis as [$key, $label, $icon, $featured]): ?>
    <article class="card stat-card<?= $featured ? ' featured' : '' ?>" data-kpi="<?= $key ?>">
      <div class="stat-top"><span class="stat-label"><?= e($label) ?></span><span class="stat-icon"><svg><use href="#<?= $icon ?>"/></svg></span></div>
      <div class="stat-value"><span class="skeleton" style="display:inline-block;width:120px;height:30px"></span></div>
      <div class="stat-foot">vs previous period</div>
    </article>
  <?php endforeach; ?>
</section>
<p class="muted" style="font-size:12.5px;margin-top:10px">Revenue counts completed orders (payment is collected at pickup). Order counts include every status.</p>

<section class="grid grid-main mt">
  <article class="card">
    <div class="card-head">
      <div><h3 class="card-title">Revenue</h3><p class="card-sub" id="revSub">By day</p></div>
      <div class="seg" role="group" aria-label="Group revenue by" id="granularity">
        <button type="button" data-g="day" aria-pressed="false">Day</button>
        <button type="button" data-g="week" aria-pressed="false">Week</button>
        <button type="button" data-g="month" aria-pressed="false">Month</button>
      </div>
    </div>
    <div class="chart-box lg"><canvas id="revChart" role="img" aria-label="Revenue over time"></canvas></div>
    <button class="table-toggle mt" type="button" data-table-toggle="revTable">Show data table</button>
    <div class="data-table-wrap" id="revTable" hidden></div>
  </article>

  <article class="card">
    <div class="card-head"><div><h3 class="card-title">Orders by Status</h3><p class="card-sub">Selected period</p></div></div>
    <div class="donut-box" style="height:200px;max-width:220px;margin:0 auto 18px">
      <canvas id="statusChart" role="img" aria-label="Orders by status"></canvas>
      <div class="donut-center"><div><strong data-status-total>0</strong><span>orders</span></div></div>
    </div>
    <div class="status-list" id="statusLegend"></div>
  </article>
</section>

<section class="grid grid-2 mt">
  <article class="card">
    <div class="card-head"><div><h3 class="card-title">Orders over Time</h3><p class="card-sub">All statuses</p></div></div>
    <div class="chart-box"><canvas id="ordersChart" role="img" aria-label="Orders over time"></canvas></div>
  </article>
  <article class="card">
    <div class="card-head">
      <div><h3 class="card-title">Completed vs Cancelled</h3><p class="card-sub">Cancelled includes declined orders</p></div>
      <div class="legend"><span><i style="background:#2E8A5A"></i>Completed</span><span><i style="background:#C9483A"></i>Cancelled / declined</span></div>
    </div>
    <div class="chart-box"><canvas id="ccChart" role="img" aria-label="Completed versus cancelled orders"></canvas></div>
  </article>
</section>

<section class="grid grid-2 mt">
  <article class="card">
    <div class="card-head">
      <div><h3 class="card-title">Best Selling Products</h3><p class="card-sub">Top 10, completed orders</p></div>
      <div class="seg" role="group" aria-label="Product metric" id="productMetric">
        <button type="button" class="active" data-pm="qty" aria-pressed="true">Quantity</button>
        <button type="button" data-pm="revenue" aria-pressed="false">Revenue</button>
      </div>
    </div>
    <div class="chart-box lg"><canvas id="productChart" role="img" aria-label="Best selling products"></canvas></div>
  </article>
  <article class="card">
    <div class="card-head"><div><h3 class="card-title">Category Performance</h3><p class="card-sub">Revenue by category</p></div></div>
    <div class="chart-box lg"><canvas id="catChart" role="img" aria-label="Revenue by product category"></canvas></div>
  </article>
</section>

<section class="grid grid-main mt">
  <article class="card">
    <div class="card-head"><div><h3 class="card-title">Orders by Weekday</h3><p class="card-sub">When customers place pre-orders</p></div></div>
    <div class="chart-box sm"><canvas id="weekdayChart" role="img" aria-label="Orders by day of week"></canvas></div>
  </article>
  <article class="card card-flush">
    <div class="card-head"><div><h3 class="card-title">Product Breakdown</h3><p class="card-sub">Quantity, revenue and orders</p></div></div>
    <div class="table-wrap" id="productTableWrap" style="margin-top:12px;max-height:300px;overflow:auto"></div>
  </article>
</section>

<?php vendor_layout_end([
    'vendor_scripts' => ['https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js'],
    'scripts' => ['assets/js/vendor/analytics.js'],
]);
