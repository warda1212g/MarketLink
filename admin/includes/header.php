<?php
/**
 * Admin layout - opening half. The including page sets:
 *   $user (from require_admin_page()), $activeNav, $pageTitle, $pageSubtitle
 */
$initials = strtoupper(implode('', array_map(
    fn($p) => mb_substr($p, 0, 1),
    array_slice(preg_split('/\s+/', trim($user['name'])), 0, 2)
)));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-url" content="<?= e(base_url()) ?>">
<title><?= e($pageTitle) ?> - MarketLink Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=Manrope:wght@300;400;500;600;700&family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,500;0,9..144,600;1,9..144,400;1,9..144,500&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<?php foreach ($pageStyles ?? [] as $href): ?>
<link rel="stylesheet" href="<?= e($href) ?>">
<?php endforeach; ?>
<link rel="stylesheet" href="<?= e(asset('assets/css/marketplace.css')) ?>">
<link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="adm-body">
<?php include __DIR__ . '/icons.php'; ?>
<?php include APP_ROOT . '/includes/partials/icons.php'; ?>
<?php include __DIR__ . '/sidebar.php'; ?>

<div class="adm-main">
  <header class="adm-topbar">
    <div class="adm-topbar-left">
      <button class="adm-icon-btn" id="admSidebarToggle" aria-label="Toggle menu" aria-controls="admSidebar" aria-expanded="false">
        <svg viewBox="0 0 24 24"><use href="#icon-menu"/></svg>
      </button>
      <div>
        <p class="adm-topbar-title"><?= e($pageTitle) ?></p>
        <p class="adm-topbar-sub"><?= e($pageSubtitle) ?></p>
      </div>
    </div>

    <div class="adm-topbar-right">
    <div class="nf" data-notif data-notif-standalone>
      <button type="button" class="adm-icon-btn adm-bell" data-notif-toggle aria-label="Notifications" aria-expanded="false">
        <svg viewBox="0 0 24 24"><use href="#icon-bell"/></svg><em class="count-badge" data-badge="notifications" hidden></em>
      </button>
      <div class="nf-panel" data-notif-panel hidden>
        <div class="nf-head"><strong>Notifications</strong><small data-notif-sub></small></div>
        <div class="nf-list" data-notif-list><div class="nf-empty">Loading...</div></div>
        <div class="nf-foot"><button type="button" data-notif-readall>Mark all as read</button><a href="notifications.php">View all</a></div>
      </div>
    </div>
    <div class="dropdown">
      <button class="adm-profile" data-bs-toggle="dropdown" aria-expanded="false">
        <span class="adm-avatar"><?= e($initials) ?></span>
        <span class="adm-profile-text">
          <strong><?= e($user['name']) ?></strong>
          <small>Administrator</small>
        </span>
        <svg viewBox="0 0 24 24" class="adm-chev"><use href="#icon-chevron"/></svg>
      </button>
      <ul class="dropdown-menu dropdown-menu-end adm-dropdown">
        <li><a class="dropdown-item" href="settings.php#profile"><svg viewBox="0 0 24 24"><use href="#icon-user"/></svg> Profile</a></li>
        <li><a class="dropdown-item" href="settings.php"><svg viewBox="0 0 24 24"><use href="#icon-settings"/></svg> Settings</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><form method="post" action="<?= e(url('auth/logout.php')) ?>"><?= csrf_field() ?><button type="submit" class="dropdown-item"><svg viewBox="0 0 24 24"><use href="#icon-logout"/></svg> Logout</button></form></li>
      </ul>
    </div>
    </div>
  </header>

  <main class="adm-content">
