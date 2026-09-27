<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/analytics.php';
require_once APP_ROOT . '/includes/services/orders.php';
require_once APP_ROOT . '/includes/services/ai_features.php';
require_once APP_ROOT . '/includes/partials/vendor_layout.php';
require_once APP_ROOT . '/includes/partials/components.php';

$vendor = require_vendor();
$vid = (int) $vendor['id'];

$stats = dashboard_stats($vendor);
$statusCounts = status_breakdown($vid);
$bestSellers = best_sellers($vid, null, 5);
$recentOrders = vendor_orders($vid, ['limit' => 6]);
$lowStock = low_stock_products($vendor, 6);
$rating = rating_summary($vid);
$recentReviews = db_all('SELECT r.*, u.name AS customer_name FROM reviews r JOIN users u ON u.id = r.customer_id
                         WHERE r.vendor_id = ? ORDER BY r.created_at DESC LIMIT 3', [$vid]);

// discount suggestions and market gaps
$discounts = vendor_discount_suggestions($vendor);
$gaps = vendor_category_gaps($vid);

// Setup checklist - only shown while something is missing.
$setup = [];
if (!$vendor['latitude']) $setup[] = ['i-pin', 'Pin your stall location', 'So nearby customers can find you', 'vendor/location.php'];
if (!$stats['products']['value']) $setup[] = ['i-box', 'Add your first product', 'Publish what you sell', 'vendor/products.php?new=1'];
if (!db_value('SELECT 1 FROM cutoff_settings WHERE vendor_id = ? LIMIT 1', [$vid])) $setup[] = ['i-clock', 'Set order cut-off times', 'Control when pre-orders close', 'vendor/cutoff.php'];
if (!$vendor['operating_days'] || !$vendor['description']) $setup[] = ['i-user', 'Complete your profile', 'Operating days & description', 'vendor/profile.php'];

vendor_layout_start(['title' => 'Dashboard', 'active' => 'dashboard']);
?>

<?php if ($setup): ?>
<div class="setup-list fade-in">
  <?php foreach ($setup as [$icon, $title, $sub, $href]): ?>
    <a class="setup-item" href="<?= e(url($href)) ?>">
      <span class="stat-icon"><svg><use href="#<?= $icon ?>"/></svg></span>
      <span><?= e($title) ?><small><?= e($sub) ?></small></span>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<section class="grid grid-4" aria-label="Key statistics">
  <article class="card stat-card fade-in">
    <div class="stat-top"><span class="stat-label">Total Products</span><span class="stat-icon"><svg><use href="#i-box"/></svg></span></div>
    <div class="stat-value"><?= number_format($stats['products']['value']) ?></div>
    <div class="stat-foot">
      <?php if ($stats['products']['this_month']): ?><span class="trend up"><svg><use href="#i-up"/></svg><?= $stats['products']['this_month'] ?></span> this month
      <?php else: ?><?= $stats['products']['live'] ?> live in stock<?php endif; ?>
    </div>
  </article>

  <article class="card stat-card fade-in" style="animation-delay:.05s">
    <div class="stat-top"><span class="stat-label">Pending Orders</span><span class="stat-icon"><svg><use href="#i-cart"/></svg></span></div>
    <div class="stat-value"><?= number_format($stats['pending']['value']) ?></div>
    <div class="stat-foot">
      <?php if ($stats['pending']['urgent']): ?><span class="trend down"><svg><use href="#i-alert"/></svg><?= $stats['pending']['urgent'] ?></span> need action by tomorrow
      <?php else: ?>Nothing urgent<?php endif; ?>
    </div>
  </article>

  <article class="card stat-card fade-in" style="animation-delay:.1s">
    <div class="stat-top"><span class="stat-label">Today's Orders</span><span class="stat-icon"><svg><use href="#i-bag"/></svg></span></div>
    <div class="stat-value"><?= number_format($stats['today']['value']) ?></div>
    <div class="stat-foot"><?= trend_badge($stats['today']['change']) ?> vs yesterday, <?= $stats['today']['pickups'] ?> pickups today</div>
  </article>

  <article class="card stat-card featured fade-in" style="animation-delay:.15s">
    <div class="stat-top"><span class="stat-label">Total Revenue</span><span class="stat-icon"><svg><use href="#i-money"/></svg></span></div>
    <div class="stat-value"><?= e(money($stats['revenue']['value'])) ?></div>
    <div class="stat-foot"><?= trend_badge($stats['revenue']['change']) ?> last 30 days</div>
  </article>
</section>

<section class="mt" id="smartInsights" aria-label="Smart insights">
  <div class="grid grid-2">
    <article class="card">
      <div class="card-head">
        <div><h3 class="card-title">Perishability Discount Engine</h3>
          <p class="card-sub">AI checks stock left vs. what you normally sell in a week</p></div>
      </div>
      <?php if (!$discounts): ?>
        <?= empty_state('i-check', 'No discount suggestions', 'Your stock levels look balanced against your usual weekly sales.') ?>
      <?php else: ?>
        <div class="rank-list" id="discountList">
        <?php foreach ($discounts as $d): ?>
          <div class="rank-item" data-discount-product="<?= (int) $d['product_id'] ?>" data-discount-pct="<?= (int) $d['discount'] ?>">
            <div class="rank-body" style="min-width:0">
              <strong><?= e($d['name']) ?></strong>
              <p class="muted" style="font-size:12.5px;margin-top:2px">
                <?= e(qty($d['quantity']) . ' ' . $d['unit']) ?> left - about <?= e($d['weeks_worth']) ?> weeks of your usual sales.
                Suggested <strong><?= (int) $d['discount'] ?>% off</strong> before it wilts.
              </p>
            </div>
            <div style="text-align:right;white-space:nowrap">
              <div class="rank-val"><?= e(money($d['new_price'])) ?><small>was <?= e(money($d['price'])) ?></small></div>
              <button type="button" class="btn btn-soft btn-sm" data-apply-discount><svg><use href="#i-check"/></svg>Apply <?= (int) $d['discount'] ?>% off</button>
            </div>
          </div>
        <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </article>

    <article class="card">
      <div class="card-head">
        <div><h3 class="card-title">Market Gap Opportunities</h3>
          <p class="card-sub">What your customers buy nearby - but not from you</p></div>
      </div>
      <?php if (!$gaps): ?>
        <?= empty_state('i-sprout', 'No gaps detected yet', 'Once your customers order from other stalls, the AI spots what you could stock too.') ?>
      <?php else: ?>
        <div class="rank-list">
        <?php foreach ($gaps as $g): ?>
          <div class="rank-item">
            <span class="thumb"><svg><use href="#i-sprout"/></svg></span>
            <div class="rank-body">
              <strong><?= e($g['name']) ?></strong>
              <p class="muted" style="font-size:12.5px;margin-top:2px">
                <?= (int) $g['customers'] ?> of your customers bought <?= e(strtolower($g['name'])) ?> from other stalls.
                You don't sell this yet - consider adding it.
              </p>
            </div>
          </div>
        <?php endforeach; ?>
        </div>
        <p class="muted" style="font-size:13px;margin-top:10px">Add a matching product on <a class="link" href="<?= e(url('vendor/products.php?new=1')) ?>">My Products</a>.</p>
      <?php endif; ?>
    </article>
  </div>
</section>

<section class="grid grid-main mt">
  <article class="card" id="salesCard">
    <div class="card-head">
      <div>
        <h3 class="card-title">Sales Overview</h3>
        <p class="card-sub" data-range-label>Last 30 days</p>
      </div>
      <?= range_filter(['today' => 'Today', '7d' => '7 Days', '30d' => '30 Days', '3m' => '3 Months', 'year' => 'This Year', 'custom' => 'Custom'], '30d') ?>
    </div>
    <div class="chart-summary">
      <div><span>Revenue</span><strong data-total="revenue">-</strong></div>
      <div><span>Orders</span><strong data-total="orders">-</strong></div>
      <div><span>Avg. order</span><strong data-total="aov">-</strong></div>
      <div style="margin-left:auto">
        <div class="seg" role="group" aria-label="Chart metric">
          <button type="button" class="active" data-metric="revenue" aria-pressed="true">Revenue</button>
          <button type="button" data-metric="orders" aria-pressed="false">Orders</button>
        </div>
      </div>
    </div>
    <div class="chart-box"><canvas id="salesChart" role="img" aria-label="Sales over time"></canvas><div class="loading-overlay" data-loading><span class="spinner"></span></div></div>
    <button class="table-toggle mt" type="button" data-table-toggle="salesTable">Show data table</button>
    <div class="data-table-wrap" id="salesTable" hidden></div>
  </article>

  <article class="card">
    <div class="card-head">
      <div><h3 class="card-title">Order Status</h3><p class="card-sub">All time</p></div>
      <a class="link" href="<?= e(url('vendor/orders.php')) ?>">Orders <svg><use href="#i-arrow"/></svg></a>
    </div>
    <?php $totalOrders = array_sum($statusCounts); ?>
    <div class="donut-wrap">
      <div class="donut-box">
        <canvas id="statusChart" role="img" aria-label="Orders by status"></canvas>
        <div class="donut-center"><div><strong><?= number_format($totalOrders) ?></strong><span>orders</span></div></div>
      </div>
      <div class="status-list" id="statusLegend"></div>
    </div>
    <script type="application/json" id="statusData"><?= json_encode($statusCounts) ?></script>
  </article>
</section>

<section class="mt">
  <article class="card card-flush">
    <div class="card-head">
      <div><h3 class="card-title">Recent Orders</h3><p class="card-sub">Latest pre-orders from customers</p></div>
      <a class="link" href="<?= e(url('vendor/orders.php')) ?>">View all <svg><use href="#i-arrow"/></svg></a>
    </div>
    <?php if (!$recentOrders): ?>
      <?= empty_state('i-cart', 'No orders yet', 'When customers pre-order from your stall they will appear here.') ?>
    <?php else: ?>
    <div class="table-wrap" style="margin-top:14px">
      <table class="table table-cards" id="recentOrders">
        <thead><tr><th>Order</th><th>Customer</th><th class="num">Items</th><th>Pickup</th><th class="num">Total</th><th>Status</th><th class="num">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($recentOrders as $o): ?>
          <tr data-order-id="<?= (int) $o['id'] ?>">
            <td data-label="Order" class="cell-strong">#<?= e($o['order_number']) ?></td>
            <td data-label="Customer"><?= e($o['customer_name']) ?></td>
            <td data-label="Items" class="num"><?= (int) $o['item_count'] ?></td>
            <td data-label="Pickup" class="cell-muted nowrap"><?= e(pickup_label($o['pickup_date'], null, null)) ?></td>
            <td data-label="Total" class="num cell-strong nowrap"><?= e(money($o['total_amount'])) ?></td>
            <td data-label="Status"><span class="pill pill-<?= e($o['status']) ?>"><?= e(status_label($o['status'])) ?></span></td>
            <td data-label="Actions"><div class="row-actions"><?= order_action_buttons($o) ?></div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </article>

</section>

<section class="grid grid-3 mt">
  <article class="card">
    <div class="card-head">
      <div><h3 class="card-title">Best Selling Products</h3><p class="card-sub">Completed orders, all time</p></div>
    </div>
    <?php if (!$bestSellers): ?>
      <?= empty_state('i-sprout', 'No sales yet', 'Best sellers appear once orders are completed.') ?>
    <?php else: $max = max(array_column($bestSellers, 'qty')) ?: 1; ?>
      <div class="rank-list">
      <?php foreach ($bestSellers as $p): ?>
        <div class="rank-item">
          <span class="thumb"<?= $p['image'] ? ' style="background-image:url(\'' . e($p['image']) . '\')"' : '' ?>><?= $p['image'] ? '' : '<svg><use href="#i-sprout"/></svg>' ?></span>
          <div class="rank-body">
            <strong><?= e($p['name']) ?></strong>
            <div class="rank-bar"><i style="width:<?= round($p['qty'] / $max * 100) ?>%"></i></div>
          </div>
          <div class="rank-val"><?= e($p['qty_label']) ?><small><?= e($p['revenue_label']) ?></small></div>
        </div>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </article>
  <article class="card">
    <div class="card-head">
      <div><h3 class="card-title">Low Stock</h3><p class="card-sub">At or below <?= e(qty($vendor['low_stock_threshold'])) ?> units</p></div>
      <a class="link" href="<?= e(url('vendor/products.php?stock=low')) ?>">Manage Inventory <svg><use href="#i-arrow"/></svg></a>
    </div>
    <?php if (!$lowStock): ?>
      <?= empty_state('i-check', 'Stock looks healthy', 'No products are running low right now.') ?>
    <?php else: foreach ($lowStock as $p): ?>
      <div class="low-item">
        <span class="thumb"<?= $p['image'] ? ' style="background-image:url(\'' . e(upload_url($p['image'])) . '\')"' : '' ?>><?= $p['image'] ? '' : '<svg><use href="#i-sprout"/></svg>' ?></span>
        <strong><?= e($p['name']) ?></strong>
        <span class="low-qty<?= (float) $p['quantity'] <= 0 ? ' zero' : '' ?>"><?= (float) $p['quantity'] <= 0 ? 'Out of stock' : e(qty($p['quantity']) . ' ' . $p['unit'] . ' left') ?></span>
      </div>
    <?php endforeach; endif; ?>
  </article>

  <article class="card">
    <div class="card-head">
      <div><h3 class="card-title">Recent Reviews</h3>
        <p class="card-sub"><?= $rating['count'] ? e($rating['avg'] . ' average from ' . $rating['count'] . ' review' . ($rating['count'] > 1 ? 's' : '')) : 'No reviews yet' ?></p></div>
      <a class="link" href="<?= e(url('vendor/reviews.php')) ?>">All reviews <svg><use href="#i-arrow"/></svg></a>
    </div>
    <?php if (!$recentReviews): ?>
      <?= empty_state('i-star-o', 'No reviews yet', 'Customers can review your stall after a completed pickup.') ?>
    <?php else: foreach ($recentReviews as $r): ?>
      <div class="low-item" style="align-items:flex-start">
        <span class="avatar" style="background:var(--olive)"><?= e(mb_strtoupper(mb_substr($r['customer_name'], 0, 1))) ?></span>
        <div style="flex:1;min-width:0">
          <strong><?= e($r['customer_name']) ?></strong> <?= stars((int) $r['rating']) ?>
          <p class="muted" style="font-size:13px;margin-top:2px"><?= e(mb_strimwidth((string) $r['review'], 0, 120, '...')) ?></p>
        </div>
        <small class="muted" style="white-space:nowrap"><?= e(time_ago($r['created_at'])) ?></small>
      </div>
    <?php endforeach; endif; ?>
  </article>
</section>

<?php vendor_layout_end([
    'vendor_scripts' => ['https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js'],
    'scripts' => ['assets/js/vendor/order-actions.js', 'assets/js/vendor/ai-insights.js', 'assets/js/vendor/dashboard.js'],
]);
