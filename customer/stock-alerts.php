<?php
/** Low stock items the customer usually buys or has saved */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/ai_features.php';
require_once APP_ROOT . '/includes/partials/customer_layout.php';

$user = require_customer_dashboard();
$alerts = customer_last_chance((int) $user['id'], 12);

customer_layout_start([
    'title' => 'Stock Alerts', 'active' => 'alerts',
    'heading' => 'Stock Alerts', 'subheading' => 'Items you usually buy that are running low. Reserve them before market day.',
]);
?>

<?php if (!$alerts): ?>
  <div class="card"><?= empty_state('i-check', 'Nothing urgent right now', 'When stock of items you have bought or saved runs low, you will get a heads-up here.', '<a class="btn btn-primary" href="' . e(url('products.php')) . '">Browse products</a>') ?></div>
<?php else: ?>
  <article class="card card-flush">
    <div class="card-head">
      <div><h3 class="card-title">Running low</h3><p class="card-sub"><?= count($alerts) ?> item<?= count($alerts) === 1 ? '' : 's' ?> with 3 units or fewer left</p></div>
    </div>
    <div class="table-wrap">
      <table class="table table-cards">
        <thead><tr><th>Product</th><th>Stall</th><th>Freshness</th><th class="num">Price</th><th class="num">Left</th><th class="num">Action</th></tr></thead>
        <tbody>
          <?php foreach ($alerts as $p): $img = upload_url($p['image']); ?>
            <tr>
              <td data-label="Product">
                <div class="co-prod">
                  <span class="thumb"<?= $img ? ' style="background-image:url(\'' . e($img) . '\')"' : '' ?>><?= $img ? '' : '<svg><use href="#i-sprout"/></svg>' ?></span>
                  <div><strong><?= e($p['name']) ?></strong><small class="muted"><?= e($p['ai_reason']) ?></small></div>
                </div>
              </td>
              <td data-label="Stall"><a href="<?= e(url('market.php?id=' . $p['vendor_id'])) ?>"><?= e($p['stall_name']) ?></a></td>
              <td data-label="Freshness"><?php if ($p['freshness']): ?>
                <div class="co-fresh"><span><i style="width:<?= max(4, min(100, (int) $p['freshness']['score'])) ?>%"></i></span><small><?= (int) $p['freshness']['score'] ?>/100</small></div>
              <?php else: ?><span class="muted">Not set</span><?php endif; ?></td>
              <td data-label="Price" class="num nowrap"><?= e(money($p['price'])) ?> <small class="muted">/ <?= e($p['unit']) ?></small></td>
              <td data-label="Left" class="num"><span class="co-low"><?= e(qty($p['quantity'])) ?> <?= e($p['unit']) ?></span></td>
              <td data-label="Action"><div class="row-actions"><a class="btn btn-primary btn-sm" href="<?= e(url('market.php?id=' . $p['vendor_id'])) ?>">Pre-order</a></div></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </article>
<?php endif; ?>

<?php customer_layout_end();
