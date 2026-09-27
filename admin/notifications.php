<?php
/** Admin notifications (vendor sign-ups, reports, moderation) plus the recent platform activity log. */
require_once __DIR__ . '/../includes/bootstrap.php';
$user = require_admin_page();

$activeNav    = 'notifications';
$pageTitle    = 'Notifications & Activity';
$pageSubtitle = 'Alerts for you and everything happening on MarketLink';

$perPage = 25;
$page = max(1, int_input($_GET, 'page', 1));
$activity = db_all('SELECT a.*, u.name AS actor FROM activity_log a LEFT JOIN users u ON u.id = a.actor_user_id
                    ORDER BY a.id DESC LIMIT ' . ($perPage + 1) . ' OFFSET ' . (($page - 1) * $perPage));
$hasMore = count($activity) > $perPage;
$activity = array_slice($activity, 0, $perPage);

include __DIR__ . '/includes/header.php';
?>
<section class="adm-page-head">
  <div><p class="kicker">STAY INFORMED</p><h1 class="adm-h1">Notifications &amp; <em>Activity</em></h1>
    <p class="adm-lede">New vendor registrations, reported content and moderation alerts. Click a notification to open the related record.</p></div>
</section>

<div class="row g-4">
  <div class="col-lg-7">
    <section class="adm-card adm-panel" data-notif-center>
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px">
        <div class="adm-seg" role="tablist" aria-label="Filter notifications">
          <button type="button" class="active" data-filter="all" aria-selected="true">All</button>
          <button type="button" data-filter="unread" aria-selected="false">Unread</button>
          <button type="button" data-filter="read" aria-selected="false">Read</button>
        </div>
        <button type="button" class="adm-btn-outline" data-nc-readall>Mark all as read</button>
      </div>
      <div class="nc-list" data-nc-list><div class="adm-empty-inline">Loading...</div></div>
      <div style="text-align:center;padding-top:12px"><button type="button" class="adm-btn-outline" data-nc-more hidden>Load more</button></div>
    </section>
  </div>
  <div class="col-lg-5">
    <section class="adm-card adm-panel">
      <h2 class="adm-h2" style="margin-bottom:12px">Recent activity</h2>
      <?php if (!$activity): ?>
        <div class="adm-empty-inline"><strong>No activity yet</strong>Actions on the platform are recorded here.</div>
      <?php else: ?>
        <ul class="list-unstyled m-0">
          <?php foreach ($activity as $a): ?>
            <li style="padding:10px 0;border-bottom:1px solid var(--line-soft)">
              <div style="font-size:13.5px"><?= e($a['message']) ?></div>
              <small class="text-muted"><?= e(str_replace('_', ' ', $a['type'])) ?><?= $a['actor'] ? ', ' . e($a['actor']) : '' ?>, <?= e(time_ago($a['created_at'])) ?></small>
            </li>
          <?php endforeach; ?>
        </ul>
        <div style="display:flex;gap:8px;justify-content:center;margin-top:12px">
          <?php if ($page > 1): ?><a class="adm-btn-outline" href="?page=<?= $page - 1 ?>">Newer</a><?php endif; ?>
          <?php if ($hasMore): ?><a class="adm-btn-outline" href="?page=<?= $page + 1 ?>">Older</a><?php endif; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
