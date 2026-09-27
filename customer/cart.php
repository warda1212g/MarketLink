<?php
/** Customer cart inside the dashboard (same cart as the website's cart.php). */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/cart.php';
require_once APP_ROOT . '/includes/partials/customer_layout.php';
require_once APP_ROOT . '/includes/partials/cart_view.php';

$user = require_customer_dashboard();
$cart = cart_to_array(cart_groups((int) $user['id']));

customer_layout_start([
    'title' => 'My Cart', 'active' => 'cart',
    'heading' => 'My Cart', 'subheading' => 'Items from each stall become a separate pre-order. You pay the farmer at pickup.',
]);
?>
<div class="co-page-actions">
  <span class="muted"><?= (int) $cart['count'] ?> item<?= (int) $cart['count'] === 1 ? '' : 's' ?> in your cart</span>
  <a class="btn btn-ghost btn-sm" href="<?= e(url('products.php')) ?>"><svg style="transform:rotate(180deg)"><use href="#i-arrow"/></svg>Continue shopping</a>
</div>
<?php cart_view($cart); ?>
<?php customer_layout_end();
