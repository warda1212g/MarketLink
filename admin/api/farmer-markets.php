<?php
/**
 * A farmer's market assignments and farmer categories, managed from Manage Users.
 * GET  ?vendor_id=                                   -> all markets / types with the farmer's selections
 * POST {vendor_id, market_ids[], type_ids[]}         -> replace both sets (X-CSRF-Token required)
 * Existing assignments keep their status (a market-level suspension is not undone by saving here).
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/markets.php';

$admin = require_admin_api();

api_run(function () use ($admin) {
    $in = $_SERVER['REQUEST_METHOD'] === 'GET' ? $_GET : request_data();
    $vendor = db_one('SELECT v.id, v.stall_name, u.name, u.approval_status FROM vendors v JOIN users u ON u.id = v.user_id WHERE v.id = ?',
        [int_input($in, 'vendor_id', 0)]);
    if (!$vendor) {
        json_error('Vendor not found.', 404);
    }
    $vid = (int) $vendor['id'];

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        json_ok([
            'vendor'  => ['id' => $vid, 'farm' => $vendor['stall_name'], 'name' => $vendor['name'], 'approval' => $vendor['approval_status']],
            'markets' => array_map(fn($m) => [
                'id' => (int) $m['id'], 'name' => $m['name'], 'city' => $m['city'], 'type' => $m['type_name'], 'status' => $m['status'],
                'assigned' => $m['assignment'] !== null, 'assignment' => $m['assignment'],
            ], db_all(
                'SELECT m.id, m.name, m.city, m.status, t.name AS type_name, fm.status AS assignment
                   FROM markets m JOIN market_types t ON t.id = m.market_type_id
                   LEFT JOIN farmer_markets fm ON fm.market_id = m.id AND fm.vendor_id = ?
                  ORDER BY t.sort_order, m.name', [$vid]
            )),
            'types' => array_map(fn($t) => [
                'id' => (int) $t['id'], 'label' => $t['farmer_label'], 'icon' => $t['icon'], 'selected' => (bool) $t['selected'],
            ], db_all(
                'SELECT t.id, t.farmer_label, t.icon,
                        EXISTS(SELECT 1 FROM farmer_categories fc WHERE fc.vendor_id = ? AND fc.market_type_id = t.id) AS selected
                   FROM market_types t ORDER BY t.sort_order, t.name', [$vid]
            )),
        ]);
    }

    require_method('POST');
    $marketIds = array_values(array_unique(array_filter(array_map('intval', (array) ($in['market_ids'] ?? [])))));
    $typeIds = array_values(array_unique(array_filter(array_map('intval', (array) ($in['type_ids'] ?? [])))));
    if ($marketIds && $vendor['approval_status'] !== 'approved') {
        throw new ValidationException(['market_ids' => 'Approve this vendor before assigning markets.'], 'Approve this vendor before assigning markets.');
    }
    if ($marketIds) {
        $ph = implode(',', array_fill(0, count($marketIds), '?'));
        $found = db_all("SELECT id, market_type_id FROM markets WHERE id IN ($ph)", $marketIds);
        if (count($found) !== count($marketIds)) {
            throw new ValidationException(['market_ids' => 'One of the selected markets no longer exists.']);
        }
        // a farmer selling in a market is, by definition, that market type's kind of farmer
        $typeIds = array_values(array_unique(array_merge($typeIds, array_map(fn($m) => (int) $m['market_type_id'], $found))));
    }

    db_transaction(function (PDO $pdo) use ($vid, $marketIds, $typeIds, $admin) {
        $keep = $marketIds ? ' AND market_id NOT IN (' . implode(',', array_fill(0, count($marketIds), '?')) . ')' : '';
        $pdo->prepare("DELETE FROM farmer_markets WHERE vendor_id = ?$keep")->execute(array_merge([$vid], $marketIds));
        $ins = $pdo->prepare('INSERT IGNORE INTO farmer_markets (vendor_id, market_id, assigned_by) VALUES (?, ?, ?)');
        foreach ($marketIds as $m) {
            $ins->execute([$vid, $m, $admin['id']]);
        }
        $keep = $typeIds ? ' AND market_type_id NOT IN (' . implode(',', array_fill(0, count($typeIds), '?')) . ')' : '';
        $pdo->prepare("DELETE FROM farmer_categories WHERE vendor_id = ?$keep")->execute(array_merge([$vid], $typeIds));
        $ins = $pdo->prepare('INSERT IGNORE INTO farmer_categories (vendor_id, market_type_id) VALUES (?, ?)');
        foreach ($typeIds as $t) {
            $ins->execute([$vid, $t]);
        }
    });
    log_activity('farmer_markets_updated', 'Markets updated for ' . $vendor['stall_name'], 'farmer', $vid, (int) $admin['id']);
    json_ok([], 'Markets and categories saved for ' . $vendor['stall_name'] . '.');
});
