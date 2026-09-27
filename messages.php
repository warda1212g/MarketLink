<?php
/**
 * Customer messages. ?vendor=ID starts a chat with a stall, ?c=ID opens a conversation.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once APP_ROOT . '/includes/partials/site_layout.php';
require_once APP_ROOT . '/includes/partials/chat_view.php';

$user = require_customer();
$initial = customer_chat_initial($user, 'messages.php');

site_layout_start(['title' => 'Messages', 'active' => 'messages']);
?>
<div class="page-head" style="margin-top:0">
  <div><p class="ml-kicker">Talk to your farmers</p><h2>My <em>Messages</em></h2><p>Ask about your order, pickup time or products. Stalls you've ordered from appear here.</p></div>
</div>
<?php customer_chat_view($initial); ?>
<?php site_layout_end(['assets/js/messages.js']);
