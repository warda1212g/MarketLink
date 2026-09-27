<?php
/**
 * Customer dashboard shell: <head>, sidebar, top bar and closing scripts (same look as the vendor workspace).
 *
 * Usage:
 *   $user = customer_layout_start(['title' => 'My Orders', 'active' => 'orders', 'heading' => ..., 'subheading' => ...]);
 *   ...page content...
 *   customer_layout_end(['scripts' => ['assets/js/favorites.js']]);
 */
declare(strict_types=1);

require_once __DIR__ . '/assistant_widget.php';
require_once __DIR__ . '/components.php';

const CUSTOMER_NAV = [
    'Overview' => [
        ['key' => 'dashboard',     'label' => 'Dashboard',        'icon' => 'i-grid',  'href' => 'customer/dashboard.php'],
    ],
    'Shopping' => [
        ['key' => 'orders',        'label' => 'My Orders',        'icon' => 'i-bag',   'href' => 'customer/orders.php'],
        ['key' => 'cart',          'label' => 'My Cart',          'icon' => 'i-cart',  'href' => 'customer/cart.php'],
        ['key' => 'favorites',     'label' => 'Favorites',        'icon' => 'i-heart', 'href' => 'customer/favorites.php'],
        ['key' => 'alerts',        'label' => 'Stock Alerts',     'icon' => 'i-alert', 'href' => 'customer/stock-alerts.php'],
    ],
    'Account' => [
        ['key' => 'messages',      'label' => 'Messages',         'icon' => 'i-chat',  'href' => 'customer/messages.php'],
        ['key' => 'notifications', 'label' => 'Notifications',    'icon' => 'i-bell',  'href' => 'customer/notifications.php'],
        ['key' => 'profile',       'label' => 'Profile & Security', 'icon' => 'i-user', 'href' => 'customer/profile.php'],
    ],
];

/** Page guard for the customer dashboard pages. */
function require_customer_dashboard(): array
{
    $user = require_login();
    if ($user['role'] !== 'customer') {
        redirect(home_for($user));
    }
    if (!is_approved($user)) {
        redirect('auth/pending.php');
    }
    return $user;
}

function customer_greeting(): string
{
    $h = (int) date('G');
    return $h < 12 ? 'Good morning' : ($h < 17 ? 'Good afternoon' : 'Good evening');
}

/** Counts shown as sidebar badges. */
function customer_nav_counts(int $uid): array
{
    static $counts = [];
    if (!isset($counts[$uid])) {
        $counts[$uid] = [
            'orders' => (int) db_value("SELECT COUNT(*) FROM orders WHERE customer_id = ? AND status IN ('pending','accepted','preparing','ready','picked_up')", [$uid]),
        ];
    }
    return $counts[$uid];
}

function customer_layout_start(array $opts): array
{
    $user = require_customer_dashboard();
    $uid = (int) $user['id'];
    $active = $opts['active'] ?? '';
    $counts = customer_nav_counts($uid);
    $firstName = explode(' ', trim($user['name']))[0];
    $heading = $opts['heading'] ?? (customer_greeting() . ', ' . $firstName);
    $sub = $opts['subheading'] ?? 'Track your pre-orders and keep up with your local farmers.';
    $initial = mb_strtoupper(mb_substr(trim($user['name']), 0, 1));
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-url" content="<?= e(base_url()) ?>">
<meta name="robots" content="noindex">
<title><?= e(($opts['title'] ?? 'Dashboard') . ', MarketLink') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Fraunces:opsz,wght@9..144,500;9..144,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('assets/css/dashboard.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('assets/css/marketplace.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('assets/css/customer.css')) ?>">
</head>
<body class="dash customer-dash">
<?php require APP_ROOT . '/includes/partials/icons.php'; ?>
<a class="skip-link" href="#main">Skip to content</a>

<aside class="sidebar" id="sidebar" aria-label="Customer navigation">
  <div class="sidebar-head">
    <a href="<?= e(url('index.php')) ?>" class="sb-brand">
      <svg class="sb-brand-mark"><use href="#i-leafpin"/></svg>
      <span>MARKET<b>LINK</b></span>
    </a>
    <button class="icon-btn sb-close" data-sidebar-close aria-label="Close menu"><svg><use href="#i-close"/></svg></button>
  </div>

  <div class="sb-stall">
    <span class="sb-stall-avatar"><?= e($initial) ?></span>
    <div>
      <strong><?= e($user['name']) ?></strong>
      <small>Customer account</small>
    </div>
  </div>

  <nav class="sb-nav">
    <?php foreach (CUSTOMER_NAV as $group => $items): ?>
      <p class="sb-label"><?= e($group) ?></p>
      <?php foreach ($items as $item):
          $isActive = $active === $item['key'];
          $live = ['messages' => 'messages', 'notifications' => 'notifications', 'cart' => 'cart'][$item['key']] ?? null;
          $badge = $item['key'] === 'orders' ? $counts['orders'] : 0; ?>
        <a href="<?= e(url($item['href'])) ?>" class="sb-link<?= $isActive ? ' active' : '' ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
          <svg><use href="#<?= $item['icon'] ?>"/></svg>
          <span><?= e($item['label']) ?></span>
          <?php if ($live): ?><em class="sb-badge" data-badge="<?= $live ?>" hidden></em>
          <?php elseif ($badge > 0): ?><em class="sb-badge"><?= $badge > 99 ? '99+' : $badge ?></em><?php endif; ?>
        </a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>

  <div class="sb-foot">
    <a href="<?= e(url('markets.php')) ?>" class="sb-link"><svg><use href="#i-store"/></svg><span>Browse Markets</span></a>
    <a href="<?= e(url('index.php')) ?>" class="sb-link"><svg style="transform:rotate(180deg)"><use href="#i-arrow"/></svg><span>Back to Website</span></a>
    <form method="post" action="<?= e(url('auth/logout.php')) ?>">
      <?= csrf_field() ?>
      <button type="submit" class="sb-link sb-logout"><svg><use href="#i-logout"/></svg><span>Logout</span></button>
    </form>
  </div>
</aside>
<div class="sidebar-scrim" data-sidebar-close></div>

<div class="dash-main">
  <header class="topbar">
    <button class="icon-btn tb-menu" data-sidebar-open aria-label="Open menu" aria-controls="sidebar"><svg><use href="#i-menu"/></svg></button>
    <div class="tb-title">
      <h1><?= e($heading) ?></h1>
      <p><?= e($sub) ?></p>
    </div>

    <form class="tb-search" role="search" data-global-search data-order-url="customer/orders.php" action="<?= e(url('products.php')) ?>">
      <svg><use href="#i-search"/></svg>
      <input type="search" name="q" placeholder="Search products or #order" aria-label="Search products or your orders" autocomplete="off">
      <kbd>/</kbd>
    </form>

    <div class="tb-actions">
      <a class="icon-btn tb-bell" href="<?= e(url('customer/messages.php')) ?>" aria-label="Messages" title="Messages">
        <svg><use href="#i-chat"/></svg><span class="dot-badge" data-badge="messages" hidden></span>
      </a>
      <a class="icon-btn tb-bell" href="<?= e(url('customer/cart.php')) ?>" aria-label="Cart" title="Cart">
        <svg><use href="#i-cart"/></svg><span class="dot-badge" data-badge="cart" hidden></span>
      </a>
      <div class="dropdown" data-dropdown data-notif>
        <button class="icon-btn tb-bell" data-dropdown-toggle data-notif-toggle aria-label="Notifications" aria-expanded="false">
          <svg><use href="#i-bell"/></svg>
          <span class="dot-badge" data-badge="notifications" hidden></span>
        </button>
        <div class="dropdown-menu notif-menu nf-panel-inline" role="menu">
          <div class="nf-head"><strong>Notifications</strong><small data-notif-sub></small></div>
          <div class="nf-list" data-notif-list><div class="nf-empty">Loading...</div></div>
          <div class="nf-foot"><button type="button" data-notif-readall>Mark all as read</button><a href="<?= e(url('customer/notifications.php')) ?>">View all notifications</a></div>
        </div>
      </div>

      <div class="dropdown" data-dropdown>
        <button class="tb-profile" data-dropdown-toggle aria-expanded="false" aria-label="Account menu">
          <span class="avatar"><?= e($initial) ?></span>
          <span class="tb-profile-text"><strong><?= e($user['name']) ?></strong><small>Customer</small></span>
          <svg class="chev"><use href="#i-chev"/></svg>
        </button>
        <div class="dropdown-menu" role="menu">
          <a href="<?= e(url('customer/profile.php')) ?>" role="menuitem"><svg><use href="#i-user"/></svg>Profile &amp; Security</a>
          <a href="<?= e(url('customer/orders.php')) ?>" role="menuitem"><svg><use href="#i-bag"/></svg>My Orders</a>
          <a href="<?= e(url('index.php')) ?>" role="menuitem"><svg><use href="#i-store"/></svg>Back to website</a>
          <form method="post" action="<?= e(url('auth/logout.php')) ?>">
            <?= csrf_field() ?>
            <button type="submit" role="menuitem"><svg><use href="#i-logout"/></svg>Logout</button>
          </form>
        </div>
      </div>
    </div>
  </header>

  <main class="dash-content" id="main">
<?php foreach (take_flashes() as $f): ?>
    <div class="alert alert-<?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div>
<?php endforeach; ?>
<?php
    return $user;
}

function customer_layout_end(array $opts = []): void
{
    ?>
  </main>
</div>

<div class="toast-stack" id="toastStack" aria-live="polite" aria-atomic="false"></div>

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

<script src="<?= e(asset('assets/js/vendor/core.js')) ?>"></script>
<script src="<?= e(asset('assets/js/notifications.js')) ?>"></script>
<script src="<?= e(asset('assets/js/report.js')) ?>"></script>
<?php foreach ($opts['scripts'] ?? [] as $src): ?>
<script src="<?= e(asset($src)) ?>"></script>
<?php endforeach; ?>
<?php assistant_widget(); ?>
</body>
</html>
<?php
}
