<?php
/** Customer profile: personal details and password change. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once APP_ROOT . '/includes/partials/customer_layout.php';

$user = require_customer_dashboard();
$uid = (int) $user['id'];

$errors = [];
$old = ['name' => $user['name'], 'phone' => (string) $user['phone'], 'address' => (string) ($user['address'] ?? '')];
if (is_post()) {
    verify_csrf_or_fail();
    $old = ['name' => str_input($_POST, 'name', 120), 'phone' => str_input($_POST, 'phone', 30), 'address' => str_input($_POST, 'address', 255)];
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    if (mb_strlen($old['name']) < 2) $errors['name'] = 'Enter your full name.';
    if ($old['phone'] !== '' && !preg_match('/^[0-9+\-\s()]{7,30}$/', $old['phone'])) $errors['phone'] = 'Enter a valid phone number.';
    if ($new !== '' || $current !== '') {
        $hash = (string) db_value('SELECT password_hash FROM users WHERE id = ?', [$uid]);
        if (!password_verify($current, $hash)) $errors['current_password'] = 'Your current password is incorrect.';
        if (strlen($new) < 8) $errors['new_password'] = 'Use at least 8 characters.';
        elseif ($new !== $confirm) $errors['confirm_password'] = 'Passwords do not match.';
    }

    if (!$errors) {
        db_query('UPDATE users SET name = ?, phone = ?, address = ? WHERE id = ?', [$old['name'], $old['phone'] ?: null, $old['address'] ?: null, $uid]);
        if ($new !== '') {
            db_query('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $uid]);
        }
        flash('success', 'Your profile has been updated.');
        redirect('customer/profile.php');
    }
}

$member = db_value('SELECT created_at FROM users WHERE id = ?', [$uid]);
$orderCount = (int) db_value("SELECT COUNT(*) FROM orders WHERE customer_id = ? AND status = 'completed'", [$uid]);
$favCount = (int) db_value('SELECT COUNT(*) FROM favorites WHERE customer_id = ?', [$uid]);

$field = function (string $name, string $label, string $input) use ($errors): string {
    return '<div class="field"><label for="f_' . $name . '">' . e($label) . '</label>' . $input
        . (isset($errors[$name]) ? '<span class="field-error">' . e($errors[$name]) . '</span>' : '') . '</div>';
};
$inv = fn(string $k) => isset($errors[$k]) ? ' invalid' : '';

customer_layout_start([
    'title' => 'Profile & Security', 'active' => 'profile',
    'heading' => 'Profile & Security', 'subheading' => 'Keep your contact details up to date so farmers can reach you at pickup.',
]);
?>

<?php if ($errors): ?><div class="alert alert-error" role="alert">Please correct the highlighted fields.</div><?php endif; ?>

<div class="co-profile">
  <aside class="card co-profile-card">
    <span class="co-avatar"><?= e(mb_strtoupper(mb_substr(trim($user['name']), 0, 1))) ?></span>
    <h3><?= e($user['name']) ?></h3>
    <p class="muted"><?= e($user['email']) ?></p>
    <div class="co-profile-stats">
      <div><strong><?= $orderCount ?></strong><span>Collected</span></div>
      <div><strong><?= $favCount ?></strong><span>Saved</span></div>
    </div>
    <dl class="kv">
      <dt>Phone</dt><dd><?= e($user['phone'] ?: 'Not added') ?></dd>
      <dt>Address</dt><dd><?= e($user['address'] ?? '' ?: 'Not added') ?></dd>
      <dt>Member since</dt><dd><?= e(fmt_date($member, 'F Y')) ?></dd>
    </dl>
  </aside>

  <form class="co-profile-forms" method="post" action="<?= e(url('customer/profile.php')) ?>" novalidate>
    <?= csrf_field() ?>
    <article class="card">
      <div class="card-head"><div><h3 class="card-title">Personal details</h3><p class="card-sub">Farmers see your name and phone number on your pre-orders.</p></div></div>
      <div class="form-grid co-form-grid">
        <?= $field('name', 'Full name', '<input class="input' . $inv('name') . '" id="f_name" name="name" maxlength="120" required value="' . e($old['name']) . '">') ?>
        <?= $field('phone', 'Phone', '<input class="input' . $inv('phone') . '" id="f_phone" name="phone" maxlength="30" value="' . e($old['phone']) . '" placeholder="+92 300 1234567">') ?>
        <div class="co-span"><?= $field('address', 'Address', '<input class="input" id="f_address" name="address" maxlength="255" value="' . e($old['address']) . '" placeholder="House, street, area, city">') ?></div>
        <div class="field co-span">
          <label for="f_email">Email</label>
          <input class="input" id="f_email" value="<?= e($user['email']) ?>" disabled>
          <span class="hint">Your email is your sign-in and cannot be changed.</span>
        </div>
      </div>
    </article>

    <article class="card mt">
      <div class="card-head"><div><h3 class="card-title">Change password</h3><p class="card-sub">Leave these blank to keep your current password.</p></div><span class="stat-icon"><svg><use href="#i-lock"/></svg></span></div>
      <div class="form-grid co-form-grid">
        <div class="co-span"><?= $field('current_password', 'Current password', '<input class="input' . $inv('current_password') . '" type="password" id="f_current_password" name="current_password" autocomplete="current-password">') ?></div>
        <?= $field('new_password', 'New password', '<input class="input' . $inv('new_password') . '" type="password" id="f_new_password" name="new_password" autocomplete="new-password" minlength="8">') ?>
        <?= $field('confirm_password', 'Confirm new password', '<input class="input' . $inv('confirm_password') . '" type="password" id="f_confirm_password" name="confirm_password" autocomplete="new-password">') ?>
      </div>
    </article>

    <div class="co-form-foot">
      <a class="btn btn-ghost" href="<?= e(url('customer/dashboard.php')) ?>">Cancel</a>
      <button type="submit" class="btn btn-primary"><svg><use href="#i-check"/></svg>Save changes</button>
    </div>
  </form>
</div>

<?php customer_layout_end();
