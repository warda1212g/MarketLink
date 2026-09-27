<?php
/**
 * Market schedule & order cut-off rules.
 *
 * A market "window" is one concrete market date for a vendor:
 *   market_date + pickup times + the deadline after which pre-orders close.
 * Windows come from cutoff_settings. When a vendor has none configured we fall back
 * to their profile operating days, closing orders at pickup start on the day.
 */
declare(strict_types=1);

function vendor_cutoff_settings(int $vendorId, bool $activeOnly = false): array
{
    $sql = 'SELECT * FROM cutoff_settings WHERE vendor_id = ?' . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY market_day';
    return db_all($sql, [$vendorId]);
}

/**
 * Upcoming market windows for the next $days days (includes today's market until pickup ends).
 * @return array<int, array{market_date:string, day:int, pickup_start:string, pickup_end:string, deadline:DateTimeImmutable, open:bool}>
 */
function vendor_market_windows(array $vendor, int $days = 14, ?DateTimeImmutable $now = null): array
{
    $now = $now ?? new DateTimeImmutable('now');
    $rules = [];

    foreach (vendor_cutoff_settings((int) $vendor['id'], true) as $s) {
        $rules[(int) $s['market_day']] = [
            'pickup_start' => $s['pickup_start'],
            'pickup_end'   => $s['pickup_end'],
            'days_before'  => (int) $s['cutoff_days_before'],
            'cutoff_time'  => $s['cutoff_time'],
        ];
    }

    if (!$rules && $vendor['pickup_start'] && $vendor['pickup_end']) {
        foreach (days_from_set($vendor['operating_days'] ?? '') as $d) {
            $rules[$d] = [
                'pickup_start' => $vendor['pickup_start'],
                'pickup_end'   => $vendor['pickup_end'],
                'days_before'  => 0,
                'cutoff_time'  => $vendor['pickup_start'],
            ];
        }
    }

    $windows = [];
    $today = $now->setTime(0, 0);
    for ($i = 0; $i <= $days; $i++) {
        $date = $today->modify("+{$i} day");
        $dow = (int) $date->format('N');
        if (!isset($rules[$dow])) {
            continue;
        }
        $r = $rules[$dow];
        $pickupEnd = new DateTimeImmutable($date->format('Y-m-d') . ' ' . $r['pickup_end']);
        if ($pickupEnd <= $now) {
            continue; // market already over
        }
        $deadline = new DateTimeImmutable($date->modify('-' . $r['days_before'] . ' day')->format('Y-m-d') . ' ' . $r['cutoff_time']);
        $windows[] = [
            'market_date'  => $date->format('Y-m-d'),
            'day'          => $dow,
            'pickup_start' => $r['pickup_start'],
            'pickup_end'   => $r['pickup_end'],
            'deadline'     => $deadline,
            'open'         => $now < $deadline,
        ];
    }
    return $windows;
}

/**
 * Summary used by customer pages and the order API.
 * - accepting: at least one upcoming window is open
 * - current_closed: the nearest market has passed its cut-off
 */
function vendor_order_status(array $vendor, ?DateTimeImmutable $now = null): array
{
    $windows = vendor_market_windows($vendor, 14, $now);
    $open = array_values(array_filter($windows, fn($w) => $w['open']));
    $nearest = $windows[0] ?? null;

    $status = [
        'configured'     => (bool) $windows,
        'accepting'      => (bool) $open,
        'current_closed' => $nearest !== null && !$nearest['open'],
        'nearest'        => $nearest,
        'next_open'      => $open[0] ?? null,
        'open_windows'   => $open,
        'message'        => '',
    ];

    if (!$windows) {
        $status['message'] = 'This vendor has not published a market schedule yet.';
    } elseif ($status['current_closed']) {
        $status['message'] = 'Orders for this market are currently closed.';
    } elseif ($open) {
        $status['message'] = 'Pre-orders close ' . window_deadline_label($open[0]) . '.';
    }
    return $status;
}

function window_label(array $w): string
{
    return DAYS[$w['day']] . ', ' . date('M j', strtotime($w['market_date'])) . ', ' . fmt_time($w['pickup_start']) . ' - ' . fmt_time($w['pickup_end']);
}

function window_deadline_label(array $w): string
{
    /** @var DateTimeImmutable $d */
    $d = $w['deadline'];
    return $d->format('l, M j') . ' at ' . $d->format('g:i A');
}

/** Find an open window for a given pickup date or null (server-side cut-off enforcement). */
function find_open_window(array $vendor, string $pickupDate): ?array
{
    foreach (vendor_market_windows($vendor) as $w) {
        if ($w['market_date'] === $pickupDate && $w['open']) {
            return $w;
        }
    }
    return null;
}
