<?php
/**
 * Cart page content, used by cart.php and customer/cart.php.
 */
declare(strict_types=1);

function cart_view(array $cart): void
{ ?>
<div id="cartRoot">
<?php if (!$cart['groups']): ?>
  <div class="card"><?= empty_state('i-cart', 'Your cart is empty', 'Browse markets and add fresh products to your cart.', '<a class="btn btn-primary" href="' . e(url('markets.php')) . '">Browse markets</a>') ?></div>
<?php else: ?>
  <div class="cart-layout">
    <div>
      <?php foreach ($cart['groups'] as $g): ?>
        <section class="card cart-group">
          <div class="cart-group-head">
            <h3><a href="<?= e(url('market.php?id=' . $g['vendor_id'])) ?>"><?= e($g['stall_name']) ?></a></h3>
            <span class="muted">Subtotal <strong><?= e($g['subtotal']) ?></strong></span>
          </div>
          <?php if ($g['notice']): ?><div class="cart-notice"><?= e($g['notice']) ?></div><?php endif; ?>
          <?php foreach ($g['items'] as $i): ?>
            <div class="cart-line" data-cart-line="<?= $i['product_id'] ?>">
              <span class="cart-thumb"<?= $i['image'] ? ' style="background-image:url(\'' . e($i['image']) . '\')"' : '' ?>><?= $i['image'] ? '' : '<svg><use href="#i-sprout"/></svg>' ?></span>
              <div><h4><?= e($i['name']) ?></h4><small><?= e($i['price']) ?> / <?= e($i['unit']) ?></small>
                <?php if ($i['problem']): ?><span class="cart-problem"><?= e($i['problem']) ?></span><?php endif; ?></div>
              <span class="qty-inline cart-qty">
                <button type="button" data-cart-step="-1" aria-label="Less <?= e($i['name']) ?>"><svg viewBox="0 0 24 24"><path d="M5 12h14"/></svg></button>
                <input type="number" value="<?= e(qty($i['quantity'])) ?>" min="0" max="<?= e(qty($i['stock'])) ?>" step="<?= e($i['step']) ?>" data-cart-qty inputmode="decimal" aria-label="Quantity of <?= e($i['name']) ?>">
                <button type="button" data-cart-step="1" aria-label="More <?= e($i['name']) ?>"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg></button>
              </span>
              <span class="line-total"><?= e($i['line_total']) ?></span>
              <button type="button" class="icon-btn cart-remove" data-cart-remove aria-label="Remove <?= e($i['name']) ?>" title="Remove"><svg><use href="#i-trash"/></svg></button>
            </div>
          <?php endforeach; ?>
        </section>
      <?php endforeach; ?>
    </div>
    <aside class="card cart-summary">
      <h3 class="card-title">Order summary</h3>
      <dl>
        <dt>Items</dt><dd><?= (int) $cart['count'] ?></dd>
        <dt>Stalls</dt><dd><?= count($cart['groups']) ?> pre-order<?= count($cart['groups']) === 1 ? '' : 's' ?></dd>
        <dt class="grand">Total</dt><dd class="grand"><?= e($cart['total']) ?></dd>
      </dl>
      <?php $blocked = array_filter($cart['groups'], fn($g) => !$g['can_order']); ?>
      <?php if ($blocked): ?>
        <div class="cart-notice">Fix the highlighted items (or remove them) before checking out.</div>
        <span class="btn btn-primary btn-block" aria-disabled="true" style="opacity:.55">Proceed to checkout</span>
      <?php else: ?>
        <a class="btn btn-primary btn-block" href="<?= e(url('checkout.php')) ?>">Proceed to checkout <svg><use href="#i-arrow"/></svg></a>
      <?php endif; ?>
      <p class="muted" style="font-size:12.5px;margin-top:12px">You choose your pickup slot after the vendor accepts your order. Payment is made to the farmer at pickup.</p>
    </aside>
  </div>
<?php endif; ?>
</div>

<script>
(function () {
  var root = document.getElementById("cartRoot");
  function save(line, qty) {
    root.style.opacity = ".6";
    ML.api("api/public/cart.php", { body: { action: qty > 0 ? "update" : "remove", product_id: Number(line.dataset.cartLine), quantity: qty } })
      .then(function () { location.reload(); })
      .catch(function (err) { ML.toast(err.message, "error"); root.style.opacity = ""; });
  }
  var t;
  root.addEventListener("click", function (e) {
    var line = e.target.closest("[data-cart-line]");
    if (!line) return;
    var input = line.querySelector("[data-cart-qty]");
    var b = e.target.closest("[data-cart-step]");
    if (b) {
      var step = Number(input.step) || 1;
      input.value = Math.max(0, Math.round(((Number(input.value) || 0) + step * Number(b.dataset.cartStep)) * 100) / 100);
      clearTimeout(t); t = setTimeout(function () { save(line, Number(input.value)); }, 450);
    }
    if (e.target.closest("[data-cart-remove]")) save(line, 0);
  });
  root.addEventListener("change", function (e) {
    if (e.target.matches("[data-cart-qty]")) save(e.target.closest("[data-cart-line]"), Number(e.target.value) || 0);
  });
})();
</script>
<?php }
