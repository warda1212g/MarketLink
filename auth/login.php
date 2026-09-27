<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once APP_ROOT . '/includes/partials/auth_layout.php';
require_once APP_ROOT . '/includes/services/google.php';

if ($u = current_user()) {
    redirect(home_for($u));
}

$email = '';
$error = $_SESSION['auth_error'] ?? '';
unset($_SESSION['auth_error']);
$role = (($_POST['role'] ?? $_GET['role'] ?? '') === 'vendor') ? 'vendor' : 'customer';

if (is_post()) {
    verify_csrf_or_fail();
    $email = str_input($_POST, 'email', 190);
    $password = (string) ($_POST['password'] ?? '');

    if (login_throttled()) {
        $error = 'Too many sign-in attempts. Please wait 15 minutes and try again.';
    } elseif ($email === '' || $password === '') {
        $error = 'Enter your email and password.';
    } elseif (($user = attempt_login($email, $password)) && $user['role'] === 'admin') {
        // Admins use their own password-only page (no Google there).
        $error = 'Admin accounts sign in at the admin login page.';
    } elseif ($user && $user['role'] !== $role) {
        $error = 'This account is registered as a ' . $user['role'] . '. Choose "' . ucfirst($user['role']) . '" above and sign in again.';
    } elseif ($user) {
        login_user($user);
        unset($_SESSION['_login_attempts']);
        // always go to the home page after login
        unset($_SESSION['_intended']);
        redirect(home_for($user));
    } else {
        record_login_failure();
        $error = 'The email or password is incorrect.';
    }
}

auth_layout_start('Sign in');
?>
      <h1>Welcome back</h1>
      <p class="lead">Sign in to manage your stall or your pre-orders.</p>
      <?php if ($error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
      <form method="post" novalidate>
        <?= csrf_field() ?>
        <p class="role-pick-label">Sign in as</p>
        <div class="role-pick" role="radiogroup" aria-label="Sign in as">
          <label><input type="radio" name="role" value="customer" <?= $role === 'customer' ? 'checked' : '' ?>><span><svg><use href="#i-bag"/></svg><strong>Customer</strong><small>Find markets &amp; pre-order</small></span></label>
          <label><input type="radio" name="role" value="vendor" <?= $role === 'vendor' ? 'checked' : '' ?>><span><svg><use href="#i-store"/></svg><strong>Vendor</strong><small>Manage my stall</small></span></label>
        </div>
        <a class="btn-google" id="googleBtn" href="<?= e(url('auth/google.php?from=login&role=' . $role)) ?>">
          <?php google_icon(); ?><span>Continue with Google</span>
        </a>
        <div class="auth-divider"><span>or with email</span></div>
        <input type="hidden" name="next" value="<?= e($_POST['next'] ?? ($_GET['next'] ?? '')) ?>">
        <div class="field">
          <label for="email">Email</label>
          <input class="input" type="email" id="email" name="email" value="<?= e($email) ?>" autocomplete="email" required autofocus>
        </div>
        <div class="field">
          <label for="password">Password</label>
          <input class="input" type="password" id="password" name="password" autocomplete="current-password" required>
        </div>
        <button class="btn btn-primary btn-block" type="submit">Sign in <svg><use href="#i-arrow"/></svg></button>
      </form>
      <p class="auth-foot">New to MarketLink? <a href="<?= e(url('auth/register.php')) ?>">Create an account</a></p>
      <?php role_pick_script(); ?>
<?php auth_layout_end();
