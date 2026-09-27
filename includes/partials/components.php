<?php
/** Small server-rendered UI components shared by vendor pages. */
declare(strict_types=1);

function trend_badge(?float $change): string
{
    if ($change === null) {
        return '<span class="trend up"><svg><use href="#i-up"/></svg>New</span>';
    }
    if (abs($change) < 0.05) {
        return '<span class="trend flat">0%</span>';
    }
    $up = $change > 0;
    return '<span class="trend ' . ($up ? 'up' : 'down') . '"><svg><use href="#i-' . ($up ? 'up' : 'down') . '"/></svg>' . e(abs($change)) . '%</span>';
}

function range_filter(array $options, string $default): string
{
    $html = '<div class="filters-row" data-range-filter data-default="' . e($default) . '"><div class="seg" role="group" aria-label="Date range">';
    foreach ($options as $key => $label) {
        $on = $key === $default;
        $html .= '<button type="button" data-range="' . e($key) . '" class="' . ($on ? 'active' : '') . '" aria-pressed="' . ($on ? 'true' : 'false') . '">' . e($label) . '</button>';
    }
    $html .= '</div>';
    if (isset($options['custom'])) {
        $html .= '<div class="custom-range" hidden>'
            . '<input type="date" name="from" aria-label="From date" max="' . date('Y-m-d') . '">'
            . '<span class="muted">-</span>'
            . '<input type="date" name="to" aria-label="To date" max="' . date('Y-m-d') . '">'
            . '<button type="button" class="btn btn-soft btn-sm" data-apply>Apply</button></div>';
    }
    return $html . '</div>';
}

function empty_state(string $icon, string $title, string $text, string $actionHtml = ''): string
{
    return '<div class="empty"><div class="empty-icon"><svg><use href="#' . e($icon) . '"/></svg></div><h4>' . e($title) . '</h4><p>' . e($text) . '</p>' . $actionHtml . '</div>';
}

function stars(int $rating): string
{
    $html = '<span class="stars" aria-label="' . $rating . ' out of 5 stars">';
    for ($i = 1; $i <= 5; $i++) {
        $html .= '<svg class="' . ($i <= $rating ? '' : 'off') . '"><use href="#i-star"/></svg>';
    }
    return $html . '</span>';
}

/** Buttons for the next valid status transitions of an order. */
function order_action_buttons(array $o, bool $withView = true): string
{
    $map = [
        'accepted'  => ['Accept', 'btn-primary', 'i-check'],
        'declined'  => ['Reject', 'btn-danger-ghost', 'i-x'],
        'preparing' => ['Start Preparing', 'btn-secondary', 'i-box'],
        'ready'     => ['Mark Ready', 'btn-secondary', 'i-bag'],
        'completed' => ['Mark Completed', 'btn-primary', 'i-check'],
        'cancelled' => ['Cancel', 'btn-danger-ghost', 'i-x'],
    ];
    $html = '';
    if ($withView) {
        $html .= '<a class="btn btn-ghost btn-sm btn-icon-only" href="' . e(url('vendor/orders.php?open=' . (int) $o['id'])) . '" aria-label="View order ' . e($o['order_number']) . '" title="View"><svg><use href="#i-eye"/></svg></a>';
    }
    foreach (ORDER_TRANSITIONS[$o['status']] ?? [] as $to) {
        if ($to === 'cancelled' && $withView) continue; // keep compact rows focused on the happy path
        if ($to === 'ready' && $o['status'] === 'accepted' && $withView) continue; // compact rows: accepted -> preparing first
        [$label, $cls, $icon] = $map[$to];
        $html .= '<button type="button" class="btn ' . $cls . ' btn-sm" data-order-action="' . e($to) . '" data-order-id="' . (int) $o['id'] . '" data-order-number="' . e($o['order_number']) . '"><svg><use href="#' . $icon . '"/></svg>' . e($label) . '</button>';
    }
    return $html;
}
