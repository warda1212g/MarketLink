<?php
/** Customer cart on the main website (the dashboard has its own copy at customer/cart.php). */
require_once __DIR__ . '/includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/cart.php';
require_once APP_ROOT . '/includes/partials/site_layout.php';
require_once APP_ROOT . '/includes/partials/components.php';
require_once APP_ROOT . '/includes/partials/cart_view.php';

$user = require_customer();
$cart = cart_to_array(cart_groups((int) $user['id']));

site_layout_start(['title' => 'My Cart', 'active' => 'cart']);
?>
<div class="page-head" style="margin-top:0">
  <div><p class="ml-kicker">Step 1 of 2, Review cart</p><h2>My <em>Cart</em></h2><p>Items from each stall become a separate pre-order. You pay the farmer at pickup.</p></div>
  <a class="btn btn-ghost" href="<?= e(url('products.php')) ?>"><svg style="transform:rotate(180deg)"><use href="#i-arrow"/></svg>Continue shopping</a>
</div>
<?php cart_view($cart); ?>
<?php site_layout_end();
