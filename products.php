<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once APP_ROOT . '/includes/partials/site_layout.php';
require_once APP_ROOT . '/includes/partials/components.php';
require_once APP_ROOT . '/includes/services/home.php';

$user = current_user();
$isCustomer = $user && $user['role'] === 'customer';
$favs = $isCustomer ? favorite_ids((int) $user['id']) : ['vendor' => [], 'product' => []];

/* filters */
$q        = str_input($_GET, 'q', 80);
$catId    = int_input($_GET, 'category', 0);
$subId    = int_input($_GET, 'sub', 0);
$marketId = int_input($_GET, 'market', 0);
$inStock  = !empty($_GET['stock']);
$sorts = [
    'new'        => ['Newest first', 'p.created_at DESC'],
    'popular'    => ['Most reserved', 'sold DESC, p.created_at DESC'],
    'rating'     => ['Top rated farmers', 'rating IS NULL, rating DESC, p.created_at DESC'],
    'price_asc'  => ['Price: low to high', 'p.price ASC'],
    'price_desc' => ['Price: high to low', 'p.price DESC'],
];
$sort = isset($sorts[$_GET['sort'] ?? '']) ? $_GET['sort'] : 'new';
$perPage = 24;
$page = max(1, int_input($_GET, 'page', 1));

/* categories (live counts, only ones with products) */
$catRows = db_all(
    "SELECT c.id, c.name, c.parent_id, c.sort_order,
            COUNT(p.id) AS items,
            SUM(p.status = 'available' AND p.quantity > 0) AS in_stock,
            SUBSTRING_INDEX(GROUP_CONCAT(p.image ORDER BY p.updated_at DESC SEPARATOR '|'), '|', 1) AS image
       FROM categories c
       JOIN products p ON (p.category_id = c.id OR p.subcategory_id = c.id)
       JOIN vendors v ON v.id = p.vendor_id AND v.is_active = 1
       JOIN users u   ON u.id = v.user_id AND u.is_active = 1 AND u.approval_status = 'approved'
      WHERE c.is_active = 1 AND " . HOME_PRODUCT_WHERE . "
      GROUP BY c.id, c.name, c.parent_id, c.sort_order
      ORDER BY c.sort_order, c.name"
);
$topCats = array_values(array_filter($catRows, fn($c) => $c['parent_id'] === null));
$subCats = $catId ? array_values(array_filter($catRows, fn($c) => (int) $c['parent_id'] === $catId)) : [];
$current = null;
foreach ($catRows as $c) {
    if ((int) $c['id'] === $catId) {
        $current = $c;
    }
}
$allCount = (int) db_value('SELECT COUNT(*) ' . HOME_PRODUCT_SQL);

$markets = db_all(
    "SELECT m.id, m.name, COUNT(p.id) AS items " . HOME_PRODUCT_FROM . "
       JOIN markets m ON m.id = p.market_id AND m.status = 'active'
      WHERE " . HOME_PRODUCT_WHERE . "
      GROUP BY m.id, m.name ORDER BY m.name"
);

/* products */
$where = [HOME_PRODUCT_WHERE];
$params = [];
if ($catId) {
    $where[] = '(p.category_id = ? OR p.subcategory_id = ?)';
    array_push($params, $catId, $catId);
}
if ($subId) {
    $where[] = 'p.subcategory_id = ?';
    $params[] = $subId;
}
if ($marketId) {
    $where[] = 'p.market_id = ?';
    $params[] = $marketId;
}
if ($inStock) {
    $where[] = "p.status = 'available' AND p.quantity > 0";
}
if ($q !== '') {
    $where[] = '(p.name LIKE ? OR p.description LIKE ? OR v.stall_name LIKE ? OR c.name LIKE ? OR m.name LIKE ?)';
    array_push($params, ...array_fill(0, 5, '%' . $q . '%'));
}
$fromSql = HOME_PRODUCT_FROM . '
       LEFT JOIN categories c  ON c.id = p.category_id
       LEFT JOIN categories sc ON sc.id = p.subcategory_id
       LEFT JOIN markets m     ON m.id = p.market_id
      WHERE ' . implode(' AND ', $where);

$total = (int) db_value('SELECT COUNT(*) ' . $fromSql, $params);
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$products = db_all(
    "SELECT p.*, c.name AS category, sc.name AS subcategory, m.id AS market_id, m.name AS market_name,
            v.id AS vendor_id, v.stall_name,
            (SELECT ROUND(AVG(r.rating), 1) FROM reviews r WHERE r.vendor_id = v.id AND r.status = 'visible') AS rating,
            (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi WHERE oi.product_id = p.id) AS sold
       " . $fromSql . '
      ORDER BY (p.status = "available" AND p.quantity > 0) DESC, ' . $sorts[$sort][1] . '
      LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
    $params
);
$topSold = $products ? max(array_map(fn($p) => (float) $p['sold'], $products)) : 0;

/** products.php URL keeping the current filters, with overrides (null removes a key). */
$link = function (array $over = []) use ($q, $catId, $subId, $marketId, $inStock, $sort): string {
    $params = array_merge(['q' => $q, 'category' => $catId ?: null, 'sub' => $subId ?: null, 'market' => $marketId ?: null,
                           'stock' => $inStock ? 1 : null, 'sort' => $sort !== 'new' ? $sort : null], $over);
    $params = array_filter($params, fn($v) => $v !== null && $v !== '' && $v !== 0);
    return url('products.php' . ($params ? '?' . http_build_query($params) : ''));
};

$heart = function (string $type, int $id, string $name) use ($user, $isCustomer, $favs): string {
    if ($isCustomer) {
        return favorite_button($type, $id, isset($favs[$type][$id]), $name);
    }
    if ($user) {
        return '';
    }
    return str_replace('<button ', '<button data-login="' . e(url('auth/login.php?next=' . urlencode(url('products.php')))) . '" ', favorite_button($type, $id, false, $name));
};

$title = $current ? $current['name'] : 'All Products';
site_layout_start([
    'title' => $title,
    'active' => 'products',
    'description' => 'Browse ' . $allCount . ' fresh products from local farmers on MarketLink.',
    'css' => ['assets/css/products.css'],
    'hero' => [
        'kicker' => $current ? 'CATEGORY' : "WHAT'S FRESH",
        'title' => $current ? e($current['name']) . '<br><em>Fresh &amp; Local</em>.' : 'Browse All<br><em>Products</em>.',
        'lede' => $current
            ? $current['items'] . ' products in ' . $current['name'] . ', ' . (int) $current['in_stock'] . ' in stock right now.'
            : $allCount . ' products from local farmers across ' . count($markets) . ' markets - stock and prices update live.',
        'image' => 'https://images.unsplash.com/photo-1610832958506-aa56368176cf?q=80&w=1800&auto=format&fit=crop',
    ],
]);
?>

<!-- categories -->
<nav class="cat-strip" aria-label="Product categories">
  <a class="cat-tile<?= !$catId ? ' active' : '' ?>" href="<?= e($link(['category' => null, 'sub' => null, 'page' => null])) ?>">
    <span class="cat-tile-img cat-all"><svg><use href="#i-grid"/></svg></span>
    <span class="cat-tile-name">All</span>
    <span class="cat-tile-count" data-live-total><?= $allCount ?></span>
  </a>
  <?php foreach ($topCats as $c): $img = upload_url($c['image']); ?>
  <a class="cat-tile<?= $catId === (int) $c['id'] ? ' active' : '' ?>" href="<?= e($link(['category' => (int) $c['id'], 'sub' => null, 'page' => null])) ?>">
    <span class="cat-tile-img"<?= $img ? ' style="background-image:url(\'' . e($img) . '\')"' : '' ?>></span>
    <span class="cat-tile-name"><?= e($c['name']) ?></span>
    <span class="cat-tile-count" data-live-cat="<?= (int) $c['id'] ?>"><?= (int) $c['items'] ?></span>
  </a>
  <?php endforeach; ?>
</nav>

<?php if ($subCats): ?>
<div class="sub-chips" aria-label="Subcategories">
  <a class="sub-chip<?= !$subId ? ' active' : '' ?>" href="<?= e($link(['sub' => null, 'page' => null])) ?>">All <?= e($current['name'] ?? '') ?></a>
  <?php foreach ($subCats as $s): ?>
    <a class="sub-chip<?= $subId === (int) $s['id'] ? ' active' : '' ?>" href="<?= e($link(['sub' => (int) $s['id'], 'page' => null])) ?>"><?= e($s['name']) ?> <small><?= (int) $s['items'] ?></small></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- filters -->
<form class="finder-form products-filters" method="get">
  <?php if ($catId): ?><input type="hidden" name="category" value="<?= $catId ?>"><?php endif; ?>
  <?php if ($subId): ?><input type="hidden" name="sub" value="<?= $subId ?>"><?php endif; ?>
  <div class="search-input">
    <svg><use href="#i-search"/></svg>
    <input class="input" type="search" name="q" placeholder="Search products, farmers, markets..." value="<?= e($q) ?>">
  </div>
  <select class="select" name="market" onchange="this.form.submit()" aria-label="Market">
    <option value="0">All markets</option>
    <?php foreach ($markets as $m): ?>
      <option value="<?= (int) $m['id'] ?>" <?= $marketId === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?> (<?= (int) $m['items'] ?>)</option>
    <?php endforeach; ?>
  </select>
  <select class="select" name="sort" onchange="this.form.submit()" aria-label="Sort">
    <?php foreach ($sorts as $k => [$label]): ?>
      <option value="<?= $k ?>" <?= $sort === $k ? 'selected' : '' ?>><?= e($label) ?></option>
    <?php endforeach; ?>
  </select>
  <label class="stock-toggle"><input type="checkbox" name="stock" value="1" <?= $inStock ? 'checked' : '' ?> onchange="this.form.submit()"> In stock only</label>
  <button type="submit" class="btn btn-secondary"><svg><use href="#i-search"/></svg>Search</button>
</form>

<div class="page-head products-head">
  <div>
    <h2><?= e($title) ?></h2>
    <p><?= $total ?> product<?= $total === 1 ? '' : 's' ?><?= $q !== '' ? ' matching "' . e($q) . '"' : '' ?>, <span class="live-note"><span class="live-dot" aria-hidden="true"></span>live, updated <span data-live-at>just now</span></span></p>
  </div>
  <?php if ($q !== '' || $catId || $marketId || $inStock || $sort !== 'new'): ?>
    <a class="btn btn-ghost btn-sm" href="<?= e(url('products.php')) ?>">Clear filters</a>
  <?php endif; ?>
</div>

<?php if (!$products): ?>
  <div class="card"><?= empty_state('i-box', 'No products found', 'Try a different search, category or market - or check back soon for fresh stock.', '<a class="btn btn-primary" href="' . e(url('products.php')) . '">See all products</a>') ?></div>
<?php else: ?>
  <div class="shop-grid pc-grid">
    <?php foreach ($products as $p):
        $avail = $p['status'] === 'available' && (float) $p['quantity'] > 0;
        $img = upload_url($p['image']);
        $stall = url('market.php?id=' . $p['vendor_id']);
        $cat = trim(($p['category'] ?? '') . ($p['subcategory'] ? ' / ' . $p['subcategory'] : ''));
        $extra = array_filter([
            (float) $p['sold'] > 0 ? qty($p['sold']) . ' ' . $p['unit'] . ' reserved' : '',
            $p['harvest_date'] ? 'Harvested ' . fmt_date($p['harvest_date'], 'M j') : '',
        ]); ?>
      <article class="p-card shop-card pc-card<?= $avail ? '' : ' sold-out' ?>" data-product="<?= (int) $p['id'] ?>">
        <div class="pc-media">
          <a class="p-img pc-img" href="<?= e($stall) ?>" tabindex="-1" aria-hidden="true"<?= $img ? ' style="background-image:url(\'' . e($img) . '\')"' : '' ?>><?= $img ? '' : '<svg><use href="#i-sprout"/></svg>' ?></a>
          <span class="pill <?= $avail ? 'pill-available' : 'pill-sold_out' ?>" data-live-pill><?= $avail ? e(home_product_badge($p, $topSold)) : 'Sold out' ?></span>
          <?= $heart('product', (int) $p['id'], $p['name']) ?>
          <span class="pc-rating<?= $p['rating'] === null ? ' is-new' : '' ?>"><?php if ($p['rating'] !== null): ?><svg><use href="#i-star"/></svg><?= e(number_format((float) $p['rating'], 1)) ?><?php else: ?>New stall<?php endif; ?></span>
        </div>

        <div class="p-body pc-body">
          <?php if ($cat !== ''): ?><span class="p-cat pc-cat"><?= e($cat) ?></span><?php endif; ?>
          <h3 class="p-name pc-name" title="<?= e($p['name']) ?>"><a href="<?= e($stall) ?>"><?= e($p['name']) ?></a></h3>
          <a class="pc-stall" href="<?= e($stall) ?>" title="<?= e($p['stall_name'] . ($p['market_name'] ? ', ' . $p['market_name'] : '')) ?>">
            <svg><use href="#i-store"/></svg><span><?= e($p['stall_name']) ?><?= $p['market_name'] ? ', ' . e($p['market_name']) : '' ?></span>
          </a>
          <?php if ($extra): ?><p class="pc-extra"><?= e(implode(', ', $extra)) ?></p><?php endif; ?>

          <div class="pc-price-row">
            <span class="p-price"><span data-live-price><?= e(money($p['price'])) ?></span> <small>/ <?= e($p['unit']) ?></small></span>
            <span class="p-stock" data-live-qty><?= $avail ? e(qty($p['quantity']) . ' ' . $p['unit'] . ' left') : 'Sold out' ?></span>
          </div>

          <div class="pc-actions">
            <?php if ($isCustomer): $step = in_array($p['unit'], ['kg', 'g', 'litre'], true) ? '0.5' : '1'; ?>
              <div class="pc-cart" data-cart-row<?= $avail ? '' : ' hidden' ?>>
                <span class="qty-inline sp-qty">
                  <button type="button" data-step="-1" aria-label="Less <?= e($p['name']) ?>"><svg viewBox="0 0 24 24"><path d="M5 12h14"/></svg></button>
                  <input type="number" min="<?= $step ?>" max="<?= e(qty($p['quantity'])) ?>" step="<?= $step ?>" value="<?= $step ?>" data-qty inputmode="decimal" aria-label="Quantity of <?= e($p['name']) ?>">
                  <button type="button" data-step="1" aria-label="More <?= e($p['name']) ?>"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg></button>
                </span>
                <button type="button" class="btn btn-primary pc-add" data-add-cart aria-label="Add <?= e($p['name']) ?> to cart"><svg><use href="#i-cart"/></svg><span>Add</span></button>
              </div>
              <a class="pc-stall-btn" href="<?= e($stall) ?>" aria-label="View stall" title="View stall"><svg><use href="#i-store"/></svg></a>
            <?php else: ?>
              <a class="btn btn-ghost pc-view" href="<?= e($stall) ?>">View stall <svg><use href="#i-arrow"/></svg></a>
            <?php endif; ?>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
  </div>

  <?php if ($pages > 1): ?>
  <nav class="pager" aria-label="Pages">
    <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="<?= e($link(['page' => $page - 1])) ?>">‹ Prev</a><?php endif; ?>
    <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
      <a class="btn btn-sm <?= $i === $page ? 'btn-primary' : 'btn-ghost' ?>" href="<?= e($link(['page' => $i])) ?>"<?= $i === $page ? ' aria-current="page"' : '' ?>><?= $i ?></a>
    <?php endfor; ?>
    <?php if ($page < $pages): ?><a class="btn btn-ghost btn-sm" href="<?= e($link(['page' => $page + 1])) ?>">Next ›</a><?php endif; ?>
  </nav>
  <?php endif; ?>
<?php endif; ?>

<?php site_layout_end(['assets/js/market.js', 'assets/js/favorites.js', 'assets/js/products-live.js']);
