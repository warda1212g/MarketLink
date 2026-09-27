<?php
/**
 * Vendor analytics - every number here is computed from orders / order_items / products.
 *
 * Revenue is counted from orders in REVENUE_STATUSES (payment is taken at pickup,
 * so only completed orders are treated as earned revenue).
 */
declare(strict_types=1);

const REVENUE_STATUSES = ['completed'];
const ANALYTICS_RANGES = ['today', '7d', '30d', '3m', '6m', '1y', 'year', 'custom'];

function revenue_status_sql(string $alias = 'o'): string
{
    return $alias . '.status IN (' . implode(',', array_map(fn($s) => "'" . $s . "'", REVENUE_STATUSES)) . ')';
}

/**
 * Resolve a range key into [start, end) plus a sensible bucket granularity.
 */
function resolve_range(string $key, ?string $from = null, ?string $to = null, ?string $granularity = null): array
{
    $now = new DateTimeImmutable('now');
    $today = $now->setTime(0, 0);
    $end = $today->modify('+1 day');

    switch ($key) {
        case 'today': $start = $today; break;
        case '7d':    $start = $today->modify('-6 days'); break;
        case '3m':    $start = $today->modify('-3 months')->modify('+1 day'); break;
        case '6m':    $start = $today->modify('-6 months')->modify('+1 day'); break;
        case '1y':    $start = $today->modify('-1 year')->modify('+1 day'); break;
        case 'year':  $start = $today->setDate((int) $today->format('Y'), 1, 1); break;
        case 'custom':
            if ($from && $to && valid_date($from) && valid_date($to)) {
                $start = new DateTimeImmutable($from);
                $end = (new DateTimeImmutable($to))->modify('+1 day');
                if ($end <= $start) {
                    [$start, $end] = [$end->modify('-1 day'), $start->modify('+1 day')];
                }
                if ($start->diff($end)->days > 1100) {
                    $start = $end->modify('-1100 days');
                }
                break;
            }
            $key = '30d';
            // fall through
        default:
            $key = '30d';
            $start = $today->modify('-29 days');
    }

    $days = (int) $start->diff($end)->days;
    $auto = $days <= 1 ? 'hour' : ($days <= 45 ? 'day' : ($days <= 200 ? 'week' : 'month'));
    $g = in_array($granularity, ['hour', 'day', 'week', 'month'], true) ? $granularity : $auto;
    if ($g === 'hour' && $days > 3) {
        $g = 'day';
    }

    return [
        'key'         => $key,
        'start'       => $start,
        'end'         => $end,
        'days'        => $days,
        'granularity' => $g,
        'prev_start'  => $start->modify("-{$days} days"),
        'prev_end'    => $start,
        'label'       => $start->format('M j, Y') . ' - ' . $end->modify('-1 day')->format('M j, Y'),
    ];
}

function range_from_request(array $src): array
{
    $key = in_array($src['range'] ?? '', ANALYTICS_RANGES, true) ? $src['range'] : '30d';
    return resolve_range($key, $src['from'] ?? null, $src['to'] ?? null, $src['granularity'] ?? null);
}

/** SQL expression that buckets a datetime column. */
function bucket_sql(string $g, string $col): string
{
    switch ($g) {
        case 'hour':  return "DATE_FORMAT($col, '%Y-%m-%d %H:00:00')";
        case 'week':  return "DATE_FORMAT(DATE_SUB(DATE($col), INTERVAL WEEKDAY($col) DAY), '%Y-%m-%d')";
        case 'month': return "DATE_FORMAT($col, '%Y-%m-01')";
        default:      return "DATE_FORMAT($col, '%Y-%m-%d')";
    }
}

/** Ordered bucket keys + human labels covering the whole range (so gaps show as zero). */
function bucket_keys(array $r): array
{
    $g = $r['granularity'];
    $cursor = $r['start'];
    if ($g === 'week') {
        $cursor = $cursor->modify('-' . ((int) $cursor->format('N') - 1) . ' days');
    } elseif ($g === 'month') {
        $cursor = $cursor->modify('first day of this month');
    }
    $step = ['hour' => '+1 hour', 'day' => '+1 day', 'week' => '+1 week', 'month' => '+1 month'][$g];
    $keyFmt = $g === 'hour' ? 'Y-m-d H:00:00' : 'Y-m-d';
    $labelFmt = ['hour' => 'g A', 'day' => $r['days'] <= 7 ? 'D' : 'M j', 'week' => 'M j', 'month' => 'M Y'][$g];

    $keys = [];
    $guard = 0;
    while ($cursor < $r['end'] && $guard++ < 500) {
        $keys[$cursor->format($keyFmt)] = $cursor->format($labelFmt);
        $cursor = $cursor->modify($step);
    }
    return $keys;
}

/** Revenue + order-count time series. */
function sales_series(int $vendorId, array $r): array
{
    $bucket = bucket_sql($r['granularity'], 'o.created_at');
    $rows = db_all("SELECT $bucket AS b,
                           COUNT(*) AS orders,
                           SUM(CASE WHEN " . revenue_status_sql() . " THEN o.total_amount ELSE 0 END) AS revenue,
                           SUM(o.status = 'completed') AS completed,
                           SUM(o.status IN ('cancelled','declined')) AS cancelled
                    FROM orders o
                    WHERE o.vendor_id = ? AND o.created_at >= ? AND o.created_at < ?
                    GROUP BY b", [$vendorId, $r['start']->format('Y-m-d H:i:s'), $r['end']->format('Y-m-d H:i:s')]);
    $byKey = [];
    foreach ($rows as $row) {
        $byKey[$row['b']] = $row;
    }

    $out = ['labels' => [], 'keys' => [], 'revenue' => [], 'orders' => [], 'completed' => [], 'cancelled' => []];
    foreach (bucket_keys($r) as $key => $label) {
        $row = $byKey[$key] ?? null;
        $out['keys'][] = $key;
        $out['labels'][] = $label;
        $out['revenue'][] = $row ? round((float) $row['revenue'], 2) : 0;
        $out['orders'][] = $row ? (int) $row['orders'] : 0;
        $out['completed'][] = $row ? (int) $row['completed'] : 0;
        $out['cancelled'][] = $row ? (int) $row['cancelled'] : 0;
    }
    $out['granularity'] = $r['granularity'];
    return $out;
}

function period_totals(int $vendorId, DateTimeImmutable $start, DateTimeImmutable $end): array
{
    $row = db_one("SELECT COUNT(*) AS orders,
                          COALESCE(SUM(CASE WHEN " . revenue_status_sql() . " THEN o.total_amount END), 0) AS revenue,
                          SUM(" . revenue_status_sql() . ") AS revenue_orders,
                          SUM(o.status = 'completed') AS completed,
                          SUM(o.status IN ('cancelled','declined')) AS cancelled
                   FROM orders o WHERE o.vendor_id = ? AND o.created_at >= ? AND o.created_at < ?",
        [$vendorId, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')]);

    $sold = db_value("SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi JOIN orders o ON o.id = oi.order_id
                      WHERE o.vendor_id = ? AND " . revenue_status_sql() . " AND o.created_at >= ? AND o.created_at < ?",
        [$vendorId, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')]);

    $revenue = (float) $row['revenue'];
    $revOrders = (int) $row['revenue_orders'];
    return [
        'revenue'   => $revenue,
        'orders'    => (int) $row['orders'],
        'completed' => (int) $row['completed'],
        'cancelled' => (int) $row['cancelled'],
        'aov'       => $revOrders > 0 ? round($revenue / $revOrders, 2) : 0.0,
        'sold'      => (float) $sold,
    ];
}

/** Percentage change, or null when there is no baseline. */
function pct_change(float $current, float $previous): ?float
{
    if ($previous == 0.0) {
        return $current == 0.0 ? 0.0 : null;
    }
    return round((($current - $previous) / $previous) * 100, 1);
}

function analytics_kpis(int $vendorId, array $r): array
{
    $cur = period_totals($vendorId, $r['start'], $r['end']);
    $prev = period_totals($vendorId, $r['prev_start'], $r['prev_end']);
    $kpi = function (string $key) use ($cur, $prev) {
        return ['value' => $cur[$key], 'previous' => $prev[$key], 'change' => pct_change((float) $cur[$key], (float) $prev[$key])];
    };
    return [
        'revenue'   => $kpi('revenue') + ['label' => money($cur['revenue'])],
        'orders'    => $kpi('orders') + ['label' => number_format($cur['orders'])],
        'aov'       => $kpi('aov') + ['label' => money($cur['aov'])],
        'sold'      => $kpi('sold') + ['label' => qty($cur['sold'])],
        'completed' => $cur['completed'],
        'cancelled' => $cur['cancelled'],
    ];
}

function status_breakdown(int $vendorId, ?array $r = null): array
{
    $sql = 'SELECT status, COUNT(*) AS n FROM orders WHERE vendor_id = ?';
    $params = [$vendorId];
    if ($r) {
        $sql .= ' AND created_at >= ? AND created_at < ?';
        $params[] = $r['start']->format('Y-m-d H:i:s');
        $params[] = $r['end']->format('Y-m-d H:i:s');
    }
    $counts = array_fill_keys(ORDER_STATUSES, 0);
    foreach (db_all($sql . ' GROUP BY status', $params) as $row) {
        $counts[$row['status']] = (int) $row['n'];
    }
    return $counts;
}

function best_sellers(int $vendorId, ?array $r = null, int $limit = 5): array
{
    $sql = 'SELECT oi.product_id, oi.product_name AS name, oi.unit, p.image,
                   SUM(oi.quantity) AS qty, SUM(oi.subtotal) AS revenue, COUNT(DISTINCT o.id) AS orders
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            LEFT JOIN products p ON p.id = oi.product_id
            WHERE o.vendor_id = ? AND ' . revenue_status_sql();
    $params = [$vendorId];
    if ($r) {
        $sql .= ' AND o.created_at >= ? AND o.created_at < ?';
        $params[] = $r['start']->format('Y-m-d H:i:s');
        $params[] = $r['end']->format('Y-m-d H:i:s');
    }
    $limit = max(1, min(50, $limit));
    $sql .= " GROUP BY oi.product_id, oi.product_name, oi.unit, p.image ORDER BY qty DESC, revenue DESC LIMIT $limit";

    return array_map(fn($row) => [
        'product_id' => (int) $row['product_id'],
        'name'       => $row['name'],
        'unit'       => $row['unit'],
        'image'      => upload_url($row['image']),
        'qty'        => (float) $row['qty'],
        'qty_label'  => qty($row['qty']) . ' ' . $row['unit'],
        'revenue'    => (float) $row['revenue'],
        'revenue_label' => money($row['revenue']),
        'orders'     => (int) $row['orders'],
    ], db_all($sql, $params));
}

function category_performance(int $vendorId, array $r): array
{
    $rows = db_all('SELECT COALESCE(c.name, "Uncategorised") AS name, SUM(oi.subtotal) AS revenue, SUM(oi.quantity) AS qty
                    FROM order_items oi
                    JOIN orders o ON o.id = oi.order_id
                    LEFT JOIN products p ON p.id = oi.product_id
                    LEFT JOIN categories c ON c.id = p.category_id
                    WHERE o.vendor_id = ? AND ' . revenue_status_sql() . ' AND o.created_at >= ? AND o.created_at < ?
                    GROUP BY name ORDER BY revenue DESC',
        [$vendorId, $r['start']->format('Y-m-d H:i:s'), $r['end']->format('Y-m-d H:i:s')]);
    return array_map(fn($row) => ['name' => $row['name'], 'revenue' => round((float) $row['revenue'], 2), 'qty' => (float) $row['qty']], $rows);
}

/** Orders grouped by weekday (Mon..Sun) inside the range. */
function orders_by_weekday(int $vendorId, array $r): array
{
    $counts = array_fill(1, 7, 0);
    $rows = db_all('SELECT WEEKDAY(created_at) + 1 AS d, COUNT(*) AS n FROM orders
                    WHERE vendor_id = ? AND created_at >= ? AND created_at < ? GROUP BY d',
        [$vendorId, $r['start']->format('Y-m-d H:i:s'), $r['end']->format('Y-m-d H:i:s')]);
    foreach ($rows as $row) {
        $counts[(int) $row['d']] = (int) $row['n'];
    }
    return ['labels' => array_map(fn($d) => substr($d, 0, 3), array_values(DAYS)), 'orders' => array_values($counts)];
}

/** Headline numbers for the dashboard home. */
function dashboard_stats(array $vendor): array
{
    $vid = (int) $vendor['id'];
    $today = date('Y-m-d');

    $products = db_one('SELECT COUNT(*) AS total,
                               SUM(created_at >= DATE_FORMAT(CURDATE(), "%Y-%m-01")) AS this_month,
                               SUM(status = "available" AND quantity > 0) AS live
                        FROM products WHERE vendor_id = ? AND deleted_at IS NULL', [$vid]);

    $pending = db_one('SELECT COUNT(*) AS total, SUM(pickup_date <= DATE_ADD(CURDATE(), INTERVAL 1 DAY)) AS urgent
                       FROM orders WHERE vendor_id = ? AND status = "pending"', [$vid]);

    $todayOrders = (int) db_value('SELECT COUNT(*) FROM orders WHERE vendor_id = ? AND DATE(created_at) = ?', [$vid, $today]);
    $yesterdayOrders = (int) db_value('SELECT COUNT(*) FROM orders WHERE vendor_id = ? AND DATE(created_at) = DATE_SUB(?, INTERVAL 1 DAY)', [$vid, $today]);
    $todayPickups = (int) db_value('SELECT COUNT(*) FROM orders WHERE vendor_id = ? AND pickup_date = ? AND status IN ("accepted","ready")', [$vid, $today]);

    $revenueAll = (float) db_value('SELECT COALESCE(SUM(total_amount), 0) FROM orders o WHERE vendor_id = ? AND ' . revenue_status_sql(), [$vid]);
    $r30 = resolve_range('30d');
    $cur = period_totals($vid, $r30['start'], $r30['end']);
    $prev = period_totals($vid, $r30['prev_start'], $r30['prev_end']);

    return [
        'products' => [
            'value' => (int) $products['total'],
            'this_month' => (int) $products['this_month'],
            'live' => (int) $products['live'],
        ],
        'pending' => ['value' => (int) $pending['total'], 'urgent' => (int) $pending['urgent']],
        'today' => [
            'value' => $todayOrders,
            'change' => pct_change($todayOrders, $yesterdayOrders),
            'yesterday' => $yesterdayOrders,
            'pickups' => $todayPickups,
        ],
        'revenue' => [
            'value' => $revenueAll,
            'last30' => $cur['revenue'],
            'change' => pct_change($cur['revenue'], $prev['revenue']),
        ],
    ];
}

function low_stock_products(array $vendor, int $limit = 6): array
{
    $limit = max(1, min(50, $limit));
    return db_all("SELECT id, name, unit, quantity, image, status FROM products
                   WHERE vendor_id = ? AND deleted_at IS NULL AND quantity <= ?
                   ORDER BY quantity ASC, name LIMIT $limit", [$vendor['id'], $vendor['low_stock_threshold']]);
}

function rating_summary(int $vendorId): array
{
    $row = db_one('SELECT COUNT(*) AS n, COALESCE(AVG(rating), 0) AS avg, SUM(vendor_reply IS NULL) AS unanswered FROM reviews WHERE status = "visible" AND vendor_id = ?', [$vendorId]);
    $dist = array_fill(1, 5, 0);
    foreach (db_all('SELECT rating, COUNT(*) AS n FROM reviews WHERE status = "visible" AND vendor_id = ? GROUP BY rating', [$vendorId]) as $d) {
        $dist[(int) $d['rating']] = (int) $d['n'];
    }
    return ['count' => (int) $row['n'], 'avg' => round((float) $row['avg'], 1), 'unanswered' => (int) $row['unanswered'], 'distribution' => $dist];
}
