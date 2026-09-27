<?php
/** Customer's orders with tabs and search */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/orders.php';
require_once APP_ROOT . '/includes/partials/customer_layout.php';

$user = require_customer_dashboard();
$uid = (int) $user['id'];

$filters = ['' => 'All', 'active' => 'Active', 'completed' => 'Completed', 'closed' => 'Cancelled / Rejected'];
$filter = isset($filters[$_GET['status'] ?? '']) ? ($_GET['status'] ?? '') : '';
$q = ltrim(str_input($_GET, 'q', 40), '#');
$groups = [
    'active'    => "o.status IN ('pending','accepted','preparing','ready','picked_up')",
    'completed' => "o.status = 'completed'",
    'closed'    => "o.status IN ('cancelled','declined')",
];

$base = ['o.customer_id = ?'];
$params = [$uid];
if ($q !== '') {
    $base[] = '(o.order_number LIKE ? OR v.stall_name LIKE ?)';
    array_push($params, "%$q%", "%$q%");
}
$where = $base;
if ($filter) $where[] = $groups[$filter];

// Tab counts (respecting the search)
$countRow = db_one('SELECT COUNT(*) AS all_n, ' . implode(', ', array_map(fn($k, $sql) => "SUM($sql) AS $k", array_keys($groups), $groups)) . '
                    FROM orders o JOIN vendors v ON v.id = o.vendor_id WHERE ' . implode(' AND ', $base), $params);
$tabCount = ['' => (int) $countRow['all_n'], 'active' => (int) $countRow['active'], 'completed' => (int) $countRow['completed'], 'closed' => (int) $countRow['closed']];

$perPage = 10;
$total = $tabCount[$filter];
$pages = max(1, (int) ceil($total / $perPage));
$page = min(max(1, int_input($_GET, 'page', 1)), $pages);
$orders = db_all('SELECT o.*, v.stall_name, v.id AS vid, r.id AS review_id,
                         (SELECT GROUP_CONCAT(CONCAT(oi.product_name, " x ", TRIM(TRAILING "." FROM TRIM(TRAILING "0" FROM oi.quantity)), " ", oi.unit) SEPARATOR ", ") FROM order_items oi WHERE oi.order_id = o.id) AS summary
                  FROM orders o JOIN vendors v ON v.id = o.vendor_id LEFT JOIN reviews r ON r.order_id = o.id
                  WHERE ' . implode(' AND ', $where) . ' ORDER BY o.created_at DESC, o.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $params);
$placed = int_input($_GET, 'placed', 0);

$link = function (array $over) use ($filter, $q): string {
    $args = array_merge(['status' => $filter, 'q' => $q, 'page' => null], $over);
    if (($args['page'] ?? null) === 1) $args['page'] = null;
    return url('customer/orders.php?' . http_build_query(array_filter($args, fn($v) => $v !== null && $v !== '')));
};

customer_layout_start([
    'title' => 'My Orders', 'active' => 'orders',
    'heading' => 'My Orders', 'subheading' => 'Track your pre-orders, choose pickup slots and review your farmers.',
]);
?>

<?php if ($placed): ?>
  <div class="alert alert-success" role="status">Your pre-order has been placed. The farmer will confirm it shortly.</div>
<?php endif; ?>

<article class="card card-flush">
  <div class="card-head co-orders-head">
    <div class="tabs co-tabs" role="tablist" aria-label="Filter orders">
      <?php foreach ($filters as $k => $label): ?>
        <a role="tab" class="tab<?= $k === $filter ? ' active' : '' ?>" aria-selected="<?= $k === $filter ? 'true' : 'false' ?>" href="<?= e($link(['status' => $k])) ?>"><?= e($label) ?> <span class="tab-count"><?= $tabCount[$k] ?></span></a>
      <?php endforeach; ?>
    </div>
    <form class="co-search" method="get" role="search">
      <?php if ($filter): ?><input type="hidden" name="status" value="<?= e($filter) ?>"><?php endif; ?>
      <svg><use href="#i-search"/></svg>
      <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Order # or stall" aria-label="Search orders">
    </form>
  </div>

  <?php if (!$orders): ?>
    <?= empty_state('i-bag', $q !== '' ? 'No orders match your search' : ($filter ? 'No orders here' : 'No orders yet'), 'Find a market near you and reserve fresh stock for pickup.', '<a class="btn btn-primary" href="' . e(url('markets.php')) . '">Browse markets</a>') ?>
  <?php else: ?>
    <div class="co-order-list">
      <?php foreach ($orders as $o): $tl = order_timeline($o); ?>
        <article class="co-order<?= (int) $o['id'] === $placed ? ' highlight' : '' ?>" id="order-<?= (int) $o['id'] ?>">
          <div class="co-order-head">
            <div class="co-order-id">
              <span class="thumb"><svg><use href="#i-store"/></svg></span>
              <div>
                <strong>Order #<?= e($o['order_number']) ?></strong>
                <small><a href="<?= e(url('market.php?id=' . $o['vid'])) ?>"><?= e($o['stall_name']) ?></a>, placed <?= e(fmt_date($o['created_at'], 'M j, Y')) ?></small>
              </div>
            </div>
            <div class="co-order-amt">
              <strong><?= e(money($o['total_amount'])) ?></strong>
              <span class="pill pill-<?= e($o['status']) ?>"><?= e(customer_status_label($o['status'])) ?></span>
            </div>
          </div>

          <p class="co-order-summary"><?= e(mb_strimwidth((string) $o['summary'], 0, 160, '...')) ?></p>

          <ol class="co-timeline" aria-label="Order progress">
            <?php foreach ($tl as $i => $s): ?>
              <li class="<?= e($s['state']) ?>"><i aria-hidden="true"><?php if ($s['state'] === 'done'): ?><svg><use href="#i-check"/></svg><?php elseif ($s['state'] === 'stopped'): ?><svg><use href="#i-x"/></svg><?php else: ?><?= $i + 1 ?><?php endif; ?></i><span><?= e($s['label']) ?></span></li>
            <?php endforeach; ?>
          </ol>

          <?php if (in_array($o['status'], ['cancelled', 'declined'], true) && $o['cancel_reason']): ?><div class="od-reason">Reason: <?= e($o['cancel_reason']) ?></div><?php endif; ?>

          <div class="co-order-foot">
            <span class="co-pickup"><svg><use href="#i-calendar"/></svg><?= $o['pickup_date'] ? e(pickup_label($o['pickup_date'], $o['pickup_start'], $o['pickup_end'])) : 'Pickup slot not chosen yet' ?></span>
            <div class="row-actions">
              <?php if ($o['status'] === 'completed' && !$o['review_id']): ?><a class="btn btn-soft btn-sm" href="<?= e(url('order.php?id=' . $o['id'])) ?>"><svg><use href="#i-star"/></svg>Write review</a><?php endif; ?>
              <?php if (in_array($o['status'], PICKUP_SELECTABLE, true) && !$o['pickup_date']): ?><a class="btn btn-soft btn-sm" href="<?= e(url('order.php?id=' . $o['id'])) ?>#pickup"><svg><use href="#i-clock"/></svg>Choose pickup slot</a><?php endif; ?>
              <?php if ($o['status'] === 'ready'): ?><a class="btn btn-soft btn-sm" href="<?= e(url('order.php?id=' . $o['id'])) ?>"><svg><use href="#i-check"/></svg>Confirm pickup</a><?php endif; ?>
              <?php if (in_array($o['status'], ['completed', 'cancelled', 'declined'], true)): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('market.php?id=' . $o['vid'])) ?>"><svg><use href="#i-refresh"/></svg>Reorder</a><?php endif; ?>
              <a class="btn btn-primary btn-sm" href="<?= e(url('order.php?id=' . $o['id'])) ?>">View details <svg><use href="#i-arrow"/></svg></a>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>

    <?php if ($pages > 1): ?>
      <nav class="co-pager" aria-label="Pages">
        <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="<?= e($link(['page' => $page - 1])) ?>">Previous</a><?php endif; ?>
        <span class="muted">Page <?= $page ?> of <?= $pages ?></span>
        <?php if ($page < $pages): ?><a class="btn btn-ghost btn-sm" href="<?= e($link(['page' => $page + 1])) ?>">Next</a><?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</article>

<?php customer_layout_end();
