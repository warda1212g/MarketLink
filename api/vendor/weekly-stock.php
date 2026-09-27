<?php
/**
 * Recurring weekly stock template.
 * GET                                   -> products, template grouped by day, recent applications
 * POST save_day {day, items:[{product_id, quantity, is_active}]} -> replace one day's template
 * POST clear_day {day}                  -> delete a day's template
 * POST copy_day {from, to}              -> duplicate a day into another
 * POST apply {day}                      -> push that day's quantities into live product stock
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/ai_features.php';

function valid_day($d): int
{
    $d = (int) $d;
    if ($d < 1 || $d > 7) {
        throw new ValidationException(['day' => 'Choose a day.'], 'Choose a valid day.');
    }
    return $d;
}

api_run(function () {
    $vendor = require_vendor_api();
    $vid = (int) $vendor['id'];

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $products = db_all('SELECT id, name, unit, quantity, status, image FROM products WHERE vendor_id = ? AND deleted_at IS NULL ORDER BY name', [$vid]);
        $rows = db_all('SELECT ws.id, ws.product_id, ws.day_of_week, ws.quantity, ws.is_active
                        FROM weekly_stock ws JOIN products p ON p.id = ws.product_id AND p.deleted_at IS NULL
                        WHERE ws.vendor_id = ? ORDER BY p.name', [$vid]);
        $days = array_fill_keys(range(1, 7), []);
        foreach ($rows as $r) {
            $days[(int) $r['day_of_week']][] = [
                'id' => (int) $r['id'], 'product_id' => (int) $r['product_id'],
                'quantity' => (float) $r['quantity'], 'is_active' => (bool) $r['is_active'],
            ];
        }
        $applied = db_all('SELECT day_of_week, items, applied_at FROM stock_applications WHERE vendor_id = ? ORDER BY applied_at DESC LIMIT 5', [$vid]);

        json_ok([
            'products' => array_map(fn($p) => [
                'id' => (int) $p['id'], 'name' => $p['name'], 'unit' => $p['unit'],
                'quantity' => (float) $p['quantity'], 'status' => $p['status'], 'image' => upload_url($p['image']),
            ], $products),
            'days' => $days,
            'operating_days' => days_from_set($vendor['operating_days']),
            'today' => (int) date('N'),
            'suggestions' => array_map(function ($items) {
                return array_map(function ($qty, $pid) {
                    return ['product_id' => (int) $pid, 'quantity' => (float) $qty];
                }, array_values($items), array_keys($items));
            }, vendor_stock_suggestions($vid)),
            'applied' => array_map(fn($a) => [
                'day' => DAYS[(int) $a['day_of_week']], 'items' => (int) $a['items'], 'when' => time_ago($a['applied_at']),
            ], $applied),
        ]);
    }

    require_method('POST');
    $in = request_data();

    switch ($in['action'] ?? '') {
        case 'save_day':
            $day = valid_day($in['day'] ?? 0);
            $items = is_array($in['items'] ?? null) ? $in['items'] : [];
            $owned = array_map('intval', array_column(db_all('SELECT id FROM products WHERE vendor_id = ? AND deleted_at IS NULL', [$vid]), 'id'));
            $clean = [];
            $errors = [];
            foreach ($items as $i => $it) {
                $pid = (int) ($it['product_id'] ?? 0);
                $q = is_numeric($it['quantity'] ?? null) ? round((float) $it['quantity'], 2) : -1;
                if (!in_array($pid, $owned, true)) { $errors["items.$i"] = 'Choose one of your products.'; continue; }
                if ($q < 0 || $q > 1000000) { $errors["items.$i"] = 'Enter a quantity of 0 or more.'; continue; }
                if (isset($clean[$pid])) { $errors["items.$i"] = 'This product is already in the template.'; continue; }
                $clean[$pid] = ['q' => $q, 'active' => !empty($it['is_active']) ? 1 : 0];
            }
            if ($errors) {
                throw new ValidationException($errors, reset($errors));
            }
            db_transaction(function (PDO $pdo) use ($vid, $day, $clean) {
                $pdo->prepare('DELETE FROM weekly_stock WHERE vendor_id = ? AND day_of_week = ?')->execute([$vid, $day]);
                $ins = $pdo->prepare('INSERT INTO weekly_stock (vendor_id, product_id, day_of_week, quantity, is_active) VALUES (?,?,?,?,?)');
                foreach ($clean as $pid => $c) {
                    $ins->execute([$vid, $pid, $day, $c['q'], $c['active']]);
                }
            });
            json_ok(['day' => $day, 'count' => count($clean)], DAYS[$day] . ' template saved.');

        case 'clear_day':
            $day = valid_day($in['day'] ?? 0);
            db_query('DELETE FROM weekly_stock WHERE vendor_id = ? AND day_of_week = ?', [$vid, $day]);
            json_ok(['day' => $day], DAYS[$day] . ' template deleted.');

        case 'copy_day':
            $from = valid_day($in['from'] ?? 0);
            $to = valid_day($in['to'] ?? 0);
            if ($from === $to) {
                throw new ValidationException([], 'Choose a different day to copy to.');
            }
            db_transaction(function (PDO $pdo) use ($vid, $from, $to) {
                $pdo->prepare('DELETE FROM weekly_stock WHERE vendor_id = ? AND day_of_week = ?')->execute([$vid, $to]);
                $pdo->prepare('INSERT INTO weekly_stock (vendor_id, product_id, day_of_week, quantity, is_active)
                               SELECT vendor_id, product_id, ?, quantity, is_active FROM weekly_stock WHERE vendor_id = ? AND day_of_week = ?')
                    ->execute([$to, $vid, $from]);
            });
            json_ok([], DAYS[$from] . ' copied to ' . DAYS[$to] . '.');

        case 'apply':
            $day = valid_day($in['day'] ?? 0);
            $count = (int) db_value('SELECT COUNT(*) FROM weekly_stock ws JOIN products p ON p.id = ws.product_id AND p.deleted_at IS NULL
                                     WHERE ws.vendor_id = ? AND ws.day_of_week = ? AND ws.is_active = 1', [$vid, $day]);
            if (!$count) {
                throw new ValidationException([], 'The ' . DAYS[$day] . ' template has no active products yet.');
            }
            db_transaction(function (PDO $pdo) use ($vid, $day, $count) {
                $pdo->prepare('UPDATE products p JOIN weekly_stock ws ON ws.product_id = p.id
                               SET p.quantity = ws.quantity, p.status = IF(ws.quantity > 0, "available", "sold_out")
                               WHERE ws.vendor_id = ? AND p.vendor_id = ? AND ws.day_of_week = ? AND ws.is_active = 1 AND p.deleted_at IS NULL')
                    ->execute([$vid, $vid, $day]);
                $pdo->prepare('INSERT INTO stock_applications (vendor_id, day_of_week, week_start, items) VALUES (?,?,?,?)')
                    ->execute([$vid, $day, date('Y-m-d', strtotime('monday this week')), $count]);
            });
            json_ok(['items' => $count], DAYS[$day] . ' template applied - ' . $count . ' product' . ($count > 1 ? 's' : '') . ' restocked.');

        default:
            json_error('Unknown action.');
    }
});
