<?php
/** Minimal shell shared by login / register. */
declare(strict_types=1);

require_once __DIR__ . '/navbar.php';

function auth_layout_start(string $title): void
{ ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?>, MarketLink</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@500;600;700&family=Manrope:wght@400;500;600;700;800&family=Fraunces:ital,opsz,wght@0,9..144,500;0,9..144,600;1,9..144,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('assets/css/dashboard.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('assets/css/auth.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('assets/css/navbar.css')) ?>">
</head>
<body class="dash auth-page">
<?php require APP_ROOT . '/includes/partials/icons.php'; ?>
<?php site_navbar('', true); ?>
<div class="auth-wrap">
  <aside class="auth-side" style="background-image:url('https://images.unsplash.com/photo-1500937386664-56d1dfef3854?q=80&amp;w=1400&amp;auto=format&amp;fit=crop')">
    <div class="auth-side-inner">
      <a href="<?= e(url('index.php')) ?>" class="sb-brand"><svg class="sb-brand-mark"><use href="#i-leafpin"/></svg><span>MARKET<b>LINK</b></span></a>
      <div>
        <h2>Local markets.<br><em>Fresh connections.</em></h2>
        <p>Publish weekly stock, manage pre-orders and meet the customers who shop your stall.</p>
      </div>
    </div>
  </aside>
  <main class="auth-main">
    <div class="auth-card">
<?php foreach (take_flashes() as $f): ?>
      <div class="alert alert-<?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div>
<?php endforeach; ?>
<?php }

function auth_layout_end(): void
{ ?>
    </div>
  </main>
</div>
<script src="<?= e(asset('assets/js/navbar.js')) ?>"></script>
</body>
</html>
<?php }
