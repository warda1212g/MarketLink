<?php
require_once APP_ROOT . '/includes/services/contact.php';
/** @var string $activeNav set by the including page */
$navGroups = [
    'Overview' => [
        'dashboard'  => ['Dashboard',             'dashboard.php',     'icon-grid'],
        'reports'    => ['Reports & Analytics',   'reports.php',       'icon-chart'],
    ],
    'Marketplace' => [
        'farmers'    => ['Farmers & Vendors',     'farmers.php',       'icon-farmer'],
        'types'      => ['Market Categories',     'market-types.php',  'icon-layers'],
        'markets'    => ['Markets & Stalls',      'markets.php',       'icon-stall'],
        'moderation' => ['Content & Moderation',  'moderation.php',    'icon-shield'],
    ],
    'Communication' => [
        'notifications' => ['Notifications',      'notifications.php', 'icon-bell'],
        'messages'   => ['Contact Messages',      'messages.php',      'icon-mail'],
    ],
    'System' => [
        'settings'   => ['Settings',              'settings.php',      'icon-settings'],
    ],
];
$openReports = (int) db_value("SELECT COUNT(*) FROM reports WHERE status = 'open'");
$pendingVendors = (int) db_value("SELECT COUNT(*) FROM users WHERE role = 'vendor' AND approval_status = 'pending'");
$unreadMsgs = contact_unread_count();
$navCounts = ['messages' => $unreadMsgs, 'moderation' => $openReports, 'farmers' => $pendingVendors];
$sbInitials = strtoupper(implode('', array_map(fn($p) => mb_substr($p, 0, 1), array_slice(preg_split('/\s+/', trim($user['name'])), 0, 2))));
?>
<aside class="adm-sidebar" id="admSidebar" aria-label="Admin navigation">
  <div class="adm-sidebar-head">
    <a href="dashboard.php" class="brand" aria-label="MarketLink admin home">
      <svg class="brand-mark" viewBox="0 0 40 40" aria-hidden="true"><use href="#icon-leafpin"/></svg>
      <span class="brand-word">MARKET<span class="brand-word-accent">LINK</span></span>
    </a>
    <button class="adm-sidebar-close" id="admSidebarClose" aria-label="Close menu"><svg viewBox="0 0 24 24"><use href="#icon-close"/></svg></button>
  </div>

  <a href="settings.php#profile" class="adm-sb-user" title="<?= e($user['name']) ?>">
    <span class="adm-sb-avatar"><?= e($sbInitials) ?></span>
    <span class="adm-sb-user-text"><strong><?= e($user['name']) ?></strong><small><i aria-hidden="true"></i>Administrator</small></span>
  </a>

  <nav class="adm-nav" aria-label="Admin sections">
    <?php foreach ($navGroups as $group => $items): ?>
      <p class="adm-nav-label"><?= e($group) ?></p>
      <?php foreach ($items as $key => [$label, $href, $icon]): $n = $navCounts[$key] ?? 0; ?>
        <a href="<?= e($href) ?>" class="adm-nav-link<?= $activeNav === $key ? ' active' : '' ?>"<?= $activeNav === $key ? ' aria-current="page"' : '' ?> title="<?= e($label) ?>">
          <span class="adm-nav-icon"><svg viewBox="0 0 24 24"><use href="#<?= e($icon) ?>"/></svg></span>
          <span class="adm-nav-text"><?= e($label) ?></span>
          <?php if ($n): ?><b class="adm-nav-count<?= $key === 'farmers' || $key === 'moderation' ? ' is-alert' : '' ?>"><?= $n > 99 ? '99+' : $n ?></b><?php endif; ?>
          <?php if ($key === 'notifications'): ?><b class="adm-nav-count" data-badge="notifications" hidden></b><?php endif; ?>
        </a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>

  <div class="adm-sidebar-foot">
    <a href="<?= e(url('index.php')) ?>" class="adm-nav-link" target="_blank" rel="noopener" title="View website">
      <span class="adm-nav-icon"><svg viewBox="0 0 24 24"><use href="#icon-link"/></svg></span>
      <span class="adm-nav-text">View Website</span>
    </a>
    <form method="post" action="<?= e(url('auth/logout.php')) ?>">
      <?= csrf_field() ?>
      <button type="submit" class="adm-nav-link adm-nav-logout" title="Logout">
        <span class="adm-nav-icon"><svg viewBox="0 0 24 24"><use href="#icon-logout"/></svg></span>
        <span class="adm-nav-text">Logout</span>
      </button>
    </form>
  </div>
</aside>
<div class="adm-backdrop" id="admBackdrop"></div>
