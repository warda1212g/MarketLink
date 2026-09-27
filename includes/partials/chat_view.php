<?php
/**
 * Customer inbox, used by messages.php and customer/messages.php.
 */
declare(strict_types=1);

require_once APP_ROOT . '/includes/services/messages.php';

/**
 * Handles ?vendor=ID[&order=ID] (open or start a thread, then redirect to ?c=ID on $selfPath) and ?c=ID.
 * Returns the conversation id to open first (0 = none).
 */
function customer_chat_initial(array $user, string $selfPath): int
{
    if ($vid = int_input($_GET, 'vendor')) {
        $c = conversation_open((int) $user['id'], $vid, int_input($_GET, 'order'));
        if (!$c) {
            flash('error', 'You can message a stall after placing an order with it.');
            redirect($selfPath);
        }
        redirect($selfPath . '?c=' . $c['id']);
    }
    $cid = int_input($_GET, 'c');
    return $cid && conversation_for_user($user, $cid) ? $cid : 0;
}

function customer_chat_view(int $initial): void
{ ?>
<div class="chat" id="chat" data-role="customer" data-initial="<?= $initial ?>" data-autoselect="1">
  <aside class="chat-list">
    <div class="chat-list-head"><h3>Conversations</h3><em class="count-badge" data-badge="messages" hidden></em></div>
    <div class="chat-convs" id="chatConvs"><div class="chat-empty"><p>Loading conversations...</p></div></div>
  </aside>
  <section class="chat-thread" id="chatThread">
    <div class="chat-empty"><svg><use href="#i-chat"/></svg><p>Select a conversation to read and reply.</p><small>Stalls you have ordered from appear on the left.</small></div>
  </section>
</div>
<?php }
