<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/contact.php';
$user = require_admin_page();
contact_ensure_table();

/* actions (POST + CSRF, then back to the same filtered view) */
if (is_post()) {
    verify_csrf_or_fail();
    $id = (int) ($_POST['id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    $back = 'admin/messages.php' . (($q = (string) ($_POST['back'] ?? '')) !== '' ? '?' . $q : '');
    if ($action === 'read_all') {
        db_query('UPDATE contact_messages SET is_read = 1 WHERE is_read = 0');
        flash('success', 'All messages marked as read.');
    } elseif ($id > 0 && in_array($action, ['read', 'unread', 'delete'], true)) {
        if ($action === 'delete') {
            db_query('DELETE FROM contact_messages WHERE id = ?', [$id]);
            log_activity('contact', 'Deleted contact message #' . $id, 'contact_message', $id, (int) $user['id']);
            flash('success', 'Message deleted.');
        } else {
            db_query('UPDATE contact_messages SET is_read = ? WHERE id = ?', [$action === 'read' ? 1 : 0, $id]);
        }
    }
    redirect($back);
}

$activeNav    = 'messages';
$pageTitle    = 'Contact Messages';
$pageSubtitle = 'Messages sent from the Contact Us page';

$FILTERS = ['unread', 'read'];
$filter = in_array($_GET['filter'] ?? '', $FILTERS, true) ? $_GET['filter'] : '';
$topic = array_key_exists($_GET['topic'] ?? '', CONTACT_TOPICS) ? $_GET['topic'] : '';
$search = trim((string) ($_GET['q'] ?? ''));

$sql = 'SELECT m.*, u.role AS user_role FROM contact_messages m LEFT JOIN users u ON u.id = m.user_id WHERE 1=1';
$params = [];
if ($filter) { $sql .= ' AND m.is_read = ?'; $params[] = $filter === 'read' ? 1 : 0; }
if ($topic)  { $sql .= ' AND m.topic = ?';   $params[] = $topic; }
if ($search !== '') {
    $sql .= ' AND (m.name LIKE ? OR m.email LIKE ? OR m.message LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
$sql .= ' ORDER BY m.is_read ASC, m.created_at DESC LIMIT 200';
$rows = db_all($sql, $params);

$counts = db_one('SELECT COUNT(*) AS total, COALESCE(SUM(is_read = 0), 0) AS unread,
                         COALESCE(SUM(created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)), 0) AS week
                  FROM contact_messages');

$query = http_build_query(array_filter(['filter' => $filter, 'topic' => $topic, 'q' => $search], fn($v) => $v !== ''));
$link = function (array $change) use ($filter, $topic, $search): string {
    $q = array_filter(array_merge(['filter' => $filter, 'topic' => $topic, 'q' => $search], $change), fn($v) => $v !== '');
    return 'messages.php' . ($q ? '?' . http_build_query($q) : '');
};

include __DIR__ . '/includes/header.php';
?>

<?php foreach (take_flashes() as $f): ?>
  <div class="adm-flash adm-flash-<?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div>
<?php endforeach; ?>

<section class="adm-page-head">
  <div>
    <p class="kicker">INBOX</p>
    <h1 class="adm-h1">Contact <em>Messages</em></h1>
    <p class="adm-lede">Questions and feedback from the Contact Us page. Unread messages stay at the top until you open them.</p>
  </div>
  <div style="display:flex;flex-direction:column;gap:8px;align-items:flex-end">
    <div class="adm-seg" role="group" aria-label="Filter by status">
      <a href="<?= e($link(['filter' => ''])) ?>" class="<?= $filter === '' ? 'active' : '' ?>">All</a>
      <a href="<?= e($link(['filter' => 'unread'])) ?>" class="<?= $filter === 'unread' ? 'active' : '' ?>">Unread<?= $counts['unread'] ? ' (' . (int) $counts['unread'] . ')' : '' ?></a>
      <a href="<?= e($link(['filter' => 'read'])) ?>" class="<?= $filter === 'read' ? 'active' : '' ?>">Read</a>
    </div>
    <div class="adm-seg" role="group" aria-label="Filter by topic">
      <a href="<?= e($link(['topic' => ''])) ?>" class="<?= $topic === '' ? 'active' : '' ?>">Any topic</a>
      <?php foreach (array_keys(CONTACT_TOPICS) as $k): ?>
        <a href="<?= e($link(['topic' => $k])) ?>" class="<?= $topic === $k ? 'active' : '' ?>"><?= e(['general' => 'General', 'order' => 'Orders', 'farmer' => 'Farmers', 'feedback' => 'Feedback'][$k]) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php
$read = (int) $counts['total'] - (int) $counts['unread'];
$kpis = [
    ['Total messages', (int) $counts['total'], 'icon-mail', 'All time', $link(['filter' => '']), $filter === '', ''],
    ['Unread', (int) $counts['unread'], 'icon-eye', (int) $counts['unread'] ? 'Waiting for a reply' : 'Inbox is clear', $link(['filter' => 'unread']), $filter === 'unread', (int) $counts['unread'] ? 'is-alert' : ''],
    ['Read', $read, 'icon-check', (int) $counts['total'] ? round($read / (int) $counts['total'] * 100) . '% of all messages' : 'Nothing yet', $link(['filter' => 'read']), $filter === 'read', ''],
    ['Last 7 days', (int) $counts['week'], 'icon-clock', 'New this week', '', false, 'is-static'],
];
?>
<!-- Same KPI cards as the dashboard; the first three also filter the list -->
<section class="adm-kpi-grid kpi-8 msg-kpis" aria-label="Message counts">
  <?php foreach ($kpis as [$label, $value, $icon, $note, $href, $on, $mod]): $tag = $href ? 'a' : 'article'; ?>
    <<?= $tag ?><?= $href ? ' href="' . e($href) . '"' : '' ?> class="adm-card adm-kpi <?= $mod ?><?= $on ? ' is-active' : '' ?>"<?= $on ? ' aria-current="true"' : '' ?>>
      <div class="adm-kpi-top">
        <span class="adm-kpi-icon"><svg viewBox="0 0 24 24"><use href="#<?= $icon ?>"/></svg></span>
        <span class="adm-kpi-label"><?= e($label) ?></span>
      </div>
      <div class="adm-kpi-value"><?= number_format($value) ?></div>
      <div class="adm-kpi-foot"><span class="adm-kpi-note"><?= e($note) ?></span><?php if ($on): ?><span class="adm-change flat">Showing</span><?php endif; ?></div>
    </<?= $tag ?>>
  <?php endforeach; ?>
</section>

<section class="adm-card adm-panel">
  <div class="msg-toolbar">
    <form method="get" class="msg-search" role="search">
      <?php if ($filter): ?><input type="hidden" name="filter" value="<?= e($filter) ?>"><?php endif; ?>
      <?php if ($topic): ?><input type="hidden" name="topic" value="<?= e($topic) ?>"><?php endif; ?>
      <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search name, email or message..." aria-label="Search messages">
      <button type="submit">Search</button>
    </form>
    <?php if ($counts['unread']): ?>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="read_all"><input type="hidden" name="back" value="<?= e($query) ?>">
        <button type="submit" class="adm-act approve"><svg viewBox="0 0 24 24"><use href="#icon-check"/></svg>Mark all as read</button>
      </form>
    <?php endif; ?>
  </div>

  <?php if (!$rows): ?>
    <div class="adm-empty-inline"><strong><?= $search || $filter || $topic ? 'No matching messages' : 'No messages yet' ?></strong>Messages sent from the Contact Us page will appear here.</div>
  <?php else: ?>
  <div class="msg-list">
    <?php foreach ($rows as $m): $unread = !$m['is_read']; ?>
      <details class="msg<?= $unread ? ' is-unread' : '' ?>" id="msg-<?= (int) $m['id'] ?>">
        <summary>
          <span class="adm-avatar"><?= e(mb_strtoupper(mb_substr($m['name'], 0, 1))) ?></span>
          <span class="msg-who">
            <strong><?= e($m['name']) ?><?php if ($m['user_role']): ?> <span class="msg-role"><?= e(ucfirst($m['user_role'])) ?></span><?php endif; ?></strong>
            <small><?= e($m['email']) ?></small>
          </span>
          <span class="msg-preview"><span class="msg-topic"><?= e(CONTACT_TOPICS[$m['topic']] ?? ucfirst($m['topic'])) ?></span><?= e(mb_strimwidth($m['message'], 0, 110, '...')) ?></span>
          <time datetime="<?= e($m['created_at']) ?>"><?= e(time_ago($m['created_at'])) ?></time>
        </summary>
        <div class="msg-body">
          <p><?= nl2br(e($m['message'])) ?></p>
          <div class="msg-meta">Received <?= e(date('M j, Y, g:i A', strtotime($m['created_at']))) ?></div>
          <div class="adm-actions msg-actions">
            <a class="adm-act approve" href="mailto:<?= e($m['email']) ?>?subject=<?= e(rawurlencode('Re: your message to MarketLink')) ?>"><svg viewBox="0 0 24 24"><use href="#icon-reply"/></svg>Reply by email</a>
            <form method="post">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><input type="hidden" name="back" value="<?= e($query) ?>">
              <button type="submit" name="action" value="<?= $unread ? 'read' : 'unread' ?>" class="adm-act"><svg viewBox="0 0 24 24"><use href="#icon-<?= $unread ? 'check' : 'eye' ?>"/></svg><?= $unread ? 'Mark as read' : 'Mark as unread' ?></button>
              <button type="submit" name="action" value="delete" class="adm-act suspend" data-confirm-delete><svg viewBox="0 0 24 24"><use href="#icon-trash"/></svg>Delete</button>
            </form>
          </div>
        </div>
      </details>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<script>
(function () {
  // Opening an unread message marks it read in the background.
  var token = document.querySelector('meta[name="csrf-token"]').content;
  document.querySelectorAll(".msg.is-unread").forEach(function (d) {
    d.addEventListener("toggle", function () {
      if (!d.open || !d.classList.contains("is-unread")) return;
      d.classList.remove("is-unread");
      var body = new FormData();
      body.append("_csrf", token); body.append("action", "read"); body.append("id", d.id.replace("msg-", ""));
      fetch("messages.php", { method: "POST", body: body, credentials: "same-origin", redirect: "manual" });
      var badge = document.querySelector('.adm-nav-link[href="messages.php"] .adm-nav-count');
      if (badge) { var n = Number(badge.textContent) - 1; if (n > 0) badge.textContent = n; else badge.remove(); }
    });
  });
  document.querySelectorAll("[data-confirm-delete]").forEach(function (b) {
    b.addEventListener("click", function (e) { if (!confirm("Delete this message permanently?")) e.preventDefault(); });
  });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
