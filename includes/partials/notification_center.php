<?php
/** Notification centre markup shared by the customer, vendor and admin pages (driven by assets/js/notifications.js). */
declare(strict_types=1);

function notification_center(): void
{ ?>
<div class="card card-flush" data-notif-center>
  <div class="card-head" style="padding:16px 18px 0;margin-bottom:0">
    <div class="tabs" role="tablist" aria-label="Filter notifications" style="margin:0;border:0">
      <button type="button" role="tab" class="tab active" data-filter="all" aria-selected="true">All</button>
      <button type="button" role="tab" class="tab" data-filter="unread" aria-selected="false">Unread <em class="count-badge" data-badge="notifications" hidden></em></button>
      <button type="button" role="tab" class="tab" data-filter="read" aria-selected="false">Read</button>
    </div>
    <button type="button" class="btn btn-ghost btn-sm" data-nc-readall><svg><use href="#i-check"/></svg>Mark all as read</button>
  </div>
  <div class="nc-list" data-nc-list style="border-top:1px solid var(--border-soft);margin-top:12px"><div class="empty-mini">Loading...</div></div>
  <div style="text-align:center;padding:14px"><button type="button" class="btn btn-ghost btn-sm" data-nc-more hidden>Load more</button></div>
</div>
<?php }
