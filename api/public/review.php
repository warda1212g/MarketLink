<?php
/** POST {order_id, rating, review} -> customer reviews a vendor after a completed order (one review per order). */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/notifications.php';

api_run(function () {
    require_method('POST');
    $user = current_user();
    if ($user && $user['role'] === 'customer' && !is_approved($user)) {
        json_error('Your account is on hold. Contact MarketLink support.', 403);
    }
    if (!$user || $user['role'] !== 'customer') {
        json_error('Please sign in with a customer account to leave a review.', 401);
    }
    if (!csrf_valid()) {
        json_error('Security token mismatch. Refresh the page and try again.', 419);
    }
    $in = request_data();
    $orderId = (int) ($in['order_id'] ?? 0);
    $rating = int_input($in, 'rating', 0);
    $text = str_input($in, 'review', 1000);

    $order = db_one('SELECT id, vendor_id, status FROM orders WHERE id = ? AND customer_id = ?', [$orderId, $user['id']]);
    if (!$order || $order['status'] !== 'completed') {
        json_error('You can review a stall after your order is completed.', 422);
    }
    if ($rating < 1 || $rating > 5) {
        throw new ValidationException(['rating' => 'Choose a rating from 1 to 5.'], 'Choose a rating from 1 to 5.');
    }
    if (db_value('SELECT 1 FROM reviews WHERE order_id = ?', [$orderId])) {
        json_error('You have already reviewed this order.', 409);
    }
    db_query('INSERT INTO reviews (customer_id, vendor_id, order_id, rating, review) VALUES (?, ?, ?, ?, ?)',
        [$user['id'], $order['vendor_id'], $orderId, $rating, $text ?: null]);
    $reviewId = (int) db()->lastInsertId();
    notify_vendor((int) $order['vendor_id'], 'review_new', 'New review received from ' . $user['name'],
        $rating . '-star review' . ($text !== '' ? ' - "' . mb_strimwidth($text, 0, 100, '...') . '"' : ''), 'vendor/reviews.php?filter=unanswered', 'review', $reviewId);
    notify((int) $user['id'], 'review_submitted', 'Your review was posted', 'Thanks for reviewing your order. It is now visible on the stall page.',
        'order.php?id=' . $orderId, 'review', $reviewId);
    json_ok([], 'Thanks! Your review was posted.');
});
