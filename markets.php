<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once APP_ROOT . '/includes/partials/site_layout.php';
require_once APP_ROOT . '/includes/services/markets.php';

const MARKETS_PREVIEW = 8; // cards shown before the in-page "View more" toggle

// start location: ?lat&lng, then last search, then the customer's saved location
$start = null;
if (!empty($_GET['q']) || !empty($_GET['locate'])) {
    // Explicit new search from the homepage - handled client-side.
} elseif (isset($_GET['lat'], $_GET['lng']) && is_numeric($_GET['lat']) && is_numeric($_GET['lng'])) {
    $start = ['lat' => (float) $_GET['lat'], 'lng' => (float) $_GET['lng']];
} elseif (!empty($_SESSION['customer_location'])) {
    $start = $_SESSION['customer_location'];
} elseif (($u = current_user()) && $u['role'] === 'customer') {
    $row = db_one('SELECT last_latitude, last_longitude FROM users WHERE id = ?', [$u['id']]);
    if ($row && $row['last_latitude'] !== null) {
        $start = ['lat' => (float) $row['last_latitude'], 'lng' => (float) $row['last_longitude']];
    }
}
$radius = in_array((int) ($_GET['radius'] ?? 0), config('nearby.radii'), true) ? (int) $_GET['radius'] : (int) config('nearby.default_radius');

ob_start(); ?>
  <form class="finder-form" id="finderForm" role="search">
    <div class="search-input">
      <svg><use href="#i-pin"/></svg>
      <input class="input" type="search" name="q" id="placeQuery" placeholder="Enter your location... e.g. Gulshan-e-Iqbal, Karachi" aria-label="Your location" autocomplete="off" value="<?= e($_GET['q'] ?? '') ?>">
    </div>
    <select class="select" name="radius" id="radius" aria-label="Search radius">
      <?php foreach (config('nearby.radii') as $r): ?><option value="<?= $r ?>" <?= $r === $radius ? 'selected' : '' ?>>Within <?= $r ?> km</option><?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-secondary"><svg><use href="#i-search"/></svg>Search</button>
    <div class="finder-actions">
      <button type="button" class="btn btn-light" id="useLocation"><svg><use href="#i-locate"/></svg>Use My Current Location</button>
    </div>
  </form>
<?php
$finder = ob_get_clean();

site_layout_start([
    'title' => 'Find Markets Near You',
    'active' => 'markets',
    'description' => 'Find local farmers markets and vendor stalls near you.',
    'hero' => [
        'kicker' => 'Discover nearby',
        'title' => 'Find Markets<br><em>Near You</em>.',
        'lede' => 'Enter an area or landmark, or share your current location - we will show nearby stalls, sorted by distance.',
        'image' => 'https://images.unsplash.com/photo-1471193945509-9ad0617afabf?q=80&w=1800&auto=format&fit=crop',
        'extra' => $finder,
    ],
]);
?>

<?php
$marketCategories = db_all(
    "SELECT t.id, t.name, t.image, t.description,
            (SELECT COUNT(*) FROM farmer_categories fc JOIN vendors v ON v.id = fc.vendor_id AND v.is_active = 1
               JOIN users u ON u.id = v.user_id AND u.approval_status = 'approved' AND u.is_active = 1
              WHERE fc.market_type_id = t.id) AS stalls
       FROM market_types t WHERE t.is_active = 1 ORDER BY t.sort_order, t.name");
?>

<!-- 1. Near you -->
<section id="near-you" class="mk-section">
  <header class="mk-head">
    <div>
      <p class="ml-kicker">01, Around you</p>
      <h2 class="ml-serif" id="resultsTitle">Markets <em>near you</em></h2>
      <p class="mk-sub" id="resultsSub">Search a location to see nearby stalls, sorted by distance.</p>
    </div>
  </header>
  <div class="results-layout">
    <section aria-live="polite">
      <div class="market-list" id="marketList">
        <div class="card"><div class="empty"><div class="empty-icon"><svg><use href="#i-pin"/></svg></div><h4>Where should we look?</h4><p>Enter your area above or use your current location.</p></div></div>
      </div>
      <div class="geo-results card card-flush" id="placeChoices" hidden style="margin-top:14px"></div>
    </section>
    <aside class="results-map">
      <div id="map" class="map"
           data-tiles="<?= e(config('map.tile_url')) ?>"
           data-attribution="<?= e(config('map.attribution')) ?>"
           data-lat="<?= e($start['lat'] ?? config('map.default_lat')) ?>"
           data-lng="<?= e($start['lng'] ?? config('map.default_lng')) ?>"
           data-zoom="<?= (int) config('map.default_zoom') ?>"
           aria-label="Map of nearby markets"></div>
    </aside>
  </div>
</section>

<!-- 2. Browse markets (categories) -->
<section id="categories" class="mk-section">
  <header class="mk-head">
    <div>
      <p class="ml-kicker">02, Categories</p>
      <h2 class="ml-serif">Browse <em>markets</em></h2>
      <p class="mk-sub">Choose a market category to see the vendors and stalls selling in it.</p>
    </div>
    <?php if ($marketCategories): ?><span class="mk-total"><b id="catCount"><?= count($marketCategories) ?></b> categories</span><?php endif; ?>
  </header>

  <?php if (!$marketCategories): ?>
    <div class="card"><div class="empty"><div class="empty-icon"><svg><use href="#i-store"/></svg></div><h4>No markets yet</h4><p>Market categories will appear here once they are published.</p></div></div>
  <?php else: ?>
    <div class="mk-toolbar" role="search">
      <div class="mk-search"><svg><use href="#i-search"/></svg>
        <input class="input" type="search" id="catSearch" placeholder="Search categories, e.g. Fruits" aria-label="Search market categories" autocomplete="off"></div>
      <select class="select" id="catShow" aria-label="Show">
        <option value="all">All categories</option>
        <?php foreach ($marketCategories as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
      </select>
      <select class="select" id="catSort" aria-label="Sort by">
        <option value="featured">Featured</option>
        <option value="name">Name A to Z</option>
        <option value="stalls">Most stalls</option>
      </select>
    </div>

    <div class="mk-cat-grid" id="catGrid">
      <?php foreach ($marketCategories as $i => $c): $ci = upload_url($c['image']); $n = (int) $c['stalls']; ?>
        <a class="mk-cat" href="<?= e(url('market-category.php?id=' . (int) $c['id'])) ?>"
           data-name="<?= e(mb_strtolower($c['name'] . ' ' . ($c['description'] ?? ''))) ?>" data-title="<?= e(mb_strtolower($c['name'])) ?>" data-id="<?= (int) $c['id'] ?>" data-stalls="<?= $n ?>" data-order="<?= $i ?>">
          <div class="mk-cat-media"<?= $ci ? ' style="background-image:url(\'' . e($ci) . '\')"' : '' ?>>
            <?php if (!$ci): ?><svg><use href="#i-sprout"/></svg><?php endif; ?>
            <span class="mk-cat-num"><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
          </div>
          <div class="mk-cat-body">
            <h3><?= e($c['name']) ?></h3>
            <p><?= e($c['description'] ? mb_strimwidth($c['description'], 0, 90, '...') : 'Fresh local produce from farmers in this category.') ?></p>
            <div class="mk-cat-foot">
              <span class="mk-chip<?= $n ? '' : ' is-empty' ?>"><?= $n ?> stall<?= $n === 1 ? '' : 's' ?></span>
              <span class="mk-go" aria-hidden="true"><svg><use href="#i-arrow"/></svg></span>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
    <div class="mk-more-wrap" id="catMoreWrap" hidden>
      <button type="button" class="btn btn-ghost mk-more" id="catMore" data-noun="categories" aria-controls="catGrid" aria-expanded="false"></button>
    </div>
    <div class="card mk-none" id="catEmpty" hidden><div class="empty"><div class="empty-icon"><svg><use href="#i-search"/></svg></div><h4>No categories match</h4><p>Try another name or clear the filters.</p></div></div>
  <?php endif; ?>
</section>

<script>
(function () {
  var LIMIT = <?= MARKETS_PREVIEW ?>;

  // "View more" button
  function label(btn, open, total) {
    btn.innerHTML = open
      ? 'Show fewer ' + btn.dataset.noun + ' <svg style="transform:rotate(-90deg)"><use href="#i-arrow"/></svg>'
      : 'View ' + (total - LIMIT) + ' more ' + btn.dataset.noun + ' <svg style="transform:rotate(90deg)"><use href="#i-arrow"/></svg>';
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  function bindMore(btn, grid, getTotal) {
    btn.addEventListener('click', function () {
      var open = grid.classList.toggle('is-collapsed') === false;
      label(btn, open, getTotal());
      if (!open) grid.closest('.mk-section').scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  }

  // Browse markets (client-side search / filter / sort)
  var grid = document.getElementById('catGrid');
  if (!grid) return;
  var q = document.getElementById('catSearch'), show = document.getElementById('catShow'), sort = document.getElementById('catSort'),
      empty = document.getElementById('catEmpty'), count = document.getElementById('catCount'),
      moreWrap = document.getElementById('catMoreWrap'), moreBtn = document.getElementById('catMore');
  var cards = Array.prototype.slice.call(grid.children), visible = 0;
  grid.classList.add('is-collapsed');

  function apply() {
    var term = q.value.trim().toLowerCase();
    visible = 0;
    cards.sort(function (a, b) {
      if (sort.value === 'name') return a.dataset.title.localeCompare(b.dataset.title);
      if (sort.value === 'stalls') return b.dataset.stalls - a.dataset.stalls || a.dataset.order - b.dataset.order;
      return a.dataset.order - b.dataset.order;
    }).forEach(function (c) {
      var ok = (!term || c.dataset.name.indexOf(term) !== -1) && (show.value === 'all' || c.dataset.id === show.value);
      c.hidden = !ok;
      c.classList.toggle('is-more', ok && visible >= LIMIT);
      if (ok) visible++;
      grid.appendChild(c);
    });
    empty.hidden = visible > 0;
    count.textContent = visible;
    moreWrap.hidden = visible <= LIMIT;
    label(moreBtn, !grid.classList.contains('is-collapsed'), visible);
  }
  bindMore(moreBtn, grid, function () { return visible; });
  q.addEventListener('input', apply); show.addEventListener('change', apply); sort.addEventListener('change', apply);
  apply();
})();
</script>

<script type="application/json" id="finderInit"><?= json_encode(['start' => $start, 'radius' => $radius, 'locate' => !empty($_GET['locate'])]) ?></script>
<?php site_layout_end(['assets/js/markets.js']);
