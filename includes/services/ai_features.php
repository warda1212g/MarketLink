<?php
/**
 * Smart features worked out from our own orders and stock data:
 * freshness score, discounts, market gaps, weekly stock suggestions,
 * stall reliability, low stock alerts, market health and a few admin helpers.
 */
declare(strict_types=1);


/* Openrouter ai decision layer */
/**
 * Asks OpenRouter first and uses the local calculation if that fails.
 */
require_once APP_ROOT . '/includes/services/openrouter.php';

function marketlink_ai_json(string $feature, array $context, string $schema, string $cacheKey, int $maxTokens = 500): ?array
{
    if (!function_exists('openrouter_cached') || !openrouter_enabled()) {
        return null;
    }
    $prompt = "You are the MarketLink AI decision engine. Feature: {$feature}.\n"
        . "Use ONLY the supplied platform data. Do not invent facts, IDs, prices, quantities, people, locations, or dates.\n"
        . "Return ONLY valid JSON matching this schema: {$schema}\n\n"
        . "PLATFORM DATA:\n" . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $raw = openrouter_cached('feature-' . $feature . '-' . $cacheKey, $prompt,
        'You are a strict structured-data assistant for a marketplace. Output JSON only. Keep recommendations practical and grounded in the provided data.',
        $maxTokens);
    if (!is_string($raw) || trim($raw) === '') return null;
    $raw = trim($raw);
    $raw = preg_replace('/^```(?:json)?\\s*|\\s*```$/i', '', $raw);
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) return $decoded;
    // Recover a JSON object/array if a model added a short sentence around it.
    $startObj = strpos($raw, '{'); $endObj = strrpos($raw, '}');
    $startArr = strpos($raw, '['); $endArr = strrpos($raw, ']');
    if ($startObj !== false && $endObj !== false && $endObj > $startObj) {
        $decoded = json_decode(substr($raw, $startObj, $endObj - $startObj + 1), true);
    } elseif ($startArr !== false && $endArr !== false && $endArr > $startArr) {
        $decoded = json_decode(substr($raw, $startArr, $endArr - $startArr + 1), true);
    }
    return is_array($decoded) ? $decoded : null;
}

function ai_source_label(): string { return openrouter_enabled() ? 'openrouter' : 'local-fallback'; }

/* harvest freshness score */

/**
 * Freshness score 15..100 from the harvest date.
 * Falls back to ~100 - 1.05 per hour; "94/100 - harvested 8 hours ago".
 * Returns null when no harvest date is set or produce is older than 72h.
 */
function freshness_info(?string $harvestDate, ?DateTimeImmutable $now = null): ?array
{
    if (!$harvestDate || !valid_date($harvestDate)) {
        return null;
    }
    $now = $now ?? new DateTimeImmutable('now');
    $harvest = new DateTimeImmutable($harvestDate);
    $midnight = $harvest->setTime(0, 0);
    // Harvest date has no time, so hours since "harvest morning" (6am) at the earliest.
    $hours = max(0.0, ($now->getTimestamp() - max($midnight->getTimestamp(), $midnight->modify('+6 hours')->getTimestamp())) / 3600);
    if ($hours > 72) {
        return null;
    }
    $score = (int) max(15, round(100 - $hours * 1.05));
    $label = $hours < 6 ? 'Just harvested' : ($hours < 24 ? 'Fresh' : ($hours < 48 ? 'Good' : 'Best used soon'));
    $age = $hours < 24 ? round($hours) . ' h ago' : round($hours / 24) . ' day' . ($hours >= 48 ? 's' : '') . ' ago';
    $ai = marketlink_ai_json('harvest_freshness_score', [
        'harvest_date' => $harvestDate, 'hours_since_harvest' => round($hours, 1),
        'local_score' => $score, 'local_label' => $label, 'local_age' => $age
    ], '{"score": number 15-100, "label": string, "age": string}',
        sha1($harvestDate . '|' . date('Y-m-d-H')), 180);
    if (is_array($ai) && isset($ai['score'])) {
        $score = max(15, min(100, (int) $ai['score']));
        $label = trim((string)($ai['label'] ?? $label));
        $age = trim((string)($ai['age'] ?? $age));
    }
    return ['score' => $score, 'label' => $label, 'age' => $age, 'hours' => $hours, 'ai_source' => ai_source_label()];
}

/* pre-order social proof */

/**
 * Orders placed in the last 7 days per product for one vendor.
 * @return array<int,int> product_id => pre-order count
 */
function product_preorder_counts(int $vendorId): array
{
    $rows = db_all(
        "SELECT i.product_id, COUNT(*) AS n
           FROM order_items i
           JOIN orders o ON o.id = i.order_id
          WHERE o.vendor_id = ? AND o.status NOT IN ('cancelled','declined')
            AND o.created_at >= (CURDATE() - INTERVAL 6 DAY)
          GROUP BY i.product_id",
        [$vendorId]
    );
    $out = [];
    foreach ($rows as $r) {
        $out[(int) $r['product_id']] = (int) $r['n'];
    }
    $ai = marketlink_ai_json('preorder_social_proof', ['product_counts' => $out],
        '{"featured_product_ids": array of product IDs with strong social proof, "headline": string}',
        sha1(json_encode($out)), 220);
    if (is_array($ai) && isset($ai['featured_product_ids']) && is_array($ai['featured_product_ids'])) {
        $featured = array_flip(array_map('intval', $ai['featured_product_ids']));
        foreach ($out as $pid => $count) {
            if ($count >= 2 && isset($featured[$pid])) $out[$pid] = $count;
        }
    }
    return $out;
}

/* pre-visit confidence */

/**
 * How reliable a stall is, based on the last 8 weeks of orders.
 */
function vendor_confidence(array $vendor): array
{
    $row = db_one(
        "SELECT COUNT(DISTINCT YEARWEEK(o.pickup_date, 3)) AS active_weeks,
                SUM(o.status NOT IN ('cancelled','declined')) AS live_orders,
                SUM(o.status = 'completed') AS completed,
                SUM(o.status IN ('cancelled','declined')) AS cancelled,
                COUNT(*) AS total
           FROM orders o
          WHERE o.vendor_id = ? AND o.pickup_date >= (CURDATE() - INTERVAL 63 DAY)
            AND o.pickup_date IS NOT NULL",
        [(int) $vendor['id']]
    );
    $total = (int) ($row['total'] ?? 0);
    if (!$total) {
        return ['pct' => null, 'weeks' => 0, 'note' => 'New stall - not enough history yet.'];
    }
    $live = (int) $row['live_orders'];
    $completed = (int) $row['completed'];
    $cancelled = (int) $row['cancelled'];
    $weeks = max(1, (int) $row['active_weeks']);
    $attendance = min(100, round($weeks / 8 * 100));
    $settled = $completed + $cancelled;
    $fulfilment = $settled > 0 ? round($completed / $settled * 100) : 70;
    $pct = (int) round($attendance * 0.7 + $fulfilment * 0.3);
    $note = $weeks >= 6
        ? 'based on ' . $weeks . ' active weeks and ' . $live . ' recent pre-orders'
        : 'early pattern from ' . $weeks . ' week' . ($weeks > 1 ? 's' : '') . ' on MarketLink';
    $ai = marketlink_ai_json('pre_visit_confidence', [
        'active_weeks_last_8' => $weeks, 'recent_live_orders' => $live,
        'completed_orders' => $completed, 'cancelled_or_declined' => $cancelled,
        'local_confidence' => $pct
    ], '{"pct": integer 0-100, "note": string}',
        sha1(json_encode([$vendor['id'], $weeks, $live, $completed, $cancelled])), 220);
    if (is_array($ai) && isset($ai['pct'])) {
        $pct = max(0, min(100, (int)$ai['pct']));
        $note = trim((string)($ai['note'] ?? $note));
    }
    return ['pct' => $pct, 'weeks' => $weeks, 'note' => $note, 'ai_source' => ai_source_label()];
}

/* last chance stock alert */

/**
 * Products running low that THIS customer usually buys:
 * previously ordered or favourited, still in stock, quantity at or below 3 units.
 */
function customer_last_chance(int $customerId, int $limit = 6): array
{
    $rows = db_all(
        "SELECT p.id, p.name, p.image, p.price, p.unit, p.quantity, p.harvest_date,
                v.id AS vendor_id, v.stall_name,
                COALESCE(prev.n, 0) AS times_bought
           FROM products p
           JOIN vendors v ON v.id = p.vendor_id AND v.is_active = 1
           JOIN users vu ON vu.id = v.user_id AND vu.is_active = 1 AND vu.approval_status = 'approved'
           JOIN (
                SELECT i.product_id, COUNT(DISTINCT o.id) AS n
                  FROM order_items i
                  JOIN orders o ON o.id = i.order_id
                 WHERE o.customer_id = ? AND o.status NOT IN ('cancelled','declined')
                 GROUP BY i.product_id
                UNION
                SELECT f.item_id, 1
                  FROM favorites f
                 WHERE f.customer_id = ? AND f.item_type = 'product'
           ) prev ON prev.product_id = p.id
          WHERE p.deleted_at IS NULL AND p.status = 'available'
            AND p.quantity > 0 AND p.quantity <= 3
          ORDER BY times_bought DESC, p.quantity ASC
          LIMIT " . (int) $limit,
        [$customerId, $customerId]
    );
    foreach ($rows as &$r) {
        $r['freshness'] = freshness_info($r['harvest_date'] ?? null);
    }
    $ai = marketlink_ai_json('last_chance_stock_alert', ['products' => array_map(fn($r) => [
        'id'=>(int)$r['id'], 'name'=>$r['name'], 'quantity'=>(float)$r['quantity'], 'times_bought'=>(int)$r['times_bought']
    ], $rows)], '{"alerts": array of objects {"product_id": integer, "reason": string}}',
        sha1(json_encode($rows)), 360);
    $reasons = [];
    foreach (($ai['alerts'] ?? []) as $a) if (isset($a['product_id'])) $reasons[(int)$a['product_id']] = trim((string)($a['reason'] ?? ''));
    foreach ($rows as &$r) $r['ai_reason'] = $reasons[(int)$r['id']] ?? ('Only ' . $r['quantity'] . ' left - this is an item you have bought or saved before.');
    return $rows;
}

/* perishability discount engine */

/**
 * Suggest a 10/20/30% discount for products that probably won't sell out in time.
 */
function vendor_discount_suggestions(array $vendor, int $limit = 4): array
{
    $vid = (int) $vendor['id'];
    $rows = db_all(
        "SELECT p.id, p.name, p.image, p.unit, p.price, p.quantity, p.harvest_date,
                COALESCE(s.sold_4w, 0) AS sold_4w
           FROM products p
           LEFT JOIN (
                SELECT i.product_id, SUM(i.quantity) AS sold_4w
                  FROM order_items i
                  JOIN orders o ON o.id = i.order_id
                 WHERE o.vendor_id = ? AND o.status = 'completed'
                   AND o.created_at >= (CURDATE() - INTERVAL 28 DAY)
                 GROUP BY i.product_id
           ) s ON s.product_id = p.id
          WHERE p.vendor_id = ? AND p.deleted_at IS NULL AND p.status = 'available' AND p.quantity > 0",
        [$vid, $vid]
    );
    $out = [];
    foreach ($rows as $p) {
        $perWeek = (float) $p['sold_4w'] / 4.0;
        if ($perWeek < 0.1) {
            continue; // never/rarely sold: no baseline to call it "overstock"
        }
        $ratio = (float) $p['quantity'] / $perWeek;
        if ($ratio < 1.6) {
            continue; // will sell out fine
        }
        $pct = $ratio < 2.5 ? 10 : ($ratio < 4 ? 20 : 30);
        $out[] = [
            'product_id' => (int) $p['id'],
            'name'       => $p['name'],
            'unit'       => $p['unit'],
            'price'      => (float) $p['price'],
            'new_price'  => round((float) $p['price'] * (1 - $pct / 100), 2),
            'discount'   => $pct,
            'quantity'   => (float) $p['quantity'],
            'per_week'   => round($perWeek, 2),
            'weeks_worth'=> round($ratio, 1),
        ];
    }
    usort($out, fn($a, $b) => $b['weeks_worth'] <=> $a['weeks_worth']);
    $out = array_slice($out, 0, $limit);
    $ai = marketlink_ai_json('perishability_discount_engine', ['candidates' => $out],
        '{"recommendations": array of objects {"product_id": integer, "discount": integer 10|20|30, "reason": string}}',
        sha1(json_encode($out)), 420);
    $rec = [];
    foreach (($ai['recommendations'] ?? []) as $a) if (isset($a['product_id'])) $rec[(int)$a['product_id']] = $a;
    foreach ($out as &$d) if (isset($rec[(int)$d['product_id']])) {
        $pct = (int)$rec[(int)$d['product_id']]['discount'];
        if (in_array($pct, [10,20,30], true)) { $d['discount']=$pct; $d['new_price']=round($d['price']*(1-$pct/100),2); }
        $d['ai_reason'] = trim((string)($rec[(int)$d['product_id']]['reason'] ?? ''));
        $d['ai_source'] = ai_source_label();
    }
    unset($d);
    return $out;
}

/* market gap opportunity detector */

/**
 * Categories this vendor's customers buy from other stalls.
 */
function vendor_category_gaps(int $vendorId, int $limit = 3): array
{
    $out = db_all(
        "SELECT c.id, c.name, COUNT(DISTINCT o.customer_id) AS customers
           FROM order_items i
           JOIN orders o ON o.id = i.order_id
           JOIN products p ON p.id = i.product_id AND p.deleted_at IS NULL
           JOIN categories c ON c.id = p.category_id
          WHERE o.status NOT IN ('cancelled','declined')
            AND p.vendor_id <> ?
            AND o.customer_id IN (SELECT DISTINCT customer_id FROM orders WHERE vendor_id = ?)
            AND c.id NOT IN (SELECT COALESCE(category_id, 0) FROM products WHERE vendor_id = ? AND deleted_at IS NULL)
          GROUP BY c.id, c.name
          ORDER BY customers DESC
          LIMIT " . (int) $limit,
        [$vendorId, $vendorId, $vendorId]
    );
    $ai = marketlink_ai_json('market_gap_opportunity_detector', ['gaps'=>$out],
        '{"opportunities": array of objects {"category_id": integer, "priority": integer 1-100, "reason": string}}',
        sha1(json_encode($out)), 360);
    $byId=[]; foreach (($ai['opportunities'] ?? []) as $a) if(isset($a['category_id'])) $byId[(int)$a['category_id']]=$a;
    foreach($out as &$g){ if(isset($byId[(int)$g['id']])){ $g['ai_priority']=max(1,min(100,(int)($byId[(int)$g['id']]['priority']??50))); $g['ai_reason']=trim((string)($byId[(int)$g['id']]['reason']??'')); } $g['ai_source']=ai_source_label(); } unset($g);
    return $out;
}

/* smart weekly stock template */

/**
 * Suggested stock per product per day from the last 6 weeks of sales.
 * Returns day => [product_id => qty]
 */
function vendor_stock_suggestions(int $vendorId): array
{
    $sales = db_all(
        "SELECT WEEKDAY(o.pickup_date) + 1 AS dow, i.product_id, SUM(i.quantity) AS sold
           FROM order_items i
           JOIN orders o ON o.id = i.order_id
          WHERE o.vendor_id = ? AND o.status = 'completed'
            AND o.pickup_date >= (CURDATE() - INTERVAL 42 DAY) AND o.pickup_date IS NOT NULL
          GROUP BY dow, i.product_id",
        [$vendorId]
    );
    $counts = [];
    foreach ($sales as $s) {
        $counts[(int) $s['dow']][(int) $s['product_id']] = (float) $s['sold'];
    }
    $products = db_all(
        'SELECT ws.product_id, ws.day_of_week, ws.quantity
           FROM weekly_stock ws
           JOIN products p ON p.id = ws.product_id AND p.deleted_at IS NULL
          WHERE ws.vendor_id = ?',
        [$vendorId]
    );
    $template = [];
    foreach ($products as $p) {
        $template[(int) $p['day_of_week']][(int) $p['product_id']] = (float) $p['quantity'];
    }
    $suggestions = [];
    for ($day = 1; $day <= 7; $day++) {
        foreach ($template[$day] ?? [] as $pid => $qty) {
            $sold = $counts[$day][$pid] ?? 0;
            // 15% headroom over the weekly average; never below the saved template.
            $suggestions[$day][$pid] = max($qty, round($sold / 6 * 1.15, 1));
        }
        foreach ($counts[$day] ?? [] as $pid => $sold) {
            if (!isset($suggestions[$day][$pid])) {
                $suggestions[$day][$pid] = max(1, round($sold / 6 * 1.15, 1));
            }
        }
    }
    $ai = marketlink_ai_json('smart_weekly_stock_template', ['local_suggestions'=>$suggestions],
        '{"suggestions": object mapping day 1-7 to object mapping product_id to non-negative number}',
        sha1(json_encode($suggestions)), 600);
    if (is_array($ai['suggestions'] ?? null)) {
        foreach ($ai['suggestions'] as $day=>$items) if ((int)$day>=1 && (int)$day<=7 && is_array($items)) {
            foreach ($items as $pid=>$qty) if (is_numeric($qty)) $suggestions[(int)$day][(int)$pid]=max(0,round((float)$qty,1));
        }
    }
    return $suggestions;
}

/* market health score */

/**
 * Weekly health score per stall for the admin:
 * attendance (30), fulfilment (30), retention (20), revenue growth (20).
 */
function admin_vendor_health(): array
{
    $vendors = db_all(
        "SELECT v.id, v.stall_name, v.is_active,
                u.name AS farmer, u.email,
                (SELECT COUNT(*) FROM products p WHERE p.vendor_id = v.id AND p.deleted_at IS NULL) AS products
           FROM vendors v
           JOIN users u ON u.id = v.user_id AND u.approval_status = 'approved'
          ORDER BY v.stall_name"
    );
    $out = [];
    foreach ($vendors as $v) {
        $vid = (int) $v['id'];
        $stats = db_one(
            "SELECT COUNT(DISTINCT YEARWEEK(o.pickup_date, 3)) AS active_weeks,
                    SUM(o.status = 'completed') AS completed,
                    SUM(o.status IN ('cancelled','declined')) AS cancelled,
                    COUNT(DISTINCT o.customer_id) AS customers,
                    COUNT(DISTINCT CASE WHEN o.status = 'completed' THEN o.customer_id END) AS served,
                    COALESCE(SUM(CASE WHEN o.status = 'completed' THEN o.total_amount END), 0) AS revenue_all_time
               FROM orders o
              WHERE o.vendor_id = ? AND o.pickup_date IS NOT NULL",
            [$vid]
        );
        $rev = db_one(
            "SELECT COALESCE(SUM(CASE WHEN o.created_at >= (CURDATE() - INTERVAL 28 DAY) THEN o.total_amount END), 0) AS last28,
                    COALESCE(SUM(CASE WHEN o.created_at >= (CURDATE() - INTERVAL 56 DAY) AND o.created_at < (CURDATE() - INTERVAL 28 DAY) THEN o.total_amount END), 0) AS prev28
               FROM orders o
              WHERE o.vendor_id = ? AND o.status = 'completed'",
            [$vid]
        );
        // Customers with 2+ completed orders from this stall.
        $repeatCustomers = (int) db_value(
            "SELECT COUNT(*) FROM (
                SELECT customer_id, COUNT(*) AS n
                  FROM orders
                 WHERE vendor_id = ? AND status = 'completed'
                 GROUP BY customer_id
                HAVING n > 1
             ) t",
            [$vid]
        );

        $completed = (int) ($stats['completed'] ?? 0);
        $cancelled = (int) ($stats['cancelled'] ?? 0);
        $settled = $completed + $cancelled;
        $activeWeeks = (int) ($stats['active_weeks'] ?? 0);
        $served = (int) ($stats['served'] ?? 0);

        $attendance = $activeWeeks >= 8 ? 30 : round($activeWeeks / 8 * 30);
        $fulfilment = $settled > 0 ? round($completed / $settled * 30) : 0;
        $retention = $served > 0 ? round(min(1, $repeatCustomers / $served) * 20) : 0;
        $last28 = (float) $rev['last28'];
        $prev28 = (float) $rev['prev28'];
        $growth = $last28 > 0
            ? ($prev28 > 0 ? round(min(1, max(-1, ($last28 - $prev28) / $prev28)) * 10 + 10) : 20)
            : 0;

        $score = (int) min(100, $attendance + $fulfilment + $retention + $growth);
        $label = $score >= 75 ? 'Thriving' : ($score >= 55 ? 'Healthy' : ($score >= 40 ? 'Needs attention' : 'At risk'));

        $actions = [];
        if ($fulfilment < 15 && $settled > 0) $actions[] = 'High cancellations - review stock accuracy before market day.';
        if ($activeWeeks < 4 && $completed < 3) $actions[] = 'Low attendance - confirm operating days and cut-off times.';
        if ($retention < 8 && $served >= 3) $actions[] = 'Customers are not returning - prompt the vendor to reply to reviews.';
        if ($growth === 0 && $completed > 0) $actions[] = 'No sales in the last 28 days - suggest a promotion or discount.';
        if (!$actions) $actions[] = 'No action needed - keep supporting this stall.';

        $out[] = [
            'vendor_id'   => $vid,
            'stall_name'  => $v['stall_name'],
            'farmer'      => $v['farmer'],
            'is_active'   => (bool) $v['is_active'],
            'products'    => (int) $v['products'],
            'score'       => $score,
            'label'       => $label,
            'attendance'  => (int) $attendance, 'fulfilment' => (int) $fulfilment,
            'retention'   => (int) $retention, 'growth' => (int) $growth,
            'active_weeks'=> $activeWeeks,
            'completed'   => $completed,
            'customers'   => $served,
            'revenue'     => (float) $stats['revenue_all_time'],
            'action'      => $actions[0],
        ];
    }
    $ai = marketlink_ai_json('market_profitability_health_score', ['stalls'=>$out],
        '{"stalls": array of objects {"vendor_id": integer, "score": integer 0-100, "label": string, "action": string}}',
        sha1(json_encode($out)), 700);
    $byId=[]; foreach (($ai['stalls'] ?? []) as $a) if(isset($a['vendor_id'])) $byId[(int)$a['vendor_id']]=$a;
    foreach($out as &$v){ if(isset($byId[(int)$v['vendor_id']])){ $v['score']=max(0,min(100,(int)($byId[(int)$v['vendor_id']]['score']??$v['score']))); $v['label']=trim((string)($byId[(int)$v['vendor_id']]['label']??$v['label'])); $v['action']=trim((string)($byId[(int)$v['vendor_id']]['action']??$v['action'])); } $v['ai_source']=ai_source_label(); } unset($v);
    usort($out, fn($a, $b) => $a['score'] <=> $b['score']);
    return $out;
}

/* farmer wellbeing monitor */

/**
 * Stalls that used to sell but have had no orders for 3+ weeks.
 */
function admin_wellbeing_flags(): array
{
    $vendors = db_all(
        "SELECT v.id, v.stall_name, u.name AS farmer, u.email, u.phone
           FROM vendors v
           JOIN users u ON u.id = v.user_id AND u.approval_status = 'approved' AND u.is_active = 1
          WHERE v.is_active = 1"
    );
    $flags = [];
    foreach ($vendors as $v) {
        $vid = (int) $v['id'];
        // Completed orders per ISO week for the last 12 weeks.
        $weeks = db_all(
            "SELECT YEARWEEK(o.pickup_date, 3) AS wk, COUNT(*) AS completed
               FROM orders o
              WHERE o.vendor_id = ? AND o.status = 'completed'
                AND o.pickup_date >= (CURDATE() - INTERVAL 84 DAY) AND o.pickup_date IS NOT NULL
              GROUP BY wk
              ORDER BY wk DESC",
            [$vid]
        );
        $byWeek = [];
        foreach ($weeks as $w) {
            $byWeek[(string) $w['wk']] = (int) $w['completed'];
        }
        if (!$byWeek) {
            continue; // never sold: not a wellbeing drop, just new
        }
        // count empty weeks going back from this week
        $consecutivePoor = 0;
        $probe = (string) date('oW');
        $guard = 0;
        while ($guard++ < 26) {
            $sales = $byWeek[$probe] ?? 0;
            if ($sales > 0) {
                break;
            }
            $consecutivePoor++;
            $year = (int) substr($probe, 0, 4);
            $week = (int) substr($probe, 4, 2);
            $probe = (new DateTimeImmutable())->setISODate($year, max(0, $week - 1))->format('oW');
        }
        if ($consecutivePoor >= 3) {
            $avg = round(array_sum($byWeek) / max(1, count(array_filter($byWeek))), 1);
            $flags[] = [
                'vendor_id' => $vid,
                'stall_name'=> $v['stall_name'],
                'farmer'    => $v['farmer'],
                'email'     => $v['email'],
                'phone'     => $v['phone'],
                'poor_weeks'=> $consecutivePoor,
                'usual'     => $avg,
                'suggestion'=> 'Consider reaching out - ' . $v['farmer'] . ' may need platform support '
                    . 'or a temporary promotion boost (usual weekly sales: ' . $avg . ' completed orders).',
            ];
        }
    }
    $ai = marketlink_ai_json('farmer_wellbeing_monitor', ['flags'=>$flags],
        '{"flags": array of objects {"vendor_id": integer, "suggestion": string, "priority": integer 1-100}}',
        sha1(json_encode($flags)), 500);
    $byId=[]; foreach (($ai['flags'] ?? []) as $a) if(isset($a['vendor_id'])) $byId[(int)$a['vendor_id']]=$a;
    foreach($flags as &$f){ if(isset($byId[(int)$f['vendor_id']])){ $f['suggestion']=trim((string)($byId[(int)$f['vendor_id']]['suggestion']??$f['suggestion'])); $f['ai_priority']=max(1,min(100,(int)($byId[(int)$f['vendor_id']]['priority']??50))); } $f['ai_source']=ai_source_label(); } unset($f);
    usort($flags, fn($a, $b) => $b['poor_weeks'] <=> $a['poor_weeks']);
    return $flags;
}

/* new market location scout */

/**
 * Areas with many customers but no stall nearby (about 5 km grid).
 */
function admin_location_scout(int $limit = 6): array
{
    $cells = db_all(
        "SELECT ROUND(last_latitude, 2) AS lat_c, ROUND(last_longitude, 2) AS lng_c,
                COUNT(*) AS customers
           FROM users
          WHERE role = 'customer' AND approval_status = 'approved' AND is_active = 1
            AND last_latitude IS NOT NULL AND last_longitude IS NOT NULL
          GROUP BY lat_c, lng_c
          HAVING customers >= 3
          ORDER BY customers DESC
          LIMIT 40"
    );
    if (!$cells) {
        return [];
    }
    $stalls = db_all(
        "SELECT latitude, longitude, stall_name
           FROM vendors
          WHERE is_active = 1 AND latitude IS NOT NULL AND longitude IS NOT NULL"
    );
    $out = [];
    foreach ($cells as $c) {
        $best = null;
        $bestName = null;
        foreach ($stalls as $s) {
            $d = admin_haversine_km((float) $c['lat_c'], (float) $c['lng_c'], (float) $s['latitude'], (float) $s['longitude']);
            if ($best === null || $d < $best) {
                $best = $d;
                $bestName = $s['stall_name'];
            }
        }
        $customers = (int) $c['customers'];
        if ($best !== null && $best < 3.0) {
            continue; // already well served
        }
        $score = (int) min(100, round($customers * (1 + ($best ?? 5) / 5)));
        $out[] = [
            'lat' => (float) $c['lat_c'],
            'lng' => (float) $c['lng_c'],
            'customers' => $customers,
            'nearest_km' => $best === null ? null : round($best, 1),
            'nearest_stall' => $bestName,
            'score' => $score,
            'summary' => $customers . ' registered customers, nearest stall '
                . ($best === null ? 'unknown' : round($best, 1) . ' km away' . ($bestName ? ' (' . $bestName . ')' : '')),
        ];
    }
    usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
    $out = array_slice($out, 0, $limit);
    $ai = marketlink_ai_json('new_market_location_scout', ['clusters'=>$out],
        '{"clusters": array of objects {"lat": number, "lng": number, "score": integer 0-100, "summary": string}}',
        sha1(json_encode($out)), 600);
    foreach($out as &$c){ foreach(($ai['clusters'] ?? []) as $a){ if(isset($a['lat'],$a['lng']) && abs((float)$a['lat']-(float)$c['lat'])<0.011 && abs((float)$a['lng']-(float)$c['lng'])<0.011){ if(isset($a['score'])) $c['score']=max(0,min(100,(int)$a['score'])); if(isset($a['summary'])) $c['summary']=trim((string)$a['summary']); break; } } $c['ai_source']=ai_source_label(); } unset($c);
    return $out;
}

function admin_haversine_km(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $r = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($a)));
}
