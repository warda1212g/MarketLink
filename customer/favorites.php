<?php
/** Customer's saved stalls and products. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/favorites.php';
require_once APP_ROOT . '/includes/partials/customer_layout.php';

$user = require_customer_dashboard();
$uid = (int) $user['id'];

favorites_ensure_table();
$favVendors = db_all("SELECT v.id, v.stall_name, v.image, v.operating_days, v.location_address, v.address,
                             (SELECT ROUND(AVG(rating),1) FROM reviews r WHERE r.vendor_id = v.id AND r.status = 'visible') AS rating,
                             (SELECT COUNT(*) FROM products p WHERE p.vendor_id = v.id AND p.deleted_at IS NULL AND p.moderation_status = 'active') AS products
                      FROM favorites f JOIN vendors v ON v.id = f.item_id AND v.is_active = 1
                      WHERE f.customer_id = ? AND f.item_type = 'vendor' ORDER BY f.created_at DESC", [$uid]);
$favProducts = db_all("SELECT p.id, p.name, p.image, p.price, p.unit, p.quantity, p.status, v.id AS vid, v.stall_name
                       FROM favorites f JOIN products p ON p.id = f.item_id AND p.deleted_at IS NULL AND p.moderation_status = 'active'
                       JOIN vendors v ON v.id = p.vendor_id AND v.is_active = 1
                       WHERE f.customer_id = ? AND f.item_type = 'product' ORDER BY f.created_at DESC", [$uid]);

$tab = ($_GET['tab'] ?? '') === 'products' ? 'products' : 'stalls';

customer_layout_start([
    'title' => 'Favorites', 'active' => 'favorites',
    'heading' => 'Favorites', 'subheading' => 'Stalls and products you have saved for quick access.',
]);
?>

<div class="tabs" role="tablist" aria-label="Favorite type">
  <a role="tab" class="tab<?= $tab === 'stalls' ? ' active' : '' ?>" aria-selected="<?= $tab === 'stalls' ? 'true' : 'false' ?>" href="<?= e(url('customer/favorites.php')) ?>">Stalls <span class="tab-count"><?= count($favVendors) ?></span></a>
  <a role="tab" class="tab<?= $tab === 'products' ? ' active' : '' ?>" aria-selected="<?= $tab === 'products' ? 'true' : 'false' ?>" href="<?= e(url('customer/favorites.php?tab=products')) ?>">Products <span class="tab-count"><?= count($favProducts) ?></span></a>
</div>

<?php if ($tab === 'stalls'): ?>
  <?php if (!$favVendors): ?>
    <div class="card"><?= empty_state('i-heart', 'No favorite stalls yet', 'Tap the heart on any stall to save it here.', '<a class="btn btn-primary" href="' . e(url('markets.php')) . '">Browse markets</a>') ?></div>
  <?php else: ?>
    <div class="co-fav-grid">
      <?php foreach ($favVendors as $v): $img = upload_url($v['image']); ?>
        <article class="card co-fav" data-fav-card>
          <div class="co-fav-img"<?= $img ? ' style="background-image:url(\'' . e($img) . '\')"' : '' ?>><?= $img ? '' : '<svg><use href="#i-store"/></svg>' ?><?= favorite_button('vendor', (int) $v['id'], true, $v['stall_name']) ?></div>
          <div class="co-fav-body">
            <h3><?= e($v['stall_name']) ?></h3>
            <ul class="co-meta">
              <li><svg><use href="#i-pin"/></svg><span><?= e($v['location_address'] ?: $v['address'] ?: 'Location not set') ?></span></li>
              <li><svg><use href="#i-calendar"/></svg><span><?= e(days_short_label(days_from_set($v['operating_days']))) ?></span></li>
            </ul>
            <div class="co-fav-foot">
              <span class="co-rating"><?php if ($v['rating']): ?><svg><use href="#i-star"/></svg><?= e($v['rating']) ?><?php else: ?>New<?php endif; ?></span>
              <span class="muted co-sub"><?= (int) $v['products'] ?> products</span>
              <a class="btn btn-ghost btn-sm" href="<?= e(url('market.php?id=' . $v['id'])) ?>">Visit stall</a>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
<?php else: ?>
  <?php if (!$favProducts): ?>
    <div class="card"><?= empty_state('i-heart', 'No favorite products yet', 'Tap the heart on any product to save it here.', '<a class="btn btn-primary" href="' . e(url('products.php')) . '">Browse products</a>') ?></div>
  <?php else: ?>
    <div class="co-fav-grid">
      <?php foreach ($favProducts as $p): $img = upload_url($p['image']); $avail = $p['status'] === 'available' && (float) $p['quantity'] > 0; ?>
        <article class="card co-fav" data-fav-card>
          <div class="co-fav-img"<?= $img ? ' style="background-image:url(\'' . e($img) . '\')"' : '' ?>><?= $img ? '' : '<svg><use href="#i-sprout"/></svg>' ?><?= favorite_button('product', (int) $p['id'], true, $p['name']) ?>
            <span class="pill <?= $avail ? 'pill-available' : 'pill-sold_out' ?>"><?= $avail ? 'In stock' : 'Sold out' ?></span></div>
          <div class="co-fav-body">
            <h3><?= e($p['name']) ?></h3>
            <p class="muted co-sub"><?= e($p['stall_name']) ?></p>
            <div class="co-fav-foot">
              <span class="co-price"><?= e(money($p['price'])) ?> <small>/ <?= e($p['unit']) ?></small></span>
              <a class="btn btn-primary btn-sm" href="<?= e(url('market.php?id=' . $p['vid'])) ?>">Reserve</a>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php customer_layout_end(['scripts' => ['assets/js/favorites.js']]);
