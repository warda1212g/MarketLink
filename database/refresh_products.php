<?php
/**
 * Gives every demo stall 13-15 products with photos from Wikipedia.
 *
 *   php database/refresh_products.php --images   only download photos
 *   php database/refresh_products.php            also add the products
 *
 * Product list is in database/product_catalogue.php.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Run from the command line: php database/refresh_products.php');
}
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 3);
require_once __DIR__ . '/../includes/bootstrap.php';

const SEED_DOMAIN = 'seed.marketlink.pk';
const IMG_WIDTH = 900;
mt_srand(20260927);

$CATALOGUE = require __DIR__ . '/product_catalogue.php';
$cacheFile = APP_ROOT . '/storage/cache/wiki-images.json';
$cache = is_file($cacheFile) ? (json_decode((string) file_get_contents($cacheFile), true) ?: []) : [];

function http_get(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 40,
        CURLOPT_USERAGENT => 'MarketLink/1.0 (demo product images; admin@marketlink.local)']);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, is_string($body) ? $body : ''];
}

/** Get the image URL for every source */
function resolve_sources(array $sources): void
{
    global $cache, $cacheFile;
    $todo = array_values(array_filter(array_unique($sources), fn($s) => !isset($cache[$s])));
    $titles = array_values(array_filter($todo, fn($s) => strpos($s, 'File:') !== 0));
    foreach (array_chunk($titles, 50) as $chunk) {
        [, $body] = http_get('https://en.wikipedia.org/w/api.php?' . http_build_query([
            'action' => 'query', 'format' => 'json', 'redirects' => 1, 'prop' => 'pageimages',
            'piprop' => 'thumbnail', 'pithumbsize' => IMG_WIDTH, 'titles' => implode('|', $chunk)]));
        $data = json_decode($body, true)['query'] ?? [];
        $map = [];
        foreach (array_merge($data['normalized'] ?? [], $data['redirects'] ?? []) as $n) $map[$n['to']] = $n['from'];
        foreach ($data['pages'] ?? [] as $page) {
            $t = $page['title'];
            while (isset($map[$t])) $t = $map[$t];
            $cache[$t] = $page['thumbnail']['source'] ?? null;
        }
    }
    foreach (array_diff($todo, $titles) as $file) {
        [, $body] = http_get('https://en.wikipedia.org/w/api.php?' . http_build_query([
            'action' => 'query', 'format' => 'json', 'prop' => 'imageinfo', 'iiprop' => 'url', 'iiurlwidth' => IMG_WIDTH, 'titles' => $file]));
        $page = current(json_decode($body, true)['query']['pages'] ?? []) ?: [];
        $cache[$file] = $page['imageinfo'][0]['thumburl'] ?? null;
    }
    file_put_contents($cacheFile, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/** Download the photo once and return its local path */
function source_image(string $source): ?string
{
    global $cache;
    $url = $cache[$source] ?? null;
    if (!$url) return null;
    $base = 'uploads/products/wiki-' . substr(md5($source . '|' . $url), 0, 16);
    foreach (['jpg', 'png', 'webp'] as $ext) {
        if (is_file(APP_ROOT . '/' . $base . '.' . $ext)) return $base . '.' . $ext;
    }
    for ($try = 0; $try < 3; $try++) {
        [$code, $bin] = http_get($url);
        if ($code === 200) break;
        sleep(2 + $try * 3);
    }
    $info = $code === 200 ? @getimagesizefromstring($bin) : false;
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
    if (!$ext) return null;
    if (!is_dir(APP_ROOT . '/uploads/products')) mkdir(APP_ROOT . '/uploads/products', 0755, true);
    file_put_contents(APP_ROOT . '/' . $base . '.' . $ext, $bin);
    usleep(300000);
    return $base . '.' . $ext;
}

/* images */
$sources = [];
foreach ($CATALOGUE as $products) foreach ($products as $p) $sources[] = $p[4];
resolve_sources($sources);

$imageFor = [];   // product name => local path
$missing = [];
$sheet = '';
foreach ($CATALOGUE as $typeId => $products) {
    foreach ($products as [$name, , , , $source]) {
        $img = source_image($source);
        if ($img) $imageFor[$name] = $img; else $missing[] = "$name ($source)";
        $sheet .= '<figure><img src="../../' . htmlspecialchars((string) $img) . '"><figcaption>' . $typeId . ', ' . htmlspecialchars($name) . '<br><small>' . htmlspecialchars($source) . '</small></figcaption></figure>';
    }
}
file_put_contents(APP_ROOT . '/storage/cache/product-images.html', '<!doctype html><meta charset="utf-8"><style>body{font:12px sans-serif;display:grid;grid-template-columns:repeat(8,1fr);gap:6px;margin:6px}img{width:100%;height:150px;object-fit:cover;background:#eee}figure{margin:0}</style>' . $sheet);
echo count($imageFor) . ' product photos ready' . ($missing ? '; missing: ' . implode(', ', $missing) : '') . "\n";

/* products */
if (in_array('--images', $argv, true)) exit("Review sheet: storage/cache/product-images.html
");
$added = 0; $updated = 0;
foreach ($CATALOGUE as $typeId => $products) {
    $categoryId = (int) db_value('SELECT id FROM categories WHERE market_type_id = ? AND parent_id IS NULL ORDER BY sort_order, id LIMIT 1', [$typeId]) ?: null;
    $vendors = db_all("SELECT v.id, v.stall_name, v.address,
                              (SELECT fm.market_id FROM farmer_markets fm WHERE fm.vendor_id = v.id ORDER BY fm.market_id LIMIT 1) AS market_id
                         FROM vendors v JOIN users u ON u.id = v.user_id
                         JOIN farmer_categories fc ON fc.vendor_id = v.id AND fc.market_type_id = ?
                        WHERE u.email LIKE ? ORDER BY v.id", [$typeId, '%@' . SEED_DOMAIN]);
    foreach ($vendors as $i => $v) {
        $existing = array_column(db_all('SELECT id, name, image FROM products WHERE vendor_id = ?', [$v['id']]), null, 'name');

        // New photo for the products the stall already has.
        foreach ($existing as $name => $row) {
            if (!isset($imageFor[$name]) || $row['image'] === $imageFor[$name]) continue;
            db_query('UPDATE products SET image = ? WHERE id = ?', [$imageFor[$name], $row['id']]);
            if ($row['image'] && strpos($row['image'], 'uploads/products/seed-') === 0
                && !db_value('SELECT 1 FROM products WHERE image = ? LIMIT 1', [$row['image']])) {
                delete_upload($row['image']);
            }
            $updated++;
        }

        // each stall skips a few different products (15, 14, 13)
        $skip = [];
        for ($k = 0; $k <= $i; $k++) $skip[] = (count($products) - 1 - $k * 5) % count($products);
        foreach ($products as $idx => $prod) {
            [$name, $unit, $price, $stock] = $prod;
            if (in_array($idx, $skip, true) || isset($existing[$name])) continue;
            $p = round($price * (0.9 + mt_rand(0, 25) / 100) / 5) * 5;
            $qty = $stock <= 10 ? mt_rand(1, $stock) : (int) round($stock * mt_rand(40, 110) / 100);
            $city = trim((string) strrchr((string) $v['address'], ','), ', ');
            db_query("INSERT INTO products (vendor_id, market_id, category_id, name, description, price, unit, quantity, image, status, created_at)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'available', NOW() - INTERVAL ? DAY)",
                [$v['id'], $v['market_id'], $prod[5] ?? $categoryId, $name,
                 $name . ' from ' . $v['stall_name'] . ($city ? ', ' . $city : '') . '. ' . ['Freshly harvested this week.', 'Carefully sorted, top grade.', 'Direct from the farm - no middlemen.', 'Packed on the day of pickup.'][mt_rand(0, 3)],
                 $p, $unit, $qty, $imageFor[$name] ?? null, mt_rand(1, 60)]);
            $added++;
        }
    }
}
echo "Updated $updated product photos" . ", added $added products" . ".\n";
{
    foreach (db_all("SELECT v.stall_name, COUNT(p.id) n FROM vendors v LEFT JOIN products p ON p.vendor_id = v.id GROUP BY v.id HAVING n < 10") as $r) {
        echo "  note: {$r['stall_name']} has only {$r['n']} products\n";
    }
}
echo "Review sheet: storage/cache/product-images.html\n";
