<?php
/**
 * Vendor dashboard shell: <head>, sidebar, top bar and closing scripts.
 *
 * Usage:
 *   vendor_layout_start(['title' => 'My Products', 'active' => 'products', 'heading' => ..., 'subheading' => ...]);
 *   ...page content...
 *   vendor_layout_end(['scripts' => ['assets/js/vendor/products.js']]);
 */
declare(strict_types=1);

require_once __DIR__ . '/assistant_widget.php';

const VENDOR_NAV = [
    ['key' => 'dashboard', 'label' => 'Dashboard',        'icon' => 'i-grid',     'href' => 'vendor/dashboard.php'],
    ['key' => 'profile',   'label' => 'My Profile',       'icon' => 'i-user',     'href' => 'vendor/profile.php'],
    ['key' => 'markets',   'label' => 'My Stall / Market', 'icon' => 'i-store',   'href' => 'vendor/markets.php'],
    ['key' => 'products',  'label' => 'My Products',      'icon' => 'i-box',      'href' => 'vendor/products.php'],
    ['key' => 'weekly',    'label' => 'Weekly Stock',     'icon' => 'i-calendar', 'href' => 'vendor/weekly-stock.php'],
    ['key' => 'orders',    'label' => 'My Orders',        'icon' => 'i-cart',     'href' => 'vendor/orders.php'],
    ['key' => 'messages',  'label' => 'Messages',         'icon' => 'i-chat',     'href' => 'vendor/messages.php'],
    ['key' => 'notifications', 'label' => 'Notifications', 'icon' => 'i-bell',    'href' => 'vendor/notifications.php'],
    ['key' => 'cutoff',    'label' => 'Cut-Off Settings', 'icon' => 'i-clock',    'href' => 'vendor/cutoff.php'],
    ['key' => 'analytics', 'label' => 'Analytics / Sales', 'icon' => 'i-chart',   'href' => 'vendor/analytics.php'],
    ['key' => 'reviews',   'label' => 'Reviews',          'icon' => 'i-star-o',   'href' => 'vendor/reviews.php'],
    ['key' => 'location',  'label' => 'Location',         'icon' => 'i-pin',      'href' => 'vendor/location.php'],
];

function greeting(): string
{
    $h = (int) date('G');
    return $h < 12 ? 'Good Morning' : ($h < 17 ? 'Good Afternoon' : 'Good Evening');
}

/** Counts that drive sidebar badges and the notification bell. */
function vendor_nav_counts(array $vendor): array
{
    static $counts = null;
    if ($counts === null) {
        $vid = (int) $vendor['id'];
        $counts = [
            'pending'    => (int) db_value('SELECT COUNT(*) FROM orders WHERE vendor_id = ? AND status = "pending"', [$vid]),
            'unanswered' => (int) db_value('SELECT COUNT(*) FROM reviews WHERE vendor_id = ? AND vendor_reply IS NULL', [$vid]),
            'low_stock'  => (int) db_value('SELECT COUNT(*) FROM products WHERE vendor_id = ? AND deleted_at IS NULL AND quantity <= ?', [$vid, $vendor['low_stock_threshold']]),
        ];
    }
    return $counts;
}

function vendor_notifications(array $vendor): array
{
    $vid = (int) $vendor['id'];
    $items = [];
    foreach (db_all('SELECT o.id, o.order_number, o.total_amount, o.created_at, u.name FROM orders o JOIN users u ON u.id = o.customer_id
                     WHERE o.vendor_id = ? AND o.status = "pending" ORDER BY o.created_at DESC LIMIT 5', [$vid]) as $o) {
        $items[] = ['icon' => 'i-cart', 'text' => 'New pre-order #' . $o['order_number'] . ' from ' . $o['name'] . ', ' . money($o['total_amount']),
                    'time' => time_ago($o['created_at']), 'href' => url('vendor/orders.php?status=pending&open=' . $o['id'])];
    }
    foreach (db_all('SELECT r.id, r.rating, r.created_at, u.name FROM reviews r JOIN users u ON u.id = r.customer_id
                     WHERE r.vendor_id = ? AND r.vendor_reply IS NULL ORDER BY r.created_at DESC LIMIT 3', [$vid]) as $r) {
        $items[] = ['icon' => 'i-star', 'text' => $r['name'] . ' left a ' . $r['rating'] . '-star review', 'time' => time_ago($r['created_at']),
                    'href' => url('vendor/reviews.php?filter=unanswered')];
    }
    $low = vendor_nav_counts($vendor)['low_stock'];
    if ($low > 0) {
        $items[] = ['icon' => 'i-alert', 'text' => $low . ' product' . ($low > 1 ? 's are' : ' is') . ' running low on stock', 'time' => '', 'href' => url('vendor/products.php?stock=low')];
    }
    return $items;
}

function vendor_layout_start(array $opts): void
{
    $vendor = current_vendor();
    $user = current_user();
    $active = $opts['active'] ?? '';
    $title = ($opts['title'] ?? 'Dashboard') . ', MarketLink Vendor';
    $counts = vendor_nav_counts($vendor);
    $firstName = explode(' ', trim($vendor['contact_person'] ?: $user['name']))[0];
    $heading = $opts['heading'] ?? (greeting() . ', ' . $firstName);
    $sub = $opts['subheading'] ?? 'Manage your market stall and weekly inventory.';
    $avatar = upload_url($vendor['image']);
    $initials = strtoupper(mb_substr($vendor['stall_name'] ?: $user['name'], 0, 1));
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-url" content="<?= e(base_url()) ?>">
<meta name="robots" content="noindex">
<title><?= e($title) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Fraunces:opsz,wght@9..144,500;9..144,600&display=swap" rel="stylesheet">
<?php foreach ($opts['styles'] ?? [] as $href): ?>
<link rel="stylesheet" href="<?= e($href) ?>">
<?php endforeach; ?>
<link rel="stylesheet" href="<?= e(asset('assets/css/dashboard.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('assets/css/marketplace.css')) ?>">
</head>
<body class="dash">
<?php require APP_ROOT . '/includes/partials/icons.php'; ?>
<a class="skip-link" href="#main">Skip to content</a>

<aside class="sidebar" id="sidebar" aria-label="Vendor navigation">
  <div class="sidebar-head">
    <a href="<?= e(url('vendor/dashboard.php')) ?>" class="sb-brand">
      <svg class="sb-brand-mark"><use href="#i-leafpin"/></svg>
      <span>MARKET<b>LINK</b></span>
    </a>
    <button class="icon-btn sb-close" data-sidebar-close aria-label="Close menu"><svg><use href="#i-close"/></svg></button>
  </div>

  <div class="sb-stall">
    <span class="sb-stall-avatar"<?= $avatar ? ' style="background-image:url(\'' . e($avatar) . '\')"' : '' ?>><?= $avatar ? '' : e($initials) ?></span>
    <div>
      <strong><?= e($vendor['stall_name']) ?></strong>
      <small>Vendor workspace</small>
    </div>
  </div>

  <nav class="sb-nav">
    <p class="sb-label">Menu</p>
    <?php foreach (VENDOR_NAV as $item):
        $badge = $item['key'] === 'orders' ? $counts['pending'] : ($item['key'] === 'reviews' ? $counts['unanswered'] : 0);
        $live = ['messages' => 'messages', 'notifications' => 'notifications'][$item['key']] ?? null; ?>
      <a href="<?= e(url($item['href'])) ?>" class="sb-link<?= $active === $item['key'] ? ' active' : '' ?>"<?= $active === $item['key'] ? ' aria-current="page"' : '' ?>>
        <svg><use href="#<?= $item['icon'] ?>"/></svg>
        <span><?= e($item['label']) ?></span>
        <?php if ($live): ?><em class="sb-badge" data-badge="<?= $live ?>" hidden></em>
        <?php elseif ($badge > 0): ?><em class="sb-badge"><?= $badge > 99 ? '99+' : $badge ?></em><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <div class="sb-foot">
    <a href="<?= e(url('vendor/settings.php')) ?>" class="sb-link<?= $active === 'settings' ? ' active' : '' ?>"><svg><use href="#i-settings"/></svg><span>Settings</span></a>
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
      <h1><?= e($heading) ?><?= empty($opts['heading']) ? '' : '' ?></h1>
      <p><?= e($sub) ?></p>
    </div>

    <form class="tb-search" role="search" data-global-search action="<?= e(url('vendor/products.php')) ?>">
      <svg><use href="#i-search"/></svg>
      <input type="search" name="q" placeholder="Search products or #order..." aria-label="Search products or orders" autocomplete="off">
      <kbd>/</kbd>
    </form>

    <div class="tb-actions">
      <a class="icon-btn tb-bell" href="<?= e(url('vendor/messages.php')) ?>" aria-label="Messages" title="Messages">
        <svg><use href="#i-chat"/></svg><span class="dot-badge" data-badge="messages" hidden></span>
      </a>
      <div class="dropdown" data-dropdown data-notif>
        <button class="icon-btn tb-bell" data-dropdown-toggle data-notif-toggle aria-label="Notifications" aria-expanded="false">
          <svg><use href="#i-bell"/></svg>
          <span class="dot-badge" data-badge="notifications" hidden></span>
        </button>
        <div class="dropdown-menu notif-menu nf-panel-inline" role="menu">
          <div class="nf-head"><strong>Notifications</strong><small data-notif-sub></small></div>
          <div class="nf-list" data-notif-list><div class="nf-empty">Loading...</div></div>
          <div class="nf-foot"><button type="button" data-notif-readall>Mark all as read</button><a href="<?= e(url('vendor/notifications.php')) ?>">View all notifications</a></div>
        </div>
      </div>

      <div class="dropdown" data-dropdown>
        <button class="tb-profile" data-dropdown-toggle aria-expanded="false" aria-label="Account menu">
          <span class="avatar"<?= $avatar ? ' style="background-image:url(\'' . e($avatar) . '\')"' : '' ?>><?= $avatar ? '' : e($initials) ?></span>
          <span class="tb-profile-text"><strong><?= e($vendor['contact_person'] ?: $user['name']) ?></strong><small>Vendor</small></span>
          <svg class="chev"><use href="#i-chev"/></svg>
        </button>
        <div class="dropdown-menu" role="menu">
          <a href="<?= e(url('vendor/profile.php')) ?>" role="menuitem"><svg><use href="#i-user"/></svg>My Profile</a>
          <a href="<?= e(url('vendor/settings.php')) ?>" role="menuitem"><svg><use href="#i-settings"/></svg>Settings</a>
          <a href="<?= e(url('market.php?id=' . $vendor['id'])) ?>" role="menuitem" target="_blank" rel="noopener"><svg><use href="#i-store"/></svg>View public stall</a>
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
}

function vendor_layout_end(array $opts = []): void
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

<?php foreach ($opts['vendor_scripts'] ?? [] as $src): ?>
<script src="<?= e($src) ?>"></script>
<?php endforeach; ?>
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
