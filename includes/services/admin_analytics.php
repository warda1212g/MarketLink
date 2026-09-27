<?php
/**
 * Queries for the admin reports page.
 *
 * Notes:
 *  - an order belongs to the market its products are listed in
 *  - valid orders = not cancelled or declined
 *  - order value = total of valid orders (people pay at pickup)
 */
declare(strict_types=1);

const RA_PERIODS = ['week' => 'This Week', 'month' => 'This Month', 'year' => 'This Year', 'custom' => 'Custom Range'];

/** Order -> market map (derived from the ordered products). */
const RA_ORDER_MARKET = "(SELECT oi.order_id, MIN(p.market_id) AS market_id
                            FROM order_items oi JOIN products p ON p.id = oi.product_id
                           GROUP BY oi.order_id)";

/** Aggregate columns over an `orders o` row set. */
const RA_STATUS_COLS = "COUNT(*) AS orders,
       SUM(o.status = 'pending') AS pending,
       SUM(o.status IN ('accepted','preparing')) AS accepted,
       SUM(o.status = 'ready') AS ready,
       SUM(o.status IN ('completed','picked_up')) AS completed,
       SUM(o.status IN ('cancelled','declined')) AS cancelled,
       SUM(o.status NOT IN ('cancelled','declined')) AS valid_orders,
       COALESCE(SUM(CASE WHEN o.status NOT IN ('cancelled','declined') THEN o.total_amount END), 0) AS order_value,
       COALESCE(SUM(CASE WHEN o.status IN ('completed','picked_up') THEN o.total_amount END), 0) AS completed_value";

/* filters */

/**
 * Validated filter set. $src keys: period, start, end, market, farmer.
 * Relative periods use today's date; with $anchor (report generation) they cover the week/month/year of that date.
 */
function ra_filters(array $src, bool $anchored = false): array
{
    $period = isset(RA_PERIODS[$src['period'] ?? '']) ? $src['period'] : 'month';
    $today = new DateTimeImmutable('today');
    $anchor = $today;
    if ($anchored && valid_date((string) ($src['start'] ?? ''))) {
        $anchor = new DateTimeImmutable($src['start']);
    }

    switch ($period) {
        case 'week':
            $start = $anchor->modify('monday this week');
            $end = $start->modify('+6 days');
            break;
        case 'year':
            $start = $anchor->setDate((int) $anchor->format('Y'), 1, 1);
            $end = $anchor->setDate((int) $anchor->format('Y'), 12, 31);
            break;
        case 'custom':
            $s = (string) ($src['start'] ?? '');
            $e = (string) ($src['end'] ?? '');
            $start = valid_date($s) ? new DateTimeImmutable($s) : $today->modify('-29 days');
            $end = valid_date($e) ? new DateTimeImmutable($e) : $today;
            if ($end < $start) {
                [$start, $end] = [$end, $start];
            }
            if ($start->diff($end)->days > 366 * 5) { // keep queries bounded
                $start = $end->modify('-5 years');
            }
            break;
        default: // month
            $start = $anchor->modify('first day of this month');
            $end = $anchor->modify('last day of this month');
    }

    $marketId = (int) ($src['market'] ?? 0);
    $market = $marketId ? db_one('SELECT id, name FROM markets WHERE id = ?', [$marketId]) : null;
    $farmerId = (int) ($src['farmer'] ?? 0);
    $farmer = $farmerId ? db_one('SELECT v.id, v.stall_name, u.name FROM vendors v JOIN users u ON u.id = v.user_id WHERE v.id = ?', [$farmerId]) : null;

    $days = $start->diff($end)->days + 1;
    return [
        'period'      => $period,
        'start'       => $start->format('Y-m-d'),
        'end'         => $end->format('Y-m-d'),
        'from'        => $start->format('Y-m-d 00:00:00'),
        'to'          => $end->modify('+1 day')->format('Y-m-d 00:00:00'), // exclusive
        'days'        => $days,
        'granularity' => ($period === 'year' || ($period === 'custom' && $days > 62)) ? 'month' : 'day',
        'market_id'   => $market ? (int) $market['id'] : null,
        'market_name' => $market['name'] ?? null,
        'farmer_id'   => $farmer ? (int) $farmer['id'] : null,
        'farmer_name' => $farmer ? ($farmer['stall_name'] . ' (' . $farmer['name'] . ')') : null,
        'label'       => ra_period_label($period, $start, $end),
    ];
}

function ra_period_label(string $period, DateTimeImmutable $start, DateTimeImmutable $end): string
{
    switch ($period) {
        case 'week':  return 'Week of ' . $start->format('M j') . ' - ' . $end->format('M j, Y');
        case 'month': return $start->format('F Y');
        case 'year':  return $start->format('Y');
        default:      return $start->format('M j, Y') . ' - ' . $end->format('M j, Y');
    }
}

/** Options for the market / farmer dropdowns. */
function ra_filter_options(): array
{
    return [
        'markets' => db_all('SELECT id, name, status FROM markets ORDER BY name'),
        'farmers' => db_all('SELECT v.id, v.stall_name, u.name FROM vendors v JOIN users u ON u.id = v.user_id ORDER BY v.stall_name'),
    ];
}

/**
 * FROM/WHERE for orders in scope (period + market + farmer). Returns [sql, params].
 * Always exposes `o` (orders) and `om` (order -> market).
 */
function ra_order_scope(array $f): array
{
    $sql = 'FROM orders o LEFT JOIN ' . RA_ORDER_MARKET . ' om ON om.order_id = o.id
           WHERE o.created_at >= ? AND o.created_at < ?';
    $params = [$f['from'], $f['to']];
    if ($f['market_id']) {
        $sql .= ' AND om.market_id = ?';
        $params[] = $f['market_id'];
    }
    if ($f['farmer_id']) {
        $sql .= ' AND o.vendor_id = ?';
        $params[] = $f['farmer_id'];
    }
    return [$sql, $params];
}

/** Casts an aggregate row to numbers and adds the average order value (null when there are no valid orders). */
function ra_order_row(?array $r): array
{
    $keys = ['orders', 'pending', 'accepted', 'ready', 'completed', 'cancelled', 'valid_orders'];
    $out = [];
    foreach ($keys as $k) {
        $out[$k] = (int) ($r[$k] ?? 0);
    }
    $out['order_value'] = round((float) ($r['order_value'] ?? 0), 2);
    $out['completed_value'] = round((float) ($r['completed_value'] ?? 0), 2);
    $out['avg_order_value'] = $out['valid_orders'] > 0 ? round($out['order_value'] / $out['valid_orders'], 2) : null;
    return $out;
}

/* sections */

function ra_kpis(array $f, array $orders): array
{
    $mParams = [];
    $vendorScope = '';
    if ($f['market_id']) {
        $vendorScope .= " AND EXISTS (SELECT 1 FROM farmer_markets fm WHERE fm.vendor_id = v.id AND fm.market_id = ? AND fm.status = 'active')";
        $mParams[] = $f['market_id'];
    }
    if ($f['farmer_id']) {
        $vendorScope .= ' AND v.id = ?';
        $mParams[] = $f['farmer_id'];
    }
    $farmers = db_one(
        "SELECT COUNT(*) AS total,
                SUM(v.is_active = 1 AND u.is_active = 1 AND u.approval_status = 'approved') AS active
           FROM vendors v JOIN users u ON u.id = v.user_id WHERE 1 = 1" . $vendorScope,
        $mParams
    );

    $pWhere = 'p.deleted_at IS NULL';
    $pParams = [];
    if ($f['market_id']) { $pWhere .= ' AND p.market_id = ?'; $pParams[] = $f['market_id']; }
    if ($f['farmer_id']) { $pWhere .= ' AND p.vendor_id = ?'; $pParams[] = $f['farmer_id']; }
    $products = db_one("SELECT COUNT(*) AS total, SUM(p.status = 'available' AND p.quantity > 0) AS available FROM products p WHERE $pWhere", $pParams);

    $markets = $f['market_id']
        ? db_one("SELECT COUNT(*) AS total, SUM(status = 'active') AS active FROM markets WHERE id = ?", [$f['market_id']])
        : db_one("SELECT COUNT(*) AS total, SUM(status = 'active') AS active FROM markets");

    return [
        'farmers'           => (int) $farmers['total'],
        'active_farmers'    => (int) $farmers['active'],
        'customers'         => (int) db_value("SELECT COUNT(*) FROM users WHERE role = 'customer'"),
        'markets'           => (int) $markets['total'],
        'active_markets'    => (int) $markets['active'],
        'products'          => (int) $products['total'],
        'available_products'=> (int) $products['available'],
    ] + $orders;
}

function ra_order_totals(array $f): array
{
    [$scope, $params] = ra_order_scope($f);
    return ra_order_row(db_one('SELECT ' . RA_STATUS_COLS . ' ' . $scope, $params));
}

/** Orders and order value per day (or per month), with every bucket present (0 when empty). */
function ra_time_series(array $f): array
{
    [$scope, $params] = ra_order_scope($f);
    $fmt = $f['granularity'] === 'month' ? '%Y-%m' : '%Y-%m-%d';
    $rows = db_all("SELECT DATE_FORMAT(o.created_at, '$fmt') AS k, " . RA_STATUS_COLS . " $scope GROUP BY k", $params);
    $byKey = [];
    foreach ($rows as $r) {
        $byKey[$r['k']] = ra_order_row($r);
    }

    $series = [];
    $cursor = new DateTimeImmutable($f['start']);
    $last = new DateTimeImmutable($f['end']);
    if ($f['granularity'] === 'month') {
        $cursor = $cursor->modify('first day of this month');
    }
    while ($cursor <= $last) {
        $key = $cursor->format($f['granularity'] === 'month' ? 'Y-m' : 'Y-m-d');
        $row = $byKey[$key] ?? ra_order_row(null);
        $series[] = [
            'key'         => $key,
            'label'       => $f['granularity'] === 'month' ? $cursor->format('M Y') : ($f['period'] === 'week' ? $cursor->format('D j') : $cursor->format('M j')),
            'orders'      => $row['orders'],
            'completed'   => $row['completed'],
            'cancelled'   => $row['cancelled'],
            'order_value' => $row['order_value'],
        ];
        $cursor = $cursor->modify($f['granularity'] === 'month' ? '+1 month' : '+1 day');
    }
    return $series;
}

/** Per-market performance (one grouped query per metric family, joined - no per-row queries). */
function ra_markets(array $f): array
{
    $params = [];
    $farmerFm = $f['farmer_id'] ? ' AND fm.vendor_id = ?' : '';
    $farmerP = $f['farmer_id'] ? ' AND p.vendor_id = ?' : '';
    $farmerO = $f['farmer_id'] ? ' AND o.vendor_id = ?' : '';
    $farmerR = $f['farmer_id'] ? ' AND r.vendor_id = ?' : '';

    $sql = "SELECT m.id, m.name, m.status, m.city, m.area, m.address, m.operating_days, m.opening_time, m.closing_time, t.name AS type_name,
                   COALESCE(fm.farmers, 0) AS farmers, COALESCE(pr.products, 0) AS products, COALESCE(pr.available, 0) AS available_products,
                   os.orders, os.pending, os.accepted, os.ready, os.completed, os.cancelled, os.valid_orders, os.order_value, os.completed_value,
                   COALESCE(rv.reviews, 0) AS reviews, rv.avg_rating
              FROM markets m
              LEFT JOIN market_types t ON t.id = m.market_type_id
              LEFT JOIN (SELECT fm.market_id, COUNT(*) AS farmers FROM farmer_markets fm
                          WHERE fm.status = 'active'$farmerFm GROUP BY fm.market_id) fm ON fm.market_id = m.id
              LEFT JOIN (SELECT p.market_id, COUNT(*) AS products, SUM(p.status = 'available' AND p.quantity > 0) AS available
                           FROM products p WHERE p.deleted_at IS NULL$farmerP GROUP BY p.market_id) pr ON pr.market_id = m.id
              LEFT JOIN (SELECT om.market_id, " . RA_STATUS_COLS . "
                           FROM orders o JOIN " . RA_ORDER_MARKET . " om ON om.order_id = o.id
                          WHERE o.created_at >= ? AND o.created_at < ?$farmerO GROUP BY om.market_id) os ON os.market_id = m.id
              LEFT JOIN (SELECT om.market_id, COUNT(*) AS reviews, ROUND(AVG(r.rating), 2) AS avg_rating
                           FROM reviews r JOIN " . RA_ORDER_MARKET . " om ON om.order_id = r.order_id
                          WHERE r.status = 'visible' AND r.created_at >= ? AND r.created_at < ?$farmerR GROUP BY om.market_id) rv ON rv.market_id = m.id";
    if ($f['farmer_id']) { $params[] = $f['farmer_id']; }
    if ($f['farmer_id']) { $params[] = $f['farmer_id']; }
    array_push($params, $f['from'], $f['to']);
    if ($f['farmer_id']) { $params[] = $f['farmer_id']; }
    array_push($params, $f['from'], $f['to']);
    if ($f['farmer_id']) { $params[] = $f['farmer_id']; }

    $where = [];
    if ($f['market_id']) {
        $where[] = 'm.id = ?';
        $params[] = $f['market_id'];
    }
    if ($f['farmer_id']) {
        $where[] = '(fm.farmers > 0 OR pr.products > 0 OR os.orders > 0)';
    }
    $sql .= ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY os.order_value IS NULL, os.order_value DESC, pr.products DESC, m.name';

    return array_map(function ($r) {
        return [
            'id' => (int) $r['id'], 'name' => $r['name'], 'status' => $r['status'], 'type' => $r['type_name'],
            'location' => implode(', ', array_filter([$r['address'], $r['area'], $r['city']])),
            'operating' => trim(($r['operating_days'] ? str_replace(',', ', ', ucwords((string) $r['operating_days'], ',')) : '') .
                ($r['opening_time'] && $r['closing_time'] ? ', ' . fmt_time($r['opening_time']) . ' - ' . fmt_time($r['closing_time']) : ''), ' , '),
            'farmers' => (int) $r['farmers'], 'products' => (int) $r['products'], 'available_products' => (int) $r['available_products'],
            'reviews' => (int) $r['reviews'], 'avg_rating' => $r['avg_rating'] !== null ? (float) $r['avg_rating'] : null,
            'has_orders' => $r['orders'] !== null,
        ] + ra_order_row($r);
    }, db_all($sql, $params));
}

/** Per-farmer performance. */
function ra_farmers(array $f, int $limit = 300): array
{
    $params = [];
    $marketP = $f['market_id'] ? ' AND p.market_id = ?' : '';
    $marketO = $f['market_id'] ? ' AND om.market_id = ?' : '';

    $sql = "SELECT v.id, v.stall_name, v.contact_person, v.phone, v.is_active, u.name AS user_name, u.approval_status, u.created_at AS joined_at,
                   mk.markets, COALESCE(pr.products, 0) AS products, COALESCE(pr.available, 0) AS available_products, COALESCE(pr.sold_out, 0) AS sold_out_products,
                   os.orders, os.pending, os.accepted, os.ready, os.completed, os.cancelled, os.valid_orders, os.order_value, os.completed_value,
                   COALESCE(rv.reviews, 0) AS reviews, rv.avg_rating
              FROM vendors v
              JOIN users u ON u.id = v.user_id
              LEFT JOIN (SELECT fm.vendor_id, GROUP_CONCAT(m.name ORDER BY m.name SEPARATOR ', ') AS markets
                           FROM farmer_markets fm JOIN markets m ON m.id = fm.market_id
                          WHERE fm.status = 'active' GROUP BY fm.vendor_id) mk ON mk.vendor_id = v.id
              LEFT JOIN (SELECT p.vendor_id, COUNT(*) AS products,
                                SUM(p.status = 'available' AND p.quantity > 0) AS available,
                                SUM(NOT (p.status = 'available' AND p.quantity > 0)) AS sold_out
                           FROM products p WHERE p.deleted_at IS NULL$marketP GROUP BY p.vendor_id) pr ON pr.vendor_id = v.id
              LEFT JOIN (SELECT o.vendor_id, " . RA_STATUS_COLS . "
                           FROM orders o LEFT JOIN " . RA_ORDER_MARKET . " om ON om.order_id = o.id
                          WHERE o.created_at >= ? AND o.created_at < ?$marketO GROUP BY o.vendor_id) os ON os.vendor_id = v.id
              LEFT JOIN (SELECT r.vendor_id, COUNT(*) AS reviews, ROUND(AVG(r.rating), 2) AS avg_rating
                           FROM reviews r LEFT JOIN " . RA_ORDER_MARKET . " om ON om.order_id = r.order_id
                          WHERE r.status = 'visible' AND r.created_at >= ? AND r.created_at < ?$marketO GROUP BY r.vendor_id) rv ON rv.vendor_id = v.id
             WHERE 1 = 1";
    if ($f['market_id']) { $params[] = $f['market_id']; }
    array_push($params, $f['from'], $f['to']);
    if ($f['market_id']) { $params[] = $f['market_id']; }
    array_push($params, $f['from'], $f['to']);
    if ($f['market_id']) { $params[] = $f['market_id']; }

    if ($f['farmer_id']) {
        $sql .= ' AND v.id = ?';
        $params[] = $f['farmer_id'];
    }
    if ($f['market_id']) {
        $sql .= " AND EXISTS (SELECT 1 FROM farmer_markets x WHERE x.vendor_id = v.id AND x.market_id = ?)";
        $params[] = $f['market_id'];
    }
    $sql .= ' ORDER BY os.order_value IS NULL, os.order_value DESC, os.orders DESC, v.stall_name LIMIT ' . $limit;

    return array_map(fn($r) => [
        'id' => (int) $r['id'], 'stall_name' => $r['stall_name'],
        'farmer_name' => $r['contact_person'] ?: $r['user_name'],
        'markets' => $r['markets'], 'active' => (int) $r['is_active'] === 1 && $r['approval_status'] === 'approved',
        'products' => (int) $r['products'], 'available_products' => (int) $r['available_products'], 'sold_out_products' => (int) $r['sold_out_products'],
        'reviews' => (int) $r['reviews'], 'avg_rating' => $r['avg_rating'] !== null ? (float) $r['avg_rating'] : null,
        'has_orders' => $r['orders'] !== null,
    ] + ra_order_row($r), db_all($sql, $params));
}

/**
 * Product performance from order_items of valid orders in the period.
 * $activeOnly: only products that were actually ordered (for "top products" lists).
 */
function ra_products(array $f, int $limit = 15, bool $activeOnly = true, string $orderBy = 'value'): array
{
    $params = [];
    $farmerO = $f['farmer_id'] ? ' AND o.vendor_id = ?' : '';
    $sql = "SELECT p.id, p.name, p.price, p.unit, p.quantity, p.status, c.name AS category, v.stall_name, m.name AS market_name,
                   COALESCE(ps.orders, 0) AS orders, COALESCE(ps.qty, 0) AS qty, COALESCE(ps.value, 0) AS value,
                   COALESCE(pr.reviews, 0) AS reviews, pr.avg_rating
              FROM products p
              JOIN vendors v ON v.id = p.vendor_id
              LEFT JOIN categories c ON c.id = p.category_id
              LEFT JOIN markets m ON m.id = p.market_id
              LEFT JOIN (SELECT oi.product_id, COUNT(DISTINCT o.id) AS orders, SUM(oi.quantity) AS qty, SUM(oi.subtotal) AS value
                           FROM order_items oi JOIN orders o ON o.id = oi.order_id
                          WHERE o.status NOT IN ('cancelled','declined') AND o.created_at >= ? AND o.created_at < ?$farmerO
                          GROUP BY oi.product_id) ps ON ps.product_id = p.id
              LEFT JOIN (SELECT oi.product_id, COUNT(DISTINCT r.id) AS reviews, ROUND(AVG(r.rating), 2) AS avg_rating
                           FROM reviews r JOIN order_items oi ON oi.order_id = r.order_id
                          WHERE r.status = 'visible' AND r.created_at >= ? AND r.created_at < ?
                          GROUP BY oi.product_id) pr ON pr.product_id = p.id
             WHERE p.deleted_at IS NULL";
    array_push($params, $f['from'], $f['to']);
    if ($f['farmer_id']) { $params[] = $f['farmer_id']; }
    array_push($params, $f['from'], $f['to']);
    if ($f['market_id']) { $sql .= ' AND p.market_id = ?'; $params[] = $f['market_id']; }
    if ($f['farmer_id']) { $sql .= ' AND p.vendor_id = ?'; $params[] = $f['farmer_id']; }
    if ($activeOnly) {
        $sql .= $orderBy === 'reviews' ? ' AND pr.reviews > 0' : ' AND ps.orders > 0';
    }
    $order = [
        'value'   => 'value DESC, qty DESC',
        'qty'     => 'qty DESC, value DESC',
        'reviews' => 'reviews DESC, pr.avg_rating DESC',
    ][$orderBy] ?? 'value DESC';
    $sql .= " ORDER BY $order, p.name LIMIT " . $limit;

    return array_map(fn($r) => [
        'id' => (int) $r['id'], 'name' => $r['name'], 'category' => $r['category'], 'farmer' => $r['stall_name'], 'market' => $r['market_name'],
        'price' => (float) $r['price'], 'unit' => $r['unit'], 'stock' => (float) $r['quantity'],
        'available' => $r['status'] === 'available' && (float) $r['quantity'] > 0,
        'orders' => (int) $r['orders'], 'qty' => (float) $r['qty'], 'value' => round((float) $r['value'], 2),
        'reviews' => (int) $r['reviews'], 'avg_rating' => $r['avg_rating'] !== null ? (float) $r['avg_rating'] : null,
    ], db_all($sql, $params));
}

function ra_customers(array $f): array
{
    [$scope, $params] = ra_order_scope($f);
    $act = db_one("SELECT COUNT(DISTINCT o.customer_id) AS with_orders,
                          COUNT(DISTINCT CASE WHEN o.status IN ('completed','picked_up') THEN o.customer_id END) AS with_completed,
                          COUNT(*) AS orders $scope", $params);
    $withOrders = (int) $act['with_orders'];
    return [
        'total'          => (int) db_value("SELECT COUNT(*) FROM users WHERE role = 'customer'"),
        'new'            => (int) db_value("SELECT COUNT(*) FROM users WHERE role = 'customer' AND created_at >= ? AND created_at < ?", [$f['from'], $f['to']]),
        'with_orders'    => $withOrders,
        'with_completed' => (int) $act['with_completed'],
        'orders'         => (int) $act['orders'],
        'avg_orders'     => $withOrders > 0 ? round((int) $act['orders'] / $withOrders, 2) : null,
        'new_farmers'    => (int) db_value("SELECT COUNT(*) FROM users WHERE role = 'vendor' AND created_at >= ? AND created_at < ?", [$f['from'], $f['to']]),
    ];
}

function ra_reviews(array $f): array
{
    $sql = 'FROM reviews r LEFT JOIN ' . RA_ORDER_MARKET . " om ON om.order_id = r.order_id
            WHERE r.status = 'visible' AND r.created_at >= ? AND r.created_at < ?";
    $params = [$f['from'], $f['to']];
    if ($f['market_id']) { $sql .= ' AND om.market_id = ?'; $params[] = $f['market_id']; }
    if ($f['farmer_id']) { $sql .= ' AND r.vendor_id = ?'; $params[] = $f['farmer_id']; }
    $row = db_one("SELECT COUNT(*) AS total, ROUND(AVG(r.rating), 2) AS avg_rating,
                          SUM(r.rating = 5) AS s5, SUM(r.rating = 4) AS s4, SUM(r.rating = 3) AS s3, SUM(r.rating = 2) AS s2, SUM(r.rating = 1) AS s1
                     $sql", $params);
    return [
        'total'        => (int) $row['total'],
        'avg_rating'   => $row['avg_rating'] !== null ? (float) $row['avg_rating'] : null,
        'distribution' => [5 => (int) $row['s5'], 4 => (int) $row['s4'], 3 => (int) $row['s3'], 2 => (int) $row['s2'], 1 => (int) $row['s1']],
    ];
}

/** Every dataset the analytics page needs, computed once and reused by charts, tables and reports. */
function ra_build(array $f): array
{
    $orders = ra_order_totals($f);
    $markets = ra_markets($f);
    $farmers = ra_farmers($f);
    $byReviews = fn($a, $b) => [$b['reviews'], $b['avg_rating'] ?? 0] <=> [$a['reviews'], $a['avg_rating'] ?? 0];
    $mReviews = array_values(array_filter($markets, fn($m) => $m['reviews'] > 0));
    usort($mReviews, $byReviews);
    $fReviews = array_values(array_filter($farmers, fn($x) => $x['reviews'] > 0));
    usort($fReviews, $byReviews);

    return [
        'filters'          => $f,
        'kpis'             => ra_kpis($f, $orders),
        'orders'           => $orders,
        'series'           => ra_time_series($f),
        'markets'          => $markets,
        'farmers'          => $farmers,
        'top_products'     => ra_products($f, 15, true, 'value'),
        'customers'        => ra_customers($f),
        'reviews'          => ra_reviews($f),
        'review_markets'   => array_slice($mReviews, 0, 5),
        'review_farmers'   => array_slice($fReviews, 0, 5),
        'review_products'  => ra_products($f, 5, true, 'reviews'),
    ];
}

/** Order lines for the Order Report (admin-only; includes customer names). */
function ra_order_list(array $f, int $limit = 1000): array
{
    [, $params] = ra_order_scope($f);
    return db_all(
        "SELECT o.id, o.order_number, o.status, o.total_amount, o.created_at, o.pickup_date, o.pickup_start, o.pickup_end,
                c.name AS customer, v.stall_name AS farmer, m.name AS market, items.products, items.quantity
           FROM orders o
           LEFT JOIN " . RA_ORDER_MARKET . " om ON om.order_id = o.id
           LEFT JOIN markets m ON m.id = om.market_id
           LEFT JOIN users c ON c.id = o.customer_id
           LEFT JOIN vendors v ON v.id = o.vendor_id
           LEFT JOIN (SELECT oi.order_id,
                             GROUP_CONCAT(CONCAT(oi.product_name, ' x ', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM oi.quantity)), ' ', oi.unit) SEPARATOR '; ') AS products,
                             SUM(oi.quantity) AS quantity
                        FROM order_items oi GROUP BY oi.order_id) items ON items.order_id = o.id
          WHERE o.created_at >= ? AND o.created_at < ?"
          . ($f['market_id'] ? ' AND om.market_id = ?' : '')
          . ($f['farmer_id'] ? ' AND o.vendor_id = ?' : '')
          . ' ORDER BY o.created_at DESC LIMIT ' . $limit,
        $params
    );
}

/** Human status for tables (groups aliases the same way the analytics do). */
function ra_status_label(string $s): string
{
    return ['pending' => 'Pending', 'accepted' => 'Accepted', 'preparing' => 'Accepted (preparing)', 'ready' => 'Ready for Pickup',
            'picked_up' => 'Completed (picked up)', 'completed' => 'Completed', 'declined' => 'Cancelled (declined)', 'cancelled' => 'Cancelled'][$s] ?? ucfirst($s);
}

function ra_money(?float $v): string
{
    return $v === null ? '-' : money($v);
}

function ra_rating(?float $v): string
{
    return $v === null ? '-' : number_format($v, 1) . ' / 5';
}
