<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once APP_ROOT . '/includes/partials/home_layout.php';

// Live platform numbers (falls back to 0 on a fresh install).
$counts = db_one("SELECT
    (SELECT COUNT(*) FROM vendors v JOIN users u ON u.id = v.user_id WHERE v.is_active = 1 AND u.approval_status = 'approved') AS farmers,
    (SELECT COUNT(*) FROM markets WHERE status = 'active') AS markets,
    (SELECT COUNT(*) FROM products WHERE deleted_at IS NULL) AS products,
    (SELECT COUNT(DISTINCT city) FROM markets WHERE status = 'active') AS cities");

// Market categories with a photo of one of their markets (falls back to the category image).
$categories = db_all(
    "SELECT t.id, t.name,
            COALESCE((SELECT m.image FROM markets m WHERE m.market_type_id = t.id AND m.status = 'active' AND m.image IS NOT NULL
                       ORDER BY m.id DESC LIMIT 1), t.image) AS image,
            (SELECT COUNT(*) FROM farmer_categories fc WHERE fc.market_type_id = t.id) AS stalls
       FROM market_types t WHERE t.is_active = 1 ORDER BY t.sort_order, t.name");

// photos used on this page
$img = fn(string $p) => e(url($p));
$photos = [
    'bazaar'  => 'uploads/markets/wiki-e2f95a82449b5e27.jpg', // Bahawalpur spice shop
    'mandi'   => 'uploads/markets/wiki-8b055380bf80f3fa.jpg', // Layyah fruit & vegetable market
    'shopper' => 'uploads/markets/wiki-57a743e4d99fc361.jpg', // onions at a farmers' market
    'farmer'  => 'uploads/markets/wiki-0b3a1014cbee51bd.jpg', // cotton picking
];

home_layout_start([
    'title' => 'About Us',
    'active' => 'about',
    'description' => 'MarketLink connects local farmers and the people who enjoy their food - market by market.',
    'styles' => ['assets/css/about.css'],
    'hero' => [
        'eyebrow' => 'OUR STORY',
        'lines' => ['GROWN', 'LOCALLY.', 'SHARED', 'TOGETHER.'],
        'copy' => 'MarketLink was built to close the gap between the people who grow food and the people who enjoy it - one local market at a time.',
        'image' => 'https://images.unsplash.com/photo-1560493676-04071c5f467b?q=80&w=1800&auto=format&fit=crop',
    ],
]);
?>

<!-- Story -->
<section class="ab-section on-cream" id="story">
  <div class="container ab-story">
    <div class="ab-story-media reveal-on-scroll">
      <div class="ab-photo ab-photo-main" style="background-image:url('<?= $img($photos['mandi']) ?>')" role="img" aria-label="A busy fruit and vegetable mandi in Punjab"></div>
      <div class="ab-photo ab-photo-inset" style="background-image:url('<?= $img($photos['bazaar']) ?>')" role="img" aria-label="Chilli and spice stall in a Pakistani bazaar"></div>
      <div class="ab-badge">
        <strong><?= number_format((int) $counts['markets']) ?></strong>
        <span>markets across<br><?= number_format((int) $counts['cities']) ?> cities</span>
      </div>
    </div>
    <div class="ab-story-copy reveal-on-scroll" style="--stagger:.1s">
      <p class="kicker">Who we are</p>
      <h2 class="h-serif">The mandi you love,<br><em>now a tap away</em>.</h2>
      <p class="ab-body">Pakistan's markets run on trust - the sabzi wala who knows your order, the farmer who saves the best mangoes for regulars. But shoppers rarely know what's in stock before they go, and farmers rarely know how much to bring.</p>
      <p class="ab-body">MarketLink keeps that relationship and removes the guesswork. Farmers publish what they'll bring each day, customers reserve before the cut-off, and market day becomes a quick, certain pickup - paid in person, at the stall.</p>
      <div class="ab-signoff">
        <span class="ab-signoff-mark"><svg viewBox="0 0 40 40"><use href="#icon-leafpin"/></svg></span>
        <div><strong>The MarketLink Team</strong><small>Built for local markets, by people who shop in them.</small></div>
      </div>
    </div>
  </div>
</section>

<!-- Numbers -->
<section class="ab-numbers on-white" aria-label="MarketLink in numbers">
  <div class="container ab-numbers-grid reveal-on-scroll">
    <div><strong><?= number_format((int) $counts['farmers']) ?></strong><span>Verified farmers &amp; stalls</span></div>
    <div><strong><?= number_format((int) $counts['markets']) ?></strong><span>Active markets</span></div>
    <div><strong><?= number_format((int) $counts['products']) ?></strong><span>Fresh products listed</span></div>
    <div><strong><?= count($categories) ?></strong><span>Market categories</span></div>
  </div>
</section>

<!-- Why -->
<section class="ab-section on-beige" id="why">
  <div class="container">
    <header class="ab-head reveal-on-scroll">
      <p class="kicker">Why MarketLink</p>
      <h2 class="h-serif">Same market. <em>Better market day</em>.</h2>
      <p class="lede">We didn't replace the local market - we fixed the parts that waste everyone's time.</p>
    </header>
    <div class="ab-compare">
      <div class="ab-compare-card is-before reveal-on-scroll">
        <p class="ab-compare-tag">Before</p>
        <h3>A trip on hope</h3>
        <ul>
          <li>You travel to the mandi without knowing what's in stock</li>
          <li>Favourite items sell out before you arrive</li>
          <li>Farmers guess how much to harvest and bring</li>
          <li>Unsold produce goes to waste at the end of the day</li>
        </ul>
      </div>
      <div class="ab-compare-arrow" aria-hidden="true"><svg viewBox="0 0 24 24"><use href="#icon-arrow"/></svg></div>
      <div class="ab-compare-card is-after reveal-on-scroll" style="--stagger:.1s">
        <p class="ab-compare-tag">With MarketLink</p>
        <h3>A pickup you can count on</h3>
        <ul>
          <li>See each stall's live daily stock before you leave home</li>
          <li>Reserve items ahead and choose your pickup day</li>
          <li>Farmers bring exactly what's been ordered, plus a little extra</li>
          <li>Less waste, fewer wasted trips, fairer prices for growers</li>
        </ul>
      </div>
    </div>
  </div>
</section>

<!-- How it works -->
<section class="ab-section on-cream" id="how-it-works">
  <div class="container">
    <header class="ab-head center reveal-on-scroll">
      <p class="kicker">How it works</p>
      <h2 class="h-serif">From stall to bag <em>in four steps</em>.</h2>
    </header>
    <ol class="ab-steps">
      <li class="reveal-on-scroll">
        <span class="ab-step-icon"><svg viewBox="0 0 24 24"><use href="#icon-location"/></svg></span>
        <span class="ab-step-num">01</span>
        <h3>Choose a market</h3>
        <p>Browse market categories or find stalls near you on the map.</p>
      </li>
      <li class="reveal-on-scroll" style="--stagger:.08s">
        <span class="ab-step-icon"><svg viewBox="0 0 24 24"><use href="#icon-stall"/></svg></span>
        <span class="ab-step-num">02</span>
        <h3>Pick a stall</h3>
        <p>Compare farmers, their reviews and today's fresh stock.</p>
      </li>
      <li class="reveal-on-scroll" style="--stagger:.16s">
        <span class="ab-step-icon"><svg viewBox="0 0 24 24"><use href="#icon-basket"/></svg></span>
        <span class="ab-step-num">03</span>
        <h3>Reserve before cut-off</h3>
        <p>Add items to your cart and pre-order for the next pickup.</p>
      </li>
      <li class="reveal-on-scroll" style="--stagger:.24s">
        <span class="ab-step-icon"><svg viewBox="0 0 24 24"><use href="#icon-pickup"/></svg></span>
        <span class="ab-step-num">04</span>
        <h3>Pick up &amp; pay</h3>
        <p>Collect at the stall during pickup hours and pay the farmer.</p>
      </li>
    </ol>
  </div>
</section>

<!-- Categories -->
<?php if ($categories): ?>
<section class="ab-section on-forest" id="markets">
  <div class="container">
    <header class="ab-head split reveal-on-scroll">
      <div>
        <p class="kicker">Our markets</p>
        <h2 class="h-serif"><?= count($categories) ?> kinds of markets,<br><em>one place to shop them</em>.</h2>
      </div>
      <a href="<?= e(url('markets.php')) ?>#categories" class="link-arrow light">Browse all markets <svg viewBox="0 0 24 24"><use href="#icon-arrow"/></svg></a>
    </header>
    <div class="ab-cats">
      <?php foreach ($categories as $i => $c): $ci = upload_url($c['image']); ?>
        <a class="ab-cat reveal-on-scroll" style="--stagger:<?= min($i, 8) * 0.04 ?>s" href="<?= e(url('market-category.php?id=' . (int) $c['id'])) ?>">
          <span class="ab-cat-img"<?= $ci ? ' style="background-image:url(\'' . e($ci) . '\')"' : '' ?>></span>
          <span class="ab-cat-body"><strong><?= e($c['name']) ?></strong><small><?= (int) $c['stalls'] ?> stall<?= (int) $c['stalls'] === 1 ? '' : 's' ?></small></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- Audiences -->
<section class="ab-section on-cream" id="for-you">
  <div class="container">
    <header class="ab-head center reveal-on-scroll">
      <p class="kicker">Made for both sides of the stall</p>
      <h2 class="h-serif">Whether you shop or sell, <em>there's a place for you</em>.</h2>
    </header>
    <div class="ab-audience">
      <article class="ab-aud reveal-on-scroll">
        <div class="ab-aud-img" style="background-image:url('<?= $img($photos['shopper']) ?>')"></div>
        <div class="ab-aud-body">
          <p class="kicker">For customers</p>
          <h3>Shop fresh without the guesswork</h3>
          <ul class="ab-ticks">
            <li><svg viewBox="0 0 24 24"><use href="#icon-check"/></svg>Live stock from real local stalls</li>
            <li><svg viewBox="0 0 24 24"><use href="#icon-check"/></svg>Pre-order and skip the sold-out disappointment</li>
            <li><svg viewBox="0 0 24 24"><use href="#icon-check"/></svg>Save favourite stalls and track every order</li>
          </ul>
          <a href="<?= e(url('markets.php')) ?>" class="ab-btn">Explore markets <svg viewBox="0 0 24 24"><use href="#icon-arrow"/></svg></a>
        </div>
      </article>
      <article class="ab-aud reveal-on-scroll" style="--stagger:.1s">
        <div class="ab-aud-img" style="background-image:url('<?= $img($photos['farmer']) ?>')"></div>
        <div class="ab-aud-body">
          <p class="kicker">For farmers &amp; vendors</p>
          <h3>Sell more, waste less</h3>
          <ul class="ab-ticks">
            <li><svg viewBox="0 0 24 24"><use href="#icon-check"/></svg>Your stall, your prices, your pickup hours</li>
            <li><svg viewBox="0 0 24 24"><use href="#icon-check"/></svg>Plan weekly stock with smart suggestions</li>
            <li><svg viewBox="0 0 24 24"><use href="#icon-check"/></svg>Know your orders before market day starts</li>
          </ul>
          <a href="<?= e(url('auth/register.php?role=vendor')) ?>" class="ab-btn">Join as a farmer <svg viewBox="0 0 24 24"><use href="#icon-arrow"/></svg></a>
        </div>
      </article>
    </div>
  </div>
</section>

<!-- Values -->
<section class="ab-section on-white" id="values">
  <div class="container">
    <header class="ab-head center reveal-on-scroll">
      <p class="kicker">What we stand for</p>
      <h2 class="h-serif">Our <em>values</em>.</h2>
    </header>
    <div class="ab-values">
      <div class="ab-value reveal-on-scroll"><span><svg viewBox="0 0 40 40"><use href="#icon-leafpin"/></svg></span><h3>Truly local</h3><p>Every stall is a real, verified producer or trader from a local market.</p></div>
      <div class="ab-value reveal-on-scroll" style="--stagger:.06s"><span><svg viewBox="0 0 24 24"><use href="#icon-farmer"/></svg></span><h3>Farmer first</h3><p>Farmers set their own prices, stock, days and cut-off times.</p></div>
      <div class="ab-value reveal-on-scroll" style="--stagger:.12s"><span><svg viewBox="0 0 24 24"><use href="#icon-basket"/></svg></span><h3>Honest stock</h3><p>What you see online is what's on the table on your pickup day.</p></div>
      <div class="ab-value reveal-on-scroll" style="--stagger:.18s"><span><svg viewBox="0 0 24 24"><use href="#icon-clock"/></svg></span><h3>Respect for time</h3><p>Clear pickup windows mean no waiting and no wasted trips.</p></div>
      <div class="ab-value reveal-on-scroll" style="--stagger:.24s"><span><svg viewBox="0 0 24 24"><use href="#icon-star"/></svg></span><h3>Earned trust</h3><p>Reviews come only from customers who actually picked up an order.</p></div>
      <div class="ab-value reveal-on-scroll" style="--stagger:.3s"><span><svg viewBox="0 0 24 24"><use href="#icon-people"/></svg></span><h3>Community</h3><p>Stronger ties between neighbourhoods and the people who feed them.</p></div>
    </div>
  </div>
</section>

<!-- Final cta -->
<section class="section final-cta">
  <div class="container reveal-on-scroll">
    <h2 class="h-serif">Your Local Market Is<br><em>Closer Than You Think</em>.</h2>
    <p class="lede">Discover nearby farmers, explore what's fresh and reserve your next pickup with MarketLink.</p>
    <div class="hero-cta">
      <a href="<?= e(url('markets.php')) ?>" class="btn btn-primary"><span>EXPLORE MARKETS</span><svg viewBox="0 0 24 24"><use href="#icon-arrow"/></svg></a>
      <a href="<?= e(url('contact.php')) ?>" class="link-arrow light">Contact Us <svg viewBox="0 0 24 24"><use href="#icon-arrow"/></svg></a>
    </div>
  </div>
</section>

<?php home_layout_end();
