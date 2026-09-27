<?php
/** Customer order detail: tracking timeline, pickup slot, modify / cancel, message vendor, review. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/orders.php';
require_once APP_ROOT . '/includes/partials/site_layout.php';
require_once APP_ROOT . '/includes/partials/components.php';

$user = require_customer();
$order = customer_order((int) $user['id'], int_input($_GET, 'id', 0));
if (!$order) {
    http_response_code(404);
    site_layout_start(['title' => 'Order not found', 'active' => 'orders']);
    echo '<div class="card">' . empty_state('i-bag', 'Order not found', 'This order does not exist or belongs to another account.', '<a class="btn btn-primary" href="' . e(url('customer/orders.php')) . '">My orders</a>') . '</div>';
    site_layout_end();
    exit;
}
$id = (int) $order['id'];
$status = $order['status'];
$vendor = db_one('SELECT * FROM vendors WHERE id = ?', [$order['vendor_id']]);
$timeline = order_timeline($order);
$canModify = in_array($status, CUSTOMER_MODIFIABLE, true);
$canCancel = in_array($status, CUSTOMER_CANCELLABLE, true);
$canPickup = in_array($status, PICKUP_SELECTABLE, true);
$slots = $canPickup ? pickup_slots($vendor) : [];
$placed = !empty($_GET['placed']);

site_layout_start(['title' => 'Order #' . $order['order_number'], 'active' => 'orders']);
?>
<a class="link" href="<?= e(url('customer/orders.php')) ?>"><svg style="transform:rotate(180deg)"><use href="#i-arrow"/></svg>My orders</a>

<?php if ($placed): ?>
  <div class="alert alert-success mt" role="status">Pre-order placed! <?= e($order['stall_name']) ?> will accept or reject it - we'll notify you.</div>
<?php endif; ?>

<div class="page-head">
  <div><p class="ml-kicker">Placed <?= e(fmt_date($order['created_at'], 'M j, Y, g:i A')) ?></p>
    <h2>Order <em>#<?= e($order['order_number']) ?></em> <span class="pill pill-<?= e($status) ?>"><?= e(customer_status_label($status)) ?></span></h2>
    <p>From <a class="link" href="<?= e(url('market.php?id=' . $order['vendor_id'])) ?>"><?= e($order['stall_name']) ?></a><?= $order['modified_at'] ? ', modified ' . e(time_ago($order['modified_at'])) : '' ?></p></div>
</div>

<section class="card">
  <ol class="track" aria-label="Order progress">
    <?php foreach ($timeline as $i => $s): ?>
      <li class="<?= e($s['state']) ?>"><i aria-hidden="true"><?= $s['state'] === 'done' ? '<svg><use href="#i-check"/></svg>' : ($s['state'] === 'stopped' ? '<svg><use href="#i-x"/></svg>' : $i + 1) ?></i>
        <strong><?= e($s['label']) ?></strong><?php if ($s['time']): ?><small><?= e($s['time']) ?></small><?php endif; ?></li>
    <?php endforeach; ?>
  </ol>
  <?php if (in_array($status, ['cancelled', 'declined'], true)): ?>
    <div class="od-reason"><strong><?= $status === 'declined' ? 'Rejected by the vendor' : ($order['cancelled_by'] === 'customer' ? 'You cancelled this order' : 'Cancelled by the vendor') ?>.</strong>
      <?= $order['cancel_reason'] ? ' Reason: ' . e($order['cancel_reason']) : '' ?> Reserved stock was released.</div>
  <?php endif; ?>
</section>

<div class="od-grid mt" style="margin-top:20px">
  <div>
    <section class="card od-section od-items">
      <div class="od-head"><h3>Products</h3><?php if ($canModify): ?><button type="button" class="btn btn-ghost btn-sm" id="modifyBtn"><svg><use href="#i-edit"/></svg>Modify order</button><?php endif; ?></div>
      <form id="modifyForm" novalidate>
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Product</th><th class="num">Quantity</th><th class="num">Price</th><th class="num">Total</th></tr></thead>
          <tbody>
          <?php foreach ($order['items'] as $it): $step = in_array($it['unit'], ['kg', 'g', 'litre'], true) ? '0.5' : '1'; ?>
            <tr><td><?= e($it['product_name']) ?></td>
              <td class="num"><span data-view><?= e(qty($it['quantity']) . ' ' . $it['unit']) ?></span>
                <?php if ($canModify): ?><input class="input" data-edit hidden type="number" name="items[<?= (int) $it['id'] ?>]" value="<?= e(qty($it['quantity'])) ?>" min="0" step="<?= $step ?>"
                  max="<?= e(qty((float) $it['quantity'] + (float) ($it['stock'] ?? 0))) ?>" style="width:100px;height:36px;display:inline-block" aria-label="Quantity of <?= e($it['product_name']) ?>"><?php endif; ?></td>
              <td class="num"><?= e(money($it['unit_price'])) ?></td><td class="num"><?= e(money($it['subtotal'])) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr><th colspan="3">Total (pay at pickup)</th><th class="num"><?= e(money($order['total_amount'])) ?></th></tr></tfoot>
        </table></div>
        <div class="field mt"><label for="orderNote">Note for the vendor</label>
          <p data-view class="muted"><?= $order['customer_note'] ? e($order['customer_note']) : 'No note.' ?></p>
          <?php if ($canModify): ?><textarea class="textarea" data-edit hidden id="orderNote" name="note" maxlength="500"><?= e($order['customer_note']) ?></textarea><?php endif; ?></div>
        <?php if ($canModify): ?>
          <div data-edit hidden style="display:flex;gap:10px;justify-content:flex-end;margin-top:12px">
            <span class="muted" style="font-size:12.5px;margin-right:auto">Set a quantity to 0 to remove that product. The vendor is notified of changes.</span>
            <button type="button" class="btn btn-ghost" id="modifyCancel">Discard</button>
            <button type="submit" class="btn btn-primary"><svg><use href="#i-check"/></svg>Save changes</button>
          </div>
        <?php endif; ?>
      </form>
    </section>

    <section class="card od-section" id="pickup">
      <div class="od-head"><h3>Pickup slot</h3></div>
      <?php if ($order['pickup_date']): ?>
        <div class="slot-current"><svg><use href="#i-clock"/></svg>Pickup: <?= e(pickup_label($order['pickup_date'], $order['pickup_start'], $order['pickup_end'])) ?></div>
      <?php endif; ?>
      <?php if ($status === 'pending'): ?>
        <p class="muted">Once <?= e($order['stall_name']) ?> accepts your order you'll choose a pickup slot here.</p>
      <?php elseif ($canPickup && !$slots): ?>
        <p class="muted">The vendor has no upcoming pickup times published. Message them to arrange pickup.</p>
      <?php elseif ($canPickup): ?>
        <form id="slotForm" novalidate>
          <?php foreach ($slots as $day): ?>
            <div class="slot-day"><strong><?= e($day['label']) ?></strong><div class="slot-grid">
              <?php foreach ($day['slots'] as $s): $on = $order['pickup_date'] === $day['date'] && substr((string) $order['pickup_start'], 0, 5) === $s['start']; ?>
                <label class="chip-check"><input type="radio" name="slot" value="<?= e($day['date'] . '|' . $s['start']) ?>"<?= $on ? ' checked' : '' ?>><span><?= e($s['label']) ?></span></label>
              <?php endforeach; ?>
            </div></div>
          <?php endforeach; ?>
          <button type="submit" class="btn btn-primary"><svg><use href="#i-check"/></svg><?= $order['pickup_date'] ? 'Change pickup slot' : 'Confirm pickup slot' ?></button>
        </form>
      <?php elseif (!$order['pickup_date']): ?>
        <p class="muted">No pickup slot was chosen.</p>
      <?php endif; ?>
    </section>
  </div>

  <aside>
    <section class="card od-section">
      <h3 class="card-title">Stall</h3>
      <dl class="kv">
        <dt>Stall</dt><dd><?= e($order['stall_name']) ?></dd>
        <dt>Address</dt><dd><?= e($order['location_address'] ?: $order['vendor_address'] ?: '-') ?></dd>
        <?php if ($order['vendor_phone']): ?><dt>Phone</dt><dd><a class="link" href="tel:<?= e($order['vendor_phone']) ?>"><?= e($order['vendor_phone']) ?></a></dd><?php endif; ?>
      </dl>
      <div class="od-actions mt">
        <?php if ($status === 'ready'): ?>
          <button type="button" class="btn btn-primary btn-block" id="pickedUpBtn"><svg><use href="#i-check"/></svg>I've picked up my order</button>
        <?php elseif ($status === 'picked_up'): ?>
          <p class="slot-current" style="margin:0"><svg><use href="#i-check"/></svg>You confirmed pickup<?= $order['picked_up_at'] ? ' ' . e(time_ago($order['picked_up_at'])) : '' ?>. Waiting for the vendor to complete the order.</p>
        <?php endif; ?>
        <a class="btn btn-ghost btn-block" href="<?= e(url('messages.php?vendor=' . $order['vendor_id'] . '&order=' . $id)) ?>"><svg><use href="#i-chat"/></svg>Message vendor</a>
        <?php if ($canCancel): ?><button type="button" class="btn btn-danger-ghost btn-block" id="cancelBtn"><svg><use href="#i-x"/></svg>Cancel order</button>
        <?php elseif (in_array($status, ['preparing', 'ready'], true)): ?><p class="muted" style="font-size:12.5px">The vendor is already preparing this order, so it can't be cancelled here. Message them if plans change.</p><?php endif; ?>
      </div>
    </section>

    <?php if ($status === 'completed'): ?>
    <section class="card od-section">
      <h3 class="card-title">Your review</h3>
      <?php if ($order['review_id']): ?>
        <p class="mt"><?= stars((int) $order['review_rating']) ?> Thanks for reviewing <?= e($order['stall_name']) ?>.</p>
      <?php else: ?>
        <form id="reviewForm" class="mt" novalidate>
          <div class="field" data-field="rating"><span class="field-label">Rating</span>
            <div class="chip-group" role="radiogroup" aria-label="Rating">
              <?php for ($s = 5; $s >= 1; $s--): ?><label class="chip-check"><input type="radio" name="rating" value="<?= $s ?>"<?= $s === 5 ? ' checked' : '' ?>><span><?= $s ?> star<?= $s === 1 ? '' : 's' ?></span></label><?php endfor; ?>
            </div></div>
          <div class="field mt"><label for="reviewText">Review</label><textarea class="textarea" id="reviewText" name="review" maxlength="1000" placeholder="How was the produce and the pickup?"></textarea></div>
          <button type="submit" class="btn btn-primary btn-block mt"><svg><use href="#i-star"/></svg>Post review</button>
        </form>
      <?php endif; ?>
    </section>
    <?php endif; ?>
  </aside>
</div>

<script>
(function () {
  var id = <?= $id ?>;
  function call(body, btn) {
    if (btn) ML.setLoading(btn, true);
    return ML.api("api/public/order.php", { body: Object.assign({ id: id }, body) })
      .then(function (res) { ML.toast(res.message); setTimeout(function () { location.reload(); }, 700); })
      .catch(function (err) { ML.toast(err.message, "error"); if (btn) ML.setLoading(btn, false); });
  }
  var mf = document.getElementById("modifyForm");
  function editing(on) { mf.querySelectorAll("[data-edit]").forEach(function (x) { x.hidden = !on; }); mf.querySelectorAll("[data-view]").forEach(function (x) { x.hidden = on; }); }
  var mb = document.getElementById("modifyBtn");
  if (mb) {
    mb.addEventListener("click", function () { editing(true); });
    document.getElementById("modifyCancel").addEventListener("click", function () { mf.reset(); editing(false); });
    mf.addEventListener("submit", function (e) {
      e.preventDefault();
      var items = {};
      mf.querySelectorAll("input[name^=items]").forEach(function (i) { items[i.name.match(/\d+/)[0]] = Number(i.value) || 0; });
      call({ action: "modify", items: items, note: mf.elements.note.value.trim() }, mf.querySelector('[type="submit"]'));
    });
  }
  var cb = document.getElementById("cancelBtn");
  if (cb) cb.addEventListener("click", function () {
    ML.confirm({ title: "Cancel order #<?= e($order['order_number']) ?>?", text: "The vendor is notified and the reserved stock is released.", confirmText: "Cancel order", danger: true }).then(function (ok) {
      if (!ok) return;
      var reason = window.prompt("Reason for cancelling (optional):", "") || "";
      call({ action: "cancel", reason: reason.trim() }, cb);
    });
  });
  var pu = document.getElementById("pickedUpBtn");
  if (pu) pu.addEventListener("click", function () {
    ML.confirm({ title: "Confirm pickup?", text: "Only confirm once you have collected your order from <?= e($order['stall_name']) ?>.", confirmText: "Yes, I've picked it up" })
      .then(function (ok) { if (ok) call({ action: "picked_up" }, pu); });
  });
  var sf = document.getElementById("slotForm");
  if (sf) sf.addEventListener("submit", function (e) {
    e.preventDefault();
    var c = sf.querySelector('input[name="slot"]:checked');
    if (!c) { ML.toast("Choose a pickup slot.", "error"); return; }
    var p = c.value.split("|");
    call({ action: "pickup", date: p[0], start: p[1] }, sf.querySelector('[type="submit"]'));
  });
  var rf = document.getElementById("reviewForm");
  if (rf) rf.addEventListener("submit", function (e) {
    e.preventDefault();
    var btn = rf.querySelector('[type="submit"]');
    ML.setLoading(btn, true);
    ML.api("api/public/review.php", { body: { order_id: id, rating: Number(rf.querySelector('input[name="rating"]:checked').value), review: rf.elements.review.value.trim() } })
      .then(function (res) { ML.toast(res.message); setTimeout(function () { location.reload(); }, 700); })
      .catch(function (err) { ML.toast(err.message, "error"); ML.setLoading(btn, false); });
  });
})();
</script>
<?php site_layout_end();
