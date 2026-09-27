<?php
/**
 * Vendor joins / leaves markets (registers their stall in a market).
 * POST action=join  {market_id}
 * POST action=leave {market_id}   -> products listed there stay in the vendor's catalogue
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/markets.php';

api_run(function () {
    $vendor = require_vendor_api(true);
    require_method('POST');
    $vid = (int) $vendor['id'];
    $in = request_data();
    $marketId = int_input($in, 'market_id', 0);
    $market = db_one('SELECT id, name, status FROM markets WHERE id = ?', [$marketId]);
    if (!$market) {
        json_error('Market not found.', 404);
    }
    $current = db_value('SELECT status FROM farmer_markets WHERE vendor_id = ? AND market_id = ?', [$vid, $marketId]);

    switch ($in['action'] ?? '') {
        case 'join':
            if ($current === 'suspended') {
                json_error('An admin has suspended your stall in this market. Contact MarketLink support.', 403);
            }
            if ($current) {
                json_ok([], 'Your stall is already in ' . $market['name'] . '.');
            }
            if (!vendor_join_markets($vid, [$marketId])) {
                json_error('This market is not open for new stalls right now.', 409);
            }
            log_activity('market_vendor_joined', "{$vendor['stall_name']} joined {$market['name']}", 'market', $marketId);
            json_ok(['redirect' => url('vendor/profile.php?joined=' . $marketId) . '#my-stalls'],
                'Your stall is now registered in ' . $market['name'] . '.');

        case 'leave':
            if ($current === 'suspended') {
                json_error('Your stall is suspended here; only an admin can change it.', 403);
            }
            if (!$current) {
                json_error('Your stall is not in this market.', 404);
            }
            db_query('DELETE FROM farmer_markets WHERE vendor_id = ? AND market_id = ?', [$vid, $marketId]);
            log_activity('market_vendor_left', "{$vendor['stall_name']} left {$market['name']}", 'market', $marketId);
            json_ok([], 'You left ' . $market['name'] . '. Your products there are hidden until you rejoin.');

        default:
            json_error('Unknown action.');
    }
});
