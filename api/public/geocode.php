<?php
/**
 * Geocoding through our server (results are cached).
 *   GET ?q=Gulshan-e-Iqbal Karachi  -> list of places
 *   GET ?lat=24.9&lng=67.1          -> address for a point
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/geocoder.php';

api_run(function () {
    require_method('GET');

    // simple per-session limit
    $now = time();
    $hits = array_filter($_SESSION['_geo_hits'] ?? [], fn($t) => $t > $now - 60);
    if (count($hits) >= 15) {
        json_error('Too many location searches. Please wait a minute and try again.', 429);
    }

    if (isset($_GET['lat'], $_GET['lng'])) {
        $lat = float_input($_GET, 'lat');
        $lng = float_input($_GET, 'lng');
        if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180) {
            json_error('Invalid coordinates.', 422);
        }
        // round so nearby points use the same cache entry
        $key = sprintf('%.4f,%.4f', $lat, $lng);
        $cached = true;
        $result = geocode_cached('reverse', $key, function () use ($lat, $lng, &$cached) {
            $cached = false;
            return geocoder()->reverse(round($lat, 4), round($lng, 4));
        });
        if (!$cached) { $hits[] = $now; }
        $_SESSION['_geo_hits'] = $hits;
        json_ok(['result' => $result]);
    }

    $q = str_input($_GET, 'q', 200);
    if (mb_strlen($q) < 3) {
        json_error('Type at least 3 characters to search.', 422);
    }
    $cached = true;
    $results = geocode_cached('search', $q, function () use ($q, &$cached) {
        $cached = false;
        return geocoder()->search($q, 5);
    });
    if (!$cached) { $hits[] = $now; }
    $_SESSION['_geo_hits'] = $hits;
    json_ok(['results' => $results]);
});
