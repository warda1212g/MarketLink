<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once APP_ROOT . '/includes/partials/auth_layout.php';
require_once APP_ROOT . '/includes/services/google.php';
require_once APP_ROOT . '/includes/services/markets.php';
require_once APP_ROOT . '/includes/services/notifications.php';

if ($u = current_user()) {
    redirect(home_for($u));
}
$googleError = $_SESSION['auth_error'] ?? '';
unset($_SESSION['auth_error']);

$old = ['name' => '', 'email' => '', 'phone' => '', 'role' => ($_GET['role'] ?? '') === 'vendor' ? 'vendor' : 'customer', 'stall_name' => '', 'market_ids' => [], 'category_ids' => []];
$marketCategories = db_all('SELECT id, name, icon FROM market_types WHERE is_active = 1 ORDER BY sort_order, name');
$openMarkets = public_markets();
$errors = [];

if (is_post()) {
    verify_csrf_or_fail();
    $old = [
        'name'       => str_input($_POST, 'name', 120),
        'email'      => mb_strtolower(str_input($_POST, 'email', 190)),
        'phone'      => str_input($_POST, 'phone', 30),
        'role'       => ($_POST['role'] ?? '') === 'vendor' ? 'vendor' : 'customer',
        'stall_name' => str_input($_POST, 'stall_name', 150),
        'market_ids' => array_map('intval', (array) ($_POST['market_ids'] ?? [])),
        'category_ids' => array_map('intval', (array) ($_POST['category_ids'] ?? [])),
    ];
    $password = (string) ($_POST['password'] ?? '');

    if (mb_strlen($old['name']) < 2) $errors['name'] = 'Enter your full name.';
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
    if ($old['phone'] !== '' && !preg_match('/^[0-9+\-\s()]{7,30}$/', $old['phone'])) $errors['phone'] = 'Enter a valid phone number.';
    if (strlen($password) < 8) $errors['password'] = 'Use at least 8 characters.';
    if ($old['role'] === 'vendor' && mb_strlen($old['stall_name']) < 2) $errors['stall_name'] = 'Enter your stall or business name.';
    if ($old['role'] === 'vendor' && $marketCategories && !array_intersect($old['category_ids'], array_map('intval', array_column($marketCategories, 'id')))) {
        $errors['category_ids'] = 'Choose the market category your stall belongs to.';
    }
    // Admin -> Settings can pause new sign-ups per role.
    if (!app_setting_on($old['role'] === 'vendor' ? 'registration.vendors' : 'registration.customers')) {
        $googleError = $old['role'] === 'vendor' ? 'New farmer sign-ups are paused right now. Please try again later.' : 'New customer sign-ups are paused right now. Please try again later.';
        $errors['_closed'] = $googleError;
    }
    if (!$errors && db_value('SELECT 1 FROM users WHERE email = ?', [$old['email']])) {
        $errors['email'] = 'An account with this email already exists.';
    }

    if (!$errors) {
        $userId = db_transaction(function (PDO $pdo) use ($old, $password) {
            $pdo->prepare('INSERT INTO users (name, email, password_hash, role, phone, approval_status) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$old['name'], $old['email'], password_hash($password, PASSWORD_DEFAULT), $old['role'], $old['phone'] ?: null, initial_approval_status($old['role'])]);
            $id = (int) $pdo->lastInsertId();
            if ($old['role'] === 'vendor') {
                $pdo->prepare('INSERT INTO vendors (user_id, stall_name, contact_person, email, phone) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$id, $old['stall_name'], $old['name'], $old['email'], $old['phone'] ?: null]);
                // add the stall to the chosen markets (shows up after approval)
                $vendorId = (int) $pdo->lastInsertId();
                vendor_join_markets($vendorId, $old['market_ids']);
                vendor_set_categories($vendorId, $old['category_ids']);
            }
            return $id;
        });
        notify_new_account($userId, $old['role'], $old['name'], $old['stall_name']);
        login_user(['id' => $userId, 'role' => $old['role']]);
        // vendors have to wait for admin approval
        if ($old['role'] === 'customer') {
            flash('success', 'Welcome to MarketLink, ' . strtok($old['name'], ' ') . '! Your account is ready.');
        }
        redirect(home_for(['role' => $old['role'], 'approval_status' => initial_approval_status($old['role'])]));
    }
}

$err = fn($k) => isset($errors[$k]) ? '<span class="field-error">' . e($errors[$k]) . '</span>' : '';
$inv = fn($k) => isset($errors[$k]) ? ' invalid" aria-invalid="true' : '';

auth_layout_start('Create account');
?>
      <h1>Create your account</h1>
      <p class="lead">Shop local markets or sell from your own stall.</p>
      <?php if ($googleError): ?><div class="alert alert-error" role="alert"><?= e($googleError) ?></div><?php endif; ?>
      <form method="post" novalidate>
        <?= csrf_field() ?>
        <p class="role-pick-label">Sign up as</p>
        <div class="role-pick" role="radiogroup" aria-label="Account type">
          <label><input type="radio" name="role" value="customer" <?= $old['role'] === 'customer' ? 'checked' : '' ?>><span><svg><use href="#i-bag"/></svg><strong>I'm a customer</strong><small>Find markets &amp; pre-order</small></span></label>
          <label><input type="radio" name="role" value="vendor" <?= $old['role'] === 'vendor' ? 'checked' : '' ?>><span><svg><use href="#i-store"/></svg><strong>I'm a vendor</strong><small>Sell from my stall</small></span></label>
        </div>
        <a class="btn-google" id="googleBtn" href="<?= e(url('auth/google.php?from=register&role=' . $old['role'])) ?>">
          <?php google_icon(); ?><span>Sign up with Google</span>
        </a>
        <div class="auth-divider"><span>or with email</span></div>
        <p class="hint" style="margin:-4px 0 12px" id="approvalHint"><?= $old['role'] === 'vendor' ? 'Vendor accounts can sign in right away; the vendor dashboard opens once an admin approves your stall.' : 'Customer accounts are active immediately - sign up and start pre-ordering.' ?></p>
        <div class="field">
          <label for="name">Full name</label>
          <input class="input<?= $inv('name') ?>" id="name" name="name" value="<?= e($old['name']) ?>" autocomplete="name" required>
          <?= $err('name') ?>
        </div>
        <div class="field" id="stallField" <?= $old['role'] === 'vendor' ? '' : 'hidden' ?>>
          <label for="stall_name">Stall / business name</label>
          <input class="input<?= $inv('stall_name') ?>" id="stall_name" name="stall_name" value="<?= e($old['stall_name']) ?>" autocomplete="organization">
          <?= $err('stall_name') ?>
          <?php if ($marketCategories): ?>
            <span class="field-label" style="display:block;margin-top:14px">Market category <span class="req">*</span></span>
            <div class="reg-markets">
              <?php foreach ($marketCategories as $c): ?>
                <label class="reg-market"><input type="checkbox" name="category_ids[]" value="<?= (int) $c['id'] ?>"<?= in_array((int) $c['id'], $old['category_ids'], true) ? ' checked' : '' ?>>
                  <span><strong><?= e(trim(($c['icon'] ?? '') . ' ' . $c['name'])) ?></strong></span></label>
              <?php endforeach; ?>
            </div>
            <?= $err('category_ids') ?>
          <?php endif; ?>
          <?php if ($openMarkets): ?>
            <span class="field-label" style="display:block;margin-top:14px">Which markets do you want to sell in? <span class="muted">(optional)</span></span>
            <div class="reg-markets">
              <?php foreach ($openMarkets as $m): ?>
                <label class="reg-market"><input type="checkbox" name="market_ids[]" value="<?= $m['id'] ?>"<?= in_array($m['id'], $old['market_ids'], true) ? ' checked' : '' ?>>
                  <span><strong><?= e(trim(($m['type_icon'] ?? '') . ' ' . $m['name'])) ?></strong><small><?= e($m['type_name'] . ', ' . $m['location']) ?></small></span></label>
              <?php endforeach; ?>
            </div>
            <span class="hint">You can join or leave markets later from your dashboard.</span>
          <?php endif; ?>
        </div>
        <div class="field">
          <label for="email">Email</label>
          <input class="input<?= $inv('email') ?>" type="email" id="email" name="email" value="<?= e($old['email']) ?>" autocomplete="email" required>
          <?= $err('email') ?>
        </div>
        <div class="field">
          <label for="phone">Phone <span class="muted">(optional)</span></label>
          <input class="input<?= $inv('phone') ?>" type="tel" id="phone" name="phone" value="<?= e($old['phone']) ?>" autocomplete="tel">
          <?= $err('phone') ?>
        </div>
        <div class="field">
          <label for="password">Password</label>
          <input class="input<?= $inv('password') ?>" type="password" id="password" name="password" autocomplete="new-password" minlength="8" required>
          <span class="hint">At least 8 characters.</span>
          <?= $err('password') ?>
        </div>
        <button class="btn btn-primary btn-block" type="submit">Create account <svg><use href="#i-arrow"/></svg></button>
      </form>
      <p class="auth-foot">Already have an account? <a href="<?= e(url('auth/login.php')) ?>">Sign in</a></p>
      <?php role_pick_script(); ?>
      <script>
        document.querySelectorAll('input[name="role"]').forEach(function (r) {
          r.addEventListener('change', function () {
            if (!r.checked) return;
            document.getElementById('stallField').hidden = r.value !== 'vendor';
            document.getElementById('approvalHint').textContent = r.value === 'vendor'
              ? 'Vendor accounts can sign in right away; the vendor dashboard opens once an admin approves your stall.'
              : 'Customer accounts are active immediately - sign up and start pre-ordering.';
          });
        });
      </script>
<?php auth_layout_end();
