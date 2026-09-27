<?php
/**
 * Market queries used by the admin pages and vendor forms.
 * Revenue only counts completed orders.
 */
declare(strict_types=1);

const MARKET_STATUSES = ['active', 'inactive', 'suspended'];
const MARKET_DAYS = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];

function slugify(string $text): string
{
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $text), '-'));
    return $slug !== '' ? $slug : 'item';
}

/** Slug unique within $table (appends -2, -3 ... on collision). */
function unique_slug(string $table, string $base, ?int $ignoreId = null): string
{
    $slug = $base;
    for ($i = 2; ; $i++) {
        $sql = "SELECT 1 FROM $table WHERE slug = ?" . ($ignoreId ? ' AND id <> ?' : '');
        if (!db_value($sql, $ignoreId ? [$slug, $ignoreId] : [$slug])) {
            return $slug;
        }
        $slug = $base . '-' . $i;
    }
}

function market_types_all(bool $activeOnly = false): array
{
    return db_all(
        'SELECT t.*,
                (SELECT COUNT(*) FROM markets m WHERE m.market_type_id = t.id) AS markets,
                (SELECT COUNT(*) FROM categories c WHERE c.market_type_id = t.id AND c.parent_id IS NULL) AS categories
           FROM market_types t' . ($activeOnly ? ' WHERE t.is_active = 1' : '') . '
          ORDER BY t.sort_order, t.name'
    );
}

/**
 * Markets with their headline counts. $filters: q, type, status, city, id.
 * Vendors = active assignments; products = live listings in the market.
 */
function markets_list(array $filters = []): array
{
    $where = [];
    $params = [];
    if (!empty($filters['id'])) {
        $where[] = 'm.id = ?';
        $params[] = (int) $filters['id'];
    }
    if (($q = trim((string) ($filters['q'] ?? ''))) !== '') {
        $where[] = '(m.name LIKE ? OR m.city LIKE ? OR m.area LIKE ? OR m.address LIKE ? OR t.name LIKE ?)';
        array_push($params, ...array_fill(0, 5, '%' . $q . '%'));
    }
    if (!empty($filters['type'])) {
        $where[] = 'm.market_type_id = ?';
        $params[] = (int) $filters['type'];
    }
    if (in_array($filters['status'] ?? '', MARKET_STATUSES, true)) {
        $where[] = 'm.status = ?';
        $params[] = $filters['status'];
    }
    if (($city = trim((string) ($filters['city'] ?? ''))) !== '') {
        $where[] = 'm.city = ?';
        $params[] = $city;
    }

    $rows = db_all(
        "SELECT m.*, t.name AS type_name, t.icon AS type_icon, t.slug AS type_slug,
                (SELECT COUNT(*) FROM farmer_markets fm WHERE fm.market_id = m.id AND fm.status = 'active') AS vendors,
                (SELECT COUNT(*) FROM products p WHERE p.market_id = m.id AND p.deleted_at IS NULL) AS products
           FROM markets m
           JOIN market_types t ON t.id = m.market_type_id" .
        ($where ? ' WHERE ' . implode(' AND ', $where) : '') .
        " ORDER BY FIELD(m.status, 'active', 'inactive', 'suspended'), m.name",
        $params
    );
    return array_map('market_to_array', $rows);
}

function market_to_array(array $m): array
{
    $days = $m['operating_days'] ? explode(',', $m['operating_days']) : [];
    return [
        'id'             => (int) $m['id'],
        'name'           => $m['name'],
        'slug'           => $m['slug'],
        'market_type_id' => (int) $m['market_type_id'],
        'type_name'      => $m['type_name'] ?? null,
        'type_icon'      => $m['type_icon'] ?? null,
        'description'    => $m['description'],
        'image'          => upload_url($m['image']),
        'city'           => $m['city'],
        'area'           => $m['area'],
        'address'        => $m['address'],
        'location'       => implode(', ', array_filter([$m['area'], $m['city']])),
        'latitude'       => $m['latitude'] !== null ? (float) $m['latitude'] : null,
        'longitude'      => $m['longitude'] !== null ? (float) $m['longitude'] : null,
        'opening_time'   => $m['opening_time'] ? substr($m['opening_time'], 0, 5) : '',
        'closing_time'   => $m['closing_time'] ? substr($m['closing_time'], 0, 5) : '',
        'hours'          => $m['opening_time'] && $m['closing_time'] ? fmt_time($m['opening_time']) . ' - ' . fmt_time($m['closing_time']) : '',
        'operating_days' => $days,
        'days_label'     => market_days_label($days),
        'status'         => $m['status'],
        'vendors'        => (int) ($m['vendors'] ?? 0),
        'products'       => (int) ($m['products'] ?? 0),
        'created_at'     => $m['created_at'],
    ];
}

function market_days_label(array $days): string
{
    if (!$days) {
        return '';
    }
    if (count($days) === 7) {
        return 'Every day';
    }
    $keys = array_keys(MARKET_DAYS);
    $idx = array_map(fn($d) => array_search($d, $keys, true), $days);
    sort($idx);
    // consecutive run "Mon - Sat"
    if ($idx[count($idx) - 1] - $idx[0] === count($idx) - 1 && count($idx) > 2) {
        return ucfirst($keys[$idx[0]]) . ' - ' . ucfirst($keys[$idx[count($idx) - 1]]);
    }
    return implode(', ', array_map(fn($i) => ucfirst($keys[$i]), $idx));
}

/** [start, end) DATETIME strings for a period, or [null, null] for all time. */
function market_period_bounds(string $period): array
{
    $today = new DateTimeImmutable(db()->query('SELECT CURDATE()')->fetchColumn());
    switch ($period) {
        case 'today': $s = $today; $e = $s->modify('+1 day'); break;
        case 'week':  $s = $today->modify('monday this week'); $e = $s->modify('+1 week'); break;
        case 'month': $s = $today->modify('first day of this month'); $e = $s->modify('+1 month'); break;
        case 'year':  $s = $today->setDate((int) $today->format('Y'), 1, 1); $e = $s->modify('+1 year'); break;
        default:      return [null, null];
    }
    return [$s->format('Y-m-d H:i:s'), $e->format('Y-m-d H:i:s')];
}

/** Orders and completed revenue for one market (or all markets when $marketId is null) in a period. */
function market_order_totals(?int $marketId, array $range): array
{
    $sql = "SELECT COUNT(DISTINCT o.id) AS orders,
                   COALESCE(SUM(CASE WHEN o.status = 'completed' THEN oi.subtotal ELSE 0 END), 0) AS revenue
              FROM order_items oi
              JOIN orders o   ON o.id = oi.order_id
              JOIN products p ON p.id = oi.product_id
             WHERE " . ($marketId ? 'p.market_id = ?' : 'p.market_id IS NOT NULL');
    $params = $marketId ? [$marketId] : [];
    if ($range[0]) {
        $sql .= ' AND o.created_at >= ? AND o.created_at < ?';
        array_push($params, $range[0], $range[1]);
    }
    $r = db_one($sql, $params);
    return ['orders' => (int) $r['orders'], 'revenue' => round((float) $r['revenue'], 2)];
}

/** Vendors assigned to a market, with their per-market products/orders/revenue and rating. */
function market_vendors(int $marketId, array $range = [null, null]): array
{
    $orderWindow = $range[0] ? ' AND o.created_at >= ? AND o.created_at < ?' : '';
    $params = [$marketId];
    if ($range[0]) {
        array_push($params, $range[0], $range[1]);
    }
    $params[] = $marketId;

    return array_map(fn($r) => [
        'vendor_id'   => (int) $r['vendor_id'],
        'user_id'     => (int) $r['user_id'],
        'name'        => $r['name'],
        'farm'        => $r['stall_name'],
        'email'       => $r['email'],
        'phone'       => $r['phone'],
        'image'       => upload_url($r['image']),
        'categories'  => $r['categories'] ?: '',
        'products'    => (int) $r['products'],
        'orders'      => (int) $r['orders'],
        'revenue'     => round((float) $r['revenue'], 2),
        'rating'      => $r['rating'] !== null ? round((float) $r['rating'], 1) : null,
        'status'      => $r['assignment_status'],
        'approval'    => $r['approval_status'],
        'assigned_at' => $r['assigned_at'],
    ], db_all(
        "SELECT v.id AS vendor_id, u.id AS user_id, u.name, u.email, COALESCE(v.phone, u.phone) AS phone, v.stall_name, v.image,
                u.approval_status, fm.status AS assignment_status, fm.assigned_at,
                (SELECT GROUP_CONCAT(t.farmer_label ORDER BY t.sort_order SEPARATOR ', ')
                   FROM farmer_categories fc JOIN market_types t ON t.id = fc.market_type_id
                  WHERE fc.vendor_id = v.id) AS categories,
                COALESCE(s.orders, 0) AS orders, COALESCE(s.revenue, 0) AS revenue,
                (SELECT COUNT(*) FROM products p WHERE p.vendor_id = v.id AND p.market_id = fm.market_id AND p.deleted_at IS NULL) AS products,
                (SELECT AVG(r.rating) FROM reviews r WHERE r.vendor_id = v.id) AS rating
           FROM farmer_markets fm
           JOIN vendors v ON v.id = fm.vendor_id
           JOIN users u   ON u.id = v.user_id
           LEFT JOIN (
                SELECT p.vendor_id, COUNT(DISTINCT o.id) AS orders,
                       SUM(CASE WHEN o.status = 'completed' THEN oi.subtotal ELSE 0 END) AS revenue
                  FROM order_items oi
                  JOIN orders o   ON o.id = oi.order_id
                  JOIN products p ON p.id = oi.product_id
                 WHERE p.market_id = ?$orderWindow
                 GROUP BY p.vendor_id
           ) s ON s.vendor_id = v.id
          WHERE fm.market_id = ?
          ORDER BY (fm.status = 'active') DESC, revenue DESC, orders DESC, v.stall_name",
        $params
    ));
}

/** Everything that blocks a hard delete, as counts. */
function market_dependents(int $marketId): array
{
    return [
        'vendors'  => (int) db_value("SELECT COUNT(*) FROM farmer_markets WHERE market_id = ? AND status = 'active'", [$marketId]),
        'products' => (int) db_value('SELECT COUNT(*) FROM products WHERE market_id = ?', [$marketId]),
        'orders'   => (int) db_value('SELECT COUNT(DISTINCT oi.order_id) FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE p.market_id = ?', [$marketId]),
    ];
}

/** Active markets a vendor may list products in (assignment active and market active). */
function vendor_markets(int $vendorId): array
{
    return db_all(
        "SELECT m.id, m.name, m.city, m.market_type_id, t.name AS type_name
           FROM farmer_markets fm
           JOIN markets m      ON m.id = fm.market_id AND m.status = 'active'
           JOIN market_types t ON t.id = m.market_type_id
          WHERE fm.vendor_id = ? AND fm.status = 'active'
          ORDER BY m.name",
        [$vendorId]
    );
}

/* Customer side: only active markets and approved vendors are shown */
const PUBLIC_VENDOR_SQL = "JOIN vendors v ON v.id = fm.vendor_id AND v.is_active = 1
                           JOIN users u ON u.id = v.user_id AND u.is_active = 1 AND u.approval_status = 'approved'";

/** Active markets for customers / vendors to browse. $filters: q, type, city, id. */
function public_markets(array $filters = []): array
{
    $where = ["m.status = 'active'", 't.is_active = 1'];
    $params = [];
    if (!empty($filters['id'])) {
        $where[] = 'm.id = ?';
        $params[] = (int) $filters['id'];
    }
    if (($q = trim((string) ($filters['q'] ?? ''))) !== '') {
        // match the market itself, or a product sold in it
        $where[] = "(m.name LIKE ? OR m.city LIKE ? OR m.area LIKE ? OR t.name LIKE ?
                     OR EXISTS (SELECT 1 FROM products p JOIN farmer_markets fm ON fm.vendor_id = p.vendor_id AND fm.market_id = m.id AND fm.status = 'active'
                                 WHERE p.market_id = m.id AND p.deleted_at IS NULL AND p.moderation_status = 'active' AND p.name LIKE ?))";
        array_push($params, ...array_fill(0, 5, '%' . $q . '%'));
    }
    if (!empty($filters['type'])) {
        $where[] = 'm.market_type_id = ?';
        $params[] = (int) $filters['type'];
    }
    if (($city = trim((string) ($filters['city'] ?? ''))) !== '') {
        $where[] = 'm.city = ?';
        $params[] = $city;
    }
    $rows = db_all(
        "SELECT m.*, t.name AS type_name, t.icon AS type_icon,
                (SELECT COUNT(*) FROM farmer_markets fm " . PUBLIC_VENDOR_SQL . " WHERE fm.market_id = m.id AND fm.status = 'active') AS vendors,
                (SELECT COUNT(*) FROM products p JOIN farmer_markets fm ON fm.vendor_id = p.vendor_id AND fm.market_id = m.id AND fm.status = 'active' " . PUBLIC_VENDOR_SQL . "
                  WHERE p.market_id = m.id AND p.deleted_at IS NULL AND p.moderation_status = 'active') AS products
           FROM markets m JOIN market_types t ON t.id = m.market_type_id
          WHERE " . implode(' AND ', $where) . '
          ORDER BY t.sort_order, m.name',
        $params
    );
    return array_map('market_to_array', $rows);
}

/** Approved vendors with an active stall in the market. */
function public_market_vendors(int $marketId): array
{
    return db_all(
        "SELECT v.id, v.stall_name, v.description, v.image, v.location_address, v.operating_days, v.pickup_start, v.pickup_end,
                (SELECT COUNT(*) FROM products p WHERE p.vendor_id = v.id AND p.market_id = fm.market_id AND p.deleted_at IS NULL AND p.moderation_status = 'active') AS products,
                (SELECT ROUND(AVG(r.rating), 1) FROM reviews r WHERE r.vendor_id = v.id AND r.status = 'visible') AS rating,
                (SELECT COUNT(*) FROM reviews r WHERE r.vendor_id = v.id AND r.status = 'visible') AS reviews
           FROM farmer_markets fm " . PUBLIC_VENDOR_SQL . "
          WHERE fm.market_id = ? AND fm.status = 'active'
          ORDER BY products DESC, v.stall_name",
        [$marketId]
    );
}

/** Products listed in the market by its public vendors. $filters: q, category, vendor. */
function public_market_products(int $marketId, array $filters = []): array
{
    $where = ['p.market_id = ?', 'p.deleted_at IS NULL', "p.moderation_status = 'active'"];
    $params = [$marketId, $marketId]; // farmer_markets join, then the p.market_id filter
    if (($q = trim((string) ($filters['q'] ?? ''))) !== '') {
        $where[] = '(p.name LIKE ? OR p.description LIKE ? OR c.name LIKE ? OR sc.name LIKE ? OR v.stall_name LIKE ?)';
        array_push($params, ...array_fill(0, 5, '%' . $q . '%'));
    }
    if (!empty($filters['category'])) {
        $where[] = '(p.category_id = ? OR p.subcategory_id = ?)';
        array_push($params, (int) $filters['category'], (int) $filters['category']);
    }
    if (!empty($filters['vendor'])) {
        $where[] = 'v.id = ?';
        $params[] = (int) $filters['vendor'];
    }
    return db_all(
        "SELECT p.*, c.name AS category, sc.name AS subcategory, v.id AS vendor_id, v.stall_name
           FROM products p
           JOIN farmer_markets fm ON fm.vendor_id = p.vendor_id AND fm.market_id = ? AND fm.status = 'active'
           " . PUBLIC_VENDOR_SQL . "
           LEFT JOIN categories c  ON c.id = p.category_id
           LEFT JOIN categories sc ON sc.id = p.subcategory_id
          WHERE " . implode(' AND ', $where) . '
          ORDER BY (p.status = "available" AND p.quantity > 0) DESC, p.name
          LIMIT 300',
        $params
    );
}

/* Vendor side */

/**
 * Add a vendor's stall to markets. Returns the names of the markets joined.
 */
function vendor_join_markets(int $vendorId, array $marketIds): array
{
    $marketIds = array_values(array_unique(array_filter(array_map('intval', $marketIds))));
    if (!$marketIds) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($marketIds), '?'));
    $markets = db_all(
        "SELECT m.id, m.name, m.market_type_id FROM markets m JOIN market_types t ON t.id = m.market_type_id AND t.is_active = 1
          WHERE m.status = 'active' AND m.id IN ($ph)", $marketIds
    );
    foreach ($markets as $m) {
        db_query('INSERT IGNORE INTO farmer_markets (vendor_id, market_id) VALUES (?, ?)', [$vendorId, $m['id']]);
        db_query('INSERT IGNORE INTO farmer_categories (vendor_id, market_type_id) VALUES (?, ?)', [$vendorId, $m['market_type_id']]);
    }
    return array_column($markets, 'name');
}

/** Categories tree: top-level rows with a 'children' list, grouped under market type names. */
function categories_tree(bool $activeOnly = true): array
{
    $rows = db_all(
        'SELECT c.id, c.name, c.parent_id, c.market_type_id, c.is_active, t.name AS type_name
           FROM categories c
           LEFT JOIN market_types t ON t.id = c.market_type_id' .
        ($activeOnly ? ' WHERE c.is_active = 1 AND (t.id IS NULL OR t.is_active = 1)' : '') .
        ' ORDER BY t.sort_order IS NULL, t.sort_order, c.sort_order, c.name'
    );
    $top = [];
    foreach ($rows as $r) {
        if ($r['parent_id'] === null) {
            $top[(int) $r['id']] = $r + ['children' => []];
        }
    }
    foreach ($rows as $r) {
        if ($r['parent_id'] !== null && isset($top[(int) $r['parent_id']])) {
            $top[(int) $r['parent_id']]['children'][] = ['id' => (int) $r['id'], 'name' => $r['name']];
        }
    }
    return array_values($top);
}

/**
 * Save which market categories a vendor belongs to.
 */
function vendor_set_categories(int $vendorId, array $typeIds): void
{
    $typeIds = array_values(array_unique(array_filter(array_map('intval', $typeIds))));
    $valid = $typeIds ? array_map('intval', array_column(db_all(
        'SELECT id FROM market_types WHERE is_active = 1 AND id IN (' . implode(',', array_fill(0, count($typeIds), '?')) . ')', $typeIds), 'id')) : [];
    $keep = array_map('intval', array_column(db_all(
        "SELECT DISTINCT m.market_type_id FROM farmer_markets fm JOIN markets m ON m.id = fm.market_id WHERE fm.vendor_id = ? AND fm.status = 'active'", [$vendorId]), 'market_type_id'));
    $all = array_values(array_unique(array_merge($valid, $keep)));
    db_query('DELETE FROM farmer_categories WHERE vendor_id = ?' . ($all ? ' AND market_type_id NOT IN (' . implode(',', array_fill(0, count($all), '?')) . ')' : ''),
        array_merge([$vendorId], $all));
    foreach ($all as $t) {
        db_query('INSERT IGNORE INTO farmer_categories (vendor_id, market_type_id) VALUES (?, ?)', [$vendorId, $t]);
    }
}
