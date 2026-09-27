<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once APP_ROOT . '/includes/partials/vendor_layout.php';
require_once APP_ROOT . '/includes/services/markets.php';

$vendor = require_vendor();
$vid = (int) $vendor['id'];

$markets = public_markets();
// this vendor's assignments, including ones an admin suspended or in markets that are now closed
$mine = [];
foreach (db_all('SELECT fm.market_id, fm.status, fm.assigned_at,
                        (SELECT COUNT(*) FROM products p WHERE p.vendor_id = fm.vendor_id AND p.market_id = fm.market_id AND p.deleted_at IS NULL) AS products
                   FROM farmer_markets fm WHERE fm.vendor_id = ?', [$vid]) as $r) {
    $mine[(int) $r['market_id']] = $r;
}
$closedMine = $mine ? db_all(
    "SELECT m.id, m.name, m.status FROM markets m WHERE m.status <> 'active' AND m.id IN (" . implode(',', array_keys($mine)) . ')'
) : [];
$types = market_types_all(true);
$joinedCount = count(array_filter($markets, fn($m) => isset($mine[$m['id']])));

vendor_layout_start([
    'title' => 'My Markets', 'active' => 'markets',
    'heading' => 'My Markets', 'subheading' => 'Register your stall in the markets you want to sell in.',
]);
?>
<div class="page-head">
  <div><h2>Markets on MarketLink</h2><p><?= $joinedCount ? "Your stall is in $joinedCount market" . ($joinedCount === 1 ? '' : 's') . '.' : 'Your stall is not in any market yet - join one so customers can find your products.' ?></p></div>
  <div class="page-actions"><a class="btn btn-ghost" href="<?= e(url('vendor/products.php')) ?>"><svg><use href="#i-box"/></svg>My Products</a></div>
</div>

<?php foreach ($closedMine as $c): ?>
  <div class="card" style="margin-bottom:14px;padding:14px 18px"><strong><?= e($c['name']) ?></strong> is currently <?= e($c['status']) ?> by the admin, so customers can't see it right now.</div>
<?php endforeach; ?>

<div class="toolbar" role="search">
  <div class="search-input"><svg><use href="#i-search"/></svg>
    <input class="input" type="search" id="vmQ" placeholder="Search markets by name, city or type..." aria-label="Search markets"></div>
  <select class="select" id="vmType" aria-label="Filter by market type">
    <option value="">All market types</option>
    <?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>"><?= e(trim(($t['icon'] ?? '') . ' ' . $t['name'])) ?></option><?php endforeach; ?>
  </select>
  <select class="select" id="vmShow" aria-label="Show">
    <option value="">All markets</option>
    <option value="joined">Joined only</option>
    <option value="open">Not joined</option>
  </select>
</div>

<?php if (!$markets): ?>
  <div class="card"><div class="empty"><div class="empty-icon"><svg><use href="#i-store"/></svg></div><h4>No markets yet</h4><p>The MarketLink team hasn't opened any markets yet. Check back soon.</p></div></div>
<?php else: ?>
<div class="vm-grid" id="vmGrid">
  <?php foreach ($markets as $m):
      $a = $mine[$m['id']] ?? null;
      $search = mb_strtolower($m['name'] . ' ' . $m['city'] . ' ' . $m['area'] . ' ' . $m['type_name']); ?>
    <article class="card vm-card<?= $a ? ' is-joined' : '' ?>" data-id="<?= $m['id'] ?>" data-type="<?= $m['market_type_id'] ?>" data-joined="<?= $a ? 1 : 0 ?>" data-search="<?= e($search) ?>">
      <div class="vm-media"<?= $m['image'] ? ' style="background-image:url(\'' . e($m['image']) . '\')"' : '' ?>><?= $m['image'] ? '' : '<span><svg><use href="#i-store"/></svg></span>' ?></div>
      <div class="vm-body">
        <span class="p-cat"><?= e($m['type_name']) ?></span>
        <h3><?= e($m['name']) ?></h3>
        <p class="muted"><svg><use href="#i-pin"/></svg><?= e($m['location']) ?></p>
        <?php if ($m['hours']): ?><p class="muted"><svg><use href="#i-clock"/></svg><?= e($m['hours'] . ($m['days_label'] ? ', ' . $m['days_label'] : '')) ?></p><?php endif; ?>
        <p class="vm-stats"><?= $m['vendors'] ?> stall<?= $m['vendors'] === 1 ? '' : 's' ?>, <?= $m['products'] ?> product<?= $m['products'] === 1 ? '' : 's' ?></p>
      </div>
      <div class="vm-foot">
        <?php if ($a && $a['status'] === 'suspended'): ?>
          <span class="pill pill-declined">Suspended by admin</span>
        <?php elseif ($a): ?>
          <span class="pill pill-completed">Your stall is here, <?= (int) $a['products'] ?> product<?= (int) $a['products'] === 1 ? '' : 's' ?></span>
          <button type="button" class="btn btn-ghost btn-sm" data-leave>Leave</button>
        <?php else: ?>
          <button type="button" class="btn btn-primary btn-sm" data-join><svg><use href="#i-plus"/></svg>Register my stall</button>
        <?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
</div>
<div class="card" id="vmNone" hidden><div class="empty"><h4>No markets match</h4><p>Try a different search or filter.</p></div></div>
<?php endif; ?>

<?php vendor_layout_end(['scripts' => ['assets/js/vendor/markets.js']]);
