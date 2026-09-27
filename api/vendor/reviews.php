<?php
/**
 * GET  ?filter=all|unanswered|answered&rating=1-5&page=  -> reviews for this vendor only
 * POST {action:"reply", id, reply}                       -> set / edit the vendor's reply
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/analytics.php';
require_once APP_ROOT . '/includes/services/notifications.php';

api_run(function () {
    $vendor = require_vendor_api();
    $vid = (int) $vendor['id'];

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $where = ['r.vendor_id = ?', "r.status = 'visible'"];
        $params = [$vid];
        $filter = $_GET['filter'] ?? 'all';
        if ($filter === 'unanswered') $where[] = 'r.vendor_reply IS NULL';
        if ($filter === 'answered') $where[] = 'r.vendor_reply IS NOT NULL';
        if ($rating = int_input($_GET, 'rating')) {
            if ($rating >= 1 && $rating <= 5) { $where[] = 'r.rating = ?'; $params[] = $rating; }
        }
        $perPage = 10;
        $page = max(1, int_input($_GET, 'page', 1));
        $offset = ($page - 1) * $perPage;
        $rows = db_all('SELECT r.*, u.name AS customer_name, o.order_number FROM reviews r
                        JOIN users u ON u.id = r.customer_id LEFT JOIN orders o ON o.id = r.order_id
                        WHERE ' . implode(' AND ', $where) . ' ORDER BY r.created_at DESC LIMIT ' . ($perPage + 1) . " OFFSET $offset", $params);

        json_ok([
            'reviews' => array_map(fn($r) => [
                'id' => (int) $r['id'],
                'customer' => $r['customer_name'],
                'rating' => (int) $r['rating'],
                'review' => $r['review'],
                'order_number' => $r['order_number'],
                'reply' => $r['vendor_reply'],
                'created' => time_ago($r['created_at']),
                'replied' => $r['replied_at'] ? time_ago($r['replied_at']) : null,
            ], array_slice($rows, 0, $perPage)),
            'has_more' => count($rows) > $perPage,
            'summary' => rating_summary($vid),
        ]);
    }

    require_method('POST');
    $in = request_data();
    if (($in['action'] ?? '') !== 'reply') {
        json_error('Unknown action.');
    }
    $id = (int) ($in['id'] ?? 0);
    $reply = str_input($in, 'reply', 1000);
    if (mb_strlen($reply) < 2) {
        throw new ValidationException(['reply' => 'Write a short response.'], 'Write a short response.');
    }
    // Ownership check: vendors can only answer reviews of their own stall.
    if (!db_value('SELECT 1 FROM reviews WHERE id = ? AND vendor_id = ?', [$id, $vid])) {
        json_error('Review not found.', 404);
    }
    $wasAnswered = db_value('SELECT vendor_reply IS NOT NULL FROM reviews WHERE id = ?', [$id]);
    db_query('UPDATE reviews SET vendor_reply = ?, replied_at = NOW() WHERE id = ? AND vendor_id = ?', [$reply, $id, $vid]);
    if (!$wasAnswered) {
        $r = db_one('SELECT customer_id, order_id FROM reviews WHERE id = ?', [$id]);
        notify((int) $r['customer_id'], 'review_reply', $vendor['stall_name'] . ' responded to your review', mb_strimwidth($reply, 0, 140, '...'),
            'market.php?id=' . $vid, 'review', $id);
    }
    json_ok(['id' => $id, 'reply' => $reply, 'replied' => 'just now'], 'Reply posted.');
});
