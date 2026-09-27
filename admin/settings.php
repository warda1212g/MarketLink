<?php
/**
 * Admin Settings: admin profile, password, platform rules (sign-ups, farmer approval, website announcement),
 * admin accounts and system status. Platform rules are stored in app_settings (includes/services/settings.php).
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/groq.php';
$user = require_admin_page();
$uid = (int) $user['id'];

$errors = [];
$section = '';
$me = db_one('SELECT id, name, email, phone, last_login_at, created_at FROM users WHERE id = ?', [$uid]);
$old = ['name' => $me['name'], 'email' => $me['email'], 'phone' => (string) $me['phone']];

/* actions */
if (is_post()) {
    if (!csrf_valid()) {
        flash('error', 'Security token mismatch. Refresh the page and try again.');
        redirect('admin/settings.php');
    }
    $section = (string) ($_POST['section'] ?? '');

    if ($section === 'profile') {
        $old = ['name' => str_input($_POST, 'name', 120), 'email' => mb_strtolower(str_input($_POST, 'email', 190)), 'phone' => str_input($_POST, 'phone', 30)];
        if (mb_strlen($old['name']) < 2) $errors['name'] = 'Enter your full name.';
        if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
        elseif (db_value('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$old['email'], $uid])) $errors['email'] = 'Another account already uses this email.';
        if ($old['phone'] !== '' && !preg_match('/^[0-9+\-\s()]{7,30}$/', $old['phone'])) $errors['phone'] = 'Enter a valid phone number.';
        if (!$errors) {
            db_query('UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ?', [$old['name'], $old['email'], $old['phone'] ?: null, $uid]);
            flash('success', 'Your profile has been updated.');
            redirect('admin/settings.php#profile');
        }
    }

    if ($section === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        $hash = (string) db_value('SELECT password_hash FROM users WHERE id = ?', [$uid]);
        if (!password_verify($current, $hash)) $errors['current_password'] = 'Your current password is incorrect.';
        if (strlen($new) < 10) $errors['new_password'] = 'Use at least 10 characters for an admin password.';
        elseif (!preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) $errors['new_password'] = 'Use both letters and numbers.';
        elseif ($new !== $confirm) $errors['confirm_password'] = 'Passwords do not match.';
        if (!$errors) {
            db_query('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $uid]);
            flash('success', 'Your password has been changed.');
            redirect('admin/settings.php#security');
        }
    }

    if ($section === 'platform') {
        $tone = in_array($_POST['announcement_tone'] ?? '', ['info', 'success', 'warning'], true) ? $_POST['announcement_tone'] : 'info';
        $text = str_input($_POST, 'announcement_text', 240);
        $enabled = !empty($_POST['announcement_enabled']);
        if ($enabled && $text === '') {
            $errors['announcement_text'] = 'Write the announcement text, or switch the banner off.';
        }
        if (!$errors) {
            app_settings_save([
                'registration.customers' => !empty($_POST['registration_customers']) ? '1' : '0',
                'registration.vendors'   => !empty($_POST['registration_vendors']) ? '1' : '0',
                'vendors.auto_approve'   => !empty($_POST['vendors_auto_approve']) ? '1' : '0',
                'announcement.enabled'   => $enabled ? '1' : '0',
                'announcement.text'      => $text,
                'announcement.tone'      => $tone,
            ], $uid);
            flash('success', 'Platform settings saved.');
            redirect('admin/settings.php#platform');
        }
    }
}

$s = app_settings();
$pv = fn(string $post, string $key) => $section === 'platform' ? (!empty($_POST[$post]) ? '1' : '0') : $s[$key];
$annText = $section === 'platform' ? str_input($_POST, 'announcement_text', 240) : $s['announcement.text'];
$annTone = $section === 'platform' ? ($_POST['announcement_tone'] ?? 'info') : $s['announcement.tone'];

$admins = db_all("SELECT id, name, email, is_active, last_login_at, created_at FROM users WHERE role = 'admin' ORDER BY is_active DESC, name");
$pendingVendors = (int) db_value("SELECT COUNT(*) FROM users WHERE role = 'vendor' AND approval_status = 'pending'");
$counts = db_one("SELECT SUM(role = 'customer') AS customers, SUM(role = 'vendor') AS vendors FROM users WHERE is_active = 1");
$dbVersion = (string) db_value('SELECT VERSION()');
$uploadDir = APP_ROOT . '/uploads';
$system = [
    ['PHP version', PHP_VERSION, version_compare(PHP_VERSION, '8.0', '>=')],
    ['Database', 'MySQL / MariaDB ' . $dbVersion, true],
    ['Timezone', date_default_timezone_get() . ' (' . date('M j, Y g:i A') . ')', true],
    ['Uploads folder', is_dir($uploadDir) && is_writable($uploadDir) ? 'Writable' : 'Not writable', is_dir($uploadDir) && is_writable($uploadDir)],
    ['AI reports (Groq)', groq_enabled() ? 'API key configured' : 'No API key', groq_enabled()],
    ['Google sign-in', trim((string) config('google.client_id', '')) !== '' ? 'Configured' : 'Not configured', trim((string) config('google.client_id', '')) !== ''],
    ['Debug mode', config('app.debug') ? 'On (turn off in production)' : 'Off', !config('app.debug')],
];

$fieldErr = fn(string $k) => isset($errors[$k]) ? '<span class="st-error">' . e($errors[$k]) . '</span>' : '';
$inv = fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';

$activeNav    = 'settings';
$pageTitle    = 'Settings';
$pageSubtitle = 'Your account and platform preferences';
$pageStyles   = [asset('assets/css/admin-settings.css')];
$pageScripts  = [asset('assets/js/admin-settings.js')];
include __DIR__ . '/includes/header.php';
?>

<?php foreach (take_flashes() as $fl): ?>
  <div class="adm-flash adm-flash-<?= e($fl['type']) ?>" role="status"><?= e($fl['message']) ?></div>
<?php endforeach; ?>
<?php if ($errors): ?><div class="adm-flash adm-flash-error" role="alert">Please correct the highlighted fields.</div><?php endif; ?>

<section class="adm-page-head">
  <div>
    <p class="kicker">CONFIGURATION</p>
    <h1 class="adm-h1">System <em>Settings</em></h1>
    <p class="adm-lede">Manage your admin account, sign-up rules and how MarketLink behaves for farmers and customers.</p>
  </div>
</section>

<div class="st-layout">
  <nav class="st-nav adm-card" aria-label="Settings sections">
    <a href="#profile" class="active"><svg viewBox="0 0 24 24"><use href="#icon-user"/></svg><span>Profile</span></a>
    <a href="#security"><svg viewBox="0 0 24 24"><use href="#icon-shield"/></svg><span>Security</span></a>
    <a href="#platform"><svg viewBox="0 0 24 24"><use href="#icon-settings"/></svg><span>Platform</span></a>
    <a href="#admins"><svg viewBox="0 0 24 24"><use href="#icon-people"/></svg><span>Admin Accounts</span></a>
    <a href="#system"><svg viewBox="0 0 24 24"><use href="#icon-chart"/></svg><span>System Status</span></a>
  </nav>

  <div class="st-sections">

    <!-- Profile -->
    <section class="adm-card adm-panel st-section" id="profile">
      <header class="st-head">
        <div><h2 class="adm-h2">Admin Profile</h2><p class="adm-panel-sub">Your name and email are used on reports you generate and in admin notifications.</p></div>
      </header>
      <form method="post" novalidate>
        <?= csrf_field() ?><input type="hidden" name="section" value="profile">
        <div class="st-grid">
          <label><span class="adm-label">Full name</span>
            <input class="form-control<?= $inv('name') ?>" name="name" maxlength="120" required value="<?= e($old['name']) ?>"><?= $fieldErr('name') ?></label>
          <label><span class="adm-label">Email</span>
            <input class="form-control<?= $inv('email') ?>" type="email" name="email" maxlength="190" required value="<?= e($old['email']) ?>"><?= $fieldErr('email') ?>
            <small class="st-hint">This is also your admin sign-in.</small></label>
          <label><span class="adm-label">Phone</span>
            <input class="form-control<?= $inv('phone') ?>" name="phone" maxlength="30" value="<?= e($old['phone']) ?>" placeholder="+92 300 1234567"><?= $fieldErr('phone') ?></label>
          <div class="st-meta">
            <div><span class="adm-label">Last sign-in</span><strong><?= $me['last_login_at'] ? e(fmt_date($me['last_login_at'], 'M j, Y g:i A')) : 'Not recorded' ?></strong></div>
            <div><span class="adm-label">Admin since</span><strong><?= e(fmt_date($me['created_at'], 'F Y')) ?></strong></div>
          </div>
        </div>
        <div class="st-foot"><button type="submit" class="adm-btn"><svg viewBox="0 0 24 24"><use href="#icon-check"/></svg> Save profile</button></div>
      </form>
    </section>

    <!-- Security -->
    <section class="adm-card adm-panel st-section" id="security">
      <header class="st-head">
        <div><h2 class="adm-h2">Change Password</h2><p class="adm-panel-sub">Use at least 10 characters with letters and numbers. You stay signed in after changing it.</p></div>
      </header>
      <form method="post" novalidate>
        <?= csrf_field() ?><input type="hidden" name="section" value="password">
        <div class="st-grid">
          <label class="st-span"><span class="adm-label">Current password</span>
            <input class="form-control<?= $inv('current_password') ?>" type="password" name="current_password" autocomplete="current-password" required><?= $fieldErr('current_password') ?></label>
          <label><span class="adm-label">New password</span>
            <input class="form-control<?= $inv('new_password') ?>" type="password" name="new_password" autocomplete="new-password" minlength="10" required data-strength><?= $fieldErr('new_password') ?>
            <span class="st-strength" aria-hidden="true"><i></i></span><small class="st-hint" data-strength-text>Strength: not set</small></label>
          <label><span class="adm-label">Confirm new password</span>
            <input class="form-control<?= $inv('confirm_password') ?>" type="password" name="confirm_password" autocomplete="new-password" required><?= $fieldErr('confirm_password') ?></label>
        </div>
        <div class="st-foot"><button type="submit" class="adm-btn"><svg viewBox="0 0 24 24"><use href="#icon-shield"/></svg> Update password</button></div>
      </form>
    </section>

    <!-- Platform -->
    <section class="adm-card adm-panel st-section" id="platform">
      <header class="st-head">
        <div><h2 class="adm-h2">Platform Rules</h2><p class="adm-panel-sub">These take effect immediately across the website and sign-up pages.</p></div>
      </header>
      <form method="post" novalidate>
        <?= csrf_field() ?><input type="hidden" name="section" value="platform">

        <h3 class="st-sub">Sign-ups</h3>
        <div class="st-toggles">
          <label class="st-toggle">
            <span><strong>Allow new customer accounts</strong><small>Customers can register with email or Google.</small></span>
            <input type="checkbox" name="registration_customers" value="1" <?= $pv('registration_customers', 'registration.customers') === '1' ? 'checked' : '' ?>><i aria-hidden="true"></i>
          </label>
          <label class="st-toggle">
            <span><strong>Allow new farmer / vendor accounts</strong><small>Farmers can apply to sell on MarketLink.</small></span>
            <input type="checkbox" name="registration_vendors" value="1" <?= $pv('registration_vendors', 'registration.vendors') === '1' ? 'checked' : '' ?>><i aria-hidden="true"></i>
          </label>
          <label class="st-toggle">
            <span><strong>Approve new farmers automatically</strong><small>When off, new farmers wait in Manage Farmers for your review<?= $pendingVendors ? ' (' . $pendingVendors . ' waiting now)' : '' ?>. Existing pending accounts are not changed.</small></span>
            <input type="checkbox" name="vendors_auto_approve" value="1" <?= $pv('vendors_auto_approve', 'vendors.auto_approve') === '1' ? 'checked' : '' ?>><i aria-hidden="true"></i>
          </label>
        </div>

        <h3 class="st-sub">Website announcement</h3>
        <div class="st-toggles">
          <label class="st-toggle">
            <span><strong>Show announcement banner</strong><small>Appears at the top of customer pages, e.g. holiday closures or new markets.</small></span>
            <input type="checkbox" name="announcement_enabled" value="1" data-ann-toggle <?= $pv('announcement_enabled', 'announcement.enabled') === '1' ? 'checked' : '' ?>><i aria-hidden="true"></i>
          </label>
        </div>
        <div class="st-grid st-ann" data-ann-fields>
          <label class="st-span"><span class="adm-label">Message</span>
            <textarea class="form-control<?= $inv('announcement_text') ?>" name="announcement_text" rows="2" maxlength="240" data-ann-text placeholder="Markets are closed on Friday for Eid. Pre-orders reopen on Saturday."><?= e($annText) ?></textarea>
            <?= $fieldErr('announcement_text') ?><small class="st-hint"><span data-ann-count><?= mb_strlen($annText) ?></span> / 240 characters</small></label>
          <label><span class="adm-label">Style</span>
            <select class="form-select" name="announcement_tone" data-ann-tone>
              <?php foreach (['info' => 'Information (green)', 'success' => 'Good news (bright green)', 'warning' => 'Important (amber)'] as $k => $t): ?>
                <option value="<?= $k ?>" <?= $annTone === $k ? 'selected' : '' ?>><?= e($t) ?></option>
              <?php endforeach; ?>
            </select></label>
          <div><span class="adm-label">Preview</span><div class="st-preview st-preview-<?= e($annTone) ?>" data-ann-preview><?= e($annText ?: 'Your announcement will appear like this.') ?></div></div>
        </div>

        <div class="st-foot"><button type="submit" class="adm-btn"><svg viewBox="0 0 24 24"><use href="#icon-check"/></svg> Save platform settings</button></div>
      </form>
    </section>

    <!-- Admin accounts -->
    <section class="adm-card adm-panel st-section" id="admins">
      <header class="st-head">
        <div><h2 class="adm-h2">Admin Accounts</h2><p class="adm-panel-sub"><?= count($admins) ?> administrator<?= count($admins) === 1 ? '' : 's' ?> can sign in to this console.</p></div>
      </header>
      <div class="table-responsive">
        <table class="table adm-table">
          <thead><tr><th>Admin</th><th>Email</th><th>Status</th><th>Last sign-in</th><th>Added</th></tr></thead>
          <tbody>
          <?php foreach ($admins as $a): ?>
            <tr>
              <td><div class="adm-person"><span class="adm-avatar"><?= e(mb_strtoupper(mb_substr($a['name'], 0, 1))) ?></span><strong><?= e($a['name']) ?></strong><?= (int) $a['id'] === $uid ? ' <span class="adm-badge approved">You</span>' : '' ?></div></td>
              <td><?= e($a['email']) ?></td>
              <td><span class="adm-badge <?= $a['is_active'] ? 'approved' : 'cancelled' ?>"><?= $a['is_active'] ? 'Active' : 'Disabled' ?></span></td>
              <td class="muted"><?= $a['last_login_at'] ? e(fmt_date($a['last_login_at'], 'M j, Y g:i A')) : 'Never' ?></td>
              <td class="muted"><?= e(fmt_date($a['created_at'], 'M j, Y')) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- System -->
    <section class="adm-card adm-panel st-section" id="system">
      <header class="st-head">
        <div><h2 class="adm-h2">System Status</h2><p class="adm-panel-sub">Read-only health checks. Keys and debug mode are changed in <code>config/config.php</code>.</p></div>
      </header>
      <div class="st-stats">
        <div><span class="adm-label">Active customers</span><strong><?= number_format((int) $counts['customers']) ?></strong></div>
        <div><span class="adm-label">Active farmers</span><strong><?= number_format((int) $counts['vendors']) ?></strong></div>
        <div><span class="adm-label">Farmers awaiting approval</span><strong><?= $pendingVendors ?></strong></div>
      </div>
      <ul class="st-checks">
        <?php foreach ($system as [$label, $value, $ok]): ?>
          <li><span class="st-dot <?= $ok ? 'ok' : 'warn' ?>" aria-hidden="true"></span><span><?= e($label) ?></span><strong><?= e($value) ?></strong></li>
        <?php endforeach; ?>
      </ul>
    </section>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
