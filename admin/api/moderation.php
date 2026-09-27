<?php
/**
 * Content & Moderation API (admin session; CSRF on POST).
 * GET  ?tab=overview|products|reviews|vendors|reports|log &q= &status= &vendor= &category= &rating= &page=
 * POST action=product {id, status: active|inactive|flagged|removed, reason, report_id?}
 * POST action=review  {id, op: remove|restore, reason, report_id?}
 * POST action=vendor  {id, op: suspend|restore, reason, report_id?}
 * POST action=message {id, op: remove|restore, reason, report_id?}
 * POST action=report  {id, op: dismiss|resolve, reason}
 * Every action writes moderation_logs; nothing is hard-deleted.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/moderation.php';

$admin = require_admin_api();

api_run(function () use ($admin) {
    $adminId = (int) $admin['id'];
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $tab = $_GET['tab'] ?? 'overview';
        $q = str_input($_GET, 'q', 80);
        $like = '%' . $q . '%';
        $perPage = 20;
        $page = max(1, int_input($_GET, 'page', 1));
        $lim = ' LIMIT ' . ($perPage + 1) . ' OFFSET ' . (($page - 1) * $perPage);
        $out = function (array $rows, callable $map) use ($perPage, $page) {
            json_ok(['rows' => array_map($map, array_slice($rows, 0, $perPage)), 'has_more' => count($rows) > $perPage, 'page' => $page]);
        };

        switch ($tab) {
            case 'overview':
                json_ok([
                    'kpis' => [
                        'open_reports'      => (int) db_value("SELECT COUNT(*) FROM reports WHERE status = 'open'"),
                        'products_active'   => (int) db_value("SELECT COUNT(*) FROM products WHERE deleted_at IS NULL AND moderation_status = 'active'"),
                        'products_hidden'   => (int) db_value("SELECT COUNT(*) FROM products WHERE deleted_at IS NULL AND moderation_status <> 'active'"),
                        'reviews_visible'   => (int) db_value("SELECT COUNT(*) FROM reviews WHERE status = 'visible'"),
                        'reviews_removed'   => (int) db_value("SELECT COUNT(*) FROM reviews WHERE status = 'removed'"),
                        'vendors_suspended' => (int) db_value("SELECT COUNT(*) FROM users WHERE role = 'vendor' AND approval_status = 'suspended'"),
                    ],
                    'recent' => array_map('mod_log_row', db_all(mod_log_sql() . ' ORDER BY l.id DESC LIMIT 8')),
                ]);

            case 'products':
                $where = ['p.deleted_at IS NULL'];
                $params = [];
                if ($q !== '') { $where[] = '(p.name LIKE ? OR p.description LIKE ? OR v.stall_name LIKE ?)'; array_push($params, $like, $like, $like); }
                if (in_array($_GET['status'] ?? '', PRODUCT_MODERATION, true)) { $where[] = 'p.moderation_status = ?'; $params[] = $_GET['status']; }
                if ($v = int_input($_GET, 'vendor')) { $where[] = 'p.vendor_id = ?'; $params[] = $v; }
                if ($c = int_input($_GET, 'category')) { $where[] = '(c.market_type_id = ? OR sc.market_type_id = ?)'; array_push($params, $c, $c); }
                $rows = db_all('SELECT p.id, p.name, p.description, p.image, p.price, p.unit, p.moderation_status, p.status, p.created_at, v.id AS vendor_id, v.stall_name,
                                       c.name AS category, t.name AS market,
                                       (SELECT COUNT(*) FROM reports r WHERE r.content_type = "product" AND r.content_id = p.id AND r.status = "open") AS reports
                                  FROM products p JOIN vendors v ON v.id = p.vendor_id
                                  LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN categories sc ON sc.id = p.subcategory_id
                                  LEFT JOIN market_types t ON t.id = c.market_type_id
                                 WHERE ' . implode(' AND ', $where) . ' ORDER BY reports DESC, p.created_at DESC' . $lim, $params);
                $out($rows, fn($r) => [
                    'id' => (int) $r['id'], 'name' => $r['name'], 'description' => mb_strimwidth((string) $r['description'], 0, 160, '...'),
                    'image' => upload_url($r['image']), 'price' => money($r['price']) . ' / ' . $r['unit'], 'status' => $r['moderation_status'],
                    'stock' => $r['status'], 'vendor' => $r['stall_name'], 'vendor_id' => (int) $r['vendor_id'], 'category' => $r['category'], 'market' => $r['market'],
                    'reports' => (int) $r['reports'], 'view' => url('market.php?id=' . $r['vendor_id']), 'date' => fmt_date($r['created_at']),
                ]);

            case 'reviews':
                $where = ['1=1'];
                $params = [];
                if ($q !== '') { $where[] = '(r.review LIKE ? OR u.name LIKE ? OR v.stall_name LIKE ?)'; array_push($params, $like, $like, $like); }
                if (in_array($_GET['status'] ?? '', ['visible', 'removed'], true)) { $where[] = 'r.status = ?'; $params[] = $_GET['status']; }
                if (($rt = int_input($_GET, 'rating')) && $rt >= 1 && $rt <= 5) { $where[] = 'r.rating = ?'; $params[] = $rt; }
                if ($v = int_input($_GET, 'vendor')) { $where[] = 'r.vendor_id = ?'; $params[] = $v; }
                $rows = db_all('SELECT r.*, u.name AS customer, v.stall_name, o.order_number,
                                       (SELECT GROUP_CONCAT(oi.product_name SEPARATOR ", ") FROM order_items oi WHERE oi.order_id = r.order_id) AS products,
                                       (SELECT COUNT(*) FROM reports x WHERE x.content_type = "review" AND x.content_id = r.id AND x.status = "open") AS reports
                                  FROM reviews r JOIN users u ON u.id = r.customer_id JOIN vendors v ON v.id = r.vendor_id LEFT JOIN orders o ON o.id = r.order_id
                                 WHERE ' . implode(' AND ', $where) . ' ORDER BY reports DESC, r.created_at DESC' . $lim, $params);
                $out($rows, fn($r) => [
                    'id' => (int) $r['id'], 'text' => $r['review'], 'rating' => (int) $r['rating'], 'customer' => $r['customer'], 'vendor' => $r['stall_name'],
                    'order' => $r['order_number'], 'products' => $r['products'], 'status' => $r['status'], 'reply' => $r['vendor_reply'],
                    'reports' => (int) $r['reports'], 'date' => fmt_date($r['created_at']), 'view' => url('market.php?id=' . $r['vendor_id']),
                ]);

            case 'vendors':
                $where = ["u.role = 'vendor'"];
                $params = [];
                if ($q !== '') { $where[] = '(v.stall_name LIKE ? OR u.name LIKE ? OR v.description LIKE ?)'; array_push($params, $like, $like, $like); }
                if (in_array($_GET['status'] ?? '', ['pending', 'approved', 'suspended', 'rejected'], true)) { $where[] = 'u.approval_status = ?'; $params[] = $_GET['status']; }
                $rows = db_all('SELECT v.id, v.stall_name, v.description, v.image, u.name, u.email, u.approval_status, u.id AS user_id,
                                       (SELECT GROUP_CONCAT(t.name SEPARATOR ", ") FROM farmer_categories fc JOIN market_types t ON t.id = fc.market_type_id WHERE fc.vendor_id = v.id) AS categories,
                                       (SELECT COUNT(*) FROM products p WHERE p.vendor_id = v.id AND p.deleted_at IS NULL) AS products,
                                       (SELECT COUNT(*) FROM reports r WHERE r.content_type = "vendor" AND r.content_id = v.id AND r.status = "open") AS reports
                                  FROM vendors v JOIN users u ON u.id = v.user_id
                                 WHERE ' . implode(' AND ', $where) . ' ORDER BY reports DESC, v.stall_name' . $lim, $params);
                $out($rows, fn($r) => [
                    'id' => (int) $r['id'], 'name' => $r['stall_name'], 'owner' => $r['name'], 'email' => $r['email'], 'image' => upload_url($r['image']),
                    'description' => mb_strimwidth((string) $r['description'], 0, 160, '...'), 'status' => $r['approval_status'], 'categories' => $r['categories'],
                    'products' => (int) $r['products'], 'reports' => (int) $r['reports'], 'view' => url('market.php?id=' . $r['id']),
                    'manage' => 'farmers.php?id=' . $r['user_id'],
                ]);

            case 'reports':
                $where = ['1=1'];
                $params = [];
                $st = $_GET['status'] ?? 'open';
                if (in_array($st, ['open', 'resolved', 'dismissed'], true)) { $where[] = 'r.status = ?'; $params[] = $st; }
                if (in_array($_GET['type'] ?? '', ['product', 'review', 'vendor', 'message'], true)) { $where[] = 'r.content_type = ?'; $params[] = $_GET['type']; }
                if ($id = int_input($_GET, 'report')) { $where = ['r.id = ?']; $params = [$id]; }
                $rows = db_all('SELECT r.*, u.name AS reporter FROM reports r JOIN users u ON u.id = r.reporter_id
                                 WHERE ' . implode(' AND ', $where) . ' ORDER BY r.id DESC' . $lim, $params);
                $out($rows, 'report_row');

            case 'log':
                $rows = db_all(mod_log_sql() . ($q !== '' ? ' WHERE l.reason LIKE ? OR l.action LIKE ? OR l.content_type LIKE ?' : '') . ' ORDER BY l.id DESC' . $lim,
                    $q !== '' ? [$like, $like, $like] : []);
                $out($rows, 'mod_log_row');

            default:
                json_error('Unknown tab.');
        }
    }

    require_method('POST');
    $in = request_data();
    $id = int_input($in, 'id', 0);
    $reason = str_input($in, 'reason', 500);
    $reportId = int_input($in, 'report_id');
    $op = str_input($in, 'op', 20);
    switch ($in['action'] ?? '') {
        case 'product': $msg = moderate_product($adminId, $id, str_input($in, 'status', 20), $reason, $reportId); break;
        case 'review':  $msg = moderate_review($adminId, $id, $op, $reason, $reportId); break;
        case 'vendor':  $msg = moderate_vendor($adminId, $id, $op, $reason, $reportId); break;
        case 'message': $msg = moderate_message($adminId, $id, $op, $reason, $reportId); break;
        case 'report':  $msg = resolve_report($adminId, $id, $op, $reason); break;
        default: json_error('Unknown action.');
    }
    json_ok([], $msg);
});

function mod_log_sql(): string
{
    return 'SELECT l.*, u.name AS admin FROM moderation_logs l LEFT JOIN users u ON u.id = l.admin_id';
}

function mod_log_row(array $l): array
{
    return ['id' => (int) $l['id'], 'admin' => $l['admin'] ?? 'System', 'type' => $l['content_type'], 'content_id' => (int) $l['content_id'],
            'action' => $l['action'], 'reason' => $l['reason'], 'date' => fmt_date($l['created_at'], 'M j, Y, g:i A')];
}

/** Report with a preview of the reported content so the admin can decide without leaving the page. */
function report_row(array $r): array
{
    $id = (int) $r['content_id'];
    $preview = '';
    $view = null;
    $state = '';
    switch ($r['content_type']) {
        case 'product':
            $p = db_one('SELECT p.name, p.description, p.vendor_id, p.moderation_status, v.stall_name FROM products p JOIN vendors v ON v.id = p.vendor_id WHERE p.id = ?', [$id]);
            if ($p) { $preview = $p['name'] . ' - ' . $p['stall_name'] . ($p['description'] ? ': ' . mb_strimwidth($p['description'], 0, 140, '...') : ''); $view = url('market.php?id=' . $p['vendor_id']); $state = $p['moderation_status']; }
            break;
        case 'review':
            $x = db_one('SELECT r.rating, r.review, r.status, r.vendor_id, u.name FROM reviews r JOIN users u ON u.id = r.customer_id WHERE r.id = ?', [$id]);
            if ($x) { $preview = $x['rating'] . '/5 by ' . $x['name'] . ': ' . mb_strimwidth((string) $x['review'], 0, 160, '...'); $view = url('market.php?id=' . $x['vendor_id']); $state = $x['status']; }
            break;
        case 'vendor':
            $x = db_one('SELECT v.stall_name, v.description, u.approval_status FROM vendors v JOIN users u ON u.id = v.user_id WHERE v.id = ?', [$id]);
            if ($x) { $preview = $x['stall_name'] . ($x['description'] ? ': ' . mb_strimwidth($x['description'], 0, 140, '...') : ''); $view = url('market.php?id=' . $id); $state = $x['approval_status']; }
            break;
        case 'message':
            $x = db_one('SELECT m.body, m.is_removed, u.name FROM messages m JOIN users u ON u.id = m.sender_id WHERE m.id = ?', [$id]);
            if ($x) { $preview = $x['name'] . ': "' . mb_strimwidth($x['body'], 0, 200, '...') . '"'; $state = $x['is_removed'] ? 'removed' : 'visible'; }
            break;
    }
    return [
        'id' => (int) $r['id'], 'type' => $r['content_type'], 'content_id' => $id, 'reason' => REPORT_REASONS[$r['reason']] ?? $r['reason'],
        'details' => $r['details'], 'reporter' => $r['reporter'] . ' (' . $r['reporter_role'] . ')', 'status' => $r['status'],
        'action_taken' => $r['action_taken'], 'preview' => $preview !== '' ? $preview : 'Content no longer exists.', 'content_state' => $state,
        'view' => $view, 'date' => fmt_date($r['created_at'], 'M j, Y, g:i A'),
    ];
}
