<?php
/** Admin sign-in: email + password only (no Google, no sign-up). */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once APP_ROOT . '/includes/partials/auth_layout.php';

if (($u = current_user()) && $u['role'] === 'admin') {
    redirect('admin/dashboard.php');
}

$email = '';
$error = '';

if (is_post()) {
    verify_csrf_or_fail();
    $email = str_input($_POST, 'email', 190);
    $password = (string) ($_POST['password'] ?? '');

    if (login_throttled()) {
        $error = 'Too many sign-in attempts. Please wait 15 minutes and try again.';
    } elseif ($email === '' || $password === '') {
        $error = 'Enter your email and password.';
    } elseif (($user = attempt_login($email, $password)) && $user['role'] === 'admin') {
        login_user($user);
        unset($_SESSION['_login_attempts']);
        log_activity('admin_login', 'Admin signed in: ' . $user['name'], 'user', (int) $user['id'], (int) $user['id']);
        unset($_SESSION['_intended']);
        redirect('index.php'); // land on the main website; the admin dashboard is in the side rail
    } else {
        record_login_failure();
        $error = 'The email or password is incorrect.';
    }
}

auth_layout_start('Admin sign in');
?>
      <h1>Admin sign in</h1>
      <p class="lead">MarketLink administrators only.</p>
      <?php if ($error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
      <form method="post" novalidate>
        <?= csrf_field() ?>
        <div class="field">
          <label for="email">Email</label>
          <input class="input" type="email" id="email" name="email" value="<?= e($email) ?>" autocomplete="username" required autofocus>
        </div>
        <div class="field">
          <label for="password">Password</label>
          <input class="input" type="password" id="password" name="password" autocomplete="current-password" required>
        </div>
        <button class="btn btn-primary btn-block" type="submit">Sign in <svg><use href="#i-arrow"/></svg></button>
      </form>
      <p class="auth-foot"><a href="<?= e(url('index.php')) ?>">Back to MarketLink</a></p>
<?php auth_layout_end();
