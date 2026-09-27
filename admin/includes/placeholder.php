<?php
/**
 * Shared "module in progress" body for admin sections not yet built.
 * The including page sets $kicker, $headingHtml (trusted markup), $lede, $icon, $planned (list of strings).
 */
include __DIR__ . '/header.php';
?>
<section class="adm-page-head">
  <div>
    <p class="kicker"><?= e($kicker) ?></p>
    <h1 class="adm-h1"><?= $headingHtml ?></h1>
    <p class="adm-lede"><?= e($lede) ?></p>
  </div>
</section>

<section class="adm-card adm-placeholder">
  <svg viewBox="0 0 24 24"><use href="#<?= e($icon) ?>"/></svg>
  <h2 class="adm-h2">This module is being built</h2>
  <p class="adm-lede">It shares the same layout, authentication and components as the dashboard. Planned here:</p>
  <ul class="adm-legend" style="max-width:360px;margin:0 auto 28px;text-align:left;">
    <?php foreach ($planned as $item): ?>
      <li><i style="background:var(--soft-green)"></i><?= e($item) ?></li>
    <?php endforeach; ?>
  </ul>
  <a href="dashboard.php" class="adm-btn-outline">Back to Dashboard <svg viewBox="0 0 24 24"><use href="#icon-arrow"/></svg></a>
</section>
<?php include __DIR__ . '/footer.php'; ?>
