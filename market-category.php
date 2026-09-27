<?php
/** One market category (like Fruit Market) and its stalls */
require_once __DIR__ . '/includes/bootstrap.php';
require_once APP_ROOT . '/includes/partials/site_layout.php';
require_once APP_ROOT . '/includes/partials/components.php';
require_once APP_ROOT . '/includes/services/favorites.php';

$id = int_input($_GET, 'id', 0);
$cat = $id ? db_one('SELECT * FROM market_types WHERE id = ? AND is_active = 1', [$id]) : null;
if (!$cat) {
    http_response_code(404);
    site_layout_start(['title' => 'Market not found', 'active' => 'markets']);
    echo '<div class="card">' . empty_state('i-store', 'Market category not found', 'This market is not available right now.', '<a class="btn btn-primary" href="' . e(url('markets.php')) . '">Browse markets</a>') . '</div>';
    site_layout_end();
    exit;
}
$q = str_input($_GET, 'q', 80);
$params = [$id];
$search = '';
if ($q !== '') {
    $search = ' AND (v.stall_name LIKE ? OR v.description LIKE ? OR EXISTS (SELECT 1 FROM products p2 WHERE p2.vendor_id = v.id AND p2.deleted_at IS NULL AND p2.moderation_status = "active" AND p2.name LIKE ?))';
    array_push($params, "%$q%", "%$q%", "%$q%");
}
$sorts = ['products' => 'products DESC, v.stall_name', 'rating' => 'rating IS NULL, rating DESC, reviews DESC, v.stall_name', 'name' => 'v.stall_name'];
$sort = isset($sorts[$_GET['sort'] ?? '']) ? $_GET['sort'] : 'products';
$perPage = 24;
$page = max(1, int_input($_GET, 'page', 1));
$totals = db_one(
    "SELECT COUNT(*) AS stalls,
            COALESCE(SUM((SELECT COUNT(*) FROM products p WHERE p.vendor_id = v.id AND p.deleted_at IS NULL AND p.moderation_status = 'active')), 0) AS products
       FROM farmer_categories fc
       JOIN vendors v ON v.id = fc.vendor_id AND v.is_active = 1
       JOIN users u   ON u.id = v.user_id AND u.is_active = 1 AND u.approval_status = 'approved'
      WHERE fc.market_type_id = ?", [$id]);
$stalls = db_all(
    "SELECT v.id, v.stall_name, v.description, v.image, v.operating_days, v.pickup_start, v.pickup_end, v.location_address, v.address,
            (SELECT COUNT(*) FROM products p WHERE p.vendor_id = v.id AND p.deleted_at IS NULL AND p.moderation_status = 'active') AS products,
            (SELECT ROUND(AVG(r.rating), 1) FROM reviews r WHERE r.vendor_id = v.id AND r.status = 'visible') AS rating,
            (SELECT COUNT(*) FROM reviews r WHERE r.vendor_id = v.id AND r.status = 'visible') AS reviews
       FROM farmer_categories fc
       JOIN vendors v ON v.id = fc.vendor_id AND v.is_active = 1
       JOIN users u   ON u.id = v.user_id AND u.is_active = 1 AND u.approval_status = 'approved'
      WHERE fc.market_type_id = ?$search
      ORDER BY {$sorts[$sort]}
      LIMIT " . ($perPage + 1) . ' OFFSET ' . (($page - 1) * $perPage), $params);
$hasMore = count($stalls) > $perPage;
$stalls = array_slice($stalls, 0, $perPage);
$img = upload_url($cat['image']);

$pageUrl = function (array $over) use ($id, $q, $sort, $page): string {
    $args = array_merge(['id' => $id, 'q' => $q, 'sort' => $sort === 'products' ? '' : $sort, 'page' => $page > 1 ? $page : ''], $over);
    return url('market-category.php?' . http_build_query(array_filter($args, fn($x) => $x !== '' && $x !== null)));
};

ob_start(); ?>
  <div class="cd-hero-stats mcat-hero-stats">
    <div class="cd-hero-stat"><b><?= (int) $totals['stalls'] ?></b><span>Stall<?= (int) $totals['stalls'] === 1 ? '' : 's' ?></span></div>
    <div class="cd-hero-stat"><b><?= (int) $totals['products'] ?></b><span>Product<?= (int) $totals['products'] === 1 ? '' : 's' ?></span></div>
  </div>
  <div class="cd-hero-actions">
    <a class="btn btn-secondary" href="#stalls">Browse stalls <svg><use href="#i-arrow"/></svg></a>
    <a class="btn btn-light" href="<?= e(url('markets.php')) ?>#categories">All markets</a>
  </div>
<?php
$heroExtra = ob_get_clean();

site_layout_start([
    'title' => $cat['name'],
    'active' => 'markets',
    'description' => (string) $cat['description'],
    'hero' => [
        'kicker' => 'Market category',
        'title' => e($cat['name']),
        'lede' => $cat['description'] ?: 'Approved local farmers and stalls selling in this market. Open a stall to see its products and pre-order for pickup.',
        'image' => $img ?: 'https://images.unsplash.com/photo-1488459716781-31db52582fe9?q=80&w=1800&auto=format&fit=crop',
        'extra' => $heroExtra,
    ],
]);
?>
<nav class="mcat-crumbs" aria-label="Breadcrumb">
  <a href="<?= e(url('markets.php')) ?>">Markets</a><span aria-hidden="true">/</span>
  <a href="<?= e(url('markets.php')) ?>#categories">Categories</a><span aria-hidden="true">/</span>
  <span aria-current="page"><?= e($cat['name']) ?></span>
</nav>

<section id="stalls" class="mk-section">
  <header class="mk-head">
    <div>
      <p class="ml-kicker">Vendors &amp; stalls</p>
      <h2 class="ml-serif">Who sells <em>here</em></h2>
      <p class="mk-sub">Open a stall to see its products and add them to your cart.</p>
    </div>
    <span class="mk-total"><b><?= $q !== '' ? count($stalls) . ($hasMore ? '+' : '') : (int) $totals['stalls'] ?></b> <?= $q !== '' ? 'results' : 'stalls' ?></span>
  </header>

  <form class="mk-toolbar mcat-toolbar" method="get" action="<?= e(url('market-category.php')) ?>#stalls" role="search">
    <input type="hidden" name="id" value="<?= $id ?>">
    <div class="mk-search"><svg><use href="#i-search"/></svg>
      <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Search stalls or products in <?= e($cat['name']) ?>" aria-label="Search stalls"></div>
    <select class="select" name="sort" aria-label="Sort stalls" onchange="this.form.submit()">
      <option value="products"<?= $sort === 'products' ? ' selected' : '' ?>>Most products</option>
      <option value="rating"<?= $sort === 'rating' ? ' selected' : '' ?>>Top rated</option>
      <option value="name"<?= $sort === 'name' ? ' selected' : '' ?>>Name A to Z</option>
    </select>
    <button class="btn btn-primary" type="submit">Search</button>
    <?php if ($q !== ''): ?><a class="btn btn-ghost" href="<?= e(url('market-category.php?id=' . $id)) ?>#stalls">Clear</a><?php endif; ?>
  </form>

  <?php if (!$stalls): ?>
    <div class="card"><?= empty_state('i-store', $q !== '' ? 'No stalls match your search' : 'No stalls yet', $q !== '' ? 'Try a different stall or product name.' : 'Approved vendors in this market will appear here.') ?></div>
  <?php else: ?>
    <div class="mcat-grid">
      <?php foreach ($stalls as $v):
          $vi = upload_url($v['image']);
          $href = url('market.php?id=' . (int) $v['id']);
          $days = days_short_label(days_from_set($v['operating_days']));
          $where = $v['location_address'] ?: $v['address'];
          $np = (int) $v['products']; ?>
        <div class="card-fav-wrap">
        <?= favorite_heart_for_viewer('vendor', (int) $v['id'], $v['stall_name'], url('market-category.php?id=' . $id)) ?>
        <a class="mcat-card" href="<?= e($href) ?>">
          <div class="mcat-media"<?= $vi ? ' style="background-image:url(\'' . e($vi) . '\')"' : '' ?>>
            <?php if (!$vi): ?><span class="mcat-initial"><?= e(mb_strtoupper(mb_substr(trim($v['stall_name']), 0, 1))) ?></span><?php endif; ?>
            <?php if ($v['reviews']): ?>
              <span class="mcat-rating"><svg><use href="#i-star"/></svg><?= e($v['rating']) ?> <small>(<?= (int) $v['reviews'] ?>)</small></span>
            <?php else: ?>
              <span class="mcat-rating is-new">New stall</span>
            <?php endif; ?>
          </div>
          <div class="mcat-body">
            <h3><?= e($v['stall_name']) ?></h3>
            <?php if ($v['description']): ?><p class="mcat-desc"><?= e(mb_strimwidth($v['description'], 0, 110, '...')) ?></p><?php endif; ?>
            <ul class="mk-loc-meta">
              <?php if ($days): ?><li><svg><use href="#i-calendar"/></svg><span><?= e($days) ?></span></li><?php endif; ?>
              <?php if ($v['pickup_start']): ?><li><svg><use href="#i-clock"/></svg><span>Pickup <?= e(fmt_time($v['pickup_start']) . ' to ' . fmt_time($v['pickup_end'])) ?></span></li><?php endif; ?>
              <?php if ($where): ?><li><svg><use href="#i-pin"/></svg><span><?= e(mb_strimwidth($where, 0, 60, '...')) ?></span></li><?php endif; ?>
            </ul>
            <div class="mk-loc-stats">
              <div><b><?= $np ?></b><span>Product<?= $np === 1 ? '' : 's' ?></span></div>
              <span class="mcat-cta">View products</span>
              <span class="mk-go" aria-hidden="true"><svg><use href="#i-arrow"/></svg></span>
            </div>
          </div>
        </a>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($page > 1 || $hasMore): ?>
      <nav class="mcat-pager" aria-label="Pagination">
        <?php if ($page > 1): ?><a class="btn btn-ghost" href="<?= e($pageUrl(['page' => $page - 1 > 1 ? $page - 1 : ''])) ?>#stalls"><svg style="transform:rotate(180deg)"><use href="#i-arrow"/></svg>Previous</a><?php endif; ?>
        <span class="mcat-page">Page <?= $page ?></span>
        <?php if ($hasMore): ?><a class="btn btn-ghost" href="<?= e($pageUrl(['page' => $page + 1])) ?>#stalls">Next<svg><use href="#i-arrow"/></svg></a><?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</section>
<?php site_layout_end(['assets/js/favorites.js']);
