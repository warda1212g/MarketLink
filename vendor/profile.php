<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once APP_ROOT . '/includes/partials/vendor_layout.php';

$vendor = require_vendor();
$days = days_from_set($vendor['operating_days']);
$image = upload_url($vendor['image']);

// Markets this stall is registered in, with each market's category (market type).
$stalls = db_all(
    "SELECT m.id, m.name, m.city, m.area, m.status AS market_status, t.name AS type_name, t.icon, t.farmer_label,
            fm.status, fm.assigned_at,
            (SELECT COUNT(*) FROM products p WHERE p.vendor_id = fm.vendor_id AND p.market_id = m.id AND p.deleted_at IS NULL) AS products
       FROM farmer_markets fm
       JOIN markets m      ON m.id = fm.market_id
       JOIN market_types t ON t.id = m.market_type_id
      WHERE fm.vendor_id = ?
      ORDER BY fm.assigned_at DESC", [(int) $vendor['id']]
);
$allCategories = db_all('SELECT id, name, icon, description FROM market_types WHERE is_active = 1 ORDER BY sort_order, name');
$myCategoryIds = array_map('intval', array_column(db_all('SELECT market_type_id FROM farmer_categories WHERE vendor_id = ?', [(int) $vendor['id']]), 'market_type_id'));
$farmerCats = db_all(
    'SELECT t.farmer_label, t.icon FROM farmer_categories fc JOIN market_types t ON t.id = fc.market_type_id
      WHERE fc.vendor_id = ? ORDER BY t.sort_order', [(int) $vendor['id']]
);
$joinedId = int_input($_GET, 'joined');
$joined = $joinedId ? current(array_filter($stalls, fn($s) => (int) $s['id'] === $joinedId)) : null;

vendor_layout_start([
    'title' => 'My Profile', 'active' => 'profile',
    'heading' => 'My Profile', 'subheading' => 'Your stall details, market days and pickup information.',
]);
?>
<?php if ($joined): ?>
  <div class="card ps-welcome" role="status">
    <span class="ps-icon"><svg><use href="#i-store"/></svg></span>
    <div><strong>Your stall is registered in <?= e($joined['name']) ?></strong>
      <p class="muted">Category: <?= e($joined['type_name']) ?>, you're listed as a <?= e($joined['farmer_label']) ?>. Complete your profile below, then add products to this market.</p></div>
    <a class="btn btn-primary btn-sm" href="<?= e(url('vendor/products.php')) ?>"><svg><use href="#i-plus"/></svg>Add products</a>
  </div>
<?php endif; ?>

<article class="card" id="my-stalls" style="margin-bottom:18px">
  <div class="card-head" style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
    <div><h3 class="form-section-title" style="margin:0"><svg><use href="#i-store"/></svg>My Market Stalls</h3>
      <p class="card-sub">Markets and categories where your stall is registered.</p></div>
    <a class="btn btn-ghost btn-sm" href="<?= e(url('vendor/markets.php')) ?>"><svg><use href="#i-plus"/></svg>Join another market</a>
  </div>
  <?php if ($farmerCats): ?>
    <div class="ps-cats"><span class="field-label">Your farmer categories:</span>
      <?php foreach ($farmerCats as $c): ?><span class="pill pill-accepted"><?= e(trim(($c['icon'] ?? '') . ' ' . $c['farmer_label'])) ?></span><?php endforeach; ?></div>
  <?php endif; ?>
  <?php if (!$stalls): ?>
    <div class="empty" style="padding:24px 10px"><h4>No market stall yet</h4><p>Register your stall in a market so customers can find your products.</p>
      <a class="btn btn-primary btn-sm" href="<?= e(url('vendor/markets.php')) ?>">Browse markets</a></div>
  <?php else: ?>
    <div class="table-wrap"><table class="table">
      <thead><tr><th>Market</th><th>Category</th><th>Location</th><th>Products</th><th>Registered</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($stalls as $s):
          [$pill, $label] = $s['status'] === 'suspended' ? ['pill-declined', 'Suspended by admin']
              : ($s['market_status'] !== 'active' ? ['pill-muted', 'Market ' . $s['market_status']] : ['pill-completed', 'Active']); ?>
        <tr<?= $joinedId === (int) $s['id'] ? ' class="ps-new"' : '' ?>>
          <td data-label="Market"><strong><?= e($s['name']) ?></strong></td>
          <td data-label="Category"><?= e(trim(($s['icon'] ?? '') . ' ' . $s['type_name'])) ?></td>
          <td data-label="Location" class="cell-muted"><?= e(implode(', ', array_filter([$s['area'], $s['city']]))) ?></td>
          <td data-label="Products"><?= (int) $s['products'] ?></td>
          <td data-label="Registered" class="cell-muted"><?= e(fmt_date($s['assigned_at'])) ?></td>
          <td data-label="Status"><span class="pill <?= $pill ?>"><?= e($label) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</article>

<form id="profileForm" class="grid grid-main" novalidate enctype="multipart/form-data">
  <article class="card">
    <section class="form-section">
      <h3 class="form-section-title"><svg><use href="#i-store"/></svg>Business Information</h3>
      <div class="form-grid">
        <div class="field">
          <label for="stall_name">Stall / Business Name <span class="req">*</span></label>
          <input class="input" id="stall_name" name="stall_name" value="<?= e($vendor['stall_name']) ?>" maxlength="150" required>
        </div>
        <div class="field">
          <label for="contact_person">Contact Person <span class="req">*</span></label>
          <input class="input" id="contact_person" name="contact_person" value="<?= e($vendor['contact_person']) ?>" maxlength="120" required autocomplete="name">
        </div>
        <div class="field">
          <label for="phone">Phone <span class="req">*</span></label>
          <input class="input" id="phone" name="phone" type="tel" value="<?= e($vendor['phone']) ?>" maxlength="30" required autocomplete="tel">
        </div>
        <div class="field">
          <label for="email">Email <span class="req">*</span></label>
          <input class="input" id="email" name="email" type="email" value="<?= e($vendor['email']) ?>" maxlength="190" required autocomplete="email">
        </div>
        <div class="field full">
          <label for="address">Address <span class="req">*</span></label>
          <input class="input" id="address" name="address" value="<?= e($vendor['address']) ?>" maxlength="255" required autocomplete="street-address">
          <span class="hint">Map position is set on the <a class="link" href="<?= e(url('vendor/location.php')) ?>">Location</a> page.</span>
        </div>
        <div class="field full">
          <label for="description">Description</label>
          <textarea class="textarea" id="description" name="description" maxlength="2000" placeholder="Tell customers what you grow and what makes your stall special."><?= e($vendor['description']) ?></textarea>
        </div>
      </div>
    </section>

    <section class="form-section">
      <h3 class="form-section-title"><svg><use href="#i-store"/></svg>Market Category</h3>
      <div class="field" data-field="category_ids">
        <span class="field-label">Which market category is your stall in? <span class="req">*</span></span>
        <div class="chip-group" role="group" aria-label="Market categories">
          <?php foreach ($allCategories as $c): ?>
            <label class="chip-check"><input type="checkbox" name="category_ids[]" value="<?= (int) $c['id'] ?>" <?= in_array((int) $c['id'], $myCategoryIds, true) ? 'checked' : '' ?>><span><svg><use href="#i-check"/></svg><?= e(trim(($c['icon'] ?? '') . ' ' . $c['name'])) ?></span></label>
          <?php endforeach; ?>
        </div>
        <span class="hint">Categories are created by MarketLink admins. Customers browsing a category will find your stall there.</span>
      </div>
    </section>

    <section class="form-section">
      <h3 class="form-section-title"><svg><use href="#i-calendar"/></svg>Market Information</h3>
      <div class="field" data-field="operating_days">
        <span class="field-label">Operating Days <span class="req">*</span></span>
        <div class="chip-group" role="group" aria-label="Operating days">
          <?php foreach (DAY_KEYS as $n => $key): ?>
            <label class="chip-check"><input type="checkbox" name="operating_days[]" value="<?= $key ?>" <?= in_array($n, $days, true) ? 'checked' : '' ?>><span><svg><use href="#i-check"/></svg><?= DAYS[$n] ?></span></label>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <section class="form-section">
      <h3 class="form-section-title"><svg><use href="#i-clock"/></svg>Pickup</h3>
      <div class="form-grid">
        <div class="field">
          <label for="pickup_start">Opening / Pickup Start Time <span class="req">*</span></label>
          <input class="input" type="time" id="pickup_start" name="pickup_start" value="<?= e(substr((string) $vendor['pickup_start'], 0, 5)) ?>" required>
        </div>
        <div class="field">
          <label for="pickup_end">Closing / Pickup End Time <span class="req">*</span></label>
          <input class="input" type="time" id="pickup_end" name="pickup_end" value="<?= e(substr((string) $vendor['pickup_end'], 0, 5)) ?>" required>
        </div>
      </div>
      <p class="hint" style="margin-top:10px">Default pickup window. Set per-day windows and order deadlines on <a class="link" href="<?= e(url('vendor/cutoff.php')) ?>">Cut-Off Settings</a>.</p>
    </section>

    <div class="form-foot">
      <a class="btn btn-ghost" href="<?= e(url('vendor/dashboard.php')) ?>">Cancel</a>
      <button type="submit" class="btn btn-primary"><svg><use href="#i-check"/></svg>Save Profile</button>
    </div>
  </article>

  <aside>
    <article class="card">
      <h3 class="form-section-title"><svg><use href="#i-image"/></svg>Profile Image</h3>
      <div class="field" data-field="image">
        <label class="image-drop large" data-image-drop style="flex-direction:column;text-align:center">
          <span class="image-preview"<?= $image ? ' style="background-image:url(\'' . e($image) . '\')"' : '' ?>><?= $image ? '' : '<svg><use href="#i-store"/></svg>' ?></span>
          <span><strong>Upload stall image</strong><small>JPG, PNG or WEBP, max 3 MB</small></span>
          <input type="file" name="image" accept="image/jpeg,image/png,image/webp">
        </label>
      </div>
    </article>
    <article class="card mt">
      <h3 class="card-title">Public preview</h3>
      <p class="card-sub" style="margin-bottom:14px">How your stall appears in customer search</p>
      <div class="low-item" style="border:0">
        <span class="thumb"<?= $image ? ' style="background-image:url(\'' . e($image) . '\')"' : '' ?>><?= $image ? '' : '<svg><use href="#i-store"/></svg>' ?></span>
        <div><strong><?= e($vendor['stall_name']) ?></strong><br><small class="muted"><?= e(days_short_label($days)) ?>, <?= e(fmt_time($vendor['pickup_start'])) ?> - <?= e(fmt_time($vendor['pickup_end'])) ?></small></div>
      </div>
      <a class="btn btn-ghost btn-block mt" href="<?= e(url('market.php?id=' . $vendor['id'])) ?>" target="_blank" rel="noopener"><svg><use href="#i-eye"/></svg>View public stall page</a>
    </article>
  </aside>
</form>

<?php vendor_layout_end(['scripts' => ['assets/js/vendor/profile.js']]);
