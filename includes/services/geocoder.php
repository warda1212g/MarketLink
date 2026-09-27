<?php
/**
 * Geocoding (Nominatim). Called from api/public/geocode.php.
 */
declare(strict_types=1);

interface Geocoder
{
    /** @return array<int, array{label:string, lat:float, lng:float}> */
    public function search(string $query, int $limit = 5): array;

    /** @return array{label:string, lat:float, lng:float}|null */
    public function reverse(float $lat, float $lng): ?array;
}

final class NominatimGeocoder implements Geocoder
{
    private array $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    public function search(string $query, int $limit = 5): array
    {
        $params = [
            'q' => $query,
            'format' => 'jsonv2',
            'addressdetails' => 0,
            'limit' => max(1, min(10, $limit)),
        ];
        if (!empty($this->cfg['country_codes'])) {
            $params['countrycodes'] = $this->cfg['country_codes'];
        }
        $data = $this->request('/search', $params);
        $out = [];
        foreach ((array) $data as $row) {
            if (!isset($row['lat'], $row['lon'])) continue;
            $out[] = ['label' => (string) ($row['display_name'] ?? ''), 'lat' => (float) $row['lat'], 'lng' => (float) $row['lon']];
        }
        return $out;
    }

    public function reverse(float $lat, float $lng): ?array
    {
        $data = $this->request('/reverse', ['lat' => $lat, 'lon' => $lng, 'format' => 'jsonv2', 'zoom' => 17]);
        if (!is_array($data) || empty($data['display_name'])) {
            return null;
        }
        return ['label' => (string) $data['display_name'], 'lat' => (float) $data['lat'], 'lng' => (float) $data['lon']];
    }

    private function request(string $path, array $params)
    {
        if (!empty($this->cfg['email'])) {
            $params['email'] = $this->cfg['email'];
        }
        $url = rtrim($this->cfg['endpoint'], '/') . $path . '?' . http_build_query($params);

        geocoder_throttle((float) ($this->cfg['min_interval'] ?? 1.1));

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT      => $this->cfg['user_agent'],
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Accept-Language: en'],
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status >= 400) {
            throw new RuntimeException('Location service is unavailable right now' . ($err ? " ($err)" : " (HTTP $status)") . '. Please try again shortly.');
        }
        return json_decode((string) $body, true);
    }
}

/**
 * Wait between requests (Nominatim allows 1 per second).
 */
function geocoder_throttle(float $minInterval): void
{
    $file = APP_ROOT . '/storage/cache/geocoder.lock';
    $fh = fopen($file, 'c+');
    if (!$fh) return;
    flock($fh, LOCK_EX);
    $last = (float) stream_get_contents($fh);
    $wait = $last + $minInterval - microtime(true);
    if ($wait > 0) {
        usleep((int) (min($wait, 5) * 1000000));
    }
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, (string) microtime(true));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
}

function geocoder(): Geocoder
{
    $cfg = config('geocoder');
    switch ($cfg['provider']) {
        case 'nominatim':
        default:
            return new NominatimGeocoder($cfg);
    }
}

/** Cached wrapper around geocoder()->search / reverse. */
function geocode_cached(string $kind, string $query, callable $fetch)
{
    $key = hash('sha256', $kind . '|' . config('geocoder.provider') . '|' . mb_strtolower($query));
    $days = (int) config('geocoder.cache_days', 30);
    $row = db_one('SELECT response FROM geocode_cache WHERE cache_key = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? DAY)', [$key, $days]);
    if ($row) {
        return json_decode($row['response'], true);
    }
    $result = $fetch();
    db_query('INSERT INTO geocode_cache (cache_key, kind, query, response) VALUES (?, ?, ?, ?)
              ON DUPLICATE KEY UPDATE response = VALUES(response), created_at = NOW()',
        [$key, $kind, mb_substr($query, 0, 255), json_encode($result)]);
    return $result;
}
