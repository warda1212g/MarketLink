<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = require_admin_page();

$activeNav    = 'farmers';
$pageTitle    = 'Manage Users';
$pageSubtitle = 'Approve customers and vendors';
$pageScripts  = ['../assets/js/admin-farmers.js'];

$STATUSES = ['pending', 'approved', 'suspended', 'rejected'];
$ROLES = ['vendor', 'customer'];
$status = in_array($_GET['status'] ?? '', $STATUSES, true) ? $_GET['status'] : '';
$role = in_array($_GET['role'] ?? '', $ROLES, true) ? $_GET['role'] : '';
$highlightId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: null;

// One row per customer / vendor account. Approval lives on users.approval_status.
$sql = "SELECT u.id, u.name, u.email, u.role, u.phone, u.approval_status, u.created_at,
               (u.google_id IS NOT NULL) AS via_google,
               v.id AS vendor_id, v.stall_name, v.location_address AS market,
               (SELECT GROUP_CONCAT(m.name ORDER BY m.name SEPARATOR '|') FROM farmer_markets fm JOIN markets m ON m.id = fm.market_id WHERE fm.vendor_id = v.id AND fm.status = 'active') AS market_names,
               (SELECT GROUP_CONCAT(t.farmer_label ORDER BY t.sort_order SEPARATOR '|') FROM farmer_categories fc JOIN market_types t ON t.id = fc.market_type_id WHERE fc.vendor_id = v.id) AS farmer_cats,
               (SELECT COUNT(*) FROM products p WHERE p.vendor_id = v.id AND p.deleted_at IS NULL) AS products,
               CASE WHEN u.role = 'vendor'
                    THEN (SELECT COUNT(*) FROM orders o WHERE o.vendor_id = v.id)
                    ELSE (SELECT COUNT(*) FROM orders o WHERE o.customer_id = u.id) END AS orders
          FROM users u
          LEFT JOIN vendors v ON v.user_id = u.id
         WHERE u.role IN ('vendor','customer')";
$params = [];
if ($status) { $sql .= ' AND u.approval_status = ?'; $params[] = $status; }
if ($role)   { $sql .= ' AND u.role = ?';            $params[] = $role; }
$sql .= " ORDER BY (u.approval_status = 'pending') DESC, u.created_at DESC, u.id DESC";
$rows = db_all($sql, $params);
$pendingCount = (int) db_value("SELECT COUNT(*) FROM users WHERE role IN ('vendor','customer') AND approval_status = 'pending'");

$link = function (array $change) use ($status, $role): string {
    $q = array_filter(array_merge(['status' => $status, 'role' => $role], $change), fn($v) => $v !== '');
    return 'farmers.php' . ($q ? '?' . http_build_query($q) : '');
};

include __DIR__ . '/includes/header.php';
?>

<section class="adm-page-head">
  <div>
    <p class="kicker">ACCOUNTS</p>
    <h1 class="adm-h1">Manage <em>Users</em></h1>
    <p class="adm-lede">Customers are active as soon as they sign up (you can still suspend them). Vendors can sign in right away, but their stall and dashboard stay locked until you approve them.<?= $pendingCount ? ' <strong>' . $pendingCount . ' waiting.</strong>' : '' ?></p>
  </div>
  <div style="display:flex;flex-direction:column;gap:8px;align-items:flex-end">
    <div class="adm-seg" role="group" aria-label="Filter by role">
      <a href="<?= e($link(['role' => ''])) ?>" class="<?= $role === '' ? 'active' : '' ?>">Everyone</a>
      <a href="<?= e($link(['role' => 'vendor'])) ?>" class="<?= $role === 'vendor' ? 'active' : '' ?>">Vendors</a>
      <a href="<?= e($link(['role' => 'customer'])) ?>" class="<?= $role === 'customer' ? 'active' : '' ?>">Customers</a>
    </div>
    <div class="adm-seg" role="group" aria-label="Filter by status">
      <a href="<?= e($link(['status' => ''])) ?>" class="<?= $status === '' ? 'active' : '' ?>">All</a>
      <?php foreach ($STATUSES as $s): ?>
        <a href="<?= e($link(['status' => $s])) ?>" class="<?= $status === $s ? 'active' : '' ?>"><?= e(ucfirst($s)) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="adm-card adm-panel">
  <div class="table-responsive">
    <table class="table adm-table">
      <thead><tr><th>Name</th><th>Type</th><th>Stall / Business</th><th>Markets &amp; Categories</th><th>Email</th><th class="text-end">Products</th><th class="text-end">Orders</th><th>Registered</th><th>Approval</th><th class="text-end">Action</th></tr></thead>
      <tbody id="farmersBody">
      <?php if (!$rows): ?>
        <tr><td colspan="10"><div class="adm-empty-inline"><strong>No matching accounts</strong>New customers and vendors appear here as soon as they sign up.</div></td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr id="farmer-<?= (int) $r['id'] ?>"<?= $highlightId === (int) $r['id'] ? ' class="is-highlight"' : '' ?>>
          <td><div class="adm-person"><span class="adm-avatar"><?= e(mb_strtoupper(mb_substr($r['name'], 0, 1))) ?></span><?= e($r['name']) ?></div></td>
          <td><?= e(ucfirst($r['role'])) ?><?php if ($r['via_google']): ?><div class="muted">Google</div><?php endif; ?></td>
          <td><?= $r['role'] === 'vendor' ? e($r['stall_name'] ?? '-') : '<span class="muted">-</span>' ?><?php if ($r['phone']): ?><div class="muted"><?= e($r['phone']) ?></div><?php endif; ?></td>
          <td>
            <?php if ($r['role'] !== 'vendor'): ?><span class="muted">-</span>
            <?php elseif (!$r['market_names'] && !$r['farmer_cats']): ?><span class="muted">Not assigned</span>
            <?php else: ?><div class="fm-tags">
              <?php foreach (array_filter(explode('|', (string) $r['farmer_cats'])) as $c): ?><span class="fm-tag cat"><?= e($c) ?></span><?php endforeach; ?>
              <?php foreach (array_filter(explode('|', (string) $r['market_names'])) as $m): ?><span class="fm-tag"><?= e($m) ?></span><?php endforeach; ?>
            </div><?php endif; ?>
          </td>
          <td class="muted"><?= e($r['email']) ?></td>
          <td class="text-end num"><?= $r['role'] === 'vendor' ? (int) $r['products'] : '-' ?></td>
          <td class="text-end num"><?= (int) $r['orders'] ?></td>
          <td class="muted"><?= e(date('M j, Y', strtotime($r['created_at']))) ?></td>
          <td><span class="adm-badge <?= e($r['approval_status']) ?>"><?= e(ucfirst($r['approval_status'])) ?></span></td>
          <td class="text-end"><div class="adm-actions">
            <?php if ($r['approval_status'] !== 'approved'): ?>
              <button type="button" class="adm-act approve" data-farmer="<?= (int) $r['id'] ?>" data-action="approve"><svg viewBox="0 0 24 24"><use href="#icon-check"/></svg>Approve</button>
            <?php endif; ?>
            <?php if ($r['approval_status'] === 'pending'): ?>
              <button type="button" class="adm-act suspend" data-farmer="<?= (int) $r['id'] ?>" data-action="reject"><svg viewBox="0 0 24 24"><use href="#icon-close"/></svg>Reject</button>
            <?php elseif ($r['approval_status'] === 'approved'): ?>
              <button type="button" class="adm-act suspend" data-farmer="<?= (int) $r['id'] ?>" data-action="suspend"><svg viewBox="0 0 24 24"><use href="#icon-pause"/></svg>Suspend</button>
            <?php endif; ?>
            <?php if ($r['role'] === 'vendor' && $r['vendor_id']): ?>
              <button type="button" class="adm-act" data-manage="<?= (int) $r['vendor_id'] ?>"><svg viewBox="0 0 24 24"><use href="#icon-stall"/></svg>Markets</button>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<!-- Farmer markets & categories -->
<div class="modal fade adm-modal" id="manageModal" tabindex="-1" aria-labelledby="manageTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
    <form class="modal-content" id="manageForm">
      <div class="modal-header">
        <div><p class="kicker" style="margin:0 0 4px">VENDOR MARKETS</p><h2 class="adm-h2" id="manageTitle">Markets &amp; categories</h2></div>
        <button type="button" class="adm-icon-btn" data-bs-dismiss="modal" aria-label="Close"><svg viewBox="0 0 24 24"><use href="#icon-close"/></svg></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="vendor_id">
        <div class="adm-form-error" data-error-for="_form" role="alert"></div>
        <span class="adm-label">Farmer categories</span>
        <div class="fm-check-grid" id="manageTypes"></div>
        <span class="adm-label">Assigned markets</span>
        <p class="adm-panel-sub mb-2">Assigning a market also adds its farmer category. Only approved vendors can be assigned.</p>
        <div class="fm-check-grid" id="manageMarkets"></div>
        <div class="invalid-feedback d-block" data-error-for="market_ids"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="adm-btn-outline" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="adm-btn"><svg viewBox="0 0 24 24"><use href="#icon-check"/></svg> Save</button>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
