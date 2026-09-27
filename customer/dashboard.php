<?php
/** Customer dashboard home */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/favorites.php';
require_once APP_ROOT . '/includes/services/orders.php';
require_once APP_ROOT . '/includes/services/ai_features.php';
require_once APP_ROOT . '/includes/services/notifications.php';
require_once APP_ROOT . '/includes/partials/customer_layout.php';

$user = require_customer_dashboard();
$uid = (int) $user['id'];

$stats = db_one("SELECT COUNT(*) AS total,
                        SUM(status IN ('pending','accepted','preparing','ready','picked_up')) AS active,
                        SUM(status = 'completed') AS completed,
                        SUM(status IN ('cancelled','declined')) AS closed,
                        COALESCE(SUM(CASE WHEN status = 'completed' THEN total_amount END), 0) AS spent,
                        COALESCE(SUM(CASE WHEN status = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN total_amount END), 0) AS spent_30
                 FROM orders WHERE customer_id = ?", [$uid]);
$stalls = (int) db_value("SELECT COUNT(DISTINCT vendor_id) FROM orders WHERE customer_id = ? AND status = 'completed'", [$uid]);

$active = db_all("SELECT o.*, v.stall_name, v.id AS vid,
                         (SELECT COUNT(*) FROM order_items i WHERE i.order_id = o.id) AS item_count
                  FROM orders o JOIN vendors v ON v.id = o.vendor_id
                  WHERE o.customer_id = ? AND o.status IN ('pending','accepted','preparing','ready','picked_up')
                  ORDER BY o.pickup_date IS NULL, o.pickup_date, o.pickup_start LIMIT 4", [$uid]);
$recent = db_all('SELECT o.*, v.stall_name, v.id AS vid, r.id AS review_id,
                         (SELECT COUNT(*) FROM order_items i WHERE i.order_id = o.id) AS item_count
                  FROM orders o JOIN vendors v ON v.id = o.vendor_id LEFT JOIN reviews r ON r.order_id = o.id
                  WHERE o.customer_id = ? ORDER BY o.created_at DESC LIMIT 6', [$uid]);

favorites_ensure_table();
$favCount = (int) db_value('SELECT COUNT(*) FROM favorites WHERE customer_id = ?', [$uid]);
$favVendors = db_all("SELECT v.id, v.stall_name, v.image, v.operating_days,
                             (SELECT ROUND(AVG(rating),1) FROM reviews r WHERE r.vendor_id = v.id AND r.status = 'visible') AS rating
                      FROM favorites f JOIN vendors v ON v.id = f.item_id AND v.is_active = 1
                      WHERE f.customer_id = ? AND f.item_type = 'vendor' ORDER BY f.created_at DESC LIMIT 4", [$uid]);

$lastChance = array_slice(customer_last_chance($uid), 0, 4);
$notifications = notifications_list($uid, 'all', 5);

$steps = ['pending' => 'Placed', 'accepted' => 'Accepted', 'preparing' => 'Preparing', 'ready' => 'Ready', 'picked_up' => 'Picked up', 'completed' => 'Collected'];
$stepKeys = array_keys($steps);

// Getting-started hints, only while something is missing.
$setup = [];
if (!(int) $stats['total']) $setup[] = ['i-store', 'Place your first pre-order', 'Find a market near you', 'markets.php'];
if (!$favCount) $setup[] = ['i-heart', 'Save a favourite stall', 'Tap the heart on any stall', 'markets.php'];
if (!$user['phone']) $setup[] = ['i-user', 'Add your phone number', 'Farmers can reach you at pickup', 'customer/profile.php'];

customer_layout_start(['title' => 'Dashboard', 'active' => 'dashboard']);
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
  <a class="card stat-card fade-in" href="<?= e(url('customer/orders.php')) ?>">
    <div class="stat-top"><span class="stat-label">Total Orders</span><span class="stat-icon"><svg><use href="#i-bag"/></svg></span></div>
    <div class="stat-value"><?= number_format((int) $stats['total']) ?></div>
    <div class="stat-foot"><?= (int) $stats['closed'] ?> cancelled or rejected</div>
  </a>
  <a class="card stat-card fade-in" style="animation-delay:.05s" href="<?= e(url('customer/orders.php?status=active')) ?>">
    <div class="stat-top"><span class="stat-label">Active Pre-Orders</span><span class="stat-icon"><svg><use href="#i-clock"/></svg></span></div>
    <div class="stat-value"><?= number_format((int) $stats['active']) ?></div>
    <div class="stat-foot"><?= (int) $stats['active'] ? 'Waiting for pickup' : 'Nothing in progress' ?></div>
  </a>
  <a class="card stat-card fade-in" style="animation-delay:.1s" href="<?= e(url('customer/orders.php?status=completed')) ?>">
    <div class="stat-top"><span class="stat-label">Collected</span><span class="stat-icon"><svg><use href="#i-check"/></svg></span></div>
    <div class="stat-value"><?= number_format((int) $stats['completed']) ?></div>
    <div class="stat-foot">From <?= $stalls ?> local stall<?= $stalls === 1 ? '' : 's' ?></div>
  </a>
  <article class="card stat-card featured fade-in" style="animation-delay:.15s">
    <div class="stat-top"><span class="stat-label">Spent Locally</span><span class="stat-icon"><svg><use href="#i-money"/></svg></span></div>
    <div class="stat-value"><?= e(money($stats['spent'])) ?></div>
    <div class="stat-foot"><?= e(money($stats['spent_30'])) ?> in the last 30 days</div>
  </article>
</section>

<section class="grid grid-main mt">
  <article class="card">
    <div class="card-head">
      <div><h3 class="card-title">Active Pre-Orders</h3><p class="card-sub">Track each reservation until pickup</p></div>
      <a class="link" href="<?= e(url('customer/orders.php?status=active')) ?>">View all <svg><use href="#i-arrow"/></svg></a>
    </div>
    <?php if (!$active): ?>
      <?= empty_state('i-bag', 'No active pre-orders', 'Find a market near you and reserve fresh stock for pickup.', '<a class="btn btn-primary" href="' . e(url('markets.php')) . '">Find markets</a>') ?>
    <?php else: ?>
      <div class="co-track-list">
        <?php foreach ($active as $o): $idx = array_search($o['status'], $stepKeys, true); $pct = $idx === false ? 0 : round($idx / (count($stepKeys) - 1) * 100); ?>
          <div class="co-track">
            <div class="co-track-top">
              <div class="co-track-id">
                <strong><?= e($o['stall_name']) ?></strong>
                <small>#<?= e($o['order_number']) ?>, <?= (int) $o['item_count'] ?> item<?= (int) $o['item_count'] === 1 ? '' : 's' ?>, <?= e(money($o['total_amount'])) ?></small>
              </div>
              <span class="pill pill-<?= e($o['status']) ?>"><?= e(customer_status_label($o['status'])) ?></span>
            </div>
            <div class="co-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $pct ?>" aria-label="Order progress"><i style="width:<?= $pct ?>%"></i></div>
            <div class="co-steps">
              <?php foreach ($steps as $k => $label): $i = array_search($k, $stepKeys, true); ?>
                <span class="<?= $idx !== false && $i <= $idx ? 'done' : '' ?><?= $i === $idx ? ' now' : '' ?>"><?= e($label) ?></span>
              <?php endforeach; ?>
            </div>
            <div class="co-track-foot">
              <span class="co-pickup"><svg><use href="#i-calendar"/></svg><?= e(pickup_label($o['pickup_date'], $o['pickup_start'], $o['pickup_end']) ?: 'Pickup slot not chosen yet') ?></span>
              <a class="btn btn-ghost btn-sm" href="<?= e(url('order.php?id=' . $o['id'])) ?>">Track order</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </article>

  <article class="card">
    <div class="card-head">
      <div><h3 class="card-title">Stock Alerts</h3><p class="card-sub">Items you buy that are running low</p></div>
      <?php if ($lastChance): ?><a class="link" href="<?= e(url('customer/stock-alerts.php')) ?>">All <svg><use href="#i-arrow"/></svg></a><?php endif; ?>
    </div>
    <?php if (!$lastChance): ?>
      <?= empty_state('i-check', 'Nothing urgent', 'When stock of items you usually buy runs low, you will see it here before market day.') ?>
    <?php else: ?>
      <div class="rank-list">
        <?php foreach ($lastChance as $p): ?>
          <a class="rank-item" href="<?= e(url('market.php?id=' . $p['vendor_id'])) ?>">
            <span class="thumb"<?= !empty($p['image']) ? ' style="background-image:url(\'' . e(upload_url($p['image'])) . '\')"' : '' ?>><?= !empty($p['image']) ? '' : '<svg><use href="#i-sprout"/></svg>' ?></span>
            <div class="rank-body" style="min-width:0">
              <strong><?= e($p['name']) ?></strong>
              <p class="muted co-sub"><?= e($p['stall_name']) ?></p>
            </div>
            <div class="rank-val"><span class="co-low"><?= e(qty($p['quantity'])) ?> <?= e($p['unit']) ?> left</span><small><?= e(money($p['price'])) ?> / <?= e($p['unit']) ?></small></div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </article>
</section>

<section class="mt">
  <article class="card card-flush">
    <div class="card-head">
      <div><h3 class="card-title">Recent Orders</h3><p class="card-sub">Your latest pre-orders</p></div>
      <a class="link" href="<?= e(url('customer/orders.php')) ?>">View all <svg><use href="#i-arrow"/></svg></a>
    </div>
    <?php if (!$recent): ?>
      <?= empty_state('i-clock', 'No orders yet', 'Your pre-orders will show up here.') ?>
    <?php else: ?>
      <div class="table-wrap" style="margin-top:14px">
        <table class="table table-cards">
          <thead><tr><th>Order</th><th>Stall</th><th class="num">Items</th><th>Pickup</th><th class="num">Total</th><th>Status</th><th class="num">Actions</th></tr></thead>
          <tbody>
          <?php foreach ($recent as $o): ?>
            <tr>
              <td data-label="Order" class="cell-strong">#<?= e($o['order_number']) ?><br><small class="muted"><?= e(fmt_date($o['created_at'], 'M j, Y')) ?></small></td>
              <td data-label="Stall"><a href="<?= e(url('market.php?id=' . $o['vid'])) ?>"><?= e($o['stall_name']) ?></a></td>
              <td data-label="Items" class="num"><?= (int) $o['item_count'] ?></td>
              <td data-label="Pickup" class="cell-muted nowrap"><?= $o['pickup_date'] ? e(fmt_date($o['pickup_date'], 'D, M j')) : 'Not set' ?></td>
              <td data-label="Total" class="num cell-strong nowrap"><?= e(money($o['total_amount'])) ?></td>
              <td data-label="Status"><span class="pill pill-<?= e($o['status']) ?>"><?= e(customer_status_label($o['status'])) ?></span></td>
              <td data-label="Actions"><div class="row-actions">
                <?php if ($o['status'] === 'completed' && !$o['review_id']): ?>
                  <a class="btn btn-soft btn-sm" href="<?= e(url('order.php?id=' . $o['id'])) ?>"><svg><use href="#i-star"/></svg>Review</a>
                <?php elseif (in_array($o['status'], ['completed', 'cancelled', 'declined'], true)): ?>
                  <a class="btn btn-ghost btn-sm" href="<?= e(url('market.php?id=' . $o['vid'])) ?>"><svg><use href="#i-refresh"/></svg>Reorder</a>
                <?php else: ?>
                  <a class="btn btn-ghost btn-sm" href="<?= e(url('order.php?id=' . $o['id'])) ?>">Track</a>
                <?php endif; ?>
              </div></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </article>
</section>

<section class="grid grid-2 mt">
  <article class="card">
    <div class="card-head">
      <div><h3 class="card-title">Favorite Stalls</h3><p class="card-sub"><?= $favCount ?> saved item<?= $favCount === 1 ? '' : 's' ?></p></div>
      <a class="link" href="<?= e(url('customer/favorites.php')) ?>">Manage <svg><use href="#i-arrow"/></svg></a>
    </div>
    <?php if (!$favVendors): ?>
      <?= empty_state('i-heart', 'No favorite stalls yet', 'Tap the heart on any stall to save it here.') ?>
    <?php else: foreach ($favVendors as $v): $img = upload_url($v['image']); ?>
      <a class="low-item" href="<?= e(url('market.php?id=' . $v['id'])) ?>">
        <span class="thumb"<?= $img ? ' style="background-image:url(\'' . e($img) . '\')"' : '' ?>><?= $img ? '' : '<svg><use href="#i-store"/></svg>' ?></span>
        <div style="flex:1;min-width:0"><strong><?= e($v['stall_name']) ?></strong><p class="muted co-sub"><?= e(days_short_label(days_from_set($v['operating_days']))) ?></p></div>
        <span class="co-rating"><?php if ($v['rating']): ?><svg><use href="#i-star"/></svg><?= e($v['rating']) ?><?php else: ?>New<?php endif; ?></span>
      </a>
    <?php endforeach; endif; ?>
  </article>

  <article class="card">
    <div class="card-head">
      <div><h3 class="card-title">Latest Notifications</h3><p class="card-sub">Order and pickup updates</p></div>
      <a class="link" href="<?= e(url('customer/notifications.php')) ?>">View all <svg><use href="#i-arrow"/></svg></a>
    </div>
    <?php if (!$notifications): ?>
      <?= empty_state('i-bell', 'No notifications', 'Order confirmations and pickup updates will appear here.') ?>
    <?php else: foreach ($notifications as $n): ?>
      <a class="low-item co-notif<?= $n['is_read'] ? '' : ' unread' ?>" href="<?= e($n['link'] ? url($n['link']) : url('customer/notifications.php')) ?>">
        <span class="stat-icon"><svg><use href="#<?= e(notification_icon((string) $n['type'])) ?>"/></svg></span>
        <div style="flex:1;min-width:0"><strong><?= e($n['title']) ?></strong><p class="muted co-sub"><?= e(mb_strimwidth((string) $n['message'], 0, 90, '...')) ?></p></div>
        <small class="muted" style="white-space:nowrap"><?= e(time_ago($n['created_at'])) ?></small>
      </a>
    <?php endforeach; endif; ?>
  </article>
</section>

<?php customer_layout_end();
