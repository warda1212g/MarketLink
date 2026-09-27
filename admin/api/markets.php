<?php
/**
 * Admin market management API. Admin session required; every POST needs X-CSRF-Token.
 *
 * GET  ?action=list&q=&type=&status=&city=          -> markets + filter options
 * GET  ?action=get&id=                               -> one market
 * GET  ?action=analytics&id=&period=                 -> KPIs + charts for a market
 * GET  ?action=vendors&id=&period=                   -> vendors in a market
 * GET  ?action=products&id=&q=                       -> products listed in a market
 * GET  ?action=orders&id=                            -> recent orders containing the market's products
 * GET  ?action=assignable&id=                        -> approved vendors not yet in the market
 * POST action=save (multipart, optional id)          -> create / update
 * POST action=status {id, status}                    -> active | inactive | suspended
 * POST action=delete {id}                            -> hard delete only when nothing depends on it
 * POST action=assign {id, vendor_ids[]}              -> add vendors to the market
 * POST action=vendor_status {id, vendor_id, status}  -> active | suspended (inside this market)
 * POST action=unassign {id, vendor_id}               -> remove vendor from the market
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/markets.php';

$admin = require_admin_api();

function find_market(int $id): array
{
    $m = markets_list(['id' => $id])[0] ?? null;
    if (!$m) {
        json_error('Market not found.', 404);
    }
    return $m;
}

function period_param(): string
{
    return in_array($_GET['period'] ?? '', ['today', 'week', 'month', 'year', 'all'], true) ? $_GET['period'] : 'all';
}

api_run(function () use ($admin) {
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $action = $_GET['action'] ?? 'list';
        $id = int_input($_GET, 'id', 0);

        switch ($action) {
            case 'list':
                $markets = markets_list([
                    'q'      => str_input($_GET, 'q', 80),
                    'type'   => int_input($_GET, 'type'),
                    'status' => $_GET['status'] ?? '',
                    'city'   => str_input($_GET, 'city', 80),
                ]);
                json_ok([
                    'markets' => $markets,
                    'cities'  => db_all('SELECT DISTINCT city FROM markets ORDER BY city'),
                    'counts'  => db_one("SELECT COUNT(*) AS total, SUM(status = 'active') AS active,
                                                SUM(status = 'inactive') AS inactive, SUM(status = 'suspended') AS suspended
                                           FROM markets"),
                ]);

            case 'get':
                json_ok(['market' => find_market($id)]);

            case 'analytics':
                find_market($id);
                json_ok(market_analytics($id, period_param()));

            case 'vendors':
                find_market($id);
                json_ok(['vendors' => market_vendors($id, market_period_bounds(period_param()))]);

            case 'products':
                find_market($id);
                $params = [$id];
                $filter = '';
                if ($q = str_input($_GET, 'q', 80)) {
                    $filter = ' AND (p.name LIKE ? OR v.stall_name LIKE ?)';
                    array_push($params, "%$q%", "%$q%");
                }
                $rows = db_all(
                    "SELECT p.id, p.name, p.price, p.unit, p.quantity, p.status, p.image, p.created_at,
                            c.name AS category, sc.name AS subcategory, v.stall_name AS vendor, v.id AS vendor_id
                       FROM products p
                       JOIN vendors v ON v.id = p.vendor_id
                       LEFT JOIN categories c  ON c.id = p.category_id
                       LEFT JOIN categories sc ON sc.id = p.subcategory_id
                      WHERE p.market_id = ? AND p.deleted_at IS NULL$filter
                      ORDER BY p.created_at DESC, p.id DESC
                      LIMIT 200",
                    $params
                );
                json_ok(['products' => array_map(fn($p) => [
                    'id' => (int) $p['id'], 'name' => $p['name'], 'vendor' => $p['vendor'], 'vendor_id' => (int) $p['vendor_id'],
                    'category' => $p['category'], 'subcategory' => $p['subcategory'],
                    'price' => (float) $p['price'], 'price_label' => money($p['price']) . ' / ' . $p['unit'],
                    'quantity_label' => qty($p['quantity']) . ' ' . $p['unit'],
                    'status' => $p['status'], 'image' => upload_url($p['image']), 'created_at' => $p['created_at'],
                ], $rows)]);

            case 'orders':
                find_market($id);
                $rows = db_all(
                    "SELECT o.id, o.order_number, o.status, o.created_at, o.pickup_date, cu.name AS customer, v.stall_name AS vendor,
                            SUM(oi.subtotal) AS market_total, COUNT(oi.id) AS items
                       FROM order_items oi
                       JOIN products p ON p.id = oi.product_id AND p.market_id = ?
                       JOIN orders o   ON o.id = oi.order_id
                       JOIN users cu   ON cu.id = o.customer_id
                       JOIN vendors v  ON v.id = o.vendor_id
                      GROUP BY o.id, o.order_number, o.status, o.created_at, o.pickup_date, cu.name, v.stall_name
                      ORDER BY o.created_at DESC, o.id DESC
                      LIMIT 25",
                    [$id]
                );
                json_ok(['orders' => array_map(fn($o) => [
                    'id' => (int) $o['id'], 'number' => $o['order_number'] ?: order_number((int) $o['id']),
                    'customer' => $o['customer'], 'vendor' => $o['vendor'], 'items' => (int) $o['items'],
                    'total' => round((float) $o['market_total'], 2), 'total_label' => money($o['market_total']),
                    'status' => $o['status'], 'created_at' => $o['created_at'], 'pickup_date' => $o['pickup_date'],
                ], $rows)]);

            case 'assignable':
                find_market($id);
                $rows = db_all(
                    "SELECT v.id, v.stall_name, u.name, u.email, v.location_address,
                            (SELECT GROUP_CONCAT(t.farmer_label ORDER BY t.sort_order SEPARATOR ', ')
                               FROM farmer_categories fc JOIN market_types t ON t.id = fc.market_type_id
                              WHERE fc.vendor_id = v.id) AS categories
                       FROM vendors v
                       JOIN users u ON u.id = v.user_id AND u.approval_status = 'approved'
                      WHERE NOT EXISTS (SELECT 1 FROM farmer_markets fm WHERE fm.vendor_id = v.id AND fm.market_id = ?)
                      ORDER BY v.stall_name",
                    [$id]
                );
                json_ok(['vendors' => array_map(fn($r) => [
                    'id' => (int) $r['id'], 'farm' => $r['stall_name'], 'name' => $r['name'], 'email' => $r['email'],
                    'location' => $r['location_address'], 'categories' => $r['categories'] ?: '',
                ], $rows)]);

            default:
                json_error('Unknown action.');
        }
    }

    require_method('POST');
    $in = request_data();
    $action = $in['action'] ?? '';
    $adminId = (int) $admin['id'];

    switch ($action) {
        case 'save':
            $id = int_input($in, 'id');
            $existing = $id ? find_market($id) : null;
            $data = validate_market($in, $id);
            $image = store_uploaded_image('image', 'markets');
            $removeImage = !$image && !empty($in['remove_image']);
            $oldImagePath = $existing ? db_value('SELECT image FROM markets WHERE id = ?', [$id]) : null;

            $cols = [
                'market_type_id' => $data['market_type_id'], 'name' => $data['name'], 'description' => $data['description'] ?: null,
                'city' => $data['city'], 'area' => $data['area'] ?: null, 'address' => $data['address'] ?: null,
                'opening_time' => $data['opening_time'], 'closing_time' => $data['closing_time'],
                'operating_days' => implode(',', $data['operating_days']), 'status' => $data['status'],
                'latitude' => $data['latitude'], 'longitude' => $data['longitude'],
            ];
            if ($existing) {
                $cols['slug'] = unique_slug('markets', slugify($data['name'] . '-' . $data['city']), $id);
                $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($cols)));
                $vals = array_values($cols);
                if ($image || $removeImage) {
                    $set .= ', image = ?';
                    $vals[] = $image;
                }
                $vals[] = $id;
                db_query("UPDATE markets SET $set WHERE id = ?", $vals);
                if ($image || $removeImage) {
                    delete_upload($oldImagePath);
                }
                log_activity('market_updated', 'Market updated: ' . $data['name'], 'market', $id, $adminId);
                $msg = 'Market updated.';
            } else {
                $cols['slug'] = unique_slug('markets', slugify($data['name'] . '-' . $data['city']));
                $cols['image'] = $image;
                $cols['created_by'] = $adminId;
                db_query('INSERT INTO markets (' . implode(', ', array_keys($cols)) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')', array_values($cols));
                $id = (int) db()->lastInsertId();
                $msg = 'Market created.';
            }
            json_ok(['market' => find_market($id)], $msg);

        case 'status':
            $m = find_market((int) ($in['id'] ?? 0));
            $status = $in['status'] ?? '';
            if (!in_array($status, MARKET_STATUSES, true)) {
                throw new ValidationException(['status' => 'Choose a valid status.'], 'Choose a valid status.');
            }
            db_query('UPDATE markets SET status = ? WHERE id = ?', [$status, $m['id']]);
            json_ok(['market' => find_market($m['id'])], 'Market is now ' . $status . '.');

        case 'delete':
            $m = find_market((int) ($in['id'] ?? 0));
            $deps = market_dependents($m['id']);
            if (array_sum($deps) > 0) {
                json_response([
                    'ok' => false, 'dependents' => $deps,
                    'message' => sprintf('This market has %d active vendor(s), %d product(s) and %d order(s). Deactivate or suspend it instead so history is kept.',
                        $deps['vendors'], $deps['products'], $deps['orders']),
                ], 409);
            }
            $img = db_value('SELECT image FROM markets WHERE id = ?', [$m['id']]);
            db_transaction(function (PDO $pdo) use ($m) {
                // only suspended assignments can remain at this point
                $pdo->prepare('DELETE FROM farmer_markets WHERE market_id = ?')->execute([$m['id']]);
                $pdo->prepare('DELETE FROM markets WHERE id = ?')->execute([$m['id']]);
            });
            delete_upload($img);
            log_activity('market_deleted', 'Market deleted: ' . $m['name'], 'market', $m['id'], $adminId);
            json_ok(['id' => $m['id']], 'Market deleted.');

        case 'assign':
            $m = find_market((int) ($in['id'] ?? 0));
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($in['vendor_ids'] ?? [])))));
            if (!$ids) {
                throw new ValidationException(['vendor_ids' => 'Select at least one vendor.'], 'Select at least one vendor.');
            }
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $valid = db_all("SELECT v.id, v.stall_name FROM vendors v JOIN users u ON u.id = v.user_id AND u.approval_status = 'approved' WHERE v.id IN ($ph)", $ids);
            if (count($valid) !== count($ids)) {
                throw new ValidationException(['vendor_ids' => 'Only approved vendors can be assigned.'], 'Only approved vendors can be assigned.');
            }
            db_transaction(function (PDO $pdo) use ($valid, $m, $adminId) {
                $ins = $pdo->prepare('INSERT IGNORE INTO farmer_markets (vendor_id, market_id, assigned_by) VALUES (?, ?, ?)');
                // assigning to a market also tags the farmer with that market's category
                $cat = $pdo->prepare('INSERT IGNORE INTO farmer_categories (vendor_id, market_type_id) VALUES (?, ?)');
                foreach ($valid as $v) {
                    $ins->execute([$v['id'], $m['id'], $adminId]);
                    $cat->execute([$v['id'], $m['market_type_id']]);
                }
            });
            log_activity('market_vendors_assigned', sprintf('%d vendor(s) assigned to %s', count($valid), $m['name']), 'market', $m['id'], $adminId);
            json_ok(['assigned' => count($valid)], count($valid) . ' vendor(s) assigned to ' . $m['name'] . '.');

        case 'vendor_status':
            $m = find_market((int) ($in['id'] ?? 0));
            $vendorId = (int) ($in['vendor_id'] ?? 0);
            $status = ($in['status'] ?? '') === 'suspended' ? 'suspended' : 'active';
            $n = db_query('UPDATE farmer_markets SET status = ? WHERE market_id = ? AND vendor_id = ?', [$status, $m['id'], $vendorId])->rowCount();
            if (!$n && !db_value('SELECT 1 FROM farmer_markets WHERE market_id = ? AND vendor_id = ?', [$m['id'], $vendorId])) {
                json_error('That vendor is not in this market.', 404);
            }
            $farm = (string) db_value('SELECT stall_name FROM vendors WHERE id = ?', [$vendorId]);
            log_activity('market_vendor_' . $status, "Vendor $status in {$m['name']}: $farm", 'market', $m['id'], $adminId);
            json_ok(['status' => $status], $status === 'suspended' ? "$farm suspended in this market." : "$farm reactivated in this market.");

        case 'unassign':
            $m = find_market((int) ($in['id'] ?? 0));
            $vendorId = (int) ($in['vendor_id'] ?? 0);
            $farm = db_value('SELECT stall_name FROM vendors WHERE id = ?', [$vendorId]);
            if (!db_query('DELETE FROM farmer_markets WHERE market_id = ? AND vendor_id = ?', [$m['id'], $vendorId])->rowCount()) {
                json_error('That vendor is not in this market.', 404);
            }
            // Products keep market_id so order history and analytics stay intact; they stop being
            // sellable here because the vendor no longer has an active assignment.
            log_activity('market_vendor_removed', "Vendor removed from {$m['name']}: $farm", 'market', $m['id'], $adminId);
            json_ok([], "$farm removed from {$m['name']}.");

        default:
            json_error('Unknown action.');
    }
});

function validate_market(array $in, ?int $id): array
{
    $d = [
        'name'           => preg_replace('/\s+/', ' ', str_input($in, 'name', 150)),
        'market_type_id' => int_input($in, 'market_type_id'),
        'description'    => str_input($in, 'description', 2000),
        'city'           => preg_replace('/\s+/', ' ', str_input($in, 'city', 80)),
        'area'           => str_input($in, 'area', 120),
        'address'        => str_input($in, 'address', 255),
        'opening_time'   => str_input($in, 'opening_time', 8),
        'closing_time'   => str_input($in, 'closing_time', 8),
        'operating_days' => array_values(array_intersect(array_keys(MARKET_DAYS), (array) ($in['operating_days'] ?? []))),
        'status'         => (string) ($in['status'] ?? 'active'),
        'latitude'       => float_input($in, 'latitude'),
        'longitude'      => float_input($in, 'longitude'),
    ];
    $e = [];
    // map pin is optional, but must be a complete, valid pair
    if (($d['latitude'] === null) !== ($d['longitude'] === null)
        || ($d['latitude'] !== null && (abs($d['latitude']) > 90 || abs($d['longitude']) > 180))) {
        $e['latitude'] = 'The map location is invalid. Place the pin again.';
    }
    if (mb_strlen($d['name']) < 3) {
        $e['name'] = 'Market name must be at least 3 characters.';
    } elseif (!preg_match("/^[\p{L}\p{N}][\p{L}\p{N} &'().,\/-]*$/u", $d['name'])) {
        $e['name'] = 'Use letters, numbers, spaces and & \' ( ) . , / - only.';
    }
    if (!$d['market_type_id'] || !db_value('SELECT 1 FROM market_types WHERE id = ?', [$d['market_type_id']])) {
        $e['market_type_id'] = 'Choose a market type.';
    }
    if (mb_strlen($d['city']) < 2 || !preg_match("/^[\p{L} .'-]+$/u", $d['city'])) {
        $e['city'] = 'Enter a valid city (letters only).';
    }
    if ($d['area'] !== '' && !preg_match("/^[\p{L}\p{N} .,'#\/-]+$/u", $d['area'])) {
        $e['area'] = 'Area contains invalid characters.';
    }
    if (!valid_time($d['opening_time'])) {
        $e['opening_time'] = 'Enter an opening time.';
    }
    if (!valid_time($d['closing_time'])) {
        $e['closing_time'] = 'Enter a closing time.';
    } elseif (valid_time($d['opening_time']) && strcmp(substr($d['closing_time'], 0, 5), substr($d['opening_time'], 0, 5)) <= 0) {
        $e['closing_time'] = 'Closing time must be after opening time.';
    }
    if (!$d['operating_days']) {
        $e['operating_days'] = 'Select at least one operating day.';
    }
    if (!in_array($d['status'], MARKET_STATUSES, true)) {
        $e['status'] = 'Choose a valid status.';
    }
    if (!isset($e['name']) && !isset($e['city'])) {
        $dup = db_value('SELECT id FROM markets WHERE name = ? AND city = ?' . ($id ? ' AND id <> ?' : ''),
            $id ? [$d['name'], $d['city'], $id] : [$d['name'], $d['city']]);
        if ($dup) {
            $e['name'] = 'A market with this name already exists in ' . $d['city'] . '.';
        }
    }
    if ($e) {
        throw new ValidationException($e);
    }
    return $d;
}

function market_analytics(int $id, string $period): array
{
    $range = market_period_bounds($period);
    $totals = market_order_totals($id, $range);
    $win = $range[0] ? ' AND o.created_at >= ? AND o.created_at < ?' : '';
    $winParams = $range[0] ? [$range[0], $range[1]] : [];

    // time series: daily for short periods, monthly otherwise
    $today = new DateTimeImmutable(db()->query('SELECT CURDATE()')->fetchColumn());
    $buckets = [];
    if (in_array($period, ['today', 'week', 'month'], true)) {
        $start = new DateTimeImmutable($range[0]);
        $end = new DateTimeImmutable($range[1]);
        for ($d = $start; $d < $end; $d = $d->modify('+1 day')) {
            $buckets[$d->format('Y-m-d')] = $d->format('M j');
        }
        $keyExpr = "DATE_FORMAT(o.created_at, '%Y-%m-%d')";
        $seriesFrom = $range[0];
    } else {
        $start = $period === 'year' ? $today->setDate((int) $today->format('Y'), 1, 1) : $today->modify('first day of this month')->modify('-11 months');
        for ($i = 0; $i < 12; $i++) {
            $d = $start->modify("+$i months");
            $buckets[$d->format('Y-m')] = $d->format("M 'y");
        }
        $keyExpr = "DATE_FORMAT(o.created_at, '%Y-%m')";
        $seriesFrom = $start->format('Y-m-d H:i:s');
    }
    $orders = array_fill_keys(array_keys($buckets), 0);
    $revenue = $orders;
    foreach (db_all(
        "SELECT $keyExpr AS k, COUNT(DISTINCT o.id) AS n,
                COALESCE(SUM(CASE WHEN o.status = 'completed' THEN oi.subtotal ELSE 0 END), 0) AS r
           FROM order_items oi JOIN orders o ON o.id = oi.order_id JOIN products p ON p.id = oi.product_id
          WHERE p.market_id = ? AND o.created_at >= ?
          GROUP BY k",
        [$id, $seriesFrom]
    ) as $row) {
        if (isset($orders[$row['k']])) {
            $orders[$row['k']] = (int) $row['n'];
            $revenue[$row['k']] = round((float) $row['r'], 2);
        }
    }

    $statuses = array_fill_keys(['pending', 'accepted', 'ready', 'completed', 'cancelled'], 0);
    foreach (db_all(
        "SELECT o.status, COUNT(DISTINCT o.id) AS n
           FROM order_items oi JOIN orders o ON o.id = oi.order_id JOIN products p ON p.id = oi.product_id
          WHERE p.market_id = ?$win GROUP BY o.status",
        array_merge([$id], $winParams)
    ) as $row) {
        $k = $row['status'] === 'declined' ? 'cancelled' : ($row['status'] === 'preparing' ? 'accepted' : ($row['status'] === 'picked_up' ? 'ready' : $row['status']));
        $statuses[$k] += (int) $row['n'];
    }

    $categories = array_map(fn($r) => ['name' => $r['name'], 'count' => (int) $r['n']], db_all(
        "SELECT COALESCE(c.name, 'Uncategorised') AS name, COUNT(*) AS n
           FROM products p LEFT JOIN categories c ON c.id = p.category_id
          WHERE p.market_id = ? AND p.deleted_at IS NULL
          GROUP BY name ORDER BY n DESC",
        [$id]
    ));

    $vendors = market_vendors($id, $range);
    return [
        'period' => $period,
        'kpis' => [
            'vendors'  => (int) db_value("SELECT COUNT(*) FROM farmer_markets WHERE market_id = ? AND status = 'active'", [$id]),
            'products' => (int) db_value('SELECT COUNT(*) FROM products WHERE market_id = ? AND deleted_at IS NULL', [$id]),
            'orders'   => $totals['orders'],
            'revenue'  => $totals['revenue'],
        ],
        'series' => ['labels' => array_values($buckets), 'orders' => array_values($orders), 'revenue' => array_values($revenue)],
        'orderStatus' => $statuses,
        'categories' => $categories,
        'vendorPerformance' => array_slice(array_map(fn($v) => ['name' => $v['farm'], 'orders' => $v['orders'], 'revenue' => $v['revenue']], $vendors), 0, 10),
        'vendors' => $vendors,
    ];
}
