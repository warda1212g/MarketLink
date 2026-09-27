<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/analytics.php';
require_once APP_ROOT . '/includes/partials/vendor_layout.php';
require_once APP_ROOT . '/includes/partials/components.php';

$vendor = require_vendor();
$summary = rating_summary((int) $vendor['id']);
$filter = in_array($_GET['filter'] ?? '', ['unanswered', 'answered'], true) ? $_GET['filter'] : 'all';

vendor_layout_start([
    'title' => 'Reviews', 'active' => 'reviews',
    'heading' => 'Reviews', 'subheading' => 'See what customers say and respond to them.',
]);
?>
<section class="grid grid-main">
  <article class="card">
    <div class="rating-hero">
      <div class="rating-big">
        <strong><?= $summary['count'] ? number_format($summary['avg'], 1) : '-' ?></strong>
        <?= stars((int) round($summary['avg'])) ?>
        <p class="muted" style="font-size:13px;margin-top:6px">Based on <?= number_format($summary['count']) ?> review<?= $summary['count'] === 1 ? '' : 's' ?></p>
      </div>
      <div>
        <?php for ($s = 5; $s >= 1; $s--): $n = $summary['distribution'][$s]; $pct = $summary['count'] ? round($n / $summary['count'] * 100) : 0; ?>
          <div class="dist-row"><span><?= $s ?> star<?= $s === 1 ? '' : 's' ?></span><div class="dist-bar"><i style="width:<?= $pct ?>%"></i></div><span class="muted"><?= $n ?></span></div>
        <?php endfor; ?>
      </div>
    </div>
  </article>
  <article class="card stat-card featured">
    <div class="stat-top"><span class="stat-label">Awaiting your reply</span><span class="stat-icon"><svg><use href="#i-reply"/></svg></span></div>
    <div class="stat-value" id="unansweredCount"><?= number_format($summary['unanswered']) ?></div>
    <div class="stat-foot">Responding to reviews builds trust with new customers.</div>
  </article>
</section>

<div class="tabs mt" role="tablist" id="reviewTabs">
  <?php foreach (['all' => 'All reviews', 'unanswered' => 'Needs reply', 'answered' => 'Replied'] as $k => $label): ?>
    <button class="tab<?= $k === $filter ? ' active' : '' ?>" role="tab" data-filter="<?= $k ?>" aria-selected="<?= $k === $filter ? 'true' : 'false' ?>"><?= e($label) ?></button>
  <?php endforeach; ?>
  <select class="select" id="ratingFilter" aria-label="Filter by rating" style="margin-left:auto;width:auto;height:40px;align-self:center">
    <option value="">All ratings</option>
    <?php for ($s = 5; $s >= 1; $s--): ?><option value="<?= $s ?>"><?= $s ?> stars</option><?php endfor; ?>
  </select>
</div>

<div class="grid grid-2" id="reviewList" aria-live="polite">
  <div class="skeleton" style="height:180px;border-radius:20px"></div>
  <div class="skeleton" style="height:180px;border-radius:20px"></div>
</div>
<div style="text-align:center;margin-top:18px"><button class="btn btn-ghost" id="moreReviews" hidden>Load more</button></div>

<div class="modal" id="replyModal" role="dialog" aria-modal="true" aria-labelledby="replyTitle" hidden>
  <form class="modal-card" id="replyForm" novalidate>
    <div class="modal-head">
      <h3 id="replyTitle">Reply to review</h3>
      <button type="button" class="icon-btn" data-modal-close aria-label="Close"><svg><use href="#i-close"/></svg></button>
    </div>
    <div class="modal-body">
      <div class="review-card" id="replyContext" style="margin-bottom:16px;background:var(--bg)"></div>
      <input type="hidden" name="id">
      <div class="field">
        <label for="replyText">Your Response</label>
        <textarea class="textarea" id="replyText" name="reply" maxlength="1000" required placeholder="Thank the customer and address any feedback..."></textarea>
        <span class="hint"><span id="replyChars">0</span>/1000, shown publicly on your stall page</span>
      </div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
      <button type="submit" class="btn btn-primary"><svg><use href="#i-reply"/></svg>Send Reply</button>
    </div>
  </form>
</div>

<script type="application/json" id="reviewsInit"><?= json_encode(['filter' => $filter]) ?></script>
<?php vendor_layout_end(['scripts' => ['assets/js/vendor/reviews.js']]);
