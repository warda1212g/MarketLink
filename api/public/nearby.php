<?php
/** GET ?lat=&lng=&radius=10 -> vendors within radius, nearest first. */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/nearby.php';

api_run(function () {
    require_method('GET');
    $lat = float_input($_GET, 'lat');
    $lng = float_input($_GET, 'lng');
    if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180) {
        json_error('A valid location is required.', 422);
    }
    $radii = config('nearby.radii');
    $radius = int_input($_GET, 'radius', (int) config('nearby.default_radius'));
    if (!in_array($radius, $radii, true)) {
        $radius = (int) config('nearby.default_radius');
    }

    // Remember the customer's last search location (session; plus profile when signed in).
    $_SESSION['customer_location'] = ['lat' => $lat, 'lng' => $lng];
    $user = current_user();
    if ($user && $user['role'] === 'customer') {
        db_query('UPDATE users SET last_latitude = ?, last_longitude = ? WHERE id = ?', [round($lat, 7), round($lng, 7), $user['id']]);
    }

    $vendors = nearby_vendors($lat, $lng, (float) $radius);
    json_ok(['radius' => $radius, 'count' => count($vendors), 'vendors' => $vendors]);
});
