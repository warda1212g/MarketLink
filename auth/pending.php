<?php
/** Shown to vendors waiting for approval, and to suspended accounts. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once APP_ROOT . '/includes/partials/auth_layout.php';

$user = require_login();
if (is_approved($user)) {
    redirect(home_for($user));
}
$suspended = in_array($user['approval_status'] ?? '', ['suspended', 'rejected'], true);
$rejected = ($user['approval_status'] ?? '') === 'rejected';

auth_layout_start($rejected ? 'Application not approved' : ($suspended ? 'Account suspended' : 'Waiting for approval'));
?>
      <h1><?= $rejected ? 'Your application was not approved' : ($suspended ? 'Your account is on hold' : 'Thanks for joining, ' . e(strtok($user['name'], ' ')) . '!') ?></h1>
      <?php if ($rejected): ?>
        <p class="lead">An admin reviewed this account and did not approve it, so the <?= $user['role'] === 'vendor' ? 'vendor dashboard' : 'ordering area' ?> is locked. Contact MarketLink support if you'd like it reconsidered.</p>
      <?php elseif ($suspended): ?>
        <p class="lead">An admin has paused this account, so the <?= $user['role'] === 'vendor' ? 'vendor dashboard' : 'ordering area' ?> is locked. Contact MarketLink support if you think this is a mistake.</p>
      <?php else: ?>
        <p class="lead">You are signed in as a <strong><?= e($user['role']) ?></strong> with <strong><?= e($user['email']) ?></strong>.</p>
        <div class="alert alert-info" role="status">Your account is waiting for admin approval. Once it is approved, <?= $user['role'] === 'vendor' ? 'your vendor dashboard' : 'you can place pre-orders and see My Orders' ?>. Check back on this page any time.</div>
      <?php endif; ?>
      <a class="btn btn-primary btn-block" href="<?= e(url('auth/pending.php')) ?>">Check status again <svg><use href="#i-arrow"/></svg></a>
      <p class="auth-foot"><a href="<?= e(url('markets.php')) ?>">Browse markets</a></p>
      <form method="post" action="<?= e(url('auth/logout.php')) ?>" style="text-align:center;margin-top:8px">
        <?= csrf_field() ?>
        <button type="submit" class="link" style="background:none;border:0;cursor:pointer">Sign out</button>
      </form>
<?php auth_layout_end();
