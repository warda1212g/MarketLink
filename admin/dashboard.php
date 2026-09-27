<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = require_admin_page();

$activeNav    = 'dashboard';
$pageTitle    = 'Admin Dashboard';
$pageSubtitle = 'Overview of your MarketLink platform';
$pageScripts  = [
    'https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js',
    '../assets/js/admin-dashboard.js',
];

$kpiCards = [
    'markets'     => ['Active Markets',  'icon-stall'],
    'marketTypes' => ['Market Types',    'icon-layers'],
    'farmers'     => ['Total Farmers',   'icon-farmer'],
    'vendors'     => ['Market Vendors',  'icon-link'],
    'products'    => ['Total Products',  'icon-basket'],
    'customers'   => ['Total Customers', 'icon-people'],
    'orders'      => ['Total Orders',    'icon-receipt'],
    'revenue'     => ['Total Revenue',   'icon-coins'],
];

include __DIR__ . '/includes/header.php';
?>

<section class="adm-page-head">
  <div>
    <p class="kicker">ADMIN OVERVIEW</p>
    <h1 class="adm-h1">Admin <em>Dashboard</em></h1>
    <p class="adm-lede">Overview of your MarketLink platform, markets, farmers, customers and orders.</p>
  </div>
  <label class="adm-select">
    <span class="visually-hidden">Dashboard period</span>
    <svg viewBox="0 0 24 24"><use href="#icon-clock"/></svg>
    <select id="periodFilter">
      <option value="today">Today</option>
      <option value="week">This Week</option>
      <option value="month" selected>This Month</option>
      <option value="year">This Year</option>
      <option value="all">All Time</option>
    </select>
    <svg viewBox="0 0 24 24" class="adm-chev"><use href="#icon-chevron"/></svg>
  </label>
</section>

<div class="adm-error" id="dashError" hidden></div>

<!-- KPI cards -->
<section class="adm-kpi-grid kpi-8" aria-label="Key figures">
  <?php foreach ($kpiCards as $key => [$label, $icon]): ?>
    <article class="adm-card adm-kpi is-loading" data-kpi="<?= e($key) ?>">
      <div class="adm-kpi-top">
        <span class="adm-kpi-icon"><svg viewBox="0 0 24 24"><use href="#<?= e($icon) ?>"/></svg></span>
        <span class="adm-kpi-label"><?= e($label) ?></span>
      </div>
      <div class="adm-kpi-value" data-role="value"><span class="adm-skel" style="width:60%"></span></div>
      <div class="adm-kpi-foot">
        <span class="adm-change" data-role="change"></span>
        <span class="adm-kpi-note" data-role="note"><span class="adm-skel" style="width:80px"></span></span>
      </div>
    </article>
  <?php endforeach; ?>
</section>

<div class="row g-4">
  <!-- Trend -->
  <div class="col-xl-8">
    <section class="adm-card adm-panel h-100">
      <header class="adm-panel-head">
        <div>
          <h2 class="adm-h2">Order &amp; Sales Trend</h2>
          <p class="adm-panel-sub" id="trendSub">Orders placed over time</p>
        </div>
        <div class="adm-panel-tools">
          <div class="adm-seg" role="group" aria-label="Metric" id="trendMetric">
            <button type="button" data-value="orders" class="active">Orders</button>
            <button type="button" data-value="revenue">Revenue</button>
          </div>
          <div class="adm-seg" role="group" aria-label="Timeframe" id="trendGranularity">
            <button type="button" data-value="weekly">Weekly</button>
            <button type="button" data-value="monthly" class="active">Monthly</button>
            <button type="button" data-value="yearly">Yearly</button>
          </div>
        </div>
      </header>
      <div class="adm-chart-box adm-chart-lg" data-chart="trend">
        <canvas id="trendChart" aria-label="Order and sales trend chart" role="img"></canvas>
        <div class="adm-empty" hidden><svg viewBox="0 0 24 24"><use href="#icon-chart"/></svg><p>No data available yet</p><small>Orders will appear here once customers start reserving.</small></div>
        <div class="adm-chart-loading"><span class="adm-spinner"></span></div>
      </div>
    </section>
  </div>

  <!-- User distribution -->
  <div class="col-xl-4 col-md-6">
    <section class="adm-card adm-panel h-100">
      <header class="adm-panel-head">
        <div>
          <h2 class="adm-h2">User Distribution</h2>
          <p class="adm-panel-sub">Customers and farmers, all time</p>
        </div>
      </header>
      <div class="adm-chart-box adm-chart-donut" data-chart="users">
        <canvas id="userChart" aria-label="User distribution chart" role="img"></canvas>
        <div class="adm-donut-center" id="userTotal"></div>
        <div class="adm-empty" hidden><svg viewBox="0 0 24 24"><use href="#icon-people"/></svg><p>No users registered yet</p></div>
        <div class="adm-chart-loading"><span class="adm-spinner"></span></div>
      </div>
      <ul class="adm-legend" id="userLegend"></ul>
    </section>
  </div>

  <!-- Orders by status -->
  <div class="col-xl-6 col-md-6">
    <section class="adm-card adm-panel h-100">
      <header class="adm-panel-head">
        <div>
          <h2 class="adm-h2">Orders by Status</h2>
          <p class="adm-panel-sub" data-period-label>For the selected period</p>
        </div>
      </header>
      <div class="adm-chart-box adm-chart-md" data-chart="status">
        <canvas id="statusChart" aria-label="Orders by status chart" role="img"></canvas>
        <div class="adm-empty" hidden><svg viewBox="0 0 24 24"><use href="#icon-receipt"/></svg><p>No orders yet</p></div>
        <div class="adm-chart-loading"><span class="adm-spinner"></span></div>
      </div>
    </section>
  </div>

  <!-- Products by category -->
  <div class="col-xl-6">
    <section class="adm-card adm-panel h-100">
      <header class="adm-panel-head">
        <div>
          <h2 class="adm-h2">Products by Category</h2>
          <p class="adm-panel-sub">Active listings across all farmers</p>
        </div>
      </header>
      <div id="categoryBars" class="adm-bars">
        <?php for ($i = 0; $i < 6; $i++): ?><div class="adm-skel adm-skel-row"></div><?php endfor; ?>
      </div>
    </section>
  </div>

  <!-- Market structure -->
  <div class="col-xl-4 col-md-6">
    <section class="adm-card adm-panel h-100">
      <header class="adm-panel-head">
        <div><h2 class="adm-h2">Markets by Type</h2><p class="adm-panel-sub">All markets, any status</p></div>
        <a href="market-types.php" class="adm-btn-outline">Types <svg viewBox="0 0 24 24"><use href="#icon-arrow"/></svg></a>
      </header>
      <div id="marketTypeBars" class="adm-bars"><?php for ($i = 0; $i < 4; $i++): ?><div class="adm-skel adm-skel-row"></div><?php endfor; ?></div>
    </section>
  </div>
  <div class="col-xl-4 col-md-6">
    <section class="adm-card adm-panel h-100">
      <header class="adm-panel-head">
        <div><h2 class="adm-h2">Farmers by Category</h2><p class="adm-panel-sub">A farmer can belong to several</p></div>
      </header>
      <div id="farmerCategoryBars" class="adm-bars"><?php for ($i = 0; $i < 4; $i++): ?><div class="adm-skel adm-skel-row"></div><?php endfor; ?></div>
    </section>
  </div>
  <div class="col-xl-4">
    <section class="adm-card adm-panel h-100">
      <header class="adm-panel-head">
        <div><h2 class="adm-h2">Revenue by Market</h2><p class="adm-panel-sub">Completed orders, <span data-period-name>this month</span></p></div>
      </header>
      <div class="adm-chart-box adm-chart-md" data-chart="marketRevenue">
        <canvas id="marketRevenueChart" aria-label="Revenue by market chart" role="img"></canvas>
        <div class="adm-empty" hidden><svg viewBox="0 0 24 24"><use href="#icon-stall"/></svg><p>No market sales yet</p><small>Revenue appears once products listed in markets are sold.</small></div>
        <div class="adm-chart-loading"><span class="adm-spinner"></span></div>
      </div>
    </section>
  </div>

  <div class="col-12">
    <section class="adm-card adm-panel">
      <header class="adm-panel-head">
        <div><h2 class="adm-h2">Top Performing Markets</h2><p class="adm-panel-sub">Orders and completed revenue, <span data-period-name>this month</span></p></div>
        <a href="markets.php" class="adm-btn-outline">Manage Markets <svg viewBox="0 0 24 24"><use href="#icon-arrow"/></svg></a>
      </header>
      <div class="table-responsive">
        <table class="table adm-table">
          <thead><tr><th>Market</th><th>Type</th><th class="text-end">Vendors</th><th class="text-end">Orders</th><th class="text-end">Revenue</th><th>Status</th></tr></thead>
          <tbody id="topMarketsBody" data-cols="6"></tbody>
        </table>
      </div>
    </section>
  </div>

  <!-- Top farmers -->
  <div class="col-xl-8">
    <section class="adm-card adm-panel h-100">
      <header class="adm-panel-head">
        <div>
          <h2 class="adm-h2">Top Performing Vendors</h2>
          <p class="adm-panel-sub">Ranked by completed revenue, then orders, <span data-period-name>this month</span></p>
        </div>
        <a href="farmers.php" class="adm-btn-outline">View All Farmers <svg viewBox="0 0 24 24"><use href="#icon-arrow"/></svg></a>
      </header>
      <div class="table-responsive">
        <table class="table adm-table">
          <thead><tr><th>Farmer</th><th>Market / Stall</th><th class="text-end">Orders</th><th class="text-end">Revenue</th><th>Status</th></tr></thead>
          <tbody id="topFarmersBody" data-cols="5"></tbody>
        </table>
      </div>
    </section>
  </div>

  <!-- Quick actions -->
  <div class="col-xl-4">
    <section class="adm-card adm-panel h-100 adm-panel-forest">
      <header class="adm-panel-head">
        <div>
          <h2 class="adm-h2">Quick Actions</h2>
          <p class="adm-panel-sub">Jump straight to common tasks</p>
        </div>
      </header>
      <nav class="adm-quick">
        <a href="markets.php?action=add"><svg viewBox="0 0 24 24"><use href="#icon-plus"/></svg> Add Market</a>
        <a href="farmers.php"><svg viewBox="0 0 24 24"><use href="#icon-farmer"/></svg> Manage Users</a>
        <a href="reports.php"><svg viewBox="0 0 24 24"><use href="#icon-chart"/></svg> View Reports</a>
        <a href="market-types.php"><svg viewBox="0 0 24 24"><use href="#icon-tag"/></svg> Manage Categories</a>
        <a href="settings.php"><svg viewBox="0 0 24 24"><use href="#icon-settings"/></svg> System Settings</a>
      </nav>
    </section>
  </div>

  <!-- Recent orders -->
  <div class="col-xl-8">
    <section class="adm-card adm-panel h-100">
      <header class="adm-panel-head">
        <div>
          <h2 class="adm-h2">Recent Orders</h2>
          <p class="adm-panel-sub">Latest reservations across all markets</p>
        </div>
        <a href="reports.php#orders" class="adm-btn-outline">View All Orders <svg viewBox="0 0 24 24"><use href="#icon-arrow"/></svg></a>
      </header>
      <div class="table-responsive">
        <table class="table adm-table">
          <thead><tr><th>Order ID</th><th>Customer</th><th>Farmer</th><th class="text-end">Total</th><th>Pickup</th><th>Status</th><th>Date</th></tr></thead>
          <tbody id="recentOrdersBody" data-cols="7"></tbody>
        </table>
      </div>
    </section>
  </div>

  <!-- Recent activity -->
  <div class="col-xl-4">
    <section class="adm-card adm-panel h-100">
      <header class="adm-panel-head">
        <div>
          <h2 class="adm-h2">Recent Activity</h2>
          <p class="adm-panel-sub">What's been happening on MarketLink</p>
        </div>
      </header>
      <ul class="adm-activity" id="activityList">
        <?php for ($i = 0; $i < 5; $i++): ?><li><span class="adm-skel adm-skel-row"></span></li><?php endfor; ?>
      </ul>
    </section>
  </div>

  <!-- Farmer registrations -->
  <div class="col-12">
    <section class="adm-card adm-panel">
      <header class="adm-panel-head">
        <div>
          <h2 class="adm-h2">Recent Sign-ups</h2>
          <p class="adm-panel-sub">Review and approve new vendors</p>
        </div>
        <a href="farmers.php?status=pending" class="adm-btn-outline">Pending Approvals <svg viewBox="0 0 24 24"><use href="#icon-arrow"/></svg></a>
      </header>
      <div class="table-responsive">
        <table class="table adm-table">
          <thead><tr><th>Name</th><th>Stall / Account</th><th>Email</th><th>Registered</th><th>Approval</th><th class="text-end">Action</th></tr></thead>
          <tbody id="recentFarmersBody" data-cols="6"></tbody>
        </table>
      </div>
    </section>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
