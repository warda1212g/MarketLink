<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/markets.php';
$user = require_admin_page();

$activeNav    = 'markets';
$pageTitle    = 'Manage Markets';
$pageSubtitle = 'Create and manage MarketLink markets';
$pageStyles   = ['https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css'];
$pageScripts  = [
    'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js',
    '../assets/js/map-common.js',
    '../assets/js/admin-market-form.js',
    '../assets/js/admin-markets.js',
];

$marketTypes = market_types_all();
$cities = db_all('SELECT DISTINCT city FROM markets ORDER BY city');
$typeFilter = int_input($_GET, 'type');

include __DIR__ . '/includes/header.php';
?>

<section class="adm-page-head">
  <div>
    <p class="kicker">MARKETS</p>
    <h1 class="adm-h1">Manage <em>Markets</em></h1>
    <p class="adm-lede">Every market belongs to a market type. Assign approved farmers to a market and their products become available inside it.</p>
  </div>
  <div class="mk-head-actions">
    <a href="market-types.php" class="adm-btn-outline"><svg viewBox="0 0 24 24"><use href="#icon-layers"/></svg> Market Types</a>
    <button type="button" class="adm-btn" id="addMarketBtn"><svg viewBox="0 0 24 24"><use href="#icon-plus"/></svg> Add Market</button>
  </div>
</section>

<?php foreach (take_flashes() as $f): ?>
  <div class="adm-flash adm-flash-<?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div>
<?php endforeach; ?>

<section class="mk-summary" id="mkSummary" aria-label="Market counts">
  <button type="button" class="mk-sum active" data-status=""><span>All markets</span><strong data-count="total">-</strong></button>
  <button type="button" class="mk-sum" data-status="active"><span>Active</span><strong data-count="active">-</strong></button>
  <button type="button" class="mk-sum" data-status="inactive"><span>Inactive</span><strong data-count="inactive">-</strong></button>
  <button type="button" class="mk-sum" data-status="suspended"><span>Suspended</span><strong data-count="suspended">-</strong></button>
</section>

<form class="mk-toolbar adm-card" id="mkFilters" role="search" onsubmit="return false">
  <label class="mk-search">
    <svg viewBox="0 0 24 24"><use href="#icon-search"/></svg>
    <span class="visually-hidden">Search markets</span>
    <input type="search" name="q" placeholder="Search by name, location or type..." autocomplete="off">
  </label>
  <select class="form-select" name="type" aria-label="Filter by market type">
    <option value="">All types</option>
    <?php foreach ($marketTypes as $t): ?>
      <option value="<?= (int) $t['id'] ?>"<?= $typeFilter === (int) $t['id'] ? ' selected' : '' ?>><?= e($t['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <select class="form-select" name="city" aria-label="Filter by city">
    <option value="">All cities</option>
    <?php foreach ($cities as $c): ?><option value="<?= e($c['city']) ?>"><?= e($c['city']) ?></option><?php endforeach; ?>
  </select>
  <select class="form-select" name="status" aria-label="Filter by status">
    <option value="">Any status</option>
    <?php foreach (MARKET_STATUSES as $s): ?><option value="<?= e($s) ?>"><?= e(ucfirst($s)) ?></option><?php endforeach; ?>
  </select>
</form>

<div class="adm-error" id="mkError" hidden></div>
<p class="mk-result-note" id="mkResultNote" aria-live="polite"></p>

<section class="mk-grid" id="marketGrid" aria-label="Markets">
  <?php for ($i = 0; $i < 6; $i++): ?><div class="adm-card mk-card mk-card-skel"><span class="adm-skel adm-skel-row" style="height:120px"></span><span class="adm-skel adm-skel-row"></span><span class="adm-skel" style="width:60%"></span></div><?php endfor; ?>
</section>

<?php include __DIR__ . '/includes/market-form-modal.php'; ?>

<!-- Delete / status confirmation -->
<div class="modal fade adm-modal" id="confirmModal" tabindex="-1" aria-labelledby="confirmTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="adm-h2" id="confirmTitle">Are you sure?</h2>
        <button type="button" class="adm-icon-btn" data-bs-dismiss="modal" aria-label="Close"><svg viewBox="0 0 24 24"><use href="#icon-close"/></svg></button>
      </div>
      <div class="modal-body"><p class="mb-0" id="confirmText"></p></div>
      <div class="modal-footer" id="confirmActions"></div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
