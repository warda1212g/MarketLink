<?php
/**
 * Navbar, side rail and footer for the website pages.
 */
declare(strict_types=1);

require_once __DIR__ . '/assistant_widget.php';

/**
 * Top menu links: [key, label, href]
 */
function site_nav_items(bool $onHome, ?array $user = null): array
{
    // Vendors get their own "My Products" page; everyone else gets the live all-products page.
    $productsHref = ($user && $user['role'] === 'vendor')
        ? url('vendor/products.php')
        : url('products.php');

    return [
        ['home', 'Home', $onHome ? '#home' : url('index.php')],
        ['markets', 'Markets', url('markets.php')],
        ['products', 'Products', $productsHref],
        ['about', 'About Us', url('about.php')],
        ['contact', 'Contact Us', url('contact.php')],
    ];
}

/** Notification centre for each role. */
function notifications_page_path(array $user): string
{
    return ['admin' => 'admin/notifications.php', 'vendor' => 'vendor/notifications.php'][$user['role']] ?? 'customer/notifications.php';
}

/** Dashboard link for a signed-in user. */
function site_dashboard_url(array $user): string
{
    if ($user['role'] === 'admin') {
        return url('admin/dashboard.php');
    }
    return url($user['role'] === 'vendor' ? 'vendor/dashboard.php' : 'customer/dashboard.php');
}

/**
 * @param string $active current menu item
 * @param bool   $glass  true on pages without a dark hero
 */
function site_navbar(string $active = '', bool $glass = false): void
{
    $user = current_user();
    $items = site_nav_items($active === 'home', $user);
    ?>
<header class="ml-nav<?= $glass ? ' is-glass' : '' ?>" id="mlNav">
  <div class="ml-nav-inner">
    <a href="<?= e(url('index.php')) ?>" class="ml-brand" aria-label="MarketLink home">
      <svg class="ml-brand-mark" viewBox="0 0 40 40" aria-hidden="true"><use href="#i-leafpin"/></svg>
      <span class="ml-brand-word">MARKET<span>LINK</span></span>
    </a>

    <nav class="ml-nav-links" id="mlNavLinks" aria-label="Main">
      <?php foreach ($items as [$key, $label, $href]): ?>
        <a href="<?= e($href) ?>" class="<?= $key === $active ? 'active' : '' ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>

    <button class="ml-nav-toggle" id="mlNavToggle" type="button" aria-label="Open menu" aria-expanded="false" aria-controls="mlNavLinks">
      <svg viewBox="0 0 24 24" class="ml-open"><use href="#i-menu"/></svg>
      <svg viewBox="0 0 24 24" class="ml-close"><use href="#i-close"/></svg>
    </button>
  </div>
</header>

<aside class="ml-rail" aria-label="Account shortcuts">
  <?php if (!$user): ?>
    <a class="ml-rail-btn" href="<?= e(url('auth/login.php')) ?>" data-tip="Login" aria-label="Login"><svg viewBox="0 0 24 24"><use href="#i-login"/></svg></a>
    <a class="ml-rail-btn" href="<?= e(url('auth/register.php')) ?>" data-tip="Sign Up" aria-label="Sign up"><svg viewBox="0 0 24 24"><use href="#i-user-plus"/></svg></a>
    <a class="ml-rail-btn" href="<?= e(url('admin/login.php')) ?>" data-tip="Admin Login" aria-label="Admin login"><svg viewBox="0 0 24 24"><use href="#i-lock"/></svg></a>
  <?php else: ?>
    <a class="ml-rail-btn ml-rail-avatar" href="<?= e(site_dashboard_url($user)) ?>" data-tip="<?= e($user['name']) ?>" aria-label="Signed in as <?= e($user['name']) ?>"><?= e(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?></a>
    <a class="ml-rail-btn<?= $active === 'dashboard' ? ' active' : '' ?>" href="<?= e(site_dashboard_url($user)) ?>" data-tip="Dashboard" aria-label="Dashboard"><svg viewBox="0 0 24 24"><use href="#i-grid"/></svg></a>
    <?php if ($user['role'] === 'customer'): ?>
      <a class="ml-rail-btn<?= $active === 'cart' ? ' active' : '' ?>" href="<?= e(url('cart.php')) ?>" data-tip="Cart" aria-label="Cart"><svg viewBox="0 0 24 24"><use href="#i-cart"/></svg><em class="count-badge" data-badge="cart" hidden></em></a>
      <a class="ml-rail-btn<?= $active === 'orders' ? ' active' : '' ?>" href="<?= e(url('customer/orders.php')) ?>" data-tip="My Orders" aria-label="My orders"><svg viewBox="0 0 24 24"><use href="#i-bag"/></svg></a>
      <a class="ml-rail-btn" href="<?= e(url('customer/favorites.php')) ?>" data-tip="My Favorites" aria-label="My favorites"><svg viewBox="0 0 24 24"><use href="#i-heart"/></svg></a>
    <?php endif; ?>
    <?php if ($user['role'] !== 'admin'): ?>
      <a class="ml-rail-btn<?= $active === 'messages' ? ' active' : '' ?>" href="<?= e(url($user['role'] === 'vendor' ? 'vendor/messages.php' : 'messages.php')) ?>" data-tip="Messages" aria-label="Messages"><svg viewBox="0 0 24 24"><use href="#i-chat"/></svg><em class="count-badge" data-badge="messages" hidden></em></a>
    <?php endif; ?>
    <div class="nf" data-notif data-notif-standalone>
      <button type="button" class="ml-rail-btn<?= $active === 'notifications' ? ' active' : '' ?>" data-notif-toggle data-tip="Notifications" aria-label="Notifications" aria-expanded="false"><svg viewBox="0 0 24 24"><use href="#i-bell"/></svg><em class="count-badge" data-badge="notifications" hidden></em></button>
      <div class="nf-panel" data-notif-panel hidden>
        <div class="nf-head"><strong>Notifications</strong><small data-notif-sub></small></div>
        <div class="nf-list" data-notif-list><div class="nf-empty">Loading...</div></div>
        <div class="nf-foot"><button type="button" data-notif-readall>Mark all as read</button><a href="<?= e(url(notifications_page_path($user))) ?>">View all notifications</a></div>
      </div>
    </div>
    <?php if ($user['role'] === 'customer'): ?>
      <a class="ml-rail-btn" href="<?= e(url('customer/profile.php')) ?>" data-tip="Profile" aria-label="Profile"><svg viewBox="0 0 24 24"><use href="#i-user"/></svg></a>
    <?php endif; ?>
    <form method="post" action="<?= e(url('auth/logout.php')) ?>">
      <?= csrf_field() ?>
      <button type="submit" class="ml-rail-btn" data-tip="Logout" aria-label="Logout"><svg viewBox="0 0 24 24"><use href="#i-logout"/></svg></button>
    </form>
  <?php endif; ?>
</aside>
<?php
}

function site_footer(): void
{
    ?>
<footer class="ml-foot">
  <div class="ml-foot-grid">
    <div class="ml-foot-brand">
      <a href="<?= e(url('index.php')) ?>" class="ml-brand">
        <svg class="ml-brand-mark" viewBox="0 0 40 40" aria-hidden="true"><use href="#i-leafpin"/></svg>
        <span class="ml-brand-word">MARKET<span>LINK</span></span>
      </a>
      <p>Local markets. Fresh connections.</p>
    </div>
    <div class="ml-foot-col">
      <h4>EXPLORE</h4>
      <a href="<?= e(url('markets.php')) ?>">Markets</a>
      <a href="<?= e(url('index.php#farmers')) ?>">Farmers</a>
      <a href="<?= e(url('products.php')) ?>">Products</a>
      <a href="<?= e(url('index.php#categories')) ?>">Categories</a>
    </div>
    <div class="ml-foot-col">
      <h4>FOR FARMERS</h4>
      <a href="<?= e(url('auth/register.php?role=vendor')) ?>">Join MarketLink</a>
      <a href="<?= e(url('vendor/dashboard.php')) ?>">Farmer Dashboard</a>
      <a href="<?= e(url('vendor/weekly-stock.php')) ?>">Manage Stock</a>
      <a href="<?= e(url('vendor/orders.php')) ?>">Orders</a>
    </div>
    <div class="ml-foot-col">
      <h4>SUPPORT</h4>
      <a href="<?= e(url('about.php')) ?>">About Us</a>
      <a href="<?= e(url('contact.php')) ?>">Contact Us</a>
      <a href="#">Privacy Policy</a>
      <a href="#">Terms &amp; Conditions</a>
    </div>
  </div>
  <div class="ml-foot-bottom">
    <span>&copy; <?= date('Y') ?> MarketLink. All rights reserved.</span>
    <span>Maps &copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors</span>
  </div>
</footer>
<?php
}
