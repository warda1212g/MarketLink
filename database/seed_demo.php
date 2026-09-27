<?php
/**
 * Demo data for local testing. Run: php database/seed_demo.php
 * Adds some vendors, customers, products and about 4 months of orders.
 * All demo accounts use the password: password123
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
require_once __DIR__ . '/../includes/db.php';
$GLOBALS['__config'] = require __DIR__ . '/../config/config.php';
function config(string $key, $default = null) {
    $v = $GLOBALS['__config'];
    foreach (explode('.', $key) as $p) { if (!is_array($v) || !array_key_exists($p, $v)) return $default; $v = $v[$p]; }
    return $v;
}
date_default_timezone_set(config('app.timezone'));

$pdo = db();
if ($pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE '%@demo.marketlink'")->fetchColumn() > 0) {
    exit("Demo data already present (users @demo.marketlink). Nothing to do.\n");
}
mt_srand(42);
$hash = password_hash('password123', PASSWORD_DEFAULT);
$cat = [];
foreach ($pdo->query('SELECT id, slug FROM categories') as $c) $cat[$c['slug']] = (int) $c['id'];

$vendors = [
    ['Ahmed Raza', 'ahmed@demo.marketlink', 'MarketLink Fresh Farm', 'Block 13-D, Gulshan-e-Iqbal, Karachi', 24.9180, 67.0971, 'mon,wed,sat', '16:00', '19:00',
     'Family farm bringing seasonal vegetables and greens to Gulshan every week. Harvested the morning of market day.'],
    ['Sara Khan', 'sara@demo.marketlink', 'Organic Valley Stall', 'Clifton Block 5, Karachi', 24.8138, 67.0300, 'tue,fri,sun', '09:00', '13:00',
     'Certified organic fruit, herbs and free-range eggs from our farm outside Thatta.'],
    ['Bilal Ahmed', 'bilal@demo.marketlink', 'Green Harvest Market', 'Tariq Road, PECHS, Karachi', 24.8720, 67.0600, 'thu,sat', '15:00', '20:00',
     'Root vegetables, honey and home-baked breads from a small co-op of growers.'],
];

$catalog = [
    ['Fresh Spinach', 'vegetables', 90, 'bundle', 'Tender leaves, washed and bundled.'],
    ['Organic Tomatoes', 'vegetables', 180, 'kg', 'Vine-ripened, deep red tomatoes.'],
    ['Carrots', 'vegetables', 120, 'kg', 'Sweet, crunchy winter carrots.'],
    ['Potatoes', 'vegetables', 95, 'kg', 'Floury potatoes, great for curries.'],
    ['Sweet Corn', 'seasonal-produce', 60, 'piece', 'Fresh cobs picked the morning of market.'],
    ['Coriander', 'herbs-spices', 40, 'bundle', 'Fragrant fresh coriander.'],
    ['Mint', 'herbs-spices', 35, 'bundle', 'Cool garden mint.'],
    ['Farm Eggs', 'dairy-eggs', 420, 'dozen', 'Free-range desi eggs.'],
    ['Guavas', 'fruits', 220, 'kg', 'Larkana guavas, sweet and aromatic.'],
    ['Bananas', 'fruits', 160, 'dozen', 'Naturally ripened bananas.'],
    ['Sourdough Loaf', 'baked-goods', 650, 'piece', 'Slow-fermented, baked at dawn.'],
    ['Wildflower Honey', 'other', 1400, 'box', 'Raw honey, 500g jar.'],
];

$customers = [];
foreach (['Ali Khan', 'Sarah Ahmed', 'Hina Malik', 'Usman Tariq', 'Ayesha Siddiqui', 'Fahad Iqbal', 'Zainab Hussain', 'Omar Farooq', 'Maria Qureshi', 'Danish Ali'] as $i => $name) {
    $pdo->prepare('INSERT INTO users (name, email, password_hash, role, phone) VALUES (?, ?, ?, "customer", ?)')
        ->execute([$name, 'customer' . ($i + 1) . '@demo.marketlink', $hash, '0300' . str_pad((string) (1234567 + $i), 7, '0')]);
    $customers[] = (int) $pdo->lastInsertId();
}

$reviewTexts = [
    5 => ['Very fresh vegetables and pickup was easy.', 'Best tomatoes in the city, will order again!', 'Everything was ready on time. Great service.'],
    4 => ['Good quality, slightly late at pickup.', 'Fresh produce, fair prices.'],
    3 => ['Decent, but spinach was a bit wilted.'],
    2 => ['Order was incomplete this time.'],
];

foreach ($vendors as $vi => [$name, $email, $stall, $addr, $lat, $lng, $days, $ps, $pe, $desc]) {
    $pdo->prepare('INSERT INTO users (name, email, password_hash, role, phone) VALUES (?, ?, ?, "vendor", ?)')->execute([$name, $email, $hash, '0321555000' . $vi]);
    $uid = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO vendors (user_id, stall_name, contact_person, phone, email, address, description, operating_days, pickup_start, pickup_end, latitude, longitude, location_address)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$uid, $stall, $name, '0321555000' . $vi, $email, $addr, $desc, $days, $ps, $pe, $lat, $lng, $addr]);
    $vid = (int) $pdo->lastInsertId();

    // cut-off rules from operating days
    $map = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];
    foreach (explode(',', $days) as $d) {
        $pdo->prepare('INSERT INTO cutoff_settings (vendor_id, market_day, cutoff_days_before, cutoff_time, pickup_start, pickup_end) VALUES (?,?,?,?,?,?)')
            ->execute([$vid, $map[$d], 0, '12:00', $ps, $pe]);
    }

    // products (a subset per vendor)
    $products = [];
    $subset = $vi === 0 ? [0, 1, 2, 3, 4, 5, 6] : ($vi === 1 ? [1, 5, 6, 7, 8, 9] : [2, 3, 10, 11, 4]);
    foreach ($subset as $k => $pi) {
        [$pname, $pcat, $price, $unit, $pdesc] = $catalog[$pi];
        $qtyNow = [25, 4, 3, 30, 2, 18, 12, 0, 40, 9, 6, 8][$pi];
        $created = date('Y-m-d H:i:s', strtotime('-' . (130 - $k * 12) . ' days'));
        $pdo->prepare('INSERT INTO products (vendor_id, category_id, name, description, price, unit, quantity, status, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$vid, $cat[$pcat], $pname, $pdesc, $price, $unit, $qtyNow, $qtyNow > 0 ? 'available' : 'sold_out', $created]);
        $products[] = ['id' => (int) $pdo->lastInsertId(), 'name' => $pname, 'unit' => $unit, 'price' => $price, 'created' => $created];
        foreach (explode(',', $days) as $d) {
            $pdo->prepare('INSERT INTO weekly_stock (vendor_id, product_id, day_of_week, quantity) VALUES (?,?,?,?)')
                ->execute([$vid, end($products)['id'], $map[$d], [30, 20, 15, 25, 12, 20, 15, 10, 30, 12, 10, 8][$pi]]);
        }
    }

    // orders over the last ~120 days (more recent = busier)
    $orderCount = [140, 90, 60][$vi];
    for ($n = 0; $n < $orderCount; $n++) {
        $daysAgo = (int) floor(120 * pow(mt_rand() / mt_getrandmax(), 1.6));
        $created = strtotime("-{$daysAgo} days") - mt_rand(0, 12) * 3600;
        if ($created > time()) $created = time() - 600;
        $pickup = date('Y-m-d', $created + mt_rand(1, 4) * 86400);
        if ($daysAgo > 5) {
            $r = mt_rand(1, 100);
            $status = $r <= 80 ? 'completed' : ($r <= 90 ? 'cancelled' : 'declined');
        } else {
            $status = ['pending', 'pending', 'accepted', 'ready', 'completed', 'pending'][mt_rand(0, 5)];
        }
        $cust = $customers[mt_rand(0, count($customers) - 1)];
        $pdo->prepare('INSERT INTO orders (customer_id, vendor_id, pickup_date, pickup_start, pickup_end, total_amount, status, status_changed_at, created_at) VALUES (?,?,?,?,?,0,?,?,?)')
            ->execute([$cust, $vid, $pickup, $ps, $pe, $status, date('Y-m-d H:i:s', $created + 3600), date('Y-m-d H:i:s', $created)]);
        $oid = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE orders SET order_number = ? WHERE id = ?')->execute(['ML' . (1000 + $oid), $oid]);

        $total = 0;
        $picked = (array) array_rand($products, mt_rand(1, min(4, count($products))));
        foreach ($picked as $pk) {
            $p = $products[$pk];
            if (strtotime($p['created']) > $created) continue;
            $q = in_array($p['unit'], ['kg'], true) ? mt_rand(1, 4) : mt_rand(1, 3);
            $sub = $q * $p['price'];
            $total += $sub;
            $pdo->prepare('INSERT INTO order_items (order_id, product_id, product_name, unit, quantity, unit_price, subtotal) VALUES (?,?,?,?,?,?,?)')
                ->execute([$oid, $p['id'], $p['name'], $p['unit'], $q, $p['price'], $sub]);
        }
        if ($total == 0) {
            $p = $products[0];
            $total = 2 * $p['price'];
            $pdo->prepare('INSERT INTO order_items (order_id, product_id, product_name, unit, quantity, unit_price, subtotal) VALUES (?,?,?,?,?,?,?)')
                ->execute([$oid, $p['id'], $p['name'], $p['unit'], 2, $p['price'], $total]);
        }
        $pdo->prepare('UPDATE orders SET total_amount = ? WHERE id = ?')->execute([$total, $oid]);
        $pdo->prepare('INSERT INTO order_status_log (order_id, from_status, to_status, created_at) VALUES (?, NULL, ?, ?)')->execute([$oid, $status, date('Y-m-d H:i:s', $created)]);

        if ($status === 'completed' && mt_rand(1, 100) <= 30) {
            $rating = [5, 5, 5, 4, 4, 3, 2][mt_rand(0, 6)];
            $texts = $reviewTexts[$rating];
            $reply = mt_rand(0, 1) ? 'Thank you for shopping with us - see you next market day!' : null;
            $rAt = date('Y-m-d H:i:s', strtotime($pickup) + 86400);
            if (strtotime($rAt) > time()) $rAt = date('Y-m-d H:i:s', time() - 3600);
            $pdo->prepare('INSERT INTO reviews (customer_id, vendor_id, order_id, rating, review, vendor_reply, created_at, replied_at) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$cust, $vid, $oid, $rating, $texts[mt_rand(0, count($texts) - 1)], $reply, $rAt, $reply ? $rAt : null]);
        }
    }
    echo "Vendor: {$stall} ({$email}) - {$orderCount} orders\n";
}

echo "Customers: customer1@demo.marketlink ... customer10@demo.marketlink\n";
echo "Password for all demo accounts: password123\n";
