<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once APP_ROOT . '/includes/partials/navbar.php';
require_once APP_ROOT . '/includes/services/home.php';

$user = current_user();
$isCustomer = $user && $user['role'] === 'customer';
$favs = $isCustomer ? favorite_ids((int) $user['id']) : ['vendor' => [], 'product' => []];

$stats      = home_stats();
$market     = home_featured_market();
$mapMarkets = array_map(fn($m) => ['id' => (int) $m['id'], 'name' => $m['name'], 'city' => $m['city'], 'type' => $m['type'], 'lat' => (float) $m['latitude'], 'lng' => (float) $m['longitude']],
    db_all("SELECT m.id, m.name, m.city, t.name AS type, m.latitude, m.longitude FROM markets m LEFT JOIN market_types t ON t.id = m.market_type_id
             WHERE m.status = 'active' AND m.latitude IS NOT NULL AND m.longitude IS NOT NULL ORDER BY m.id"));
$categories = home_categories(6);
$products   = home_products(10);
$topSold    = $products ? max(array_map(fn($p) => (float) $p['sold'], $products)) : 0;
$farmers    = home_farmers(4);
$order      = home_order($user);
$reviews    = home_reviews(3);
$favorites  = home_favorites($user, 3);
$assistant  = home_assistant_demo();
$stories    = home_stories($stats);

/** background-image style for a real upload, or '' so the .no-img fallback shows. */
$bg = fn(?string $img): string => $img ? ' style="background-image:url(\'' . e($img) . '\')"' : '';
$noImg = fn(?string $img): string => $img ? '' : ' no-img';
$star = '<svg viewBox="0 0 24 24"><use href="#icon-star"/></svg>';
$stars = fn(float $r): string => str_repeat($star, max(1, (int) round($r)));
$live = fn(string $key): string => '<span data-live="' . e($key) . '">' . e($stats[$key] === null ? '-' : ($key === 'avg_rating' ? number_format((float) $stats[$key], 1) : home_num((int) $stats[$key]))) . '</span>';

/** Heart: real toggle for customers, sign-in prompt for guests, hidden for vendors/admins. */
$heart = function (string $type, int $id, string $name) use ($user, $isCustomer, $favs): string {
    if ($user && !$isCustomer) {
        return '';
    }
    $active = $isCustomer && isset($favs[$type][$id]);
    $attrs = $isCustomer ? '' : ' data-login="' . e(url('auth/login.php?next=' . urlencode(url('index.php')))) . '"';
    return '<button type="button" class="fav-btn' . ($active ? ' active' : '') . '" data-fav-type="' . $type . '" data-fav-id="' . $id . '"' . $attrs
        . ' aria-pressed="' . ($active ? 'true' : 'false') . '" aria-label="' . e(($active ? 'Remove ' : 'Save ') . $name) . '"><svg viewBox="0 0 24 24"><use href="#icon-favorite"/></svg></button>';
};
$loginUrl = url('auth/login.php?next=' . urlencode(url('index.php#products')));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MarketLink - Local Markets, Fresh Connections</title>
<meta name="description" content="MarketLink connects you with <?= (int) $stats['farmers'] ?> local farmers across <?= (int) $stats['markets'] ?> markets. Discover fresh weekly stock, reserve what you need, and pick it up nearby.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Montserrat:wght@400;500;600;700&family=Manrope:wght@300;400;500;600;700&family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,500;0,9..144,600;1,9..144,400;1,9..144,500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('assets/css/style.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('assets/css/navbar.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('assets/css/marketplace.css')) ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="<?= e(asset('assets/css/home-live.css')) ?>">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-url" content="<?= e(base_url()) ?>">
</head>
<body>
<?php require APP_ROOT . '/includes/partials/icons.php'; ?>

<?php require APP_ROOT . '/includes/partials/home_icons.php'; ?>

<!-- Navbar -->
<?php site_navbar("home"); ?>
<?= str_replace('class="site-announcement ', 'class="site-announcement is-floating ', site_announcement_html()) ?>

<!-- Hero -->
<section class="hero" id="home">
  <div class="hero-media" style="background-image:url('assets/img/Hero-img.png')"></div>
  <div class="hero-overlay"></div>

  <div class="hero-panel" id="heroPanel">
    <div class="hero-panel-content">
      <p class="eyebrow reveal-up" style="--d:0.12s">LOCAL. FRESH. CONNECTED.</p>

      <h1 class="hero-heading">
        <span class="line"><span class="line-inner reveal-line" style="--d:0.18s">LOCAL</span></span>
        <span class="line"><span class="line-inner reveal-line" style="--d:0.26s">MARKETS.</span></span>
        <span class="line"><span class="line-inner reveal-line" style="--d:0.34s">FRESH</span></span>
        <span class="line"><span class="line-inner reveal-line" style="--d:0.42s">CONNECTIONS.</span></span>
      </h1>

      <p class="hero-copy reveal-up" style="--d:0.9s">Discover <?= $live('farmers') ?> local farmers across <?= $live('markets') ?> markets, explore <?= $live('in_stock') ?> fresh products in stock, and reserve what you need for pickup.</p>

      <div class="hero-cta reveal-up" style="--d:1.05s">
        <a href="#markets" class="btn btn-primary">
          <span>EXPLORE MARKETS</span>
          <svg viewBox="0 0 24 24"><use href="#icon-arrow"/></svg>
        </a>
        <a href="markets.php" class="link-arrow">Find a nearby market <svg viewBox="0 0 24 24"><use href="#icon-arrow"/></svg></a>
      </div>
    </div>
  </div>

  <div class="hero-side-nav reveal-up" style="--d:1.2s">
    <span class="dot active"></span>
    <span class="dot"></span>
    <span class="dot"></span>
    <span class="dot"></span>
  </div>

  <div class="scroll-indicator reveal-up" style="--d:1.3s">
    <span>SCROLL TO DISCOVER</span>
    <div class="scroll-line"><i></i></div>
  </div>
</section>

<!-- Value / trust strip -->
<section class="value-strip on-cream">
  <div class="container value-grid">
    <div class="value-item reveal-on-scroll">
      <svg class="value-icon" viewBox="0 0 40 40"><use href="#icon-leafpin"/></svg>
      <h3>100% Local</h3>
      <p><?= $live('markets') ?> local markets supporting your community.</p>
    </div>
    <div class="value-item reveal-on-scroll" style="--stagger:0.05s">
      <svg class="value-icon" viewBox="0 0 24 24"><use href="#icon-basket"/></svg>
      <h3>Fresh &amp; Seasonal</h3>
      <p><?= $live('in_stock') ?> products in stock, <?= $live('new_week') ?> new this week.</p>
    </div>
    <div class="value-item reveal-on-scroll" style="--stagger:0.1s">
      <svg class="value-icon" viewBox="0 0 24 24"><use href="#icon-pickup"/></svg>
      <h3>Easy Pickup</h3>
      <p><?= $live('completed') ?> reservations already collected at the market.</p>
    </div>
    <div class="value-item reveal-on-scroll" style="--stagger:0.15s">
      <svg class="value-icon" viewBox="0 0 24 24"><use href="#icon-farmer"/></svg>
      <h3>Direct From Farmers</h3>
      <p>Buy straight from <?= $live('farmers') ?> farmers who grow it.</p>
    </div>
    <div class="value-item reveal-on-scroll" style="--stagger:0.2s">
      <svg class="value-icon" viewBox="0 0 24 24"><use href="#icon-people"/></svg>
      <h3>Community First</h3>
      <p><?= $live('reviews') ?> reviews averaging <?= $live('avg_rating') ?>&#9733; from local shoppers.</p>
    </div>
  </div>
</section>

<!-- Discover your local market -->
<section class="section discover on-white" id="markets">
  <div class="container discover-inner">
    <div class="reveal-on-scroll">
      <p class="kicker">DISCOVER NEARBY</p>
      <h2 class="h-serif">Discover What's<br>Happening <em>Near You</em>.</h2>
      <p class="lede"><?= $live('markets') ?> markets and <?= $live('farmers') ?> farmers are on MarketLink right now. Find one near you and see what's fresh before you make the trip.</p>
      <div class="discover-actions">
        <a href="markets.php" class="btn-outline">EXPLORE MARKETS <svg viewBox="0 0 24 24"><use href="#icon-arrow"/></svg></a>
        <a href="markets.php?locate=1" class="link-arrow ink">Find Nearby <svg viewBox="0 0 24 24"><use href="#icon-arrow"/></svg></a>
      </div>

      <form class="nearby-form" action="markets.php" method="get" role="search" aria-label="Find markets near you">
        <p class="nearby-title"><svg viewBox="0 0 24 24"><use href="#icon-location"/></svg> Find Markets Near You</p>
        <div class="nearby-row">
          <input type="search" name="q" placeholder="Enter your location..." aria-label="Your location" minlength="3" required>
          <button type="submit" class="nearby-go" aria-label="Search nearby markets"><svg viewBox="0 0 24 24"><use href="#icon-search"/></svg></button>
        </div>
        <span class="nearby-or">or</span>
        <a class="nearby-locate" href="markets.php?locate=1"><svg viewBox="0 0 24 24"><use href="#icon-location"/></svg> Use My Current Location</a>
      </form>
    </div>

    <div class="discover-map reveal-on-scroll" style="--stagger:0.1s">
      <div class="home-map" id="homeMap" aria-label="Map of MarketLink markets across Pakistan"
           data-tiles="<?= e(config('map.tile_url')) ?>" data-attribution="<?= e(config('map.attribution')) ?>"
           data-markets="<?= e(json_encode($mapMarkets)) ?>"></div>
      <div class="home-map-legend"><span></span> <?= count($mapMarkets) ?> markets on the map</div>
      <?php if ($market): ?>
      <a class="map-float-card" href="market-view.php?id=<?= (int) $market['id'] ?>">
        <h4><?= e($market['name']) ?></h4>
        <p><svg viewBox="0 0 24 24"><use href="#icon-location"/></svg> <?= e($market['location'] ?: $market['type_name']) ?><?= $market['days_label'] ? ', ' . e($market['days_label']) : '' ?><?= $market['hours'] ? ', ' . e($market['hours']) : '' ?></p>
        <p><?= (int) $market['vendors'] ?> stalls, <?= (int) $market['products'] ?> products</p>
      </a>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- Shop by category -->
<section class="section on-beige" id="categories">
  <div class="container">
    <div class="section-head reveal-on-scroll">
      <p class="kicker">EXPLORE PRODUCTS</p>
      <h2 class="h-serif">Fresh Picks From<br><em>Local Farms</em>.</h2>
      <p class="lede">Browse <?= $live('products') ?> products across <?= $live('categories') ?> categories from farmers around your local markets.</p>
    </div>

    <div class="category-grid">
      <?php foreach ($categories as $i => $c): $img = upload_url($c['image']); ?>
      <a href="products.php?category=<?= (int) $c['id'] ?>" class="category-card reveal-on-scroll"<?= $i ? ' style="--stagger:' . number_format($i * 0.05, 2) . 's"' : '' ?>>
        <div class="category-card-img<?= $noImg($img) ?>"<?= $bg($img) ?>></div>
        <div class="category-card-body">
          <h3><?= e($c['name']) ?></h3>
          <p><span data-live-cat="<?= (int) $c['id'] ?>"><?= (int) $c['items'] ?></span> items <svg viewBox="0 0 24 24"><use href="#icon-arrow"/></svg></p>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- Featured / available products -->
<section class="section on-cream" id="products">
  <div class="container">
    <div class="products-head reveal-on-scroll">
      <div>
        <p class="kicker">WHAT'S FRESH THIS WEEK</p>
        <h2 class="h-serif" style="margin-bottom:8px;">What's Fresh <em>This Week</em></h2>
        <p class="lede">The latest stock from local farmers - prices and quantities update live. <a class="link-arrow ink" href="products.php">View all <svg viewBox="0 0 24 24"><use href="#icon-arrow"/></svg></a></p>
      </div>
      <div class="rail-arrows">
        <button class="rail-arrow prev" id="railPrev" aria-label="Previous products"><svg viewBox="0 0 24 24"><use href="#icon-arrow"/></svg></button>
        <button class="rail-arrow next" id="railNext" aria-label="Next products"><svg viewBox="0 0 24 24"><use href="#icon-arrow"/></svg></button>
      </div>
    </div>

    <div class="products-rail-wrap reveal-on-scroll">
      <div class="product-scroller" id="productRail">
        <?php if (!$products): ?>
          <p class="lede">No products are in stock right now - check back soon.</p>
        <?php endif; ?>
        <?php foreach ($products as $p): $img = upload_url($p['image']); $step = in_array($p['unit'], ['kg', 'g', 'litre'], true) ? 0.5 : 1; ?>
        <article class="product-card" data-product="<?= (int) $p['id'] ?>">
          <div class="product-img-wrap">
            <a href="market.php?id=<?= (int) $p['vendor_id'] ?>" class="product-img<?= $noImg($img) ?>"<?= $bg($img) ?> aria-label="<?= e($p['name']) ?>"></a>
            <span class="product-badge"><?= e(home_product_badge($p, $topSold)) ?></span>
            <?= $heart('product', (int) $p['id'], $p['name']) ?>
          </div>
          <div class="product-body">
            <h3><?= e($p['name']) ?></h3>
            <p class="product-meta-line"><?= e($p['stall_name']) ?><?= $p['market_name'] ? ', ' . e($p['market_name']) : '' ?></p>
            <p class="product-rating"><?= $p['rating'] !== null ? $stars((float) $p['rating']) . ' ' . e(number_format((float) $p['rating'], 1)) : '<span>New stall</span>' ?></p>
            <div class="product-row"><span class="price"><span class="price-val" data-live-price><?= e(money($p['price'])) ?></span><span> /<?= e($p['unit']) ?></span></span><span class="qty" data-live-qty><?= e(qty($p['quantity']) . ' ' . $p['unit'] . ' left') ?></span></div>
            <?php if ($isCustomer): ?>
              <button type="button" class="btn-reserve" data-add-cart="<?= (int) $p['id'] ?>" data-qty="<?= $step ?>"><svg viewBox="0 0 24 24"><use href="#icon-basket"/></svg> Reserve for Pickup</button>
            <?php elseif (!$user): ?>
              <a class="btn-reserve" href="<?= e($loginUrl) ?>"><svg viewBox="0 0 24 24"><use href="#icon-basket"/></svg> Sign in to Reserve</a>
            <?php else: ?>
              <a class="btn-reserve" href="market.php?id=<?= (int) $p['vendor_id'] ?>"><svg viewBox="0 0 24 24"><use href="#icon-stall"/></svg> View Stall</a>
            <?php endif; ?>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

<!-- Farmer spotlight -->
<section class="section on-white" id="farmers">
  <div class="container">
    <div class="section-head reveal-on-scroll">
      <p class="kicker">MEET THE GROWERS</p>
      <h2 class="h-serif">Meet the Farmers<br>Behind Your <em>Food</em>.</h2>
      <p class="lede">Our top-rated growers, from <?= $live('farmers') ?> farmers who harvest and bring fresh products to your local market.</p>
    </div>

    <div class="farmer-grid">
      <?php foreach ($farmers as $i => $f): $img = upload_url($f['image']); $tags = array_slice(array_filter(explode('|', (string) $f['tags'])), 0, 3); ?>
      <article class="farmer-card reveal-on-scroll"<?= $i ? ' style="--stagger:' . number_format($i * 0.1, 1) . 's"' : '' ?>>
        <div class="farmer-card-img-wrap">
          <div class="farmer-card-img<?= $noImg($img) ?>"<?= $bg($img) ?>></div>
          <?= $heart('vendor', (int) $f['id'], $f['stall_name']) ?>
        </div>
        <div class="farmer-card-body">
          <h3><?= e($f['stall_name']) ?></h3>
          <p class="farmer-market-tag"><svg viewBox="0 0 24 24"><use href="#icon-location"/></svg> <?= e($f['market_name'] ?: ($f['location_address'] ?: 'Local stall')) ?></p>
          <p class="desc"><?= e($f['description'] ? mb_strimwidth($f['description'], 0, 120, '...') : $f['products'] . ' products listed on MarketLink.') ?></p>
          <?php if ($tags): ?><div class="farmer-tags"><?php foreach ($tags as $t): ?><span><?= e($t) ?></span><?php endforeach; ?></div><?php endif; ?>
          <div class="farmer-card-foot">
            <p class="product-rating" style="margin:0;"><?= $star ?> <?= $f['rating'] !== null ? e(number_format((float) $f['rating'], 1)) . ' <small>(' . (int) $f['reviews'] . ')</small>' : 'New' ?></p>
            <a href="market.php?id=<?= (int) $f['id'] ?>" class="link-arrow ink">View Farmer <svg viewBox="0 0 24 24"><use href="#icon-arrow"/></svg></a>
          </div>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- How it works -->
<section class="section on-beige" id="how-it-works">
  <div class="container">
    <div class="section-head center reveal-on-scroll">
      <p class="kicker">THE PROCESS</p>
      <h2 class="h-serif">From Market to Pickup,<br><em>Made Simple</em>.</h2>
    </div>

    <div class="flow-grid">
      <div class="flow-step reveal-on-scroll">
        <span class="flow-num">01</span>
        <svg class="flow-icon" viewBox="0 0 24 24"><use href="#icon-location"/></svg>
        <h3>Find a Market</h3>
        <p>Choose from <?= $live('markets') ?> markets and <?= $live('farmers') ?> farmers.</p>
      </div>
      <div class="flow-step reveal-on-scroll" style="--stagger:0.1s">
        <span class="flow-num">02</span>
        <svg class="flow-icon" viewBox="0 0 24 24"><use href="#icon-basket"/></svg>
        <h3>Explore What's Fresh</h3>
        <p>Browse <?= $live('in_stock') ?> products with live stock and prices.</p>
      </div>
      <div class="flow-step reveal-on-scroll" style="--stagger:0.2s">
        <span class="flow-num">03</span>
        <svg class="flow-icon" viewBox="0 0 24 24"><use href="#icon-check"/></svg>
        <h3>Reserve Your Items</h3>
        <p>Join <?= $live('customers') ?> shoppers who have placed <?= $live('orders') ?> reservations.</p>
      </div>
      <div class="flow-step reveal-on-scroll" style="--stagger:0.3s">
        <span class="flow-num">04</span>
        <svg class="flow-icon" viewBox="0 0 24 24"><use href="#icon-pickup"/></svg>
        <h3>Pick Up at the Market</h3>
        <p><?= $live('completed') ?> orders collected so far - pay in person at pickup.</p>
      </div>
    </div>
  </div>
</section>

<!-- Reserve / pre-order split -->
<section class="reserve-split" id="reserve">
  <div class="reserve-media" style="background-image:url('https://images.unsplash.com/photo-1652805145082-d08f3706ffe4?q=80&amp;w=1200&amp;auto=format&amp;fit=crop')"></div>
  <div class="reserve-content on-forest">
    <div class="reveal-on-scroll">
      <p class="kicker">RESERVE BEFORE MARKET DAY</p>
      <h2 class="h-serif">Know What's Available<br>Before You <em>Go</em>.</h2>
      <p class="lede">Right now <?= $live('in_stock') ?> products are ready to reserve and <?= $live('open_orders') ?> reservations are on their way to pickup. Choose a pickup window and spend less time searching.</p>
      <ul class="feature-list">
        <li><svg viewBox="0 0 24 24"><use href="#icon-check"/></svg> See live, updated weekly stock</li>
        <li><svg viewBox="0 0 24 24"><use href="#icon-check"/></svg> Reserve available products</li>
        <li><svg viewBox="0 0 24 24"><use href="#icon-check"/></svg> Select a pickup date and time</li>
        <li><svg viewBox="0 0 24 24"><use href="#icon-check"/></svg> Track your order status</li>
        <li><svg viewBox="0 0 24 24"><use href="#icon-check"/></svg> Modify or cancel before cutoff time</li>
      </ul>
      <a href="products.php" class="btn btn-primary"><span>START EXPLORING</span><svg viewBox="0 0 24 24"><use href="#icon-arrow"/></svg></a>
    </div>
  </div>
</section>

<!-- Order status / pickup experience -->
<section class="section on-forest">
  <div class="container order-status-inner">
    <div class="reveal-on-scroll">
      <p class="kicker"><?= $order && $order['mine'] ? 'YOUR LATEST RESERVATION' : 'THE PICKUP EXPERIENCE' ?></p>
      <h2 class="h-serif">Track Your Reservation,<br>Start to <em>Finish</em>.</h2>
      <p class="lede">Every reservation moves through a simple, visible status - so you always know when it's ready to collect. Payment happens in person at pickup.</p>
      <?php if ($order && $order['mine']): ?>
        <a href="order.php?id=<?= (int) $order['id'] ?>" class="link-arrow light">View this order <svg viewBox="0 0 24 24"><use href="#icon-arrow"/></svg></a>
      <?php elseif ($isCustomer): ?>
        <a href="my-orders.php" class="link-arrow light">My orders <svg viewBox="0 0 24 24"><use href="#icon-arrow"/></svg></a>
      <?php endif; ?>
    </div>

    <?php if ($order): $step = home_order_step($order['status']); ?>
    <div class="order-card reveal-on-scroll" style="--stagger:0.1s">
      <div class="order-card-head">
        <div>
          <h4><?= $order['mine'] ? 'Your Reservation' : 'Latest Reservation' ?>, <?= e($order['stall_name']) ?></h4>
          <span>Order #<?= e($order['order_number'] ?: order_number((int) $order['id'])) ?></span>
        </div>
        <svg viewBox="0 0 24 24" style="width:22px;height:22px;color:var(--soft-green)"><use href="#icon-pickup"/></svg>
      </div>
      <div class="order-items">
        <?php foreach ($order['items'] as $it): ?>
        <div class="order-item"><span><?= e($it['product_name']) ?></span><span><?= e(qty($it['quantity']) . ' ' . $it['unit']) ?></span></div>
        <?php endforeach; ?>
      </div>
      <div class="order-meta-row">
        <div>Pickup<strong><?= e(pickup_label($order['pickup_date'], $order['pickup_start'], $order['pickup_end']) ?: 'To be scheduled') ?></strong></div>
        <div>Market<strong><?= e($order['market_name'] ?: $order['stall_name']) ?></strong></div>
      </div>
      <span class="status-pill"><svg viewBox="0 0 24 24"><use href="#icon-check"/></svg> <?= e(strtoupper(status_label($order['status']))) ?></span>

      <?php if ($step >= 0): ?>
      <div class="progress-track">
        <?php foreach (['Placed', 'Accepted', 'Ready', 'Completed'] as $i => $label): ?>
        <div class="progress-step<?= $i <= $step ? ' done' : '' ?>"><span class="progress-dot"></span><span><?= $label ?></span></div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</section>

<!-- Favorites + reviews -->
<section class="section on-cream">
  <div class="container favorites-inner">
    <div>
      <p class="kicker reveal-on-scroll"><?= $favorites['own'] ? 'SAVED FOR YOU' : 'MOST LOVED' ?></p>
      <h2 class="h-serif reveal-on-scroll"><?= $favorites['own'] ? 'Your Favorite Farmers,<br>All in <em>One Place</em>.' : 'Community Favorites,<br>All in <em>One Place</em>.' ?></h2>
      <p class="lede reveal-on-scroll" style="margin-bottom:28px;"><?= $favorites['own'] ? 'The farmers and products you saved, ready whenever you want to shop again.' : 'The farmers and products shoppers save most. Sign in to build your own list.' ?></p>

      <div class="fav-cards reveal-on-scroll" style="--stagger:0.1s">
        <?php foreach ($favorites['items'] as $fv): ?>
        <div class="fav-mini-card"<?= $favorites['own'] ? ' data-fav-card' : '' ?>>
          <a class="fav-mini-img<?= $noImg($fv['image']) ?>"<?= $bg($fv['image']) ?> href="<?= e($fv['link']) ?>" aria-label="<?= e($fv['name']) ?>"></a>
          <div>
            <h4><a href="<?= e($fv['link']) ?>" style="color:inherit;text-decoration:none"><?= e($fv['name']) ?></a></h4>
            <p><?= e($fv['sub']) ?></p>
          </div>
          <?= $heart($fv['type'], $fv['id'], $fv['name']) ?>
        </div>
        <?php endforeach; ?>
        <?php if (!$favorites['items']): ?>
          <p class="lede">Tap the heart on any farmer or product to save it here.</p>
        <?php endif; ?>
      </div>
      <p class="restock-note reveal-on-scroll" style="--stagger:0.15s"><svg viewBox="0 0 24 24"><use href="#icon-clock"/></svg> Get notified when a favorite is back in weekly stock.</p>
    </div>

    <div class="review-list">
      <?php foreach ($reviews as $i => $r): ?>
      <div class="review-card reveal-on-scroll"<?= $i ? ' style="--stagger:' . number_format($i * 0.1, 1) . 's"' : '' ?>>
        <div class="review-stars"><?= $stars((float) $r['rating']) ?></div>
        <p>"<?= e(mb_strimwidth($r['review'], 0, 180, '...')) ?>"</p>
        <div class="review-author"><span><?= e(home_short_name($r['customer'])) ?></span><small><a href="market.php?id=<?= (int) $r['vendor_id'] ?>" style="color:inherit"><?= e($r['stall_name']) ?></a>, <?= e(time_ago($r['created_at'])) ?></small></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- AI assistant -->
<section class="section on-white" id="assistant">
  <div class="container assistant-inner">
    <div class="reveal-on-scroll">
      <p class="kicker">SMART MARKET SEARCH</p>
      <h2 class="h-serif">Need Help Finding<br>Something <em>Fresh</em>?</h2>
      <p class="lede">Search <?= $live('in_stock') ?> products across <?= $live('markets') ?> markets by name, farmer or market.</p>
    </div>

    <div class="chat-card reveal-on-scroll" style="--stagger:0.1s">
      <div class="chat-card-head">MarketLink Assistant</div>
      <?php if ($assistant): ?>
      <div class="chat-bubble user">Where can I find fresh <?= e(mb_strtolower($assistant['name'])) ?> this week?</div>
      <div class="chat-bubble bot">I found <?= (int) $assistant['farmers'] ?> farmer<?= $assistant['farmers'] == 1 ? '' : 's' ?> offering <?= e(mb_strtolower($assistant['name'])) ?> at <?= max(1, (int) $assistant['markets']) ?> market<?= $assistant['markets'] > 1 ? 's' : '' ?>, from <?= e(money($assistant['from_price'])) ?> / <?= e($assistant['unit']) ?>.</div>
      <div class="chat-actions">
        <a href="markets.php?mq=<?= e(urlencode($assistant['name'])) ?>">View Markets</a>
        <a href="products.php?q=<?= e(urlencode($assistant['name'])) ?>">View Products</a>
      </div>
      <?php endif; ?>
      <form class="chat-input" action="products.php" method="get" role="search" data-assistant-ask>
        <input type="search" name="q" placeholder="Ask about products, farmers or markets..." aria-label="Search products" required>
        <button type="submit" aria-label="Search"><svg viewBox="0 0 24 24"><use href="#icon-arrow"/></svg></button>
      </form>
    </div>
  </div>
</section>

<!-- Join as a farmer -->
<section class="join-split" id="join-farmers">
  <div class="join-content on-forest">
    <div class="reveal-on-scroll">
      <p class="kicker">FOR LOCAL FARMERS</p>
      <h2 class="h-serif">Bring Your Farm to<br>Your Local <em>Community</em>.</h2>
      <p class="lede">Join <?= $live('farmers') ?> farmers already selling to <?= $live('customers') ?> customers. Publish your weekly stock, manage reservations and grow with your local market.</p>
      <ul class="feature-list">
        <li><svg viewBox="0 0 24 24"><use href="#icon-check"/></svg> Manage weekly stock</li>
        <li><svg viewBox="0 0 24 24"><use href="#icon-check"/></svg> Update prices and quantities</li>
        <li><svg viewBox="0 0 24 24"><use href="#icon-check"/></svg> Receive pre-orders</li>
        <li><svg viewBox="0 0 24 24"><use href="#icon-check"/></svg> Manage pickup windows</li>
        <li><svg viewBox="0 0 24 24"><use href="#icon-check"/></svg> View order history</li>
        <li><svg viewBox="0 0 24 24"><use href="#icon-check"/></svg> Respond to customer reviews</li>
        <li><svg viewBox="0 0 24 24"><use href="#icon-check"/></svg> See basic sales insights</li>
      </ul>
      <div class="discover-actions" style="margin-top:0;">
        <a href="auth/register.php?role=vendor" class="btn btn-primary"><span>JOIN AS A FARMER</span><svg viewBox="0 0 24 24"><use href="#icon-arrow"/></svg></a>
        <a href="about.php" class="link-arrow light">Learn More <svg viewBox="0 0 24 24"><use href="#icon-arrow"/></svg></a>
      </div>
    </div>
  </div>
  <div class="join-media" style="background-image:url('https://images.unsplash.com/photo-1746623691157-c4c7a3bad0c4?q=80&amp;w=1200&amp;auto=format&amp;fit=crop')"></div>
</section>

<!-- Sustainability / community -->
<section class="section on-beige" id="about">
  <div class="container sustain-inner">
    <div class="sustain-media reveal-on-scroll" style="background-image:url('https://images.unsplash.com/photo-1597868165956-03a6827955b1?q=80&amp;w=900&amp;auto=format&amp;fit=crop')"></div>
    <div class="reveal-on-scroll" style="--stagger:0.1s">
      <p class="kicker">LOCAL &amp; CONNECTED</p>
      <h2 class="h-serif">Closer Markets.<br><em>Stronger Communities</em>.</h2>
      <ul class="sustain-list">
        <li><svg viewBox="0 0 24 24"><use href="#icon-check"/></svg> <?= $live('completed') ?> pickups completed without a wasted trip</li>
        <li><svg viewBox="0 0 24 24"><use href="#icon-check"/></svg> Live stock visibility across <?= $live('markets') ?> nearby markets</li>
        <li><svg viewBox="0 0 24 24"><use href="#icon-check"/></svg> <?= $live('farmers') ?> farmers planning weekly availability with confidence</li>
        <li><svg viewBox="0 0 24 24"><use href="#icon-check"/></svg> <?= $live('customers') ?> customers connected directly with local producers</li>
        <li><svg viewBox="0 0 24 24"><use href="#icon-check"/></svg> Encourages shopping locally, market by market</li>
      </ul>
    </div>
  </div>
</section>

<!-- Stats -->
<section class="section on-forest" id="stats">
  <div class="container">
    <div class="section-head center reveal-on-scroll">
      <p class="kicker">MARKETLINK, IN NUMBERS</p>
      <h2 class="h-serif">Fresh Food. Local People.<br><em>Real Connections</em>.</h2>
      <p class="lede">MarketLink is built to strengthen the bond between the people who grow food and the people who enjoy it.</p>
    </div>

    <div class="stats-grid reveal-on-scroll">
      <div class="stat"><span class="stat-num"><?= $live('farmers') ?></span><span class="stat-label">Local Farmers</span></div>
      <div class="stat"><span class="stat-num"><?= $live('markets') ?></span><span class="stat-label">Markets</span></div>
      <div class="stat"><span class="stat-num"><?= $live('products') ?></span><span class="stat-label">Fresh Products</span></div>
      <div class="stat"><span class="stat-num"><?= $live('orders') ?></span><span class="stat-label">Reservations</span></div>
    </div>
    <p class="stats-note"><span class="live-dot" aria-hidden="true"></span> Live from MarketLink - updated <span data-live-at>just now</span>.</p>
  </div>
</section>

<!-- News / farming stories -->
<?php if ($stories): ?>
<section class="section on-white" id="stories">
  <div class="container">
    <div class="section-head reveal-on-scroll">
      <p class="kicker">FROM OUR LOCAL COMMUNITY</p>
      <h2 class="h-serif">Stories From the<br><em>Market Floor</em>.</h2>
    </div>

    <div class="story-grid">
      <?php foreach ($stories as $i => $s): ?>
      <a href="<?= e($s['link']) ?>" class="story-card reveal-on-scroll"<?= $i ? ' style="--stagger:' . number_format($i * 0.1, 1) . 's"' : '' ?>>
        <div class="story-img"><div class="<?= trim($noImg($s['image'])) ?>"<?= $bg($s['image']) ?>></div></div>
        <p class="story-meta"><span><?= e($s['tag']) ?></span><span><?= e(fmt_date($s['date'])) ?></span></p>
        <h3><?= e($s['title']) ?></h3>
        <p><?= e($s['text']) ?></p>
        <span class="link-arrow ink">Read More <svg viewBox="0 0 24 24"><use href="#icon-arrow"/></svg></span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- Newsletter -->
<section class="newsletter on-forest">
  <div class="container newsletter-inner">
    <div class="reveal-on-scroll">
      <p class="kicker">STAY IN THE LOOP</p>
      <h2 class="h-serif">Stay Connected to<br>Your <em>Local Market</em>.</h2>
      <p class="lede"><?= $live('new_week') ?> new products were listed this week. Get weekly updates about new farmers, seasonal products and fresh stock.</p>
    </div>
    <form class="newsletter-form reveal-on-scroll" style="--stagger:0.1s" onsubmit="return false">
      <input type="email" placeholder="Enter your email" aria-label="Email address" required>
      <button type="submit"><span>Subscribe</span><svg viewBox="0 0 24 24"><use href="#icon-mail"/></svg></button>
    </form>
  </div>
</section>

<!-- Final CTA -->
<section class="section final-cta" id="contact">
  <div class="container reveal-on-scroll">
    <h2 class="h-serif">Your Local Market Is<br><em>Closer Than You Think</em>.</h2>
    <p class="lede"><?= $live('farmers') ?> farmers, <?= $live('markets') ?> markets and <?= $live('in_stock') ?> fresh products are waiting. Reserve your next pickup with MarketLink.</p>
    <div class="hero-cta">
      <a href="#markets" class="btn btn-primary">
        <span>EXPLORE MARKETS</span>
        <svg viewBox="0 0 24 24"><use href="#icon-arrow"/></svg>
      </a>
      <a href="#farmers" class="link-arrow light">Find a Farmer <svg viewBox="0 0 24 24"><use href="#icon-arrow"/></svg></a>
    </div>
  </div>
</section>

<!-- Footer -->
<?php site_footer(); ?>

<div class="home-toast" id="homeToast" role="status" aria-live="polite" hidden></div>
<script src="<?= e(asset('assets/js/navbar.js')) ?>"></script>
<?php if ($user): ?><script src="<?= e(asset('assets/js/notifications.js')) ?>"></script><?php endif; ?>
<script src="<?= e(asset('assets/js/main.js')) ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="<?= e(asset('assets/js/home.js')) ?>"></script>
<?php assistant_widget(); ?>
</body>
</html>
