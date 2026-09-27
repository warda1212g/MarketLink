<?php
/**
 * Find stalls near a point (Haversine distance in MySQL).
 */
declare(strict_types=1);

require_once __DIR__ . '/schedule.php';

const EARTH_RADIUS_KM = 6371.0;

function nearby_vendors(float $lat, float $lng, float $radiusKm, int $limit = 50): array
{
    $radiusKm = max(0.5, min(200.0, $radiusKm));
    $latDelta = rad2deg($radiusKm / EARTH_RADIUS_KM);
    $lngDelta = rad2deg($radiusKm / EARTH_RADIUS_KM / max(cos(deg2rad($lat)), 0.01));
    $limit = max(1, min(100, $limit));

    $sql = "SELECT v.*,
                   (? * ACOS(LEAST(1, GREATEST(-1,
                        COS(RADIANS(?)) * COS(RADIANS(v.latitude)) * COS(RADIANS(v.longitude) - RADIANS(?))
                      + SIN(RADIANS(?)) * SIN(RADIANS(v.latitude)))))) AS distance_km,
                   (SELECT ROUND(AVG(r.rating), 1) FROM reviews r WHERE r.vendor_id = v.id AND r.status = 'visible') AS rating,
                   (SELECT COUNT(*) FROM reviews r WHERE r.vendor_id = v.id AND r.status = 'visible') AS review_count,
                   (SELECT COUNT(*) FROM products p WHERE p.vendor_id = v.id AND p.deleted_at IS NULL AND p.moderation_status = 'active' AND p.status = 'available' AND p.quantity > 0) AS product_count
            FROM vendors v
            JOIN users u ON u.id = v.user_id AND u.is_active = 1 AND u.approval_status = 'approved'
            WHERE v.is_active = 1
              AND v.latitude IS NOT NULL AND v.longitude IS NOT NULL
              AND v.latitude BETWEEN ? AND ?
              AND v.longitude BETWEEN ? AND ?
            HAVING distance_km <= ?
            ORDER BY distance_km ASC
            LIMIT $limit";

    $rows = db_all($sql, [
        EARTH_RADIUS_KM, $lat, $lng, $lat,
        $lat - $latDelta, $lat + $latDelta,
        $lng - $lngDelta, $lng + $lngDelta,
        $radiusKm,
    ]);

    return array_map(function ($v) {
        $days = days_from_set($v['operating_days']);
        $status = vendor_order_status($v);
        return [
            'id'            => (int) $v['id'],
            'name'          => $v['stall_name'],
            'address'       => $v['location_address'] ?: $v['address'],
            'description'   => $v['description'] ? mb_strimwidth($v['description'], 0, 140, '...') : null,
            'image'         => upload_url($v['image']),
            'lat'           => (float) $v['latitude'],
            'lng'           => (float) $v['longitude'],
            'distance_km'   => round((float) $v['distance_km'], 2),
            'distance'      => format_distance((float) $v['distance_km']),
            'days'          => days_short_label($days),
            'pickup'        => $v['pickup_start'] ? fmt_time($v['pickup_start']) . ' - ' . fmt_time($v['pickup_end']) : null,
            'rating'        => $v['rating'] !== null ? (float) $v['rating'] : null,
            'review_count'  => (int) $v['review_count'],
            'product_count' => (int) $v['product_count'],
            'accepting'     => $status['accepting'],
            'closed'        => $status['current_closed'],
            'next_pickup'   => $status['next_open'] ? window_label($status['next_open']) : null,
            'url'           => url('market.php?id=' . $v['id']),
        ];
    }, $rows);
}

function format_distance(float $km): string
{
    return $km < 1 ? round($km * 1000 / 10) * 10 . ' m away' : number_format($km, 1) . ' km away';
}
