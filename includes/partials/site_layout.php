<?php
/** Layout for the customer pages (markets, stall page, orders...) */
declare(strict_types=1);

require_once __DIR__ . '/navbar.php';

/**
 * @param array $opts title, description, active (navbar key), css (extra stylesheet paths),
 *                    hero => [kicker, title (html), lede, image, extra (html)] for a homepage-style banner
 */
function site_layout_start(array $opts): void
{
    $hero = $opts['hero'] ?? null;
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-url" content="<?= e(base_url()) ?>">
<title><?= e($opts['title'] ?? 'MarketLink') ?>, MarketLink</title>
<?php if (!empty($opts['description'])): ?><meta name="description" content="<?= e($opts['description']) ?>"><?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=Manrope:wght@300;400;500;600;700;800&family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,500;0,9..144,600;1,9..144,400;1,9..144,500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="<?= e(asset('assets/css/dashboard.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('assets/css/market.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('assets/css/navbar.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('assets/css/site-theme.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('assets/css/marketplace.css')) ?>">
<?php foreach ($opts['css'] ?? [] as $css): ?><link rel="stylesheet" href="<?= e(asset($css)) ?>">
<?php endforeach; ?>
</head>
<body class="dash site">
<?php require APP_ROOT . '/includes/partials/icons.php'; ?>
<?php site_navbar($opts['active'] ?? '', !$hero); ?>
<?php if (!$hero): ?><div class="ml-nav-spacer" aria-hidden="true"></div><?php endif; ?>
<?php if ($hero): ?>
<section class="page-hero">
  <div class="page-hero-media" style="background-image:url('<?= e($hero['image'] ?? 'https://images.unsplash.com/photo-1488459716781-31db52582fe9?q=80&w=1800&auto=format&fit=crop') ?>')"></div>
  <div class="page-hero-overlay"></div>
  <div class="page-hero-inner">
    <div class="page-hero-panel">
      <?php if (!empty($hero['kicker'])): ?><p class="ml-kicker"><?= e($hero['kicker']) ?></p><?php endif; ?>
      <h1 class="ml-serif"><?= $hero['title'] ?></h1>
      <?php if (!empty($hero['lede'])): ?><p class="ml-lede"><?= e($hero['lede']) ?></p><?php endif; ?>
      <?= $hero['extra'] ?? '' ?>
    </div>
  </div>
</section>
<?php endif; ?>
<main class="site-main" id="main">
<?= site_announcement_html() ?>
<?php foreach (take_flashes() as $f): ?>
  <div class="alert alert-<?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div>
<?php endforeach; ?>
<?php
}

function site_layout_end(array $scripts = []): void
{ ?>
</main>
<?php site_footer(); ?>
<div class="toast-stack" id="toastStack" aria-live="polite"></div>
<div class="modal" id="confirmModal" role="dialog" aria-modal="true" aria-labelledby="confirmTitle" hidden>
  <div class="modal-card modal-sm">
    <div class="confirm-icon"><svg><use href="#i-alert"/></svg></div>
    <h3 id="confirmTitle">Are you sure?</h3>
    <p id="confirmText"></p>
    <div class="modal-actions">
      <button class="btn btn-ghost" data-confirm-cancel>Cancel</button>
      <button class="btn btn-primary" data-confirm-ok>Confirm</button>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="<?= e(asset('assets/js/vendor/core.js')) ?>"></script>
<script src="<?= e(asset('assets/js/map-common.js')) ?>"></script>
<script src="<?= e(asset('assets/js/navbar.js')) ?>"></script>
<?php if (current_user()): ?><script src="<?= e(asset('assets/js/notifications.js')) ?>"></script><?php endif; ?>
<script src="<?= e(asset('assets/js/report.js')) ?>"></script>
<?php foreach ($scripts as $s): ?><script src="<?= e(asset($s)) ?>"></script><?php endforeach; ?>
<?php assistant_widget(); ?>
</body>
</html>
<?php }
