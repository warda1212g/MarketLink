<?php
/**
 * Market-day cut-off rules.
 * GET                                   -> rules + live open/closed status
 * POST save {id?, market_day, cutoff_days_before, cutoff_time, pickup_start, pickup_end, is_active}
 * POST toggle {id, is_active}
 * POST delete {id}
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/schedule.php';

function cutoff_payload(array $vendor): array
{
    $rules = vendor_cutoff_settings((int) $vendor['id']);
    $windows = vendor_market_windows($vendor, 7);
    $byDay = [];
    foreach ($windows as $w) {
        $byDay[$w['day']] = $byDay[$w['day']] ?? $w; // nearest occurrence per weekday
    }
    $status = vendor_order_status($vendor);

    return [
        'rules' => array_map(function ($r) use ($byDay) {
            $w = $byDay[(int) $r['market_day']] ?? null;
            $days = (int) $r['cutoff_days_before'];
            return [
                'id' => (int) $r['id'],
                'market_day' => (int) $r['market_day'],
                'day_name' => DAYS[(int) $r['market_day']],
                'cutoff_days_before' => $days,
                'cutoff_time' => substr($r['cutoff_time'], 0, 5),
                'pickup_start' => substr($r['pickup_start'], 0, 5),
                'pickup_end' => substr($r['pickup_end'], 0, 5),
                'is_active' => (bool) $r['is_active'],
                'deadline_label' => ($days === 0 ? DAYS[(int) $r['market_day']] : DAYS[((int) $r['market_day'] - $days + 6) % 7 + 1])
                    . ' ' . fmt_time($r['cutoff_time']) . ($days ? " ($days day" . ($days > 1 ? 's' : '') . ' before)' : ''),
                'pickup_label' => fmt_time($r['pickup_start']) . ' - ' . fmt_time($r['pickup_end']),
                'next' => $w ? [
                    'date' => date('D, M j', strtotime($w['market_date'])),
                    'open' => $w['open'],
                    'deadline' => window_deadline_label($w),
                ] : null,
            ];
        }, $rules),
        'summary' => [
            'accepting' => $status['accepting'],
            'current_closed' => $status['current_closed'],
            'message' => $status['message'],
            'next_open' => $status['next_open'] ? window_label($status['next_open']) : null,
        ],
        'now' => date('l, M j, g:i A'),
    ];
}

api_run(function () {
    $vendor = require_vendor_api();
    $vid = (int) $vendor['id'];

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        json_ok(cutoff_payload($vendor));
    }

    require_method('POST');
    $in = request_data();

    switch ($in['action'] ?? '') {
        case 'save':
            $id = int_input($in, 'id');
            $day = int_input($in, 'market_day', 0);
            $before = int_input($in, 'cutoff_days_before', 0);
            $cut = str_input($in, 'cutoff_time', 8);
            $ps = str_input($in, 'pickup_start', 8);
            $pe = str_input($in, 'pickup_end', 8);
            $active = !empty($in['is_active']) ? 1 : 0;

            $errors = [];
            if ($day < 1 || $day > 7) $errors['market_day'] = 'Choose a market day.';
            if ($before < 0 || $before > 6) $errors['cutoff_days_before'] = 'Choose when orders close.';
            if (!valid_time($cut)) $errors['cutoff_time'] = 'Enter a cut-off time.';
            if (!valid_time($ps)) $errors['pickup_start'] = 'Enter a pickup start time.';
            if (!valid_time($pe)) $errors['pickup_end'] = 'Enter a pickup end time.';
            if (!$errors && strtotime($pe) <= strtotime($ps)) $errors['pickup_end'] = 'Pickup must end after it starts.';
            if (!$errors && $before === 0 && strtotime($cut) > strtotime($ps)) $errors['cutoff_time'] = 'On the market day itself, orders must close before pickup starts.';
            if (!$errors) {
                $dup = db_value('SELECT id FROM cutoff_settings WHERE vendor_id = ? AND market_day = ?' . ($id ? ' AND id <> ?' : ''), $id ? [$vid, $day, $id] : [$vid, $day]);
                if ($dup) $errors['market_day'] = DAYS[$day] . ' already has a market schedule.';
            }
            if ($errors) throw new ValidationException($errors);

            if ($id) {
                db_query('UPDATE cutoff_settings SET market_day=?, cutoff_days_before=?, cutoff_time=?, pickup_start=?, pickup_end=?, is_active=? WHERE id=? AND vendor_id=?',
                    [$day, $before, $cut, $ps, $pe, $active, $id, $vid]);
                if (!db_value('SELECT 1 FROM cutoff_settings WHERE id = ? AND vendor_id = ?', [$id, $vid])) {
                    throw new ValidationException([], 'Schedule not found.');
                }
            } else {
                db_query('INSERT INTO cutoff_settings (vendor_id, market_day, cutoff_days_before, cutoff_time, pickup_start, pickup_end, is_active) VALUES (?,?,?,?,?,?,?)',
                    [$vid, $day, $before, $cut, $ps, $pe, $active]);
            }
            json_ok(cutoff_payload($vendor), 'Cut-off settings saved.');

        case 'toggle':
            $id = (int) ($in['id'] ?? 0);
            db_query('UPDATE cutoff_settings SET is_active = ? WHERE id = ? AND vendor_id = ?', [!empty($in['is_active']) ? 1 : 0, $id, $vid]);
            json_ok(cutoff_payload($vendor), !empty($in['is_active']) ? 'Market day activated.' : 'Market day paused.');

        case 'delete':
            db_query('DELETE FROM cutoff_settings WHERE id = ? AND vendor_id = ?', [(int) ($in['id'] ?? 0), $vid]);
            json_ok(cutoff_payload($vendor), 'Market day removed.');

        default:
            json_error('Unknown action.');
    }
});
