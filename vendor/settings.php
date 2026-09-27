<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once APP_ROOT . '/includes/partials/vendor_layout.php';

$vendor = require_vendor();
$user = current_user();

vendor_layout_start([
    'title' => 'Settings', 'active' => 'settings',
    'heading' => 'Settings', 'subheading' => 'Account security and inventory preferences.',
]);
?>
<section class="grid grid-2">
  <form class="card" data-settings="inventory" novalidate>
    <h3 class="form-section-title"><svg><use href="#i-box"/></svg>Inventory alerts</h3>
    <div class="field">
      <label for="threshold">Low stock threshold</label>
      <input class="input" type="number" id="threshold" name="low_stock_threshold" min="0" step="0.5" value="<?= e(qty($vendor['low_stock_threshold'])) ?>" required>
      <span class="hint">Products at or below this quantity show in the Low Stock card and notifications.</span>
    </div>
    <div class="form-foot"><button class="btn btn-primary" type="submit"><svg><use href="#i-check"/></svg>Save</button></div>
  </form>

  <form class="card" data-settings="password" novalidate>
    <h3 class="form-section-title"><svg><use href="#i-lock"/></svg>Change password</h3>
    <div class="field">
      <label for="cur">Current password</label>
      <input class="input" type="password" id="cur" name="current_password" autocomplete="current-password" required>
    </div>
    <div class="form-grid mt">
      <div class="field">
        <label for="np">New password</label>
        <input class="input" type="password" id="np" name="new_password" autocomplete="new-password" minlength="8" required>
      </div>
      <div class="field">
        <label for="cp">Confirm new password</label>
        <input class="input" type="password" id="cp" name="confirm_password" autocomplete="new-password" minlength="8" required>
      </div>
    </div>
    <div class="form-foot"><button class="btn btn-primary" type="submit"><svg><use href="#i-check"/></svg>Update password</button></div>
  </form>
</section>

<article class="card mt">
  <h3 class="form-section-title"><svg><use href="#i-user"/></svg>Account</h3>
  <dl class="kv">
    <dt>Signed in as</dt><dd><?= e($user['name']) ?>, <?= e($user['email']) ?></dd>
    <dt>Role</dt><dd>Vendor</dd>
    <dt>Public stall</dt><dd><a class="link" href="<?= e(url('market.php?id=' . $vendor['id'])) ?>" target="_blank" rel="noopener"><?= e($vendor['stall_name']) ?> <svg><use href="#i-arrow"/></svg></a></dd>
  </dl>
</article>

<?php vendor_layout_end(['scripts' => ['assets/js/vendor/settings.js']]);
