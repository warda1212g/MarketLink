<?php
/** Layout for the About and Contact pages */
declare(strict_types=1);

require_once __DIR__ . '/navbar.php';

/**
 * @param array $opts title, description, active (navbar key),
 *                    hero => [eyebrow, lines (array of heading lines), copy, image]
 */
function home_layout_start(array $opts): void
{
    $hero = $opts['hero'];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($opts['title']) ?>, MarketLink</title>
<?php if (!empty($opts['description'])): ?><meta name="description" content="<?= e($opts['description']) ?>"><?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Montserrat:wght@400;500;600;700&family=Manrope:wght@300;400;500;600;700&family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,500;0,9..144,600;1,9..144,400;1,9..144,500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('assets/css/style.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('assets/css/navbar.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('assets/css/marketplace.css')) ?>">
<?php foreach ($opts['styles'] ?? [] as $css): ?><link rel="stylesheet" href="<?= e(asset($css)) ?>">
<?php endforeach; ?>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-url" content="<?= e(base_url()) ?>">
</head>
<body>
<?php require APP_ROOT . '/includes/partials/icons.php'; ?>
<?php require APP_ROOT . '/includes/partials/home_icons.php'; ?>
<?php site_navbar($opts['active'] ?? ''); ?>
<?= str_replace('class="site-announcement ', 'class="site-announcement is-floating ', site_announcement_html()) ?>

<section class="hero hero-page" id="top">
  <div class="hero-media" style="background-image:url('<?= e($hero['image']) ?>')"></div>
  <div class="hero-overlay"></div>
  <div class="hero-panel">
    <div class="hero-panel-content">
      <p class="eyebrow reveal-up" style="--d:0.12s"><?= e($hero['eyebrow']) ?></p>
      <h1 class="hero-heading">
        <?php foreach ($hero['lines'] as $i => $line): ?>
          <span class="line"><span class="line-inner reveal-line" style="--d:<?= number_format(0.18 + $i * 0.08, 2) ?>s"><?= e($line) ?></span></span>
        <?php endforeach; ?>
      </h1>
      <p class="hero-copy reveal-up" style="--d:0.8s"><?= e($hero['copy']) ?></p>
    </div>
  </div>
  <div class="scroll-indicator reveal-up" style="--d:1s">
    <span>SCROLL TO DISCOVER</span>
    <div class="scroll-line"><i></i></div>
  </div>
</section>
<?php
}

function home_layout_end(): void
{
    site_footer(); ?>
<script src="<?= e(asset('assets/js/navbar.js')) ?>"></script>
<?php if (current_user()): ?><script src="<?= e(asset('assets/js/notifications.js')) ?>"></script><?php endif; ?>
<script src="<?= e(asset('assets/js/main.js')) ?>"></script>
<?php assistant_widget(); ?>
</body>
</html>
<?php
}
