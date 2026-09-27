<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once APP_ROOT . '/includes/partials/vendor_layout.php';

$vendor = require_vendor();
$hasLocation = $vendor['latitude'] !== null && $vendor['longitude'] !== null;

vendor_layout_start([
    'title' => 'Stall Location', 'active' => 'location',
    'heading' => 'Stall Location', 'subheading' => 'Pin your stall so nearby customers can find your market.',
    'styles' => ['https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css'],
]);
?>
<?php if (!$hasLocation): ?>
<div class="alert alert-warn">Your stall is not on the map yet, so it won't appear in <strong>Markets near you</strong>. Search for your address or drop a pin, then save.</div>
<?php endif; ?>

<div class="map-layout">
  <article class="card map-card">
    <form class="map-search" id="geoForm" role="search">
      <div class="search-input">
        <svg><use href="#i-search"/></svg>
        <input class="input" type="search" name="q" id="geoQuery" placeholder="Search location... e.g. Gulshan-e-Iqbal, Karachi" aria-label="Search location" autocomplete="off" required minlength="3">
      </div>
      <button type="submit" class="btn btn-primary"><svg><use href="#i-search"/></svg>Search</button>
      <button type="button" class="btn btn-ghost" id="useMyLocation"><svg><use href="#i-locate"/></svg>Use current location</button>
    </form>
    <div class="geo-results" id="geoResults" hidden></div>
    <div id="map" class="map"
         data-tiles="<?= e(config('map.tile_url')) ?>"
         data-attribution="<?= e(config('map.attribution')) ?>"
         data-lat="<?= e($hasLocation ? $vendor['latitude'] : config('map.default_lat')) ?>"
         data-lng="<?= e($hasLocation ? $vendor['longitude'] : config('map.default_lng')) ?>"
         data-zoom="<?= $hasLocation ? 16 : (int) config('map.default_zoom') ?>"
         aria-label="Map - click to place your stall marker"></div>
    <p class="map-hint"><svg><use href="#i-pin"/></svg>Click anywhere on the map to move the pin, or drag it to your exact stall position.</p>
  </article>

  <article class="card">
    <div class="card-head"><div><h3 class="card-title">Selected Location</h3><p class="card-sub">Shown to customers on your market page</p></div></div>
    <form id="locationForm" novalidate>
      <div class="field">
        <label for="locAddress">Readable address</label>
        <textarea class="textarea" id="locAddress" name="location_address" maxlength="255" style="min-height:90px" placeholder="Search or drop a pin to fill this in"><?= e($vendor['location_address']) ?></textarea>
        <span class="hint" id="addrHint">You can edit this text - coordinates come from the pin.</span>
      </div>
      <div class="coord-grid mt" data-field="latitude">
        <div class="coord"><span>Latitude</span><strong id="latOut"><?= $hasLocation ? e(number_format((float) $vendor['latitude'], 6)) : '-' ?></strong></div>
        <div class="coord"><span>Longitude</span><strong id="lngOut"><?= $hasLocation ? e(number_format((float) $vendor['longitude'], 6)) : '-' ?></strong></div>
      </div>
      <input type="hidden" name="latitude" value="<?= e($vendor['latitude']) ?>">
      <input type="hidden" name="longitude" value="<?= e($vendor['longitude']) ?>">
      <button type="submit" class="btn btn-primary btn-block mt"><svg><use href="#i-pin"/></svg>Save Stall Location</button>
    </form>
    <div class="divider"></div>
    <p class="muted" style="font-size:12.5px">Map data &copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener" class="link">OpenStreetMap</a> contributors. Address search by Nominatim - searches run only when you press Search.</p>
    <?php if ($hasLocation): ?>
      <a class="btn btn-ghost btn-block mt" href="<?= e(url('markets.php?lat=' . $vendor['latitude'] . '&lng=' . $vendor['longitude'])) ?>" target="_blank" rel="noopener"><svg><use href="#i-eye"/></svg>Preview customer search</a>
    <?php endif; ?>
  </article>
</div>

<?php vendor_layout_end([
    'vendor_scripts' => ['https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js'],
    'scripts' => ['assets/js/map-common.js', 'assets/js/vendor/location.js'],
]);
