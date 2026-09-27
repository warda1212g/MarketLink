<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/schedule.php';
require_once APP_ROOT . '/includes/services/analytics.php';
require_once APP_ROOT . '/includes/partials/site_layout.php';
require_once APP_ROOT . '/includes/partials/components.php';
require_once APP_ROOT . '/includes/services/favorites.php';
require_once APP_ROOT . '/includes/services/cart.php';
require_once APP_ROOT . '/includes/services/ai_features.php';

$vendor = db_one('SELECT v.* FROM vendors v JOIN users u ON u.id = v.user_id AND u.is_active = 1 AND u.approval_status = "approved" WHERE v.id = ? AND v.is_active = 1', [(int) ($_GET['id'] ?? 0)]);
if (!$vendor) {
    http_response_code(404);
    site_layout_start(['title' => 'Market not found']);
    echo empty_state('i-store', 'Market not found', 'This stall may no longer be on MarketLink.', '<a class="btn btn-primary" href="' . e(url('markets.php')) . '">Find markets near you</a>');
    site_layout_end();
    exit;
}

$vid = (int) $vendor['id'];
$user = current_user();
$status = vendor_order_status($vendor);
$products = db_all('SELECT p.*, c.name AS category FROM products p LEFT JOIN categories c ON c.id = p.category_id
                    WHERE p.vendor_id = ? AND p.deleted_at IS NULL AND p.moderation_status = "active" ORDER BY (p.status = "available" AND p.quantity > 0) DESC, c.sort_order, p.name', [$vid]);
$rating = rating_summary($vid);
$reviews = db_all("SELECT r.*, u.name AS customer_name FROM reviews r JOIN users u ON u.id = r.customer_id WHERE r.vendor_id = ? AND r.status = 'visible' ORDER BY r.created_at DESC LIMIT 8", [$vid]);

$days = days_from_set($vendor['operating_days']);
$image = upload_url($vendor['image']);
$canOrder = $user && $user['role'] === 'customer' && is_approved($user);
$isCustomer = $user && $user['role'] === 'customer';
$favs = $isCustomer ? favorite_ids((int) $user['id']) : ['vendor' => [], 'product' => []];
$hasOrdered = $user && $user['role'] === 'customer' && db_value('SELECT 1 FROM orders WHERE customer_id = ? AND vendor_id = ? LIMIT 1', [$user['id'], $vid]);
$loginNext = url('auth/login.php?next=' . urlencode(url('market.php?id=' . $vid)));
$reportBtn = function (string $type, int $id, string $label) use ($isCustomer, $user, $loginNext): string {
    if ($user && !$isCustomer) return '';
    return '<button type="button" class="report-link" data-report-type="' . $type . '" data-report-id="' . $id . '" data-report-label="' . e($label) . '"'
        . ($user ? '' : ' data-login="' . e($loginNext) . '"') . '><svg><use href="#i-flag"/></svg>Report</button>';
};
// freshness score, pre-order counts and stall reliability
$preorders = product_preorder_counts($vid);
$confidence = vendor_confidence($vendor);

// Guests see the heart too; clicking it sends them to sign in. Vendors/admins get no heart.
$heart = function (string $type, int $id, string $name) use ($user, $isCustomer, $favs, $vid): string {
    if ($isCustomer) {
        return favorite_button($type, $id, isset($favs[$type][$id]), $name);
    }
    if ($user) {
        return '';
    }
    return str_replace('<button ', '<button data-login="' . e(url('auth/login.php?next=' . urlencode(url('market.php?id=' . $vid)))) . '" ', favorite_button($type, $id, false, $name));
};

site_layout_start(['title' => $vendor['stall_name'], 'active' => 'markets', 'description' => mb_strimwidth((string) $vendor['description'], 0, 150, '...')]);
?>
<a class="link" href="<?= e(url('markets.php')) ?>" style="margin-bottom:14px"><svg style="transform:rotate(180deg)"><use href="#i-arrow"/></svg>Back to nearby markets</a>

<section class="stall-hero mt">
  <div class="stall-cover" style="<?= $image ? "background-image:url('" . e($image) . "')" : '' ?>">
    <?= $heart('vendor', $vid, $vendor['stall_name']) ?>
    <div class="stall-cover-inner">
      <span class="pill <?= $status['current_closed'] ? 'pill-declined' : ($status['accepting'] ? 'pill-completed' : 'pill-muted') ?>" style="margin-bottom:10px">
        <?= $status['current_closed'] ? 'Orders closed' : ($status['accepting'] ? 'Taking pre-orders' : 'No upcoming market') ?>
      </span>
      <h1><?= e($vendor['stall_name']) ?></h1>
      <?php if ($confidence['pct'] !== null): ?>
        <p style="margin:4px 0 6px"><span class="pill <?= $confidence['pct'] >= 70 ? 'pill-completed' : ($confidence['pct'] >= 45 ? 'pill-available' : 'pill-declined') ?>">
          Pre-visit confidence: <?= (int) $confidence['pct'] ?>%
        </span> <small style="display:block;margin-top:5px;opacity:.9"><?= e($confidence['note']) ?></small></p>
      <?php endif; ?>
      <?php if ($vendor['description']): ?><p><?= e($vendor['description']) ?></p><?php endif; ?>
    </div>
  </div>
  <article class="card">
    <dl class="kv" style="margin-top:0">
      <dt>Rating</dt><dd><?= $rating['count'] ? stars((int) round($rating['avg'])) . ' ' . e($rating['avg']) . ' <span class="muted">(' . $rating['count'] . ')</span>' : '<span class="muted">No reviews yet</span>' ?></dd>
      <dt>Market days</dt><dd><?= e(days_short_label($days)) ?></dd>
      <dt>Pickup</dt><dd><?= $vendor['pickup_start'] ? e(fmt_time($vendor['pickup_start']) . ' - ' . fmt_time($vendor['pickup_end'])) : '-' ?></dd>
      <dt>Address</dt><dd><?= e($vendor['location_address'] ?: $vendor['address'] ?: '-') ?></dd>
      <?php if ($vendor['phone']): ?><dt>Phone</dt><dd><a class="link" href="tel:<?= e($vendor['phone']) ?>"><?= e($vendor['phone']) ?></a></dd><?php endif; ?>
    </dl>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:4px 0 14px">
      <?php if ($isCustomer): ?>
        <?= favorite_save_button('vendor', $vid, isset($favs['vendor'][$vid]), $vendor['stall_name']) ?>
      <?php elseif (!$user): ?>
        <a class="btn btn-ghost btn-sm fav-save" href="<?= e($loginNext) ?>"><svg><use href="#i-heart"/></svg>Sign in to save stall</a>
      <?php endif; ?>
      <?php if ($hasOrdered): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('messages.php?vendor=' . $vid)) ?>"><svg><use href="#i-chat"/></svg>Message vendor</a><?php endif; ?>
      <?= $reportBtn('vendor', $vid, $vendor['stall_name']) ?>
    </div>
    <?php if ($vendor['latitude'] !== null): ?>
      <div id="miniMap" class="mini-map" data-tiles="<?= e(config('map.tile_url')) ?>" data-attribution="<?= e(config('map.attribution')) ?>"
           data-lat="<?= e($vendor['latitude']) ?>" data-lng="<?= e($vendor['longitude']) ?>" data-zoom="15" aria-label="Stall location map"></div>
      <a class="link mt" style="margin-top:10px" target="_blank" rel="noopener" href="https://www.openstreetmap.org/directions?to=<?= e($vendor['latitude'] . ',' . $vendor['longitude']) ?>">Get directions <svg><use href="#i-arrow"/></svg></a>
    <?php endif; ?>
  </article>
</section>

<?php if ($status['current_closed']): ?>
  <div class="closed-banner" role="status"><svg><use href="#i-lock"/></svg>
    <div><?= e($status['message']) ?><?php if ($status['next_open']): ?><br><small>Next pickup: <?= e(window_label($status['next_open'])) ?> - you can pre-order for that market below.</small><?php endif; ?></div>
  </div>
<?php elseif ($status['accepting']): ?>
  <div class="closed-banner open-banner" role="status"><svg><use href="#i-check"/></svg><div>Pre-orders open for <?= e(window_label($status['next_open'])) ?>. <?= e($status['message']) ?></div></div>
<?php else: ?>
  <div class="closed-banner" style="background:var(--border-soft);color:var(--muted);border-color:var(--border)" role="status"><svg><use href="#i-clock"/></svg><div><?= e($status['message'] ?: 'No upcoming market is open for pre-orders right now.') ?></div></div>
<?php endif; ?>

<div class="page-head"><div><h2>This week's stock</h2><p><?= count($products) ?> product<?= count($products) === 1 ? '' : 's' ?>, prices per unit, pay at pickup</p></div></div>

<?php if (!$products): ?>
  <div class="card"><?= empty_state('i-box', 'No products listed yet', 'Check back closer to market day.') ?></div>
<?php else: ?>
<div id="stallProducts">
  <div class="shop-grid sp-grid">
    <?php foreach ($products as $p):
        $avail = $p['status'] === 'available' && (float) $p['quantity'] > 0; $img = upload_url($p['image']);
        $fr = freshness_info($p['harvest_date'] ?? null); $pp = (int) ($preorders[(int) $p['id']] ?? 0); ?>
      <article class="p-card shop-card sp-card<?= $avail ? '' : ' sold-out' ?>" data-product="<?= (int) $p['id'] ?>" data-price="<?= e($p['price']) ?>">
        <div class="p-img sp-img"<?= $img ? ' style="background-image:url(\'' . e($img) . '\')"' : '' ?>><?= $img ? '' : '<svg><use href="#i-sprout"/></svg>' ?>
          <span class="pill <?= $avail ? 'pill-available' : 'pill-sold_out' ?>"><?= $avail ? 'Available' : 'Sold out' ?></span><?= $heart('product', (int) $p['id'], $p['name']) ?>
          <?php if ($pp >= 2): ?><span class="sp-hot"><?= $pp ?> pre-ordered this week</span><?php endif; ?>
        </div>
        <div class="p-body sp-body">
          <div class="sp-top">
            <?php if (!empty($p['category'])): ?><span class="p-cat"><?= e($p['category']) ?></span><?php endif; ?>
            <?= $reportBtn('product', (int) $p['id'], $p['name']) ?>
          </div>
          <h3 class="p-name"><?= e($p['name']) ?></h3>
          <?php if ($p['description']): ?><p class="sp-desc"><?= e(mb_strimwidth($p['description'], 0, 90, '...')) ?></p><?php endif; ?>
          <?php if ($fr): ?>
            <div class="sp-fresh" title="Freshness score">
              <span class="sp-fresh-bar"><i style="width:<?= max(4, min(100, (int) $fr['score'])) ?>%"></i></span>
              <small>Freshness <?= (int) $fr['score'] ?>/100, <?= e($fr['age']) ?></small>
            </div>
          <?php endif; ?>

          <div class="sp-price-row">
            <span class="p-price"><?= e(money($p['price'])) ?> <small>/ <?= e($p['unit']) ?></small></span>
            <span class="p-stock"><?= $avail ? e(qty($p['quantity']) . ' ' . $p['unit'] . ' left') : 'Out of stock' ?></span>
          </div>

          <?php if ($avail): $step = in_array($p['unit'], ['kg', 'g', 'litre'], true) ? '0.5' : '1'; ?>
            <div class="sp-actions">
              <?php if ($canOrder): ?>
                <span class="qty-inline sp-qty">
                  <button type="button" data-step="-1" aria-label="Less <?= e($p['name']) ?>"><svg viewBox="0 0 24 24"><path d="M5 12h14"/></svg></button>
                  <input type="number" min="<?= $step ?>" max="<?= e(qty($p['quantity'])) ?>" step="<?= $step ?>" value="<?= $step ?>" data-qty inputmode="decimal" aria-label="Quantity of <?= e($p['name']) ?>">
                  <button type="button" data-step="1" aria-label="More <?= e($p['name']) ?>"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg></button>
                </span>
                <button type="button" class="btn btn-primary sp-add" data-add-cart><svg><use href="#i-cart"/></svg><span>Add to cart</span></button>
              <?php elseif (!$user): ?>
                <a class="btn btn-primary sp-add sp-full" href="<?= e($loginNext) ?>"><svg><use href="#i-login"/></svg><span>Sign in to order</span></a>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <div class="sp-actions"><span class="sp-soldout-note">Check back on the next market day</span></div>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>

  <?php if ($canOrder): ?>
  <div class="cart-bar">
    <div><small style="opacity:.7">Your cart</small><br><strong><span data-badge-plain="cart"><?= (int) cart_count((int) $user['id']) ?></span> item(s)</strong></div>
    <span style="flex:1"></span>
    <a class="btn btn-secondary" href="<?= e(url('cart.php')) ?>"><svg><use href="#i-cart"/></svg>Review cart &amp; place pre-order</a>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<section class="mt" style="margin-top:36px">
  <div class="page-head"><div><h2>Reviews</h2><p><?= $rating['count'] ? e($rating['avg'] . ' out of 5, ' . $rating['count'] . ' review' . ($rating['count'] > 1 ? 's' : '')) : 'No reviews yet' ?></p></div></div>
  <?php if (!$reviews): ?>
    <div class="card"><?= empty_state('i-star-o', 'No reviews yet', 'Customers can review this stall after a completed pickup.') ?></div>
  <?php else: ?>
  <div class="grid grid-2">
    <?php foreach ($reviews as $r): ?>
      <article class="review-card">
        <div class="review-head"><span class="avatar"><?= e(mb_strtoupper(mb_substr($r['customer_name'], 0, 1))) ?></span>
          <div style="flex:1"><strong><?= e($r['customer_name']) ?></strong><small><?= e(time_ago($r['created_at'])) ?></small></div><?= stars((int) $r['rating']) ?></div>
        <?php if ($r['review']): ?><p class="review-text">"<?= e($r['review']) ?>"</p><?php endif; ?>
        <?= $reportBtn('review', (int) $r['id'], 'review') ?>
        <?php if ($r['vendor_reply']): ?><div class="vendor-reply"><strong>Response from <?= e($vendor['stall_name']) ?></strong><?= e($r['vendor_reply']) ?></div><?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<?php site_layout_end(['assets/js/market.js', 'assets/js/favorites.js']);
