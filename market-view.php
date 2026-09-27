<?php
/** One admin-created market for customers: its stalls and a searchable product list. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once APP_ROOT . '/includes/partials/site_layout.php';
require_once APP_ROOT . '/includes/partials/components.php';
require_once APP_ROOT . '/includes/services/markets.php';
require_once APP_ROOT . '/includes/services/favorites.php';

$id = int_input($_GET, 'id', 0);
$market = $id ? (public_markets(['id' => $id])[0] ?? null) : null;
if (!$market) {
    http_response_code(404);
    site_layout_start(['title' => 'Market not found', 'active' => 'markets']);
    echo '<div class="card">' . empty_state('i-store', 'Market not found', 'This market is closed or no longer on MarketLink.', '<a class="btn btn-primary" href="' . e(url('markets.php')) . '#categories">Browse markets</a>') . '</div>';
    site_layout_end();
    exit;
}

$filters = ['q' => str_input($_GET, 'q', 80), 'category' => int_input($_GET, 'category'), 'vendor' => int_input($_GET, 'vendor')];
$vendors = public_market_vendors($id);
$products = public_market_products($id, $filters);
$filtered = $filters['q'] !== '' || $filters['category'] || $filters['vendor'];
// categories that actually have products in this market
$categories = db_all(
    "SELECT DISTINCT c.id, c.name FROM products p JOIN categories c ON c.id = p.category_id
      JOIN farmer_markets fm ON fm.vendor_id = p.vendor_id AND fm.market_id = p.market_id AND fm.status = 'active'
     WHERE p.market_id = ? AND p.deleted_at IS NULL ORDER BY c.name", [$id]
);
$self = fn(array $change = []) => url('market-view.php?' . http_build_query(array_filter(array_merge(['id' => $id], $filters, $change), fn($v) => $v !== '' && $v !== null && $v !== 0)));

site_layout_start(['title' => $market['name'], 'active' => 'markets', 'description' => mb_strimwidth((string) $market['description'], 0, 150, '...')]);
?>
<a class="link" href="<?= e(url('markets.php')) ?>#categories" style="margin-bottom:14px"><svg style="transform:rotate(180deg)"><use href="#i-arrow"/></svg>All markets</a>

<section class="stall-hero mt">
  <div class="stall-cover" style="<?= $market['image'] ? "background-image:url('" . e($market['image']) . "')" : '' ?>">
    <div class="stall-cover-inner">
      <span class="pill pill-completed" style="margin-bottom:10px"><?= e($market['type_name']) ?></span>
      <h1><?= e($market['name']) ?></h1>
      <?php if ($market['description']): ?><p><?= e($market['description']) ?></p><?php endif; ?>
    </div>
  </div>
  <article class="card">
    <dl class="kv" style="margin-top:0">
      <dt>Location</dt><dd><?= e(implode(', ', array_filter([$market['address'], $market['area'], $market['city']]))) ?></dd>
      <?php if ($market['hours']): ?><dt>Hours</dt><dd><?= e($market['hours']) ?></dd><?php endif; ?>
      <?php if ($market['days_label']): ?><dt>Open</dt><dd><?= e($market['days_label']) ?></dd><?php endif; ?>
      <dt>Stalls</dt><dd><?= count($vendors) ?></dd>
      <dt>Products</dt><dd><?= $market['products'] ?></dd>
    </dl>
    <?php if ($market['latitude'] !== null): ?>
      <div id="miniMap" class="mini-map" data-tiles="<?= e(config('map.tile_url')) ?>" data-attribution="<?= e(config('map.attribution')) ?>"
           data-lat="<?= e($market['latitude']) ?>" data-lng="<?= e($market['longitude']) ?>" data-zoom="15" aria-label="Market location map"></div>
      <a class="link mt" style="margin-top:10px" target="_blank" rel="noopener" href="https://www.openstreetmap.org/directions?to=<?= e($market['latitude'] . ',' . $market['longitude']) ?>">Get directions <svg><use href="#i-arrow"/></svg></a>
    <?php endif; ?>
  </article>
</section>

<section style="margin-top:30px">
  <div class="page-head"><div><h2>Stalls in this market</h2><p>Open a stall to pre-order from that farmer.</p></div></div>
  <?php if (!$vendors): ?>
    <div class="card"><?= empty_state('i-store', 'No stalls yet', 'Farmers are joining this market - check back soon.') ?></div>
  <?php else: ?>
    <div class="mv-stalls">
      <?php foreach ($vendors as $v): $img = upload_url($v['image']); ?>
        <article class="mv-stall">
          <a class="mv-stall-main" href="<?= e(url('market.php?id=' . (int) $v['id'])) ?>">
            <span class="market-thumb"<?= $img ? ' style="background-image:url(\'' . e($img) . '\')"' : '' ?>><?= $img ? '' : '<svg><use href="#i-store"/></svg>' ?></span>
            <span><strong><?= e($v['stall_name']) ?></strong>
              <small><?= (int) $v['products'] ?> product<?= (int) $v['products'] === 1 ? '' : 's' ?> here<?= $v['reviews'] ? ', rated ' . e($v['rating']) . ' (' . (int) $v['reviews'] . ')' : '' ?></small></span>
          </a>
          <div class="mv-stall-actions">
            <?= favorite_heart_for_viewer('vendor', (int) $v['id'], $v['stall_name'], url('market-view.php?id=' . $id)) ?>
            <a class="btn btn-ghost btn-sm" href="<?= e($self(['vendor' => (int) $v['id'], 'q' => '', 'category' => ''])) ?>#products">View products</a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<section id="products" style="margin-top:34px">
  <div class="page-head"><div><h2>Products</h2><p><?= count($products) ?> product<?= count($products) === 1 ? '' : 's' ?><?= $filtered ? ' match your search' : ' in this market' ?>, pre-order from the farmer's stall, pay at pickup</p></div></div>
  <form class="toolbar" method="get" action="<?= e(url('market-view.php')) ?>#products" role="search">
    <input type="hidden" name="id" value="<?= $id ?>">
    <div class="search-input"><svg><use href="#i-search"/></svg>
      <input class="input" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Search products in <?= e($market['name']) ?>..." aria-label="Search products"></div>
    <select class="select" name="category" aria-label="Category" onchange="this.form.submit()">
      <option value="">All categories</option>
      <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"<?= $filters['category'] === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
    </select>
    <select class="select" name="vendor" aria-label="Stall" onchange="this.form.submit()">
      <option value="">All stalls</option>
      <?php foreach ($vendors as $v): ?><option value="<?= (int) $v['id'] ?>"<?= $filters['vendor'] === (int) $v['id'] ? ' selected' : '' ?>><?= e($v['stall_name']) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-primary" type="submit"><svg><use href="#i-search"/></svg>Search</button>
    <?php if ($filtered): ?><a class="btn btn-ghost" href="<?= e(url('market-view.php?id=' . $id)) ?>#products">Clear</a><?php endif; ?>
  </form>

  <?php if (!$products): ?>
    <div class="card"><?= empty_state('i-box', $filtered ? 'Nothing matches your search' : 'No products listed yet', $filtered ? 'Try another product name or category.' : 'Farmers in this market haven\'t listed products yet.') ?></div>
  <?php else: ?>
    <div class="shop-grid">
      <?php foreach ($products as $p):
          $avail = $p['status'] === 'available' && (float) $p['quantity'] > 0; $img = upload_url($p['image']); ?>
        <article class="p-card shop-card">
          <div class="p-img"<?= $img ? ' style="background-image:url(\'' . e($img) . '\')"' : '' ?>><?= $img ? '' : '<svg><use href="#i-sprout"/></svg>' ?>
            <span class="pill <?= $avail ? 'pill-available' : 'pill-sold_out' ?>"><?= $avail ? 'Available' : 'Sold out' ?></span></div>
          <div class="p-body">
            <span class="p-cat"><?= e(trim(($p['category'] ?? '') . ($p['subcategory'] ? ', ' . $p['subcategory'] : ''))) ?></span>
            <h3 class="p-name"><?= e($p['name']) ?></h3>
            <p class="muted" style="font-size:12.5px">by <?= e($p['stall_name']) ?></p>
            <div class="p-row"><span class="p-price"><?= e(money($p['price'])) ?> <small>/ <?= e($p['unit']) ?></small></span><span class="p-stock"><?= $avail ? e(qty($p['quantity']) . ' ' . $p['unit'] . ' left') : '' ?></span></div>
            <a class="btn <?= $avail ? 'btn-primary' : 'btn-ghost' ?> btn-sm" style="margin-top:10px" href="<?= e(url('market.php?id=' . (int) $p['vendor_id'])) ?>"><?= $avail ? 'Order from stall' : 'Visit stall' ?> <svg><use href="#i-arrow"/></svg></a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php site_layout_end(['assets/js/market.js', 'assets/js/favorites.js']);
