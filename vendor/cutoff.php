<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once APP_ROOT . '/includes/partials/vendor_layout.php';

$vendor = require_vendor();

vendor_layout_start([
    'title' => 'Cut-Off Settings', 'active' => 'cutoff',
    'heading' => 'Cut-Off Settings', 'subheading' => 'Decide when pre-orders close before each market day.',
]);
?>
<div class="page-head">
  <div><h2>Market days &amp; order deadlines</h2><p id="nowLabel">Loading...</p></div>
  <div class="page-actions"><button class="btn btn-primary" data-new-rule><svg><use href="#i-plus"/></svg>Add Market Day</button></div>
</div>

<article class="card" style="margin-bottom:20px">
  <div class="card-head"><div><h3 class="card-title">How the cut-off works</h3><p class="card-sub">Enforced on the server for every pre-order</p></div></div>
  <div class="flow">
    <span>Before deadline</span><svg><use href="#i-arrow"/></svg><span class="ok">Orders allowed</span>
    <span style="background:none;padding:0 6px" class="muted">|</span>
    <span>After deadline</span><svg><use href="#i-arrow"/></svg><span class="no">Orders closed</span>
    <svg><use href="#i-arrow"/></svg><span>Next market opens automatically</span>
  </div>
  <div class="divider"></div>
  <p class="o-label">What customers see right now</p>
  <div id="customerPreview" class="cutoff-status off"><svg><use href="#i-clock"/></svg><span>Loading...</span></div>
</article>

<div class="cutoff-grid" id="rules" aria-live="polite">
  <div class="skeleton" style="height:230px;border-radius:20px"></div>
  <div class="skeleton" style="height:230px;border-radius:20px"></div>
</div>

<div class="modal" id="ruleModal" role="dialog" aria-modal="true" aria-labelledby="ruleTitle" hidden>
  <form class="modal-card" id="ruleForm" novalidate>
    <div class="modal-head">
      <h3 id="ruleTitle">Add Market Day</h3>
      <button type="button" class="icon-btn" data-modal-close aria-label="Close"><svg><use href="#i-close"/></svg></button>
    </div>
    <div class="modal-body">
      <input type="hidden" name="id">
      <div class="form-grid">
        <div class="field">
          <label for="rDay">Market Day <span class="req">*</span></label>
          <select class="select" id="rDay" name="market_day" required>
            <?php foreach (DAYS as $n => $d): ?><option value="<?= $n ?>"><?= $d ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="rBefore">Orders close</label>
          <select class="select" id="rBefore" name="cutoff_days_before">
            <option value="0">On market day</option>
            <option value="1">1 day before</option>
            <option value="2">2 days before</option>
            <option value="3">3 days before</option>
          </select>
        </div>
        <div class="field full">
          <label for="rCut">Order Cut-Off Time <span class="req">*</span></label>
          <input class="input" type="time" id="rCut" name="cutoff_time" value="12:00" required>
          <span class="hint">Customers can't place pre-orders for this market after this time.</span>
        </div>
        <div class="field">
          <label for="rPs">Pickup Start <span class="req">*</span></label>
          <input class="input" type="time" id="rPs" name="pickup_start" value="16:00" required>
        </div>
        <div class="field">
          <label for="rPe">Pickup End <span class="req">*</span></label>
          <input class="input" type="time" id="rPe" name="pickup_end" value="19:00" required>
        </div>
        <div class="field full">
          <label class="switch"><input type="checkbox" name="is_active" checked><span class="track"></span>Active - accept pre-orders for this market</label>
        </div>
      </div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
      <button type="submit" class="btn btn-primary"><svg><use href="#i-check"/></svg>Save</button>
    </div>
  </form>
</div>

<?php vendor_layout_end(['scripts' => ['assets/js/vendor/cutoff.js']]);
