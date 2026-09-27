<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = require_admin_page();

$activeNav    = 'moderation';
$pageTitle    = 'Content & Moderation';
$pageSubtitle = 'Products, reviews, vendors and reported content';
$pageScripts  = ['../assets/js/admin-moderation.js'];

$tab = in_array($_GET['tab'] ?? '', ['overview', 'products', 'reviews', 'vendors', 'reports', 'log'], true) ? $_GET['tab'] : 'overview';
$vendors = db_all('SELECT id, stall_name FROM vendors ORDER BY stall_name');
$categories = db_all('SELECT id, name FROM market_types ORDER BY sort_order, name');

include __DIR__ . '/includes/header.php';
?>
<section class="adm-page-head">
  <div><p class="kicker">TRUST &amp; SAFETY</p><h1 class="adm-h1">Content &amp; <em>Moderation</em></h1>
    <p class="adm-lede">Review what customers see. Nothing is permanently deleted - content is deactivated, flagged or removed, and every action is recorded in the moderation log.</p></div>
</section>

<div class="adm-seg mod-tabs" role="tablist" id="modTabs" aria-label="Content type">
  <?php foreach (['overview' => 'All Content', 'products' => 'Products', 'reviews' => 'Reviews', 'vendors' => 'Vendors', 'reports' => 'Reported Content', 'log' => 'Moderation Log'] as $k => $label): ?>
    <button type="button" role="tab" data-tab="<?= $k ?>" class="<?= $k === $tab ? 'active' : '' ?>" aria-selected="<?= $k === $tab ? 'true' : 'false' ?>"><?= e($label) ?></button>
  <?php endforeach; ?>
</div>

<form class="adm-card adm-panel mb-3" id="modFilters" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center" role="search">
  <input class="form-control" style="max-width:280px" type="search" name="q" placeholder="Search..." aria-label="Search">
  <select class="form-select" style="max-width:190px" name="status" aria-label="Status" data-for="products reviews vendors reports"></select>
  <select class="form-select" style="max-width:210px" name="vendor" aria-label="Vendor" data-for="products reviews">
    <option value="">All vendors</option><?php foreach ($vendors as $v): ?><option value="<?= (int) $v['id'] ?>"><?= e($v['stall_name']) ?></option><?php endforeach; ?>
  </select>
  <select class="form-select" style="max-width:210px" name="category" aria-label="Market category" data-for="products">
    <option value="">All market categories</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
  </select>
  <select class="form-select" style="max-width:150px" name="rating" aria-label="Rating" data-for="reviews">
    <option value="">Any rating</option><?php for ($i = 1; $i <= 5; $i++): ?><option value="<?= $i ?>"><?= $i ?> star<?= $i > 1 ? 's' : '' ?></option><?php endfor; ?>
  </select>
  <select class="form-select" style="max-width:170px" name="type" aria-label="Content type" data-for="reports">
    <option value="">All content types</option><option value="product">Products</option><option value="review">Reviews</option><option value="vendor">Vendors</option><option value="message">Messages</option>
  </select>
  <button class="adm-btn" type="submit"><svg viewBox="0 0 24 24"><use href="#icon-search"/></svg> Filter</button>
</form>

<div id="modBody" aria-live="polite"><section class="adm-card adm-panel"><span class="adm-skel adm-skel-row"></span></section></div>
<div style="text-align:center;margin-top:14px"><button class="adm-btn-outline" id="modMore" hidden>Load more</button></div>

<div class="modal fade adm-modal" id="modAction" tabindex="-1" aria-labelledby="modActionTitle" aria-hidden="true">
  <div class="modal-dialog modal-fullscreen-sm-down">
    <form class="modal-content" id="modActionForm" novalidate>
      <div class="modal-header"><h2 class="adm-h2" id="modActionTitle">Confirm</h2>
        <button type="button" class="adm-icon-btn" data-bs-dismiss="modal" aria-label="Close"><svg viewBox="0 0 24 24"><use href="#icon-close"/></svg></button></div>
      <div class="modal-body">
        <p id="modActionText" class="adm-lede" style="margin-bottom:12px"></p>
        <label class="adm-label" for="modReason">Reason <span id="modReasonReq" class="req">*</span></label>
        <textarea class="form-control" id="modReason" name="reason" rows="3" maxlength="500" placeholder="e.g. Inappropriate content, abusive language..."></textarea>
        <div class="invalid-feedback" data-error-for="reason"></div>
        <p class="form-text">The reason is stored in the moderation log and shared with the affected account.</p>
      </div>
      <div class="modal-footer"><button type="button" class="adm-btn-outline" data-bs-dismiss="modal">Cancel</button><button type="submit" class="adm-btn" id="modActionOk">Confirm</button></div>
    </form>
  </div>
</div>
<script type="application/json" id="modInit"><?= json_encode(['tab' => $tab, 'report' => int_input($_GET, 'report')]) ?></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
