<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once APP_ROOT . '/includes/partials/vendor_layout.php';

$vendor = require_vendor();

vendor_layout_start([
    'title' => 'Weekly Stock', 'active' => 'weekly',
    'heading' => 'Weekly Stock', 'subheading' => 'Plan a recurring stock template for every market day.',
]);
?>
<div class="info-banner">
  <svg><use href="#i-calendar"/></svg>
  <div><strong>How templates work.</strong> Save the quantities you usually bring each day. On market day, press
    <em>Apply to live stock</em> to reset your product quantities from the template in one step.</div>
</div>

<div class="day-tabs" id="dayTabs" role="tablist" aria-label="Days of the week">
  <?php foreach (DAYS as $n => $name): ?>
    <button type="button" class="day-tab" role="tab" data-day="<?= $n ?>" aria-selected="false">
      <?php if ((int) date('N') === $n): ?><span class="today-dot" title="Today"></span><?php endif; ?>
      <strong><?= substr($name, 0, 3) ?></strong><small data-day-count="<?= $n ?>">-</small>
    </button>
  <?php endforeach; ?>
</div>

<section class="grid grid-main">
  <article class="card" id="dayEditor" role="tabpanel">
    <div class="card-head">
      <div><h3 class="card-title" id="dayTitle">Monday</h3><p class="card-sub" id="daySub">Loading template...</p></div>
      <div class="page-actions">
        <button type="button" class="btn btn-soft btn-sm" id="aiPrefill" title="AI pre-fills this day from your last 6 weeks of sales"><svg><use href="#i-refresh"/></svg>Suggest from sales</button>
        <div class="dropdown" data-dropdown>
          <button class="btn btn-ghost btn-sm" data-dropdown-toggle aria-expanded="false"><svg><use href="#i-copy"/></svg>Copy to...</button>
          <div class="dropdown-menu" id="copyMenu" role="menu"></div>
        </div>
        <button class="btn btn-danger-ghost btn-sm" id="clearDay"><svg><use href="#i-trash"/></svg>Delete Template</button>
      </div>
    </div>

    <div class="stock-row stock-head"><span>Product</span><span class="stock-qty">Quantity</span><span class="stock-status">Available</span><span></span></div>
    <div id="rows"><div class="skeleton sk-line"></div><div class="skeleton sk-line"></div><div class="skeleton sk-line"></div></div>

    <button type="button" class="btn btn-soft btn-sm mt" id="addRow"><svg><use href="#i-plus"/></svg>Add product</button>

    <div class="form-foot">
      <button type="button" class="btn btn-ghost" id="applyDay"><svg><use href="#i-refresh"/></svg>Apply to live stock</button>
      <button type="button" class="btn btn-primary" id="saveDay"><svg><use href="#i-check"/></svg>Save Weekly Template</button>
    </div>
  </article>

  <article class="card">
    <div class="card-head"><div><h3 class="card-title">Recently applied</h3><p class="card-sub">Template -> live stock history</p></div></div>
    <div id="appliedList" class="muted">-</div>
    <div class="divider"></div>
    <p class="muted" style="font-size:13px">Tip: products you add here keep their current price and unit. Manage those on
      <a class="link" href="<?= e(url('vendor/products.php')) ?>">My Products <svg><use href="#i-arrow"/></svg></a></p>
  </article>
</section>

<article class="card mt">
  <div class="card-head"><div><h3 class="card-title">Week at a glance</h3><p class="card-sub">Faded days are not in your operating days</p></div></div>
  <div class="week-overview" id="weekOverview"></div>
</article>

<?php vendor_layout_end(['scripts' => ['assets/js/vendor/weekly-stock.js']]);
