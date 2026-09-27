<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/markets.php';
$user = require_admin_page();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$market = $id ? (markets_list(['id' => $id])[0] ?? null) : null;
if (!$market) {
    flash('error', 'That market does not exist.');
    redirect('admin/markets.php');
}

$activeNav    = 'markets';
$pageTitle    = $market['name'];
$pageSubtitle = 'Market overview, vendors, products and analytics';
$pageStyles   = ['https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css'];
$pageScripts  = [
    'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js',
    '../assets/js/map-common.js',
    'https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js',
    '../assets/js/admin-market-form.js',
    '../assets/js/admin-market-details.js',
];
$marketTypes = market_types_all();

include __DIR__ . '/includes/header.php';
?>
<nav class="mk-crumbs" aria-label="Breadcrumb"><a href="markets.php">Markets</a><span>/</span><span><?= e($market['type_name']) ?></span><span>/</span><strong><?= e($market['name']) ?></strong></nav>

<section class="adm-card mk-hero" id="mkHero" data-market='<?= e(json_encode($market, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS)) ?>'>
  <div class="mk-hero-media<?= $market['image'] ? '' : ' mk-card-media-icon' ?>"<?= $market['image'] ? ' style="background-image:url(\'' . e($market['image']) . '\')"' : '' ?>>
    <?php if (!$market['image']): ?><span><svg class="icon"><use href="#icon-stall"/></svg></span><?php endif; ?>
  </div>
  <div class="mk-hero-body">
    <div class="mk-card-top"><span class="mk-type"><?= e($market['type_name']) ?></span><span class="adm-badge <?= e($market['status']) ?>" id="mkStatusBadge"><?= e(ucfirst($market['status'])) ?></span></div>
    <h1 class="adm-h1"><?= e($market['name']) ?></h1>
    <?php if ($market['description']): ?><p class="adm-lede"><?= e($market['description']) ?></p><?php endif; ?>
    <ul class="mk-hero-meta">
      <li><svg viewBox="0 0 24 24"><use href="#icon-location"/></svg><?= e(implode(', ', array_filter([$market['address'], $market['area'], $market['city']]))) ?></li>
      <?php if ($market['hours']): ?><li><svg viewBox="0 0 24 24"><use href="#icon-clock"/></svg><?= e($market['hours']) ?><?= $market['days_label'] ? ', ' . e($market['days_label']) : '' ?></li><?php endif; ?>
    </ul>
  </div>
  <div class="mk-hero-actions">
    <button type="button" class="adm-btn" id="editMarketBtn"><svg viewBox="0 0 24 24"><use href="#icon-edit"/></svg> Edit Market</button>
    <div class="dropdown">
      <button type="button" class="adm-btn-outline w-100" data-bs-toggle="dropdown" aria-expanded="false">Change Status <svg viewBox="0 0 24 24" class="adm-chev"><use href="#icon-chevron"/></svg></button>
      <ul class="dropdown-menu dropdown-menu-end adm-dropdown">
        <?php foreach (MARKET_STATUSES as $s): ?>
          <li><button class="dropdown-item" data-set-status="<?= e($s) ?>"><?= e(['active' => 'Activate', 'inactive' => 'Deactivate', 'suspended' => 'Suspend'][$s]) ?></button></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</section>

<section class="adm-page-head mk-period-row">
  <div><p class="kicker" style="margin:0">MARKET INFORMATION</p></div>
  <label class="adm-select">
    <span class="visually-hidden">Analytics period</span>
    <svg viewBox="0 0 24 24"><use href="#icon-clock"/></svg>
    <select id="periodFilter">
      <option value="today">Today</option>
      <option value="week">This Week</option>
      <option value="month">This Month</option>
      <option value="year">This Year</option>
      <option value="all" selected>All Time</option>
    </select>
    <svg viewBox="0 0 24 24" class="adm-chev"><use href="#icon-chevron"/></svg>
  </label>
</section>

<div class="adm-error" id="mdError" hidden></div>

<section class="adm-kpi-grid mk-kpis" aria-label="Market figures">
  <?php foreach (['vendors' => ['Vendors', 'icon-farmer', 'active in this market'], 'products' => ['Products', 'icon-basket', 'live listings'],
                  'orders' => ['Orders', 'icon-receipt', ''], 'revenue' => ['Revenue', 'icon-coins', 'completed orders']] as $k => [$label, $icon, $noteText]): ?>
    <article class="adm-card adm-kpi" data-kpi="<?= $k ?>">
      <div class="adm-kpi-top"><span class="adm-kpi-icon"><svg viewBox="0 0 24 24"><use href="#<?= $icon ?>"/></svg></span><span class="adm-kpi-label"><?= $label ?></span></div>
      <div class="adm-kpi-value" data-role="value"><span class="adm-skel" style="width:60%"></span></div>
      <div class="adm-kpi-foot"><span class="adm-kpi-note" data-role="note"><?= e($noteText) ?></span></div>
    </article>
  <?php endforeach; ?>
</section>

<nav class="mk-tabs" role="tablist" aria-label="Market sections">
  <button type="button" role="tab" class="active" data-tab="vendors" aria-selected="true" aria-controls="tab-vendors" id="tabbtn-vendors">Vendors</button>
  <button type="button" role="tab" data-tab="products" aria-selected="false" aria-controls="tab-products" id="tabbtn-products">Products</button>
  <button type="button" role="tab" data-tab="orders" aria-selected="false" aria-controls="tab-orders" id="tabbtn-orders">Orders</button>
  <button type="button" role="tab" data-tab="analytics" aria-selected="false" aria-controls="tab-analytics" id="tabbtn-analytics">Market Analytics</button>
</nav>

<!-- Vendors -->
<section class="adm-card adm-panel mk-tabpane" id="tab-vendors" role="tabpanel" aria-labelledby="tabbtn-vendors">
  <header class="adm-panel-head">
    <div><h2 class="adm-h2">Vendors in this Market</h2><p class="adm-panel-sub">Only approved farmers with an active assignment are shown to customers.</p></div>
    <button type="button" class="adm-btn" id="assignBtn"><svg viewBox="0 0 24 24"><use href="#icon-link"/></svg> Assign Vendors</button>
  </header>
  <div class="table-responsive">
    <table class="table adm-table">
      <thead><tr><th>Vendor</th><th>Farm Name</th><th>Category</th><th class="text-end">Products</th><th class="text-end">Orders</th><th class="text-end">Revenue</th><th>Rating</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody id="vendorsBody" data-cols="9"></tbody>
    </table>
  </div>
</section>

<!-- Products -->
<section class="adm-card adm-panel mk-tabpane" id="tab-products" role="tabpanel" aria-labelledby="tabbtn-products" hidden>
  <header class="adm-panel-head">
    <div><h2 class="adm-h2">Products in this Market</h2><p class="adm-panel-sub">Listings farmers have published in this market.</p></div>
    <label class="mk-search mk-search-sm"><svg viewBox="0 0 24 24"><use href="#icon-search"/></svg><span class="visually-hidden">Search products</span><input type="search" id="productSearch" placeholder="Search product or vendor..."></label>
  </header>
  <div class="table-responsive">
    <table class="table adm-table">
      <thead><tr><th>Product</th><th>Vendor</th><th>Category</th><th class="text-end">Price</th><th class="text-end">Quantity</th><th>Availability</th><th>Added</th></tr></thead>
      <tbody id="productsBody" data-cols="7"></tbody>
    </table>
  </div>
</section>

<!-- Orders -->
<section class="adm-card adm-panel mk-tabpane" id="tab-orders" role="tabpanel" aria-labelledby="tabbtn-orders" hidden>
  <header class="adm-panel-head">
    <div><h2 class="adm-h2">Recent Orders</h2><p class="adm-panel-sub">Orders containing products from this market. Total shows this market's items only.</p></div>
  </header>
  <div class="table-responsive">
    <table class="table adm-table">
      <thead><tr><th>Order</th><th>Customer</th><th>Vendor</th><th class="text-end">Items</th><th class="text-end">Total</th><th>Status</th><th>Placed</th></tr></thead>
      <tbody id="ordersBody" data-cols="7"></tbody>
    </table>
  </div>
</section>

<!-- Analytics -->
<section class="mk-tabpane" id="tab-analytics" role="tabpanel" aria-labelledby="tabbtn-analytics" hidden>
  <div class="row g-4">
    <div class="col-xl-6"><section class="adm-card adm-panel h-100">
      <header class="adm-panel-head"><div><h2 class="adm-h2">Orders over Time</h2><p class="adm-panel-sub" data-series-label>Orders by month</p></div></header>
      <div class="adm-chart-box adm-chart-md" data-chart="orders"><canvas id="ordersChart" role="img" aria-label="Orders over time"></canvas><div class="adm-empty" hidden><svg viewBox="0 0 24 24"><use href="#icon-receipt"/></svg><p>No orders in this period</p></div></div>
    </section></div>
    <div class="col-xl-6"><section class="adm-card adm-panel h-100">
      <header class="adm-panel-head"><div><h2 class="adm-h2">Revenue over Time</h2><p class="adm-panel-sub">Completed orders only</p></div></header>
      <div class="adm-chart-box adm-chart-md" data-chart="revenue"><canvas id="revenueChart" role="img" aria-label="Revenue over time"></canvas><div class="adm-empty" hidden><svg viewBox="0 0 24 24"><use href="#icon-coins"/></svg><p>No revenue in this period</p></div></div>
    </section></div>
    <div class="col-xl-4 col-md-6"><section class="adm-card adm-panel h-100">
      <header class="adm-panel-head"><div><h2 class="adm-h2">Products by Category</h2><p class="adm-panel-sub">Live listings</p></div></header>
      <div id="categoryBars" class="adm-bars"></div>
    </section></div>
    <div class="col-xl-4 col-md-6"><section class="adm-card adm-panel h-100">
      <header class="adm-panel-head"><div><h2 class="adm-h2">Order Status</h2><p class="adm-panel-sub" data-period-name>All time</p></div></header>
      <div class="adm-chart-box adm-chart-md" data-chart="status"><canvas id="statusChart" role="img" aria-label="Orders by status"></canvas><div class="adm-empty" hidden><svg viewBox="0 0 24 24"><use href="#icon-receipt"/></svg><p>No orders yet</p></div></div>
    </section></div>
    <div class="col-xl-4"><section class="adm-card adm-panel h-100">
      <header class="adm-panel-head"><div><h2 class="adm-h2">Vendor Performance</h2><p class="adm-panel-sub">Completed revenue by vendor</p></div></header>
      <div class="adm-chart-box adm-chart-md" data-chart="vendorsPerf"><canvas id="vendorChart" role="img" aria-label="Vendor performance"></canvas><div class="adm-empty" hidden><svg viewBox="0 0 24 24"><use href="#icon-farmer"/></svg><p>No vendor sales yet</p></div></div>
    </section></div>
  </div>
</section>

<!-- Assign vendors -->
<div class="modal fade adm-modal" id="assignModal" tabindex="-1" aria-labelledby="assignTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-sm-down">
    <form class="modal-content" id="assignForm">
      <div class="modal-header">
        <div><p class="kicker" style="margin:0 0 4px">ASSIGN VENDORS</p><h2 class="adm-h2" id="assignTitle">Add farmers to <?= e($market['name']) ?></h2></div>
        <button type="button" class="adm-icon-btn" data-bs-dismiss="modal" aria-label="Close"><svg viewBox="0 0 24 24"><use href="#icon-close"/></svg></button>
      </div>
      <div class="modal-body">
        <label class="mk-search mb-3"><svg viewBox="0 0 24 24"><use href="#icon-search"/></svg><span class="visually-hidden">Filter vendors</span><input type="search" id="assignSearch" placeholder="Filter approved vendors..."></label>
        <div id="assignList" class="mk-assign-list"></div>
        <div class="invalid-feedback d-block" data-error-for="vendor_ids"></div>
      </div>
      <div class="modal-footer">
        <span class="me-auto adm-panel-sub" id="assignCount">0 selected</span>
        <button type="button" class="adm-btn-outline" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="adm-btn" id="assignSubmit"><svg viewBox="0 0 24 24"><use href="#icon-check"/></svg> Assign</button>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/includes/market-form-modal.php'; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
