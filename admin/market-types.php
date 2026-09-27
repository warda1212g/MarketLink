<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/markets.php';
$user = require_admin_page();

$activeNav    = 'types';
$pageTitle    = 'Market Categories';
$pageSubtitle = 'Market categories, product categories and subcategories';
$pageScripts  = ['../assets/js/admin-market-types.js'];

include __DIR__ . '/includes/header.php';
?>
<section class="adm-page-head">
  <div>
    <p class="kicker">CATALOGUE STRUCTURE</p>
    <h1 class="adm-h1">Market <em>Categories</em></h1>
    <p class="adm-lede">Create market categories such as Fruit Market or Livestock Market. Vendors pick a category when they set up their stall, and customers browse stalls by category. Product categories and subcategories sit underneath each market.</p>
  </div>
  <button type="button" class="adm-btn" id="addTypeBtn"><svg viewBox="0 0 24 24"><use href="#icon-plus"/></svg> Add Market Category</button>
</section>

<div class="adm-error" id="mtError" hidden></div>
<div class="mt-list" id="typeList"><?php for ($i = 0; $i < 5; $i++): ?><div class="adm-card mt-type"><span class="adm-skel adm-skel-row"></span></div><?php endfor; ?></div>

<!-- Market type modal -->
<div class="modal fade adm-modal" id="typeModal" tabindex="-1" aria-labelledby="typeModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-fullscreen-sm-down">
    <form class="modal-content" id="typeForm" novalidate>
      <div class="modal-header">
        <h2 class="adm-h2" id="typeModalTitle">Add Market Category</h2>
        <button type="button" class="adm-icon-btn" data-bs-dismiss="modal" aria-label="Close"><svg viewBox="0 0 24 24"><use href="#icon-close"/></svg></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="action" value="type_save"><input type="hidden" name="id">
        <div class="row g-3">
          <div class="col-9">
            <label class="adm-label" for="tName">Category name <span class="req">*</span></label>
            <input class="form-control" id="tName" name="name" maxlength="80" required placeholder="e.g. Fruit Market">
            <div class="invalid-feedback" data-error-for="name"></div>
          </div>
          <div class="col-3">
            <label class="adm-label" for="tIcon">Icon</label>
            <input class="form-control text-center" id="tIcon" name="icon" maxlength="16" placeholder="Optional">
          </div>
          <div class="col-8">
            <label class="adm-label" for="tLabel">Vendor label <span class="text-muted">(optional)</span></label>
            <input class="form-control" id="tLabel" name="farmer_label" maxlength="80" placeholder="e.g. Fruit Farmer - defaults from the name">
            <div class="invalid-feedback" data-error-for="farmer_label"></div>
          </div>
          <div class="col-4">
            <label class="adm-label" for="tSort">Sort order</label>
            <input class="form-control" id="tSort" name="sort_order" type="number" min="0" max="9999" value="0">
            <div class="invalid-feedback" data-error-for="sort_order"></div>
          </div>
          <div class="col-12">
            <label class="adm-label" for="tDesc">Description</label>
            <textarea class="form-control" id="tDesc" name="description" rows="2" maxlength="500" placeholder="e.g. Market for fruit farmers and fresh fruit vendors."></textarea>
          </div>
          <div class="col-12">
            <label class="adm-label" for="tImage">Image <span class="text-muted">(optional - JPG, PNG or WEBP, max 3 MB)</span></label>
            <input class="form-control" id="tImage" name="image" type="file" accept="image/jpeg,image/png,image/webp">
            <div class="invalid-feedback" data-error-for="image"></div>
            <div class="form-check mt-2" id="tImageRemoveWrap" hidden><input class="form-check-input" type="checkbox" id="tImageRemove" name="remove_image" value="1"><label class="form-check-label" for="tImageRemove">Remove current image</label></div>
          </div>
          <div class="col-12">
            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="tActive" name="is_active" value="1" checked><label class="form-check-label" for="tActive">Active (visible to customers and vendors)</label></div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="adm-btn-outline" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="adm-btn"><svg viewBox="0 0 24 24"><use href="#icon-check"/></svg> Save</button>
      </div>
    </form>
  </div>
</div>

<!-- Category modal -->
<div class="modal fade adm-modal" id="catModal" tabindex="-1" aria-labelledby="catModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-fullscreen-sm-down">
    <form class="modal-content" id="catForm" novalidate>
      <div class="modal-header">
        <div><p class="kicker" style="margin:0 0 4px" id="catModalKicker"></p><h2 class="adm-h2" id="catModalTitle">Add Category</h2></div>
        <button type="button" class="adm-icon-btn" data-bs-dismiss="modal" aria-label="Close"><svg viewBox="0 0 24 24"><use href="#icon-close"/></svg></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="action" value="category_save"><input type="hidden" name="id"><input type="hidden" name="parent_id"><input type="hidden" name="market_type_id">
        <label class="adm-label" for="cName">Name <span class="req">*</span></label>
        <input class="form-control mb-3" id="cName" name="name" maxlength="80" required>
        <div class="invalid-feedback" data-error-for="name"></div>
        <label class="adm-label" for="cDesc">Description</label>
        <input class="form-control" id="cDesc" name="description" maxlength="255">
      </div>
      <div class="modal-footer">
        <button type="button" class="adm-btn-outline" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="adm-btn"><svg viewBox="0 0 24 24"><use href="#icon-check"/></svg> Save</button>
      </div>
    </form>
  </div>
</div>

<!-- Category detail: vendors/stalls and products -->
<div class="modal fade adm-modal" id="typeView" tabindex="-1" aria-labelledby="typeViewTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-fullscreen-sm-down modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><h2 class="adm-h2" id="typeViewTitle">Market category</h2>
        <button type="button" class="adm-icon-btn" data-bs-dismiss="modal" aria-label="Close"><svg viewBox="0 0 24 24"><use href="#icon-close"/></svg></button></div>
      <div class="modal-body" id="typeViewBody"></div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
