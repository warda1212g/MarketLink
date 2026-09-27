<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once APP_ROOT . '/includes/partials/vendor_layout.php';

$vendor = require_vendor();
$tabs = ['' => 'All', 'pending' => 'New', 'accepted' => 'Accepted', 'preparing' => 'Preparing', 'ready' => 'Ready', 'picked_up' => 'Picked Up', 'completed' => 'Completed', 'declined' => 'Rejected', 'cancelled' => 'Cancelled'];
$initial = isset($tabs[$_GET['status'] ?? '']) ? ($_GET['status'] ?? '') : '';

vendor_layout_start([
    'title' => 'Pre-Orders', 'active' => 'orders',
    'heading' => 'Pre-Orders', 'subheading' => 'Review, accept and prepare incoming pre-orders.',
]);
?>
<div class="page-head">
  <div><h2>Incoming pre-orders</h2><p>Flow: New -> Accepted -> Preparing -> Ready for Pickup -> customer confirms pickup -> you mark it Completed. The customer picks a pickup slot once you accept. Rejected or cancelled orders return their stock.</p></div>
</div>

<div class="tabs" role="tablist" id="orderTabs" aria-label="Filter by status">
  <?php foreach ($tabs as $key => $label): ?>
    <button type="button" role="tab" class="tab<?= $key === $initial ? ' active' : '' ?>" data-status="<?= e($key) ?>" aria-selected="<?= $key === $initial ? 'true' : 'false' ?>">
      <?= e($label) ?> <span class="tab-count" data-count="<?= e($key ?: 'all') ?>">, </span>
    </button>
  <?php endforeach; ?>
</div>

<div class="toolbar">
  <div class="search-input">
    <svg><use href="#i-search"/></svg>
    <input class="input" type="search" id="orderSearch" placeholder="Search by order number or customer..." aria-label="Search orders" value="<?= e($_GET['q'] ?? '') ?>">
  </div>
  <button class="btn btn-ghost" id="refreshOrders"><svg><use href="#i-refresh"/></svg>Refresh</button>
</div>

<div class="order-list" id="orderList" aria-live="polite">
  <div class="skeleton" style="height:150px;border-radius:20px"></div>
  <div class="skeleton" style="height:150px;border-radius:20px"></div>
</div>
<div style="text-align:center;margin-top:18px"><button class="btn btn-ghost" id="loadMore" hidden>Load more</button></div>

<script type="application/json" id="ordersInit"><?= json_encode(['status' => $initial, 'open' => (int) ($_GET['open'] ?? 0)]) ?></script>

<?php vendor_layout_end(['scripts' => ['assets/js/vendor/order-actions.js', 'assets/js/vendor/orders.js']]);
