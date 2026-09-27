<?php
/**
 * Admin reports page: live analytics, generate a report, see the result,
 * an optional AI summary and the report history.
 * Numbers come from includes/services/admin_analytics.php.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/admin_reports.php';
$user = require_admin_page();

/* actions */
if (is_post()) {
    if (!csrf_valid()) {
        flash('error', 'Security token mismatch. Refresh the page and try again.');
        redirect('admin/reports.php');
    }
    $req = ar_request($_POST);
    $id = ar_generate($req, (int) $user['id'], !empty($_POST['with_ai']));
    $keep = array_intersect_key($_GET, array_flip(['period', 'start', 'end', 'market', 'farmer']));
    redirect('admin/reports.php?' . http_build_query($keep + ['report' => $id]) . '#report-result');
}

$reportId = int_input($_GET, 'report', 0);
$report = $reportId ? ar_find($reportId) : null;
if ($report && ($_GET['export'] ?? '') === 'csv') {
    ar_export_csv($report);
}

/* live analytics */
$f = ra_filters($_GET);
$d = ra_build($f);
$o = $d['orders'];
$k = $d['kpis'];
$opts = ra_filter_options();
$history = ar_history();
$hasOrders = $o['orders'] > 0;

$scopeText = ($f['market_name'] ?? 'All Markets') . ', ' . ($f['farmer_name'] ?? 'All Farmers');
$num = fn($v) => $v === null ? '-' : number_format((float) $v, fmod((float) $v, 1) ? 2 : 0);

$activeNav    = 'reports';
$pageTitle    = 'Reports & Analytics';
$pageSubtitle = 'Live analytics and generated reports';
$pageStyles   = [asset('assets/css/admin-reports.css')];
$pageScripts  = ['https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js', asset('assets/js/admin-reports.js'), asset('assets/js/admin-table-filter.js')];

/** Generic table for a stored report list (column names come from the report keys). */
function ra_report_table(array $rows): string
{
    if (!$rows) {
        return '<p class="adm-empty-inline">No rows for this section.</p>';
    }
    $cols = array_keys($rows[0]);
    $money = fn($c) => str_contains($c, 'value') || $c === 'price';
    $right = fn($c) => in_array($c, ['farmers', 'products', 'active_products', 'sold_out_products', 'orders', 'completed', 'cancelled', 'reviews',
                                      'average_rating', 'quantity', 'quantity_ordered', 'stock'], true) || $money($c);
    $h = '<div class="table-responsive"><table class="table adm-table ra-table"><thead><tr>';
    foreach ($cols as $c) {
        $h .= '<th' . ($right($c) ? ' class="text-end"' : '') . '>' . e(ucwords(str_replace('_', ' ', $c))) . '</th>';
    }
    $h .= '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $h .= '<tr>';
        foreach ($cols as $c) {
            $v = $r[$c];
            if ($v === null || $v === '') {
                $out = '-';
            } elseif ($money($c)) {
                $out = money((float) $v);
            } elseif ($c === 'average_rating') {
                $out = number_format((float) $v, 1) . ' / 5';
            } elseif (is_float($v) || is_int($v)) {
                $out = number_format((float) $v, fmod((float) $v, 1) ? 2 : 0);
            } else {
                $out = (string) $v;
            }
            $h .= '<td' . ($right($c) ? ' class="text-end"' : '') . '>' . e($out) . '</td>';
        }
        $h .= '</tr>';
    }
    return $h . '</tbody></table></div>';
}

/**
 * Search + filter + sort bar for one table (driven by assets/js/admin-table-filter.js).
 * $filters: [key => [label, [value => text, ...]]] matched against each row's data-tf-<key>.
 * $sorts:   [value => text] where value is "<key>:<asc|desc>" matched against data-sort-<key>.
 */
function ra_table_tools(string $table, string $placeholder, array $filters = [], array $sorts = [], int $total = 0): string
{
    $h = '<div class="tf-bar" data-tf-for="' . e($table) . '" role="search">'
       . '<div class="tf-search"><svg viewBox="0 0 24 24"><use href="#icon-search"/></svg>'
       . '<input type="search" class="form-control" data-tf-search placeholder="' . e($placeholder) . '" aria-label="' . e($placeholder) . '"></div>';
    foreach ($filters as $key => [$label, $options]) {
        $h .= '<select class="form-select" data-tf-filter="' . e($key) . '" aria-label="' . e($label) . '"><option value="">' . e($label) . ': All</option>';
        foreach ($options as $v => $t) {
            $h .= '<option value="' . e((string) $v) . '">' . e($t) . '</option>';
        }
        $h .= '</select>';
    }
    if ($sorts) {
        $h .= '<select class="form-select" data-tf-sort aria-label="Sort by"><option value="">Sort: Default</option>';
        foreach ($sorts as $v => $t) {
            $h .= '<option value="' . e($v) . '">Sort: ' . e($t) . '</option>';
        }
        $h .= '</select>';
    }
    return $h . '<button type="button" class="adm-btn-outline tf-reset" data-tf-reset hidden>Clear</button>'
         . '<span class="tf-count" data-tf-count>' . $total . ' of ' . $total . '</span></div>';
}

/** Lower-cased search text for a row. */
function ra_tf_text(...$parts): string
{
    return e(mb_strtolower(implode(' ', array_filter(array_map('strval', $parts)))));
}

$uniq = function (array $rows, string $key): array {
    $out = [];
    foreach ($rows as $r) {
        foreach (array_map('trim', explode(',', (string) ($r[$key] ?? ''))) as $v) {
            if ($v !== '') $out[mb_strtolower($v)] = $v;
        }
    }
    asort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
};

include __DIR__ . '/includes/header.php';
?>

<?php foreach (take_flashes() as $fl): ?>
  <div class="adm-flash adm-flash-<?= e($fl['type']) ?>" role="status"><?= e($fl['message']) ?></div>
<?php endforeach; ?>

<!-- Reports & analytics -->
<section class="adm-page-head ra-noprint">
  <div>
    <p class="kicker">INSIGHTS</p>
    <h1 class="adm-h1">Reports &amp; <em>Analytics</em></h1>
    <p class="adm-lede">Live figures calculated from MarketLink orders, products, markets, farmers and reviews, <strong><?= e($f['label']) ?></strong>, <?= e($scopeText) ?></p>
  </div>
  <a href="#generate" class="adm-btn"><svg viewBox="0 0 24 24"><use href="#icon-receipt"/></svg> Generate Report</a>
</section>

<!-- Filters -->
<form class="adm-card adm-panel ra-filters ra-noprint" method="get" id="analyticsFilters">
  <div class="ra-filter-grid">
    <label><span class="adm-label">Period</span>
      <select class="form-select" name="period" data-period-select>
        <?php foreach (RA_PERIODS as $key => $label): ?><option value="<?= $key ?>" <?= $f['period'] === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label data-custom-date><span class="adm-label">Start Date</span><input class="form-control" type="date" name="start" value="<?= e($f['start']) ?>"></label>
    <label data-custom-date><span class="adm-label">End Date</span><input class="form-control" type="date" name="end" value="<?= e($f['end']) ?>"></label>
    <label><span class="adm-label">Market</span>
      <select class="form-select" name="market">
        <option value="0">All Markets</option>
        <?php foreach ($opts['markets'] as $m): ?><option value="<?= (int) $m['id'] ?>" <?= $f['market_id'] === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?><?= $m['status'] !== 'active' ? ' (' . e($m['status']) . ')' : '' ?></option><?php endforeach; ?>
      </select>
    </label>
    <label><span class="adm-label">Farmer</span>
      <select class="form-select" name="farmer">
        <option value="0">All Farmers</option>
        <?php foreach ($opts['farmers'] as $v): ?><option value="<?= (int) $v['id'] ?>" <?= $f['farmer_id'] === (int) $v['id'] ? 'selected' : '' ?>><?= e($v['stall_name'] . ' - ' . $v['name']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <div class="ra-filter-actions">
      <button type="submit" class="adm-btn"><svg viewBox="0 0 24 24"><use href="#icon-search"/></svg> Apply</button>
      <a href="reports.php" class="adm-btn-outline">Reset</a>
    </div>
  </div>
</form>

<div class="ra-analytics ra-noprint">

<!-- KPI Overview -->
<section class="adm-kpi-grid ra-kpis" aria-label="KPI overview">
  <?php
  $cards = [
      ['Total Farmers', $k['farmers'], 'icon-farmer', $k['active_farmers'] . ' active (approved)'],
      ['Total Customers', $k['customers'], 'icon-people', $d['customers']['new'] . ' new in period'],
      ['Total Markets', $k['markets'], 'icon-stall', $k['active_markets'] . ' active'],
      ['Total Products', $k['products'], 'icon-basket', $k['available_products'] . ' available now'],
      ['Total Orders', $o['orders'], 'icon-receipt', 'in selected period'],
      ['Completed Orders', $o['completed'], 'icon-check', 'incl. picked up'],
      ['Pending Orders', $o['pending'], 'icon-clock', 'awaiting farmer'],
      ['Cancelled Orders', $o['cancelled'], 'icon-close', 'incl. declined'],
      ['Order Value', money($o['order_value']), 'icon-coins', 'valid orders, paid at pickup'],
  ];
  foreach ($cards as [$label, $value, $icon, $note]): ?>
    <article class="adm-card adm-kpi">
      <div class="adm-kpi-top">
        <span class="adm-kpi-icon"><svg viewBox="0 0 24 24"><use href="#<?= $icon ?>"/></svg></span>
        <span class="adm-kpi-label"><?= e($label) ?></span>
      </div>
      <div class="adm-kpi-value"><?= e(is_int($value) ? number_format($value) : $value) ?></div>
      <div class="adm-kpi-foot"><span class="adm-kpi-note"><?= e($note) ?></span></div>
    </article>
  <?php endforeach; ?>
</section>

<?php if (!$hasOrders && !$d['reviews']['total']): ?>
  <div class="adm-card adm-panel ra-empty-banner">
    <svg viewBox="0 0 24 24"><use href="#icon-chart"/></svg>
    <p><?= $f['market_id'] && !$f['farmer_id'] ? 'No order activity available for this market.' : ($f['farmer_id'] ? 'No sales data available for this Farmer.' : 'No analytics data available for the selected period.') ?></p>
  </div>
<?php endif; ?>

<div class="row g-4">
  <!-- Order Analytics -->
  <div class="col-12">
    <section class="adm-card adm-panel">
      <header class="adm-panel-head"><div><h2 class="adm-h2">Order Analytics</h2><p class="adm-panel-sub">Status groups: accepted includes preparing; completed includes picked up; cancelled includes declined.</p></div></header>
      <div class="ra-stat-row">
        <?php foreach ([['Total Orders', number_format($o['orders'])], ['Pending', number_format($o['pending'])], ['Accepted', number_format($o['accepted'])],
                        ['Ready for Pickup', number_format($o['ready'])], ['Completed', number_format($o['completed'])], ['Cancelled', number_format($o['cancelled'])],
                        ['Total Order Value', money($o['order_value'])], ['Average Order Value', ra_money($o['avg_order_value'])]] as [$l, $v]): ?>
          <div class="ra-stat"><span><?= e($l) ?></span><strong><?= e($v) ?></strong></div>
        <?php endforeach; ?>
      </div>
      <p class="ra-note">Average Order Value = Total Order Value ÷ valid orders (<?= number_format($o['valid_orders']) ?>). Completed order value: <?= e(money($o['completed_value'])) ?>.</p>
    </section>
  </div>

  <!-- Time-based charts -->
  <div class="col-xl-8">
    <section class="adm-card adm-panel h-100">
      <header class="adm-panel-head">
        <div><h2 class="adm-h2">Orders Over Time</h2><p class="adm-panel-sub"><?= $f['granularity'] === 'month' ? 'Per month' : 'Per day' ?>, <?= e($f['label']) ?></p></div>
        <div class="adm-seg" role="group" aria-label="Metric" id="seriesMetric">
          <button type="button" data-value="orders" class="active">Orders</button>
          <button type="button" data-value="order_value">Order Value</button>
        </div>
      </header>
      <div class="adm-chart-box adm-chart-lg">
        <?php if ($hasOrders): ?><canvas id="seriesChart" role="img" aria-label="Orders over time"></canvas>
        <?php else: ?><div class="adm-empty"><svg viewBox="0 0 24 24"><use href="#icon-chart"/></svg><p>No analytics data available for the selected period.</p></div><?php endif; ?>
      </div>
    </section>
  </div>
  <div class="col-xl-4 col-md-6">
    <section class="adm-card adm-panel h-100">
      <header class="adm-panel-head"><div><h2 class="adm-h2">Order Status Distribution</h2><p class="adm-panel-sub"><?= e($f['label']) ?></p></div></header>
      <div class="adm-chart-box adm-chart-donut">
        <?php if ($hasOrders): ?><canvas id="statusChart" role="img" aria-label="Order status distribution"></canvas>
        <?php else: ?><div class="adm-empty"><svg viewBox="0 0 24 24"><use href="#icon-receipt"/></svg><p>No orders in this period.</p></div><?php endif; ?>
      </div>
    </section>
  </div>

  <!-- Market Analytics -->
  <div class="col-12">
    <section class="adm-card adm-panel">
      <header class="adm-panel-head"><div><h2 class="adm-h2">Market Performance</h2><p class="adm-panel-sub">Orders are attributed to the market their products are listed in, ratings from reviews in the period</p></div></header>
      <?php if (!$d['markets']): ?>
        <div class="adm-empty"><p>No markets match the selected filters.</p></div>
      <?php else: ?>
        <?php if (array_filter($d['markets'], fn($m) => $m['orders'] > 0)): ?>
          <div class="adm-chart-box adm-chart-md"><canvas id="marketChart" role="img" aria-label="Order value by market"></canvas></div>
        <?php endif; ?>
        <?= ra_table_tools('tfMarkets', 'Search markets or market type', [
            'type'     => ['Type', $uniq($d['markets'], 'type')],
            'status'   => ['Status', $uniq($d['markets'], 'status')],
            'activity' => ['Activity', ['with' => 'With orders', 'none' => 'No orders']],
        ], ['value:desc' => 'Highest order value', 'orders:desc' => 'Most orders', 'rating:desc' => 'Best rating', 'farmers:desc' => 'Most farmers', 'name:asc' => 'Name A to Z'], count($d['markets'])) ?>
        <div class="table-responsive">
          <table class="table adm-table ra-table" id="tfMarkets">
            <thead><tr><th>Market</th><th class="text-end">Farmers</th><th class="text-end">Products</th><th class="text-end">Orders</th><th class="text-end">Completed</th><th class="text-end">Cancelled</th><th class="text-end">Order Value</th><th class="text-end">Avg Order</th><th class="text-end">Reviews</th><th class="text-end">Avg Rating</th></tr></thead>
            <tbody>
            <?php foreach ($d['markets'] as $m): ?>
              <tr data-tf-row data-tf-text="<?= ra_tf_text($m['name'], $m['type'] ?? '') ?>" data-tf-type="<?= e(mb_strtolower((string) ($m['type'] ?? ''))) ?>" data-tf-status="<?= e(mb_strtolower((string) $m['status'])) ?>"
                  data-tf-activity="<?= $m['orders'] > 0 ? 'with' : 'none' ?>" data-sort-name="<?= e(mb_strtolower($m['name'])) ?>" data-sort-value="<?= (float) $m['order_value'] ?>"
                  data-sort-orders="<?= (int) $m['orders'] ?>" data-sort-rating="<?= (float) ($m['avg_rating'] ?? 0) ?>" data-sort-farmers="<?= (int) $m['farmers'] ?>">
                <td><a href="market-details.php?id=<?= $m['id'] ?>"><?= e($m['name']) ?></a><?= $m['status'] !== 'active' ? ' <span class="adm-badge">' . e($m['status']) . '</span>' : '' ?><small class="d-block text-muted"><?= e($m['type'] ?? '') ?></small></td>
                <td class="text-end"><?= $m['farmers'] ?></td>
                <td class="text-end"><?= $m['products'] ?></td>
                <?php if ($m['orders'] > 0): ?>
                  <td class="text-end"><?= $m['orders'] ?></td><td class="text-end"><?= $m['completed'] ?></td><td class="text-end"><?= $m['cancelled'] ?></td>
                  <td class="text-end"><?= e(money($m['order_value'])) ?></td><td class="text-end"><?= e(ra_money($m['avg_order_value'])) ?></td>
                <?php else: ?>
                  <td colspan="5" class="text-muted text-center ra-muted">No order activity available for this market.</td>
                <?php endif; ?>
                <td class="text-end"><?= $m['reviews'] ?: '-' ?></td>
                <td class="text-end"><?= e(ra_rating($m['avg_rating'])) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  </div>

  <!-- Farmer Analytics -->
  <div class="col-12">
    <section class="adm-card adm-panel">
      <header class="adm-panel-head"><div><h2 class="adm-h2">Farmer Performance</h2><p class="adm-panel-sub">Sorted by order value in the period, <?= count($d['farmers']) ?> farmer<?= count($d['farmers']) === 1 ? '' : 's' ?></p></div></header>
      <?php if (!$d['farmers']): ?>
        <div class="adm-empty"><p>No farmers match the selected filters.</p></div>
      <?php else: ?>
      <?= ra_table_tools('tfFarmers', 'Search stall or farmer name', [
          'market'   => ['Market', $uniq($d['farmers'], 'markets')],
          'status'   => ['Status', ['active' => 'Active', 'inactive' => 'Inactive']],
          'activity' => ['Sales', ['with' => 'With sales', 'none' => 'No sales']],
      ], ['value:desc' => 'Highest order value', 'orders:desc' => 'Most orders', 'rating:desc' => 'Best rating', 'products:desc' => 'Most products', 'name:asc' => 'Name A to Z'], count($d['farmers'])) ?>
      <div class="table-responsive ra-scroll">
        <table class="table adm-table ra-table" id="tfFarmers">
          <thead><tr><th>Farmer</th><th>Market</th><th class="text-end">Products</th><th class="text-end">Active / Sold out</th><th class="text-end">Orders</th><th class="text-end">Completed</th><th class="text-end">Cancelled</th><th class="text-end">Order Value</th><th class="text-end">Avg Order</th><th class="text-end">Rating</th><th class="text-end">Reviews</th></tr></thead>
          <tbody>
          <?php foreach ($d['farmers'] as $x): ?>
            <tr data-tf-row data-tf-text="<?= ra_tf_text($x['stall_name'], $x['farmer_name'], $x['markets']) ?>"
                data-tf-market="|<?= e(mb_strtolower(implode('|', array_map('trim', explode(',', (string) $x['markets']))))) ?>|"
                data-tf-status="<?= $x['active'] ? 'active' : 'inactive' ?>" data-tf-activity="<?= $x['orders'] > 0 ? 'with' : 'none' ?>"
                data-sort-name="<?= e(mb_strtolower($x['stall_name'])) ?>" data-sort-value="<?= (float) $x['order_value'] ?>" data-sort-orders="<?= (int) $x['orders'] ?>"
                data-sort-rating="<?= (float) ($x['avg_rating'] ?? 0) ?>" data-sort-products="<?= (int) $x['products'] ?>">
              <td><strong><?= e($x['stall_name']) ?></strong><small class="d-block text-muted"><?= e($x['farmer_name']) ?><?= $x['active'] ? '' : ', inactive' ?></small></td>
              <td><?= e($x['markets'] ?: '-') ?></td>
              <td class="text-end"><?= $x['products'] ?></td>
              <td class="text-end"><?= $x['available_products'] ?> / <?= $x['sold_out_products'] ?></td>
              <?php if ($x['orders'] > 0): ?>
                <td class="text-end"><?= $x['orders'] ?></td><td class="text-end"><?= $x['completed'] ?></td><td class="text-end"><?= $x['cancelled'] ?></td>
                <td class="text-end"><?= e(money($x['order_value'])) ?></td><td class="text-end"><?= e(ra_money($x['avg_order_value'])) ?></td>
              <?php else: ?>
                <td colspan="5" class="text-muted text-center ra-muted">No sales data available for this Farmer.</td>
              <?php endif; ?>
              <td class="text-end"><?= e(ra_rating($x['avg_rating'])) ?></td>
              <td class="text-end"><?= $x['reviews'] ?: '-' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </section>
  </div>

  <!-- Product Analytics -->
  <div class="col-12">
    <section class="adm-card adm-panel">
      <header class="adm-panel-head"><div><h2 class="adm-h2">Product Analytics</h2><p class="adm-panel-sub">Products with the highest measured order value in the selected data (valid orders only) - not a quality ranking</p></div></header>
      <?php if (!$d['top_products']): ?>
        <div class="adm-empty"><p>No product order activity in the selected period.</p></div>
      <?php else: ?>
      <?= ra_table_tools('tfProducts', 'Search product, farmer or market', [
          'category' => ['Category', $uniq($d['top_products'], 'category')],
          'market'   => ['Market', $uniq($d['top_products'], 'market')],
          'stock'    => ['Stock', ['in' => 'In stock', 'out' => 'Sold out']],
      ], ['value:desc' => 'Highest order value', 'orders:desc' => 'Most orders', 'qty:desc' => 'Most quantity', 'price:asc' => 'Price low to high', 'price:desc' => 'Price high to low', 'name:asc' => 'Name A to Z'], count($d['top_products'])) ?>
      <div class="table-responsive">
        <table class="table adm-table ra-table" id="tfProducts">
          <thead><tr><th>Product</th><th>Category</th><th>Farmer</th><th>Market</th><th class="text-end">Price</th><th class="text-end">Stock</th><th class="text-end">Orders</th><th class="text-end">Qty Ordered</th><th class="text-end">Order Value</th><th class="text-end">Rating</th><th class="text-end">Reviews</th></tr></thead>
          <tbody>
          <?php foreach ($d['top_products'] as $p): ?>
            <tr data-tf-row data-tf-text="<?= ra_tf_text($p['name'], $p['category'] ?? '', $p['farmer'], $p['market'] ?? '') ?>"
                data-tf-category="<?= e(mb_strtolower((string) ($p['category'] ?? ''))) ?>" data-tf-market="<?= e(mb_strtolower((string) ($p['market'] ?? ''))) ?>" data-tf-stock="<?= $p['available'] ? 'in' : 'out' ?>"
                data-sort-name="<?= e(mb_strtolower($p['name'])) ?>" data-sort-value="<?= (float) $p['value'] ?>" data-sort-orders="<?= (int) $p['orders'] ?>"
                data-sort-qty="<?= (float) $p['qty'] ?>" data-sort-price="<?= (float) $p['price'] ?>">
              <td><strong><?= e($p['name']) ?></strong></td><td><?= e($p['category'] ?? '-') ?></td><td><?= e($p['farmer']) ?></td><td><?= e($p['market'] ?? '-') ?></td>
              <td class="text-end"><?= e(money($p['price'])) ?> <small>/<?= e($p['unit']) ?></small></td>
              <td class="text-end"><?= $p['available'] ? e(qty($p['stock']) . ' ' . $p['unit']) : '<span class="adm-badge">Sold out</span>' ?></td>
              <td class="text-end"><?= $p['orders'] ?></td><td class="text-end"><?= e(qty($p['qty']) . ' ' . $p['unit']) ?></td>
              <td class="text-end"><?= e(money($p['value'])) ?></td><td class="text-end"><?= e(ra_rating($p['avg_rating'])) ?></td><td class="text-end"><?= $p['reviews'] ?: '-' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="ra-note">Product ratings come from reviews left on orders that contained the product (reviews are written per order/farmer).</p>
      <?php endif; ?>
    </section>
  </div>

  <!-- Customer Analytics -->
  <div class="col-xl-5">
    <section class="adm-card adm-panel h-100">
      <header class="adm-panel-head"><div><h2 class="adm-h2">Customer Analytics</h2><p class="adm-panel-sub">New customers use the account registration date</p></div></header>
      <?php $c = $d['customers']; ?>
      <div class="ra-stat-row ra-stat-2">
        <?php foreach ([['Total Customers', number_format($c['total'])], ['New Customers (period)', number_format($c['new'])], ['Customers with Orders', number_format($c['with_orders'])],
                        ['With Completed Orders', number_format($c['with_completed'])], ['Orders Placed', number_format($c['orders'])], ['Avg Orders / Active Customer', $num($c['avg_orders'])]] as [$l, $v]): ?>
          <div class="ra-stat"><span><?= e($l) ?></span><strong><?= e($v) ?></strong></div>
        <?php endforeach; ?>
      </div>
      <?php if ($f['market_id'] || $f['farmer_id']): ?><p class="ra-note">Total and new customers are platform-wide; order-based figures follow the market/farmer filter.</p><?php endif; ?>
    </section>
  </div>

  <!-- Review & Rating Analytics -->
  <div class="col-xl-7">
    <section class="adm-card adm-panel h-100">
      <header class="adm-panel-head"><div><h2 class="adm-h2">Review &amp; Rating Analytics</h2><p class="adm-panel-sub">Visible reviews written in the period</p></div></header>
      <?php $r = $d['reviews']; ?>
      <?php if (!$r['total']): ?>
        <div class="adm-empty"><svg viewBox="0 0 24 24"><use href="#icon-star"/></svg><p>No reviews in the selected period.</p></div>
      <?php else: ?>
      <div class="ra-review-top">
        <div class="ra-stat"><span>Total Reviews</span><strong><?= number_format($r['total']) ?></strong></div>
        <div class="ra-stat"><span>Average Rating</span><strong><?= e(ra_rating($r['avg_rating'])) ?></strong></div>
        <?php foreach ($r['distribution'] as $star => $n): ?><div class="ra-stat ra-stat-sm"><span><?= $star ?>-star</span><strong><?= $n ?></strong></div><?php endforeach; ?>
      </div>
      <div class="adm-chart-box ra-chart-sm"><canvas id="ratingChart" role="img" aria-label="Rating distribution"></canvas></div>
      <?php endif; ?>
    </section>
  </div>

  <?php if ($r['total']): ?>
  <div class="col-12">
    <section class="adm-card adm-panel">
      <header class="adm-panel-head"><div><h2 class="adm-h2">Highest Review Activity</h2><p class="adm-panel-sub">Ranked by number of reviews in the period, then average rating - review volume is not a quality ranking</p></div></header>
      <div class="row g-4">
        <?php foreach (['Markets' => [$d['review_markets'], 'name'], 'Farmers' => [$d['review_farmers'], 'stall_name'], 'Products' => [$d['review_products'], 'name']] as $title => [$rows, $nameKey]): ?>
        <div class="col-lg-4">
          <h3 class="ra-h3"><?= $title ?></h3>
          <?php if (!$rows): ?><p class="adm-empty-inline">No review activity.</p><?php else: ?>
          <table class="table adm-table ra-table"><thead><tr><th><?= rtrim($title, 's') ?></th><th class="text-end">Reviews</th><th class="text-end">Avg</th></tr></thead><tbody>
            <?php foreach ($rows as $row): ?><tr><td><?= e($row[$nameKey]) ?></td><td class="text-end"><?= (int) $row['reviews'] ?></td><td class="text-end"><?= e(ra_rating($row['avg_rating'])) ?></td></tr><?php endforeach; ?>
          </tbody></table>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </section>
  </div>
  <?php endif; ?>
</div>
</div><!-- /ra-analytics -->

<script type="application/json" id="raData"><?= json_encode([
    'series'   => $d['series'],
    'status'   => ['Pending' => $o['pending'], 'Accepted' => $o['accepted'], 'Ready' => $o['ready'], 'Completed' => $o['completed'], 'Cancelled' => $o['cancelled']],
    'markets'  => array_map(fn($m) => ['name' => $m['name'], 'value' => $m['order_value'], 'orders' => $m['orders']], array_slice(array_values(array_filter($d['markets'], fn($m) => $m['orders'] > 0)), 0, 12)),
    'ratings'  => $d['reviews']['distribution'],
    'currency' => config('app.currency', 'Rs.'),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<!-- Generate report -->
<section class="adm-card adm-panel ra-generate ra-noprint" id="generate">
  <header class="adm-panel-head"><div><p class="kicker">REPORT GENERATION</p><h2 class="adm-h2">Generate Report</h2><p class="adm-panel-sub">Creates a stored report from the database for the chosen period and scope. Separate from the live analytics above.</p></div></header>
  <form method="post" class="ra-filter-grid ra-generate-grid" data-generate-form>
    <?= csrf_field() ?>
    <label><span class="adm-label">Report Type</span>
      <select class="form-select" name="report_type"><?php foreach (AR_TYPES as $key => $label): ?><option value="<?= $key ?>"><?= e($label) ?></option><?php endforeach; ?></select>
    </label>
    <label><span class="adm-label">Period</span>
      <select class="form-select" name="report_period" data-period-select><?php foreach (AR_PERIODS as $key => $label): ?><option value="<?= $key ?>" <?= $key === 'month' ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
    </label>
    <label><span class="adm-label" data-start-label>Start Date</span><input class="form-control" type="date" name="report_start" value="<?= date('Y-m-d') ?>" required></label>
    <label data-custom-date><span class="adm-label">End Date</span><input class="form-control" type="date" name="report_end" value="<?= date('Y-m-d') ?>"></label>
    <label><span class="adm-label">Market</span>
      <select class="form-select" name="report_market"><option value="0">All Markets</option><?php foreach ($opts['markets'] as $m): ?><option value="<?= (int) $m['id'] ?>"><?= e($m['name']) ?></option><?php endforeach; ?></select>
    </label>
    <label><span class="adm-label">Farmer</span>
      <select class="form-select" name="report_farmer"><option value="0">All Farmers</option><?php foreach ($opts['farmers'] as $v): ?><option value="<?= (int) $v['id'] ?>"><?= e($v['stall_name'] . ' - ' . $v['name']) ?></option><?php endforeach; ?></select>
    </label>
    <label class="ra-check"><input type="checkbox" name="with_ai" value="1" <?= groq_enabled() ? 'checked' : 'disabled' ?>> Add AI written summary<?= groq_enabled() ? '' : ' (no AI key configured)' ?></label>
    <div class="ra-filter-actions"><button type="submit" class="adm-btn" data-generate-btn><svg viewBox="0 0 24 24"><use href="#icon-receipt"/></svg> Generate Report</button></div>
  </form>
  <p class="ra-note">For Weekly / Monthly / Yearly reports the start date picks the week, month or year to report on.</p>
</section>

<!-- Generated report result -->
<?php if ($reportId && !$report): ?>
  <div class="adm-flash adm-flash-error">That report could not be found.</div>
<?php elseif ($report): $rd = $report['data']; $s = $rd['summary'] ?? []; ?>
<section class="adm-card adm-panel ra-report" id="report-result">
  <header class="adm-panel-head">
    <div>
      <p class="kicker">GENERATED REPORT #<?= (int) $report['report_id'] ?></p>
      <h2 class="adm-h2">MarketLink <?= e($rd['report_title'] ?? 'Report') ?></h2>
      <p class="adm-panel-sub"><?= e(($rd['period']['type'] ?? '') . ', ' . ($rd['period']['label'] ?? '')) ?>, <?= e(($rd['scope']['market'] ?? '') . ', ' . ($rd['scope']['farmer'] ?? '')) ?>, generated <?= e(fmt_date($report['generated_at'], 'M j, Y g:i A')) ?> by <?= e($report['generated_by_name'] ?? '-') ?></p>
    </div>
    <div class="adm-panel-tools ra-noprint">
      <button type="button" class="adm-btn-outline" data-print>Print / Save as PDF</button>
      <a class="adm-btn-outline" href="reports.php?report=<?= (int) $report['report_id'] ?>&amp;export=csv">Export CSV</a>
    </div>
  </header>

  <?php if (($s['total_orders'] ?? 0) === 0 && empty($rd['products'])): ?>
    <div class="adm-empty"><p>No analytics data available for the selected period.</p></div>
  <?php endif; ?>

  <div class="ra-stat-row">
    <?php
    $labels = ['total_orders' => 'Total Orders', 'completed_orders' => 'Completed', 'pending_orders' => 'Pending', 'accepted_orders' => 'Accepted',
               'ready_for_pickup' => 'Ready for Pickup', 'cancelled_orders' => 'Cancelled', 'order_value' => 'Order Value', 'average_order_value' => 'Average Order Value',
               'new_customers' => 'New Customers', 'new_farmers' => 'New Farmers', 'active_markets' => 'Active Markets (with orders)', 'average_monthly_orders' => 'Avg Monthly Orders',
               'reviews' => 'Reviews', 'average_rating' => 'Average Rating'];
    foreach ($labels as $key => $label): if (!array_key_exists($key, $s)) continue;
        $v = $s[$key];
        $out = $v === null ? '-' : (str_contains($key, 'value') ? money((float) $v) : ($key === 'average_rating' ? number_format((float) $v, 1) . ' / 5' : $num($v))); ?>
      <div class="ra-stat"><span><?= e($label) ?></span><strong><?= e($out) ?></strong></div>
    <?php endforeach; ?>
  </div>

  <?php
  $sections = [
      'activity' => ($rd['period']['granularity'] ?? '') === 'monthly buckets' ? 'Monthly Activity' : 'Daily Order Activity',
      'markets' => 'Market Performance', 'most_active_farmers' => 'Most Active Farmers (by order value)', 'farmers' => 'Farmer Performance',
      'farmer_activity' => 'Farmer Activity', 'top_products' => 'Top Products (highest measured order value)', 'products' => 'Product Report', 'orders' => 'Order Report',
  ];
  foreach ($sections as $key => $title): if (!isset($rd[$key])) continue; ?>
    <h3 class="ra-h3"><?= e($title) ?> <small>(<?= count($rd[$key]) ?>)</small></h3>
    <?= ra_report_table($rd[$key]) ?>
  <?php endforeach; ?>
  <p class="ra-note"><?= e($rd['definitions']['order_value'] ?? '') ?> <?= e($rd['definitions']['order_market'] ?? '') ?></p>
</section>

<!-- Ai report summary -->
<section class="adm-card adm-panel ra-ai" id="ai-summary">
  <header class="adm-panel-head"><div><p class="kicker">AI</p><h2 class="adm-h2">AI Report Summary</h2><p class="adm-panel-sub">Written by AI from the report figures above only. Always check against the database report.</p></div></header>
  <?php if ($report['ai_summary']): ?>
    <div class="ra-ai-body"><?= ar_render_ai($report['ai_summary']) ?></div>
  <?php else: ?>
    <div class="adm-flash adm-flash-info">AI summary is temporarily unavailable. The database report is still available.</div>
  <?php endif; ?>
</section>
<?php endif; ?>

<!-- Report history -->
<section class="adm-card adm-panel ra-noprint" id="history">
  <header class="adm-panel-head"><div><h2 class="adm-h2">Report History</h2><p class="adm-panel-sub">Previously generated reports (stored with their data at generation time)</p></div></header>
  <?php if (!$history): ?>
    <div class="adm-empty"><svg viewBox="0 0 24 24"><use href="#icon-receipt"/></svg><p>No reports generated yet.</p></div>
  <?php else: ?>
  <?php
  $usedTypes = array_intersect_key(AR_TYPES, array_flip(array_column($history, 'report_type')));
  $usedPeriods = array_intersect_key(AR_PERIODS, array_flip(array_column($history, 'period_type')));
  ?>
  <?= ra_table_tools('tfHistory', 'Search by #, type, market, farmer or admin', [
      'type'   => ['Type', array_combine(array_map('strtolower', array_keys($usedTypes)), $usedTypes) ?: []],
      'period' => ['Period', array_combine(array_map('strtolower', array_keys($usedPeriods)), $usedPeriods) ?: []],
      'ai'     => ['AI summary', ['yes' => 'With AI summary', 'no' => 'Without AI summary']],
      'when'   => ['Generated', ['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days']],
  ], ['date:desc' => 'Newest first', 'date:asc' => 'Oldest first'], count($history)) ?>
  <div class="table-responsive">
    <table class="table adm-table ra-table" id="tfHistory">
      <thead><tr><th>#</th><th>Report Type</th><th>Period</th><th>Market</th><th>Farmer</th><th>Generated By</th><th>Generated</th><th>AI</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($history as $h): $hid = (int) $h['report_id']; $ageDays = (int) floor((time() - strtotime($h['generated_at'])) / 86400); ?>
        <tr<?= $report && $hid === (int) $report['report_id'] ? ' class="ra-current"' : '' ?> data-tf-row
            data-tf-text="<?= ra_tf_text('#' . $hid, AR_TYPES[$h['report_type']] ?? $h['report_type'], AR_PERIODS[$h['period_type']] ?? $h['period_type'], $h['market_name'] ?? 'All Markets', $h['farmer_name'] ?? 'All Farmers', $h['generated_by_name'] ?? '') ?>"
            data-tf-type="<?= e(strtolower($h['report_type'])) ?>" data-tf-period="<?= e(strtolower($h['period_type'])) ?>" data-tf-ai="<?= $h['has_ai'] ? 'yes' : 'no' ?>"
            data-tf-when-days="<?= $ageDays ?>" data-sort-date="<?= strtotime($h['generated_at']) ?>">
          <td><?= $hid ?></td>
          <td><?= e(AR_TYPES[$h['report_type']] ?? $h['report_type']) ?></td>
          <td><?= e(AR_PERIODS[$h['period_type']] ?? $h['period_type']) ?><small class="d-block text-muted"><?= e(fmt_date($h['start_date']) . ' - ' . fmt_date($h['end_date'])) ?></small></td>
          <td><?= e($h['market_name'] ?? 'All Markets') ?></td>
          <td><?= e($h['farmer_name'] ?? 'All Farmers') ?></td>
          <td><?= e($h['generated_by_name'] ?? '-') ?></td>
          <td><?= e(fmt_date($h['generated_at'], 'M j, Y g:i A')) ?></td>
          <td><?= $h['has_ai'] ? '<span class="adm-badge approved">Yes</span>' : '<span class="text-muted">No</span>' ?></td>
          <td class="text-end ra-actions">
            <a href="reports.php?report=<?= $hid ?>#report-result">View</a>
            <a href="reports.php?report=<?= $hid ?>&amp;print=1#report-result">Print</a>
            <a href="reports.php?report=<?= $hid ?>&amp;export=csv">Export</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
