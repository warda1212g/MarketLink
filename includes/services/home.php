<?php
/** Data for the homepage sections */
declare(strict_types=1);

require_once APP_ROOT . '/includes/services/markets.php';
require_once APP_ROOT . '/includes/services/favorites.php';

/** Joins that keep only products a customer can actually see (approved, active stall; live listing). */
const HOME_PRODUCT_FROM = "FROM products p
       JOIN vendors v ON v.id = p.vendor_id AND v.is_active = 1
       JOIN users u   ON u.id = v.user_id AND u.is_active = 1 AND u.approval_status = 'approved'";
const HOME_PRODUCT_WHERE = "p.deleted_at IS NULL AND p.moderation_status = 'active'";
const HOME_PRODUCT_SQL = HOME_PRODUCT_FROM . ' WHERE ' . HOME_PRODUCT_WHERE;

/** Headline counters (stats band, value strip, hero, CTAs). */
function home_stats(): array
{
    $row = db_one(
        "SELECT
           (SELECT COUNT(*) FROM vendors v JOIN users u ON u.id = v.user_id AND u.is_active = 1 AND u.approval_status = 'approved' WHERE v.is_active = 1) AS farmers,
           (SELECT COUNT(*) FROM markets m JOIN market_types t ON t.id = m.market_type_id AND t.is_active = 1 WHERE m.status = 'active') AS markets,
           (SELECT COUNT(*) " . HOME_PRODUCT_SQL . ") AS products,
           (SELECT COUNT(*) " . HOME_PRODUCT_SQL . " AND p.status = 'available' AND p.quantity > 0) AS in_stock,
           (SELECT COUNT(*) " . HOME_PRODUCT_SQL . " AND p.created_at >= NOW() - INTERVAL 7 DAY) AS new_week,
           (SELECT COUNT(*) FROM orders) AS orders,
           (SELECT COUNT(*) FROM orders WHERE status IN ('completed','picked_up')) AS completed,
           (SELECT COUNT(*) FROM orders WHERE status IN ('pending','accepted','ready')) AS open_orders,
           (SELECT COUNT(*) FROM reviews WHERE status = 'visible') AS reviews,
           (SELECT ROUND(AVG(rating), 1) FROM reviews WHERE status = 'visible') AS avg_rating,
           (SELECT COUNT(DISTINCT customer_id) FROM orders) AS customers,
           (SELECT COUNT(DISTINCT category_id) " . HOME_PRODUCT_SQL . ") AS categories"
    ) ?? [];
    $out = [];
    foreach ($row as $k => $v) {
        $out[$k] = $k === 'avg_rating' ? ($v !== null ? (float) $v : null) : (int) $v;
    }
    return $out;
}

/** 1,234 style number; big values collapse to 1.2k. */
function home_num(int $n): string
{
    return $n >= 10000 ? rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'k' : number_format($n);
}

/** Top categories by live product count, each illustrated by one of its own product photos. */
function home_categories(int $limit = 6): array
{
    return db_all(
        "SELECT c.id, c.name, COUNT(*) AS items,
                SUM(p.status = 'available' AND p.quantity > 0) AS in_stock,
                SUBSTRING_INDEX(GROUP_CONCAT(p.image ORDER BY (p.image IS NULL), p.updated_at DESC SEPARATOR '|'), '|', 1) AS image
           " . HOME_PRODUCT_FROM . "
           JOIN categories c ON c.id = p.category_id AND c.is_active = 1
          WHERE " . HOME_PRODUCT_WHERE . "
          GROUP BY c.id, c.name
          ORDER BY in_stock DESC, items DESC, c.sort_order
          LIMIT " . $limit
    );
}

/** Freshest in-stock products for the "What's Fresh" rail. */
function home_products(int $limit = 10): array
{
    $rows = db_all(
        "SELECT p.id, p.name, p.price, p.unit, p.quantity, p.status, p.image, p.harvest_date, p.created_at,
                v.id AS vendor_id, v.stall_name, m.name AS market_name, c.name AS category,
                (SELECT ROUND(AVG(r.rating), 1) FROM reviews r WHERE r.vendor_id = v.id AND r.status = 'visible') AS rating,
                (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi WHERE oi.product_id = p.id) AS sold
           " . HOME_PRODUCT_FROM . "
           LEFT JOIN markets m ON m.id = p.market_id
           LEFT JOIN categories c ON c.id = p.category_id
          WHERE " . HOME_PRODUCT_WHERE . " AND p.status = 'available' AND p.quantity > 0
          ORDER BY p.image IS NULL, p.updated_at DESC, p.id DESC
          LIMIT " . ($limit * 12)
    );
    // Keep the rail varied: one card per product name, at most two per category.
    $picked = $names = $perCat = [];
    foreach ($rows as $r) {
        $name = mb_strtolower($r['name']);
        $cat = (string) $r['category'];
        if (isset($names[$name]) || ($perCat[$cat] ?? 0) >= 2) {
            continue;
        }
        $names[$name] = true;
        $perCat[$cat] = ($perCat[$cat] ?? 0) + 1;
        $picked[] = $r;
        if (count($picked) >= $limit) {
            break;
        }
    }
    return $picked;
}

/** Badge text for a product card, derived from its real data. */
function home_product_badge(array $p, float $topSold): string
{
    if ($topSold > 0 && (float) $p['sold'] >= $topSold) {
        return 'Best Seller';
    }
    if (strtotime((string) $p['created_at']) >= strtotime('-7 days')) {
        return 'New';
    }
    if ($p['harvest_date'] && strtotime($p['harvest_date']) >= strtotime('-3 days')) {
        return 'Just Harvested';
    }
    if ((float) $p['quantity'] <= 5) {
        return 'Low Stock';
    }
    return 'Fresh';
}

/** Best-rated, busiest farmers with the market they sell at and what they grow. */
function home_farmers(int $limit = 4): array
{
    return db_all(
        "SELECT v.id, v.stall_name, v.description, v.image, v.location_address,
                (SELECT m.name FROM farmer_markets fm JOIN markets m ON m.id = fm.market_id AND m.status = 'active'
                  WHERE fm.vendor_id = v.id AND fm.status = 'active' ORDER BY fm.assigned_at LIMIT 1) AS market_name,
                (SELECT GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR '|') FROM products p JOIN categories c ON c.id = p.category_id
                  WHERE p.vendor_id = v.id AND p.deleted_at IS NULL AND p.moderation_status = 'active') AS tags,
                (SELECT COUNT(*) FROM products p WHERE p.vendor_id = v.id AND p.deleted_at IS NULL AND p.moderation_status = 'active') AS products,
                (SELECT ROUND(AVG(r.rating), 1) FROM reviews r WHERE r.vendor_id = v.id AND r.status = 'visible') AS rating,
                (SELECT COUNT(*) FROM reviews r WHERE r.vendor_id = v.id AND r.status = 'visible') AS reviews
           FROM vendors v
           JOIN users u ON u.id = v.user_id AND u.is_active = 1 AND u.approval_status = 'approved'
          WHERE v.is_active = 1
          ORDER BY (v.image IS NULL), rating IS NULL, rating DESC, reviews DESC, products DESC
          LIMIT " . $limit
    );
}

/** The market with the most active stalls, shown on the discover map card. */
function home_featured_market(): ?array
{
    $markets = public_markets();
    if (!$markets) {
        return null;
    }
    usort($markets, fn($a, $b) => [$b['vendors'], $b['products']] <=> [$a['vendors'], $a['products']]);
    return $markets[0];
}

/**
 * Order shown in the pickup section: the signed-in customer's latest reservation,
 * otherwise the most recent live reservation on the site (no customer details shown).
 */
function home_order(?array $user): ?array
{
    $sql = "SELECT o.id, o.order_number, o.status, o.pickup_date, o.pickup_start, o.pickup_end, o.total_amount, o.created_at, v.stall_name,
                   (SELECT m.name FROM farmer_markets fm JOIN markets m ON m.id = fm.market_id
                     WHERE fm.vendor_id = v.id AND fm.status = 'active' ORDER BY fm.assigned_at LIMIT 1) AS market_name
              FROM orders o JOIN vendors v ON v.id = o.vendor_id";
    $mine = $user && $user['role'] === 'customer';
    $order = $mine
        ? db_one($sql . ' WHERE o.customer_id = ? ORDER BY o.created_at DESC LIMIT 1', [(int) $user['id']])
        : null;
    if (!$order) {
        $mine = false;
        $order = db_one($sql . " WHERE o.status IN ('ready','accepted','pending','completed') ORDER BY FIELD(o.status,'ready','accepted','pending','completed'), o.updated_at DESC LIMIT 1");
    }
    if (!$order) {
        return null;
    }
    $order['mine'] = $mine;
    $order['items'] = db_all('SELECT product_name, quantity, unit FROM order_items WHERE order_id = ? LIMIT 4', [(int) $order['id']]);
    return $order;
}

/** Progress index 0..3 for placed/accepted/ready/completed; -1 when cancelled or declined. */
function home_order_step(string $status): int
{
    return ['pending' => 0, 'accepted' => 1, 'ready' => 2, 'completed' => 3, 'picked_up' => 3][$status] ?? -1;
}

/** Latest visible reviews with the reviewer's first name and last initial only. */
function home_reviews(int $limit = 3): array
{
    return db_all(
        "SELECT r.rating, r.review, r.created_at, u.name AS customer, v.id AS vendor_id, v.stall_name
           FROM reviews r
           JOIN users u ON u.id = r.customer_id
           JOIN vendors v ON v.id = r.vendor_id AND v.is_active = 1
          WHERE r.status = 'visible' AND r.review IS NOT NULL AND r.review <> '' AND r.rating >= 4
          ORDER BY r.created_at DESC
          LIMIT " . $limit
    );
}

function home_short_name(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [$name];
    return count($parts) > 1 ? $parts[0] . ' ' . mb_substr(end($parts), 0, 1) . '.' : $parts[0];
}

/**
 * Favourites strip: the customer's own saved items, or the site's most-saved items for everyone else.
 * Rows: type, id, name, sub, image, link.
 */
function home_favorites(?array $user, int $limit = 3): array
{
    favorites_ensure_table();
    $own = $user && $user['role'] === 'customer';
    $where = $own ? 'WHERE f.customer_id = ' . (int) $user['id'] : '';
    $rows = db_all(
        "SELECT f.item_type, f.item_id, COUNT(*) AS saves, MAX(f.created_at) AS last_saved
           FROM favorites f $where
          GROUP BY f.item_type, f.item_id
          ORDER BY " . ($own ? 'last_saved DESC' : 'saves DESC, last_saved DESC') . '
          LIMIT 12'
    );
    $out = [];
    foreach ($rows as $r) {
        if ($r['item_type'] === 'vendor') {
            $v = db_one('SELECT id, stall_name, image, description FROM vendors WHERE id = ? AND is_active = 1', [(int) $r['item_id']]);
            if ($v) {
                $out[] = ['type' => 'vendor', 'id' => (int) $v['id'], 'name' => $v['stall_name'], 'sub' => $own ? 'Saved farmer' : $r['saves'] . ' saves',
                          'image' => upload_url($v['image']), 'link' => url('market.php?id=' . $v['id'])];
            }
        } else {
            $p = db_one('SELECT p.id, p.name, p.image, p.vendor_id, v.stall_name FROM products p JOIN vendors v ON v.id = p.vendor_id AND v.is_active = 1
                          WHERE p.id = ? AND p.deleted_at IS NULL AND p.moderation_status = "active"', [(int) $r['item_id']]);
            if ($p) {
                $out[] = ['type' => 'product', 'id' => (int) $p['id'], 'name' => $p['name'], 'sub' => $p['stall_name'],
                          'image' => upload_url($p['image']), 'link' => url('market.php?id=' . $p['vendor_id'])];
            }
        }
        if (count($out) >= $limit) {
            break;
        }
    }
    return ['own' => $own, 'items' => $out];
}

/** Assistant demo built from a real in-stock product: how many farmers stock it right now. */
function home_assistant_demo(): ?array
{
    $row = db_one(
        "SELECT p.name, COUNT(DISTINCT p.vendor_id) AS farmers, COUNT(DISTINCT p.market_id) AS markets, MIN(p.price) AS from_price, MIN(p.unit) AS unit
           " . HOME_PRODUCT_SQL . " AND p.status = 'available' AND p.quantity > 0
          GROUP BY p.name
          ORDER BY farmers DESC, markets DESC, RAND()
          LIMIT 1"
    );
    return $row ?: null;
}

/**
 * Stories section: newest stock, newest farmer, busiest market and a recent review.
 */
function home_stories(array $stats): array
{
    $stories = [];

    $newest = db_all("SELECT p.name, p.image, p.created_at " . HOME_PRODUCT_SQL . " AND p.status = 'available' ORDER BY p.created_at DESC LIMIT 3");
    if ($newest) {
        $names = array_column($newest, 'name');
        $stories[] = [
            'tag' => 'Weekly Stock', 'date' => $newest[0]['created_at'], 'image' => upload_url($newest[0]['image']),
            'title' => "What's Fresh This Week?",
            'text' => ($stats['new_week'] ? $stats['new_week'] . ' new listings this week, including ' : 'Latest arrivals include ') . implode(', ', $names) . '.',
            'link' => url('products.php'),
        ];
    }

    $farmer = db_one("SELECT v.id, v.stall_name, v.description, v.image, v.created_at FROM vendors v
                       JOIN users u ON u.id = v.user_id AND u.is_active = 1 AND u.approval_status = 'approved'
                      WHERE v.is_active = 1 ORDER BY v.created_at DESC LIMIT 1");
    if ($farmer) {
        $stories[] = [
            'tag' => 'Meet the Farmer', 'date' => $farmer['created_at'], 'image' => upload_url($farmer['image']),
            'title' => 'Welcome, ' . $farmer['stall_name'],
            'text' => $farmer['description'] ? mb_strimwidth($farmer['description'], 0, 110, '...') : 'The newest stall to join MarketLink - take a look at what they grow.',
            'link' => url('market.php?id=' . $farmer['id']),
        ];
    }

    $market = home_featured_market();
    if ($market) {
        $stories[] = [
            'tag' => 'Market Spotlight', 'date' => $market['created_at'], 'image' => $market['image'],
            'title' => $market['name'],
            'text' => $market['vendors'] . ' stalls and ' . $market['products'] . ' products' . ($market['location'] ? ' in ' . $market['location'] : '') . ($market['days_label'] ? ', open ' . $market['days_label'] : '') . '.',
            'link' => url('market-view.php?id=' . $market['id']),
        ];
    }

    $review = db_one("SELECT r.review, r.rating, r.created_at, v.id AS vendor_id, v.stall_name, v.image FROM reviews r
                       JOIN vendors v ON v.id = r.vendor_id AND v.is_active = 1
                      WHERE r.status = 'visible' AND r.rating = 5 AND r.review IS NOT NULL AND r.review <> ''
                      ORDER BY r.created_at DESC LIMIT 1");
    if ($review) {
        $stories[] = [
            'tag' => 'Customer Love', 'date' => $review['created_at'], 'image' => upload_url($review['image']),
            'title' => 'Five Stars for ' . $review['stall_name'],
            'text' => '"' . mb_strimwidth($review['review'], 0, 100, '...') . '"',
            'link' => url('market.php?id=' . $review['vendor_id']),
        ];
    }
    return $stories;
}

/** Everything api/public/home.php returns for the live refresh. */
function home_live(): array
{
    $stock = [];
    foreach (db_all("SELECT p.id, p.price, p.unit, p.quantity, p.status " . HOME_PRODUCT_SQL) as $p) {
        $stock[(int) $p['id']] = [
            'available' => $p['status'] === 'available' && (float) $p['quantity'] > 0,
            'qty'       => qty($p['quantity']) . ' ' . $p['unit'] . ' left',
            'price'     => money($p['price']),
        ];
    }
    $cats = [];
    foreach (home_categories(50) as $c) {
        $cats[(int) $c['id']] = (int) $c['items'];
    }
    $stats = home_stats();
    $fmt = [];
    foreach ($stats as $k => $v) {
        $fmt[$k] = $v === null ? '-' : ($k === 'avg_rating' ? number_format((float) $v, 1) : home_num((int) $v));
    }
    return ['stats' => $fmt, 'stock' => $stock, 'categories' => $cats, 'at' => date('c')];
}
