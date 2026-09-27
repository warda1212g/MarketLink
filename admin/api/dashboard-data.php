<?php
/**
 * GET admin/api/dashboard-data.php?period=month&granularity=monthly
 * Returns every number the admin dashboard shows, as JSON. Admin-only.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_admin_api();

/** Today according to MySQL, so date windows match how created_at is stamped. */
function db_today(): DateTimeImmutable
{
    static $today = null;
    return $today ??= new DateTimeImmutable(db()->query('SELECT CURDATE()')->fetchColumn());
}

$PERIODS = ['today', 'week', 'month', 'year', 'all'];
$GRANULARITIES = ['weekly', 'monthly', 'yearly'];

$period = in_array($_GET['period'] ?? '', $PERIODS, true) ? $_GET['period'] : 'month';
$granularity = in_array($_GET['granularity'] ?? '', $GRANULARITIES, true) ? $_GET['granularity'] : 'monthly';

/**
 * [currentStart, currentEnd, previousStart] as 'Y-m-d H:i:s' strings, or nulls for "all".
 * The previous period ends where the current one starts.
 */
function period_bounds(string $period): array
{
    $today = db_today();
    switch ($period) {
        case 'today':
            return [$today, $today->modify('+1 day'), $today->modify('-1 day')];
        case 'week':
            $start = $today->modify('monday this week');
            return [$start, $start->modify('+1 week'), $start->modify('-1 week')];
        case 'month':
            $start = $today->modify('first day of this month');
            return [$start, $start->modify('+1 month'), $start->modify('-1 month')];
        case 'year':
            $start = $today->setDate((int) $today->format('Y'), 1, 1);
            return [$start, $start->modify('+1 year'), $start->modify('-1 year')];
        default:
            return [null, null, null];
    }
}

[$curStart, $curEnd, $prevStart] = period_bounds($period);
$fmt = fn(?DateTimeImmutable $d) => $d ? $d->format('Y-m-d H:i:s') : null;
$cur  = [$fmt($curStart), $fmt($curEnd)];
$prev = [$fmt($prevStart), $fmt($curStart)];
$hasPeriod = $period !== 'all';

/** Runs a single-value query, appending a created_at window when bounds are given. */
function scalar(string $sql, array $params = [], ?array $range = null, string $col = 'created_at')
{
    if ($range && $range[0] !== null) {
        $sql .= (stripos($sql, ' where ') !== false ? ' AND ' : ' WHERE ') . "$col >= ? AND $col < ?";
        $params[] = $range[0];
        $params[] = $range[1];
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function pct_change(float $current, float $previous): ?float
{
    if ($previous == 0.0) {
        return null; // no baseline to compare against
    }
    return round(($current - $previous) / $previous * 100, 1);
}

/** KPI for an entity that accumulates (customers, farmers, markets). */
function growth_kpi(string $baseSql, array $params, bool $hasPeriod, array $cur, array $prev): array
{
    $total = (int) scalar("SELECT COUNT(*) FROM $baseSql", $params);
    $kpi = ['value' => $total, 'newInPeriod' => null, 'change' => null];
    if ($hasPeriod) {
        $newCur  = (int) scalar("SELECT COUNT(*) FROM $baseSql", $params, $cur);
        $newPrev = (int) scalar("SELECT COUNT(*) FROM $baseSql", $params, $prev);
        $kpi['newInPeriod'] = $newCur;
        $kpi['change'] = pct_change($newCur, $newPrev);
    }
    return $kpi;
}

/** KPI for a per-period flow (orders, revenue). */
function flow_kpi(string $sql, bool $hasPeriod, array $cur, array $prev, bool $money = false): array
{
    $cast = fn($v) => $money ? round((float) $v, 2) : (int) $v;
    $value = $cast(scalar($sql, [], $hasPeriod ? $cur : null));
    $change = null;
    if ($hasPeriod) {
        $change = pct_change((float) $value, (float) $cast(scalar($sql, [], $prev)));
    }
    return ['value' => $value, 'change' => $change];
}

// Revenue is counted on completed orders only: customers pay in person at pickup.
$REVENUE_SQL = "SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status = 'completed'";

$kpis = [
    'customers' => growth_kpi("users WHERE role = 'customer'", [], $hasPeriod, $cur, $prev),
    'farmers'   => growth_kpi("users WHERE role = 'vendor'", [], $hasPeriod, $cur, $prev),
    'markets'   => growth_kpi("markets WHERE status = 'active'", [], $hasPeriod, $cur, $prev),
    'marketTypes' => ['value' => (int) scalar('SELECT COUNT(*) FROM market_types WHERE is_active = 1'), 'newInPeriod' => null, 'change' => null],
    // Vendors = approved farmers selling in at least one market.
    'vendors'   => ['value' => (int) scalar(
        "SELECT COUNT(DISTINCT fm.vendor_id) FROM farmer_markets fm
           JOIN vendors v ON v.id = fm.vendor_id JOIN users u ON u.id = v.user_id AND u.approval_status = 'approved'
          WHERE fm.status = 'active'"), 'newInPeriod' => null, 'change' => null],
    'products'  => growth_kpi('products WHERE deleted_at IS NULL', [], $hasPeriod, $cur, $prev),
    'orders'    => flow_kpi('SELECT COUNT(*) FROM orders', $hasPeriod, $cur, $prev),
    'revenue'   => flow_kpi($REVENUE_SQL, $hasPeriod, $cur, $prev, true),
];

// trend
function build_trend(string $granularity): array
{
    $now = db_today();
    $buckets = [];

    if ($granularity === 'weekly') {
        $start = $now->modify('monday this week')->modify('-11 weeks');
        for ($i = 0; $i < 12; $i++) {
            $d = $start->modify("+$i weeks");
            $buckets[$d->format('oW')] = $d->format('M j');
        }
        $keyExpr = 'YEARWEEK(created_at, 1)';
    } elseif ($granularity === 'yearly') {
        $start = $now->setDate((int) $now->format('Y') - 4, 1, 1);
        for ($i = 0; $i < 5; $i++) {
            $y = (string) ((int) $start->format('Y') + $i);
            $buckets[$y] = $y;
        }
        $keyExpr = 'YEAR(created_at)';
    } else {
        $start = $now->modify('first day of this month')->modify('-11 months');
        for ($i = 0; $i < 12; $i++) {
            $d = $start->modify("+$i months");
            $buckets[$d->format('Y-m')] = $d->format("M 'y");
        }
        $keyExpr = "DATE_FORMAT(created_at, '%Y-%m')";
    }

    $stmt = db()->prepare(
        "SELECT $keyExpr AS bucket,
                COUNT(*) AS orders,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN total_amount ELSE 0 END), 0) AS revenue
           FROM orders
          WHERE created_at >= ?
          GROUP BY bucket"
    );
    $stmt->execute([$start->format('Y-m-d H:i:s')]);

    $orders = array_fill_keys(array_keys($buckets), 0);
    $revenue = $orders;
    foreach ($stmt as $row) {
        $key = (string) $row['bucket'];
        if (isset($orders[$key])) {
            $orders[$key] = (int) $row['orders'];
            $revenue[$key] = round((float) $row['revenue'], 2);
        }
    }

    return [
        'granularity' => $granularity,
        'labels'  => array_values($buckets),
        'orders'  => array_values($orders),
        'revenue' => array_values($revenue),
        'hasData' => array_sum($orders) > 0,
    ];
}

// distributions
$userDistribution = [
    'customers' => (int) scalar("SELECT COUNT(*) FROM users WHERE role = 'customer'"),
    'farmers'   => (int) scalar("SELECT COUNT(*) FROM users WHERE role = 'vendor'"),
];

$statusCounts = array_fill_keys(['pending', 'accepted', 'ready', 'completed', 'cancelled'], 0);
$sql = 'SELECT status, COUNT(*) AS n FROM orders';
$params = [];
if ($hasPeriod) {
    $sql .= ' WHERE created_at >= ? AND created_at < ?';
    $params = $cur;
}
$stmt = db()->prepare($sql . ' GROUP BY status');
$stmt->execute($params);
foreach ($stmt as $row) {
    // The chart has no "declined" slice, so vendor declines are shown with cancellations.
    $key = $row['status'] === 'declined' ? 'cancelled' : ($row['status'] === 'preparing' ? 'accepted' : ($row['status'] === 'picked_up' ? 'ready' : $row['status']));
    if (isset($statusCounts[$key])) {
        $statusCounts[$key] += (int) $row['n'];
    }
}

// Top-level categories that actually have listings (there are dozens of categories now).
$categories = db()->query(
    "SELECT c.name, COUNT(p.id) AS count
       FROM categories c
       JOIN products p ON p.category_id = c.id AND p.deleted_at IS NULL
      WHERE c.parent_id IS NULL
      GROUP BY c.id, c.name
      ORDER BY count DESC, c.name
      LIMIT 10"
)->fetchAll();
foreach ($categories as &$c) {
    $c['count'] = (int) $c['count'];
}
unset($c);

// market structure
$marketsByType = array_map(fn($r) => ['name' => $r['name'], 'count' => (int) $r['n']], db()->query(
    'SELECT t.name, t.icon, COUNT(m.id) AS n
       FROM market_types t JOIN markets m ON m.market_type_id = t.id
      GROUP BY t.id, t.name, t.icon ORDER BY n DESC, t.sort_order'
)->fetchAll());

$farmersByCategory = array_map(fn($r) => ['name' => $r['farmer_label'], 'count' => (int) $r['n']], db()->query(
    'SELECT t.farmer_label, COUNT(fc.vendor_id) AS n
       FROM market_types t JOIN farmer_categories fc ON fc.market_type_id = t.id
      GROUP BY t.id, t.farmer_label ORDER BY n DESC, t.sort_order'
)->fetchAll());

// Orders / revenue per market come from the market each ordered product is listed in.
$sql = "SELECT m.id, m.name, m.city, m.status, t.name AS type,
               (SELECT COUNT(*) FROM farmer_markets fm WHERE fm.market_id = m.id AND fm.status = 'active') AS vendors,
               COUNT(DISTINCT o.id) AS orders,
               COALESCE(SUM(CASE WHEN o.status = 'completed' THEN oi.subtotal ELSE 0 END), 0) AS revenue
          FROM markets m
          JOIN market_types t ON t.id = m.market_type_id
          LEFT JOIN products p     ON p.market_id = m.id
          LEFT JOIN order_items oi ON oi.product_id = p.id
          LEFT JOIN orders o       ON o.id = oi.order_id" . ($hasPeriod ? ' AND o.created_at >= ? AND o.created_at < ?' : '') . "
         GROUP BY m.id, m.name, m.city, m.status, t.name
         ORDER BY revenue DESC, orders DESC, m.name
         LIMIT 8";
$stmt = db()->prepare($sql);
$stmt->execute($hasPeriod ? $cur : []);
$topMarkets = array_map(fn($r) => [
    'id' => (int) $r['id'], 'name' => $r['name'], 'city' => $r['city'], 'type' => $r['type'], 'status' => $r['status'],
    'vendors' => (int) $r['vendors'], 'orders' => (int) $r['orders'], 'revenue' => round((float) $r['revenue'], 2),
], $stmt->fetchAll());

// top farmers (measurable: revenue, then orders)
$sql = "SELECT u.id, u.name AS farmer, f.stall_name,
               (SELECT GROUP_CONCAT(m.name ORDER BY m.name SEPARATOR ', ') FROM farmer_markets fm JOIN markets m ON m.id = fm.market_id
                 WHERE fm.vendor_id = f.id AND fm.status = 'active') AS market,
               u.approval_status AS status,
               COUNT(o.id) AS orders,
               COALESCE(SUM(CASE WHEN o.status = 'completed' THEN o.total_amount ELSE 0 END), 0) AS revenue
          FROM vendors f
          JOIN users u   ON u.id = f.user_id
          JOIN orders o  ON o.vendor_id = f.id AND o.status NOT IN ('cancelled','declined')";
$params = [];
if ($hasPeriod) {
    $sql .= ' AND o.created_at >= ? AND o.created_at < ?';
    $params = $cur;
}
$sql .= ' GROUP BY u.id, f.id, u.name, f.stall_name, u.approval_status
          ORDER BY revenue DESC, orders DESC
          LIMIT 5';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$topFarmers = array_map(fn($r) => [
    'id' => (int) $r['id'], 'farmer' => $r['farmer'], 'stall' => $r['stall_name'],
    'market' => $r['market'], 'status' => $r['status'],
    'orders' => (int) $r['orders'], 'revenue' => round((float) $r['revenue'], 2),
], $stmt->fetchAll());

// recent lists
$recentOrders = db()->query(
    "SELECT o.id, cu.name AS customer, f.stall_name AS farmer, o.total_amount AS total,
            o.pickup_date,
            CONCAT(TIME_FORMAT(o.pickup_start, '%l:%i %p'), ' - ', TIME_FORMAT(o.pickup_end, '%l:%i %p')) AS pickup_slot,
            o.status, o.created_at
       FROM orders o
       JOIN users cu  ON cu.id = o.customer_id
       JOIN vendors f ON f.id = o.vendor_id
      ORDER BY o.created_at DESC, o.id DESC
      LIMIT 8"
)->fetchAll();
foreach ($recentOrders as &$o) {
    $o['id'] = (int) $o['id'];
    $o['total'] = round((float) $o['total'], 2);
}
unset($o);

$recentFarmers = db()->query(
    "SELECT u.id, u.name, COALESCE(f.stall_name, 'Customer') AS stall_name, u.email, u.created_at,
            u.approval_status
       FROM users u
       LEFT JOIN vendors f ON f.user_id = u.id
      WHERE u.role IN ('vendor','customer')
      ORDER BY (u.approval_status = 'pending') DESC, u.created_at DESC, u.id DESC
      LIMIT 6"
)->fetchAll();
foreach ($recentFarmers as &$f) {
    $f['id'] = (int) $f['id'];
}
unset($f);

$activity = db()->query(
    'SELECT type, message, created_at FROM activity_log ORDER BY created_at DESC, id DESC LIMIT 8'
)->fetchAll();

echo json_encode([
    'period'          => $period,
    'serverNow'       => db()->query('SELECT NOW()')->fetchColumn(),
    'kpis'            => $kpis,
    'trend'           => build_trend($granularity),
    'userDistribution'=> $userDistribution,
    'orderStatus'     => $statusCounts,
    'categories'      => $categories,
    'marketsByType'   => $marketsByType,
    'farmersByCategory' => $farmersByCategory,
    'topMarkets'      => $topMarkets,
    'topFarmers'      => $topFarmers,
    'recentOrders'    => $recentOrders,
    'recentFarmers'   => $recentFarmers,
    'activity'        => $activity,
]);
