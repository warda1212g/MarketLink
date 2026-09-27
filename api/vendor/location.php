<?php
/** POST {latitude, longitude, location_address} -> save the vendor's stall coordinates. */
require_once __DIR__ . '/../../includes/bootstrap.php';

api_run(function () {
    require_method('POST');
    $vendor = require_vendor_api();
    $in = request_data();

    $lat = float_input($in, 'latitude');
    $lng = float_input($in, 'longitude');
    $address = str_input($in, 'location_address', 255);

    $errors = [];
    if ($lat === null || $lat < -90 || $lat > 90) $errors['latitude'] = 'Place the marker on the map.';
    if ($lng === null || $lng < -180 || $lng > 180) $errors['longitude'] = 'Place the marker on the map.';
    if (mb_strlen($address) < 3) $errors['location_address'] = 'Enter a readable address for customers.';
    if ($errors) throw new ValidationException($errors);

    db_query('UPDATE vendors SET latitude = ?, longitude = ?, location_address = ?, address = COALESCE(NULLIF(address, ""), ?) WHERE id = ?',
        [round($lat, 7), round($lng, 7), $address, $address, $vendor['id']]);

    json_ok(['latitude' => round($lat, 7), 'longitude' => round($lng, 7), 'location_address' => $address], 'Location saved successfully.');
});
