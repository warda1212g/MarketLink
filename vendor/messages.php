<?php
/**
 * Vendor inbox. ?order=ID opens the chat with that order's customer, ?c=ID opens a conversation.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/messages.php';
require_once APP_ROOT . '/includes/partials/vendor_layout.php';

$vendor = require_vendor();
$user = current_user();
$initial = 0;
if ($orderId = int_input($_GET, 'order')) {
    $order = db_one('SELECT id, customer_id FROM orders WHERE id = ? AND vendor_id = ?', [$orderId, $vendor['id']]);
    $c = $order ? conversation_open((int) $order['customer_id'], (int) $vendor['id'], (int) $order['id']) : null;
    if (!$c) {
        flash('error', 'Order not found.');
        redirect('vendor/messages.php');
    }
    redirect('vendor/messages.php?c=' . $c['id']);
}
if ($cid = int_input($_GET, 'c')) {
    $initial = conversation_for_user($user, $cid) ? $cid : 0;
}

vendor_layout_start([
    'title' => 'Messages', 'active' => 'messages',
    'heading' => 'Customer Messages', 'subheading' => 'Reply to customers about their orders and pickups.',
]);
?>
<div class="chat" id="chat" data-role="vendor" data-report="1" data-initial="<?= (int) $initial ?>">
  <aside class="chat-list">
    <div class="chat-list-head"><h3>Customers</h3><em class="count-badge" data-badge="messages" hidden></em></div>
    <div class="chat-convs" id="chatConvs"><div class="chat-empty"><p>Loading...</p></div></div>
  </aside>
  <section class="chat-thread" id="chatThread">
    <div class="chat-empty"><svg><use href="#i-chat"/></svg><p>Select a customer to read and reply.</p><small>Tip: open any order and choose "Message customer".</small></div>
  </section>
</div>
<?php vendor_layout_end(['scripts' => ['assets/js/messages.js']]);
