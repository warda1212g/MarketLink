<?php
/** Checkout page, places one pre-order per stall */
require_once __DIR__ . '/includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/cart.php';
require_once APP_ROOT . '/includes/partials/site_layout.php';
require_once APP_ROOT . '/includes/partials/components.php';

$user = require_customer();
$groups = cart_groups((int) $user['id']);
if (!$groups) {
    flash('info', 'Your cart is empty.');
    redirect('cart.php');
}
if (array_filter($groups, fn($g) => !$g['can_order'])) {
    flash('error', 'Some items in your cart need attention before checkout.');
    redirect('cart.php');
}
$total = array_sum(array_column($groups, 'subtotal'));

site_layout_start(['title' => 'Checkout', 'active' => 'cart']);
?>
<div class="page-head" style="margin-top:0">
  <div><p class="ml-kicker">Step 2 of 2, Confirm</p><h2>Place <em>Pre-Order</em></h2><p>Check everything below. Nothing is charged online - you pay each farmer at pickup.</p></div>
  <a class="btn btn-ghost" href="<?= e(url('cart.php')) ?>"><svg style="transform:rotate(180deg)"><use href="#i-arrow"/></svg>Back to cart</a>
</div>

<form id="checkoutForm" class="cart-layout" novalidate>
  <div>
    <?php foreach ($groups as $g): $v = $g['vendor']; $next = $g['status']['next_open']; ?>
      <section class="card cart-group">
        <div class="cart-group-head"><h3><?= e($v['stall_name']) ?></h3><span class="muted">Subtotal <strong><?= e(money($g['subtotal'])) ?></strong></span></div>
        <div class="table-wrap"><table class="table checkout-table">
          <thead><tr><th>Product</th><th class="num">Quantity</th><th class="num">Price</th><th class="num">Total</th></tr></thead>
          <tbody>
          <?php foreach ($g['items'] as $i): ?>
            <tr><td><?= e($i['name']) ?></td><td class="num"><?= e(qty($i['quantity']) . ' ' . $i['unit']) ?></td><td class="num"><?= e(money($i['price'])) ?></td><td class="num"><?= e(money($i['line_total'])) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <div class="pickup-info"><svg><use href="#i-clock"/></svg><div><strong>Pickup information</strong><br>
          <?= e($v['location_address'] ?: $v['address'] ?: 'Stall address shown on the order page') ?>.
          <?= $next ? 'Next market: ' . e(window_label($next)) . '.' : '' ?>
          You'll pick an exact pickup slot once <?= e($v['stall_name']) ?> accepts the order.</div></div>
        <div class="field mt"><label for="note<?= (int) $v['id'] ?>">Note for <?= e($v['stall_name']) ?> <span class="muted">(optional)</span></label>
          <textarea class="textarea" id="note<?= (int) $v['id'] ?>" name="notes[<?= (int) $v['id'] ?>]" maxlength="500" placeholder="e.g. Please pick ripe mangoes"></textarea></div>
      </section>
    <?php endforeach; ?>
  </div>
  <aside class="card cart-summary">
    <h3 class="card-title">Summary</h3>
    <dl>
      <?php foreach ($groups as $g): ?><dt><?= e($g['vendor']['stall_name']) ?></dt><dd><?= e(money($g['subtotal'])) ?></dd><?php endforeach; ?>
      <dt class="grand">Total</dt><dd class="grand"><?= e(money($total)) ?></dd>
    </dl>
    <button type="submit" class="btn btn-primary btn-block"><svg><use href="#i-check"/></svg>Confirm Pre-Order</button>
    <p class="muted" style="font-size:12.5px;margin-top:12px">Each stall receives its own order and confirms or rejects it. You'll get a notification at every step.</p>
  </aside>
</form>

<script>
document.getElementById("checkoutForm").addEventListener("submit", function (e) {
  e.preventDefault();
  var btn = this.querySelector('[type="submit"]'), notes = {};
  this.querySelectorAll("textarea[name^=notes]").forEach(function (t) { notes[t.name.match(/\d+/)[0]] = t.value.trim(); });
  ML.setLoading(btn, true);
  ML.api("api/public/order.php", { body: { action: "checkout", notes: notes } })
    .then(function (res) { ML.toast(res.message); setTimeout(function () { location.href = res.redirect; }, 700); })
    .catch(function (err) { ML.toast(err.message, "error"); ML.setLoading(btn, false); });
});
</script>
<?php site_layout_end();
