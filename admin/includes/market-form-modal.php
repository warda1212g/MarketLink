<?php
/**
 * Add / edit market modal. The including page provides $marketTypes (market_types_all()).
 * Filled and submitted by assets/js/admin-market-form.js.
 */
?>
<div class="modal fade adm-modal" id="marketModal" tabindex="-1" aria-labelledby="marketModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
    <form class="modal-content" id="marketForm" novalidate enctype="multipart/form-data">
      <div class="modal-header">
        <div>
          <p class="kicker" style="margin:0 0 4px">MARKET</p>
          <h2 class="adm-h2" id="marketModalTitle">Add New Market</h2>
        </div>
        <button type="button" class="adm-icon-btn" data-bs-dismiss="modal" aria-label="Close"><svg viewBox="0 0 24 24"><use href="#icon-close"/></svg></button>
      </div>

      <div class="modal-body">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="">
        <input type="hidden" name="remove_image" value="">
        <div class="adm-form-error" data-error-for="_form" role="alert"></div>

        <div class="row g-3">
          <div class="col-12">
            <div class="mk-image-field">
              <div class="mk-image-preview" id="mkImagePreview"><svg viewBox="0 0 24 24"><use href="#icon-image"/></svg></div>
              <div>
                <label class="adm-label" for="mkImage">Market Image / Icon</label>
                <input class="form-control" type="file" id="mkImage" name="image" accept="image/jpeg,image/png,image/webp">
                <div class="form-text">JPG, PNG or WEBP, up to 3 MB. Without an image the market type icon is used.</div>
                <button type="button" class="adm-link-btn" id="mkRemoveImage" hidden>Remove current image</button>
                <div class="invalid-feedback d-block" data-error-for="image"></div>
              </div>
            </div>
          </div>

          <div class="col-md-7">
            <label class="adm-label" for="mkName">Market Name <span class="req">*</span></label>
            <input class="form-control" id="mkName" name="name" maxlength="150" required placeholder="e.g. Karachi Fruit Market">
            <div class="invalid-feedback" data-error-for="name"></div>
          </div>
          <div class="col-md-5">
            <label class="adm-label" for="mkType">Market Type <span class="req">*</span></label>
            <select class="form-select" id="mkType" name="market_type_id" required>
              <option value="">Choose type...</option>
              <?php foreach ($marketTypes as $t): ?>
                <option value="<?= (int) $t['id'] ?>"<?= $t['is_active'] ? '' : ' data-inactive="1"' ?>><?= e($t['name']) ?><?= $t['is_active'] ? '' : ' (inactive)' ?></option>
              <?php endforeach; ?>
            </select>
            <div class="invalid-feedback" data-error-for="market_type_id"></div>
          </div>

          <div class="col-12">
            <label class="adm-label" for="mkDesc">Description</label>
            <textarea class="form-control" id="mkDesc" name="description" rows="2" maxlength="2000" placeholder="Fresh locally grown fruits from verified farmers."></textarea>
          </div>

          <div class="col-md-4">
            <label class="adm-label" for="mkCity">City / Location <span class="req">*</span></label>
            <input class="form-control" id="mkCity" name="city" maxlength="80" required placeholder="Karachi" list="mkCityList">
            <datalist id="mkCityList"></datalist>
            <div class="invalid-feedback" data-error-for="city"></div>
          </div>
          <div class="col-md-4">
            <label class="adm-label" for="mkArea">Area</label>
            <input class="form-control" id="mkArea" name="area" maxlength="120" placeholder="Gulshan">
            <div class="invalid-feedback" data-error-for="area"></div>
          </div>
          <div class="col-md-4">
            <label class="adm-label" for="mkAddress">Address</label>
            <input class="form-control" id="mkAddress" name="address" maxlength="255" placeholder="Block 13-D, Main University Road">
          </div>

          <div class="col-12">
            <div class="mk-loc-head">
              <span class="adm-label" style="margin:0">Map Location <span class="text-muted">(optional)</span></span>
              <div class="mk-loc-tools">
                <button type="button" class="adm-link-btn" id="mkClearLoc" hidden>Clear pin</button>
              </div>
            </div>
            <div class="mk-loc-search">
              <label class="mk-search"><svg viewBox="0 0 24 24"><use href="#icon-search"/></svg><span class="visually-hidden">Search a place</span>
                <input type="search" id="mkPlace" placeholder="Search a place, e.g. Empress Market Karachi" autocomplete="off"></label>
              <button type="button" class="adm-btn-outline" id="mkPlaceBtn">Find</button>
            </div>
            <div class="mk-place-results" id="mkPlaceResults" hidden></div>
            <div id="mkMap" class="mk-map"
                 data-tiles="<?= e(config('map.tile_url')) ?>" data-attribution="<?= e(config('map.attribution')) ?>"
                 data-lat="<?= e(config('map.default_lat')) ?>" data-lng="<?= e(config('map.default_lng')) ?>" data-zoom="<?= (int) config('map.default_zoom') ?>"
                 aria-label="Market location map. Click to place the pin."></div>
            <input type="hidden" name="latitude"><input type="hidden" name="longitude">
            <div class="form-text" id="mkLocText">Optional: search a place or click the map to pin the market. The pin can be dragged.</div>
            <div class="invalid-feedback d-block" data-error-for="latitude"></div>
          </div>

          <div class="col-6 col-md-3">
            <label class="adm-label" for="mkOpen">Opening Time <span class="req">*</span></label>
            <input class="form-control" type="time" id="mkOpen" name="opening_time" required value="08:00">
            <div class="invalid-feedback" data-error-for="opening_time"></div>
          </div>
          <div class="col-6 col-md-3">
            <label class="adm-label" for="mkClose">Closing Time <span class="req">*</span></label>
            <input class="form-control" type="time" id="mkClose" name="closing_time" required value="20:00">
            <div class="invalid-feedback" data-error-for="closing_time"></div>
          </div>
          <div class="col-md-6">
            <span class="adm-label">Status <span class="req">*</span></span>
            <div class="mk-radio-row" role="radiogroup" aria-label="Status">
              <?php foreach (MARKET_STATUSES as $s): ?>
                <label class="mk-chip"><input type="radio" name="status" value="<?= e($s) ?>"<?= $s === 'active' ? ' checked' : '' ?>><span><?= e(ucfirst($s)) ?></span></label>
              <?php endforeach; ?>
            </div>
            <div class="invalid-feedback d-block" data-error-for="status"></div>
          </div>

          <div class="col-12">
            <span class="adm-label">Operating Days <span class="req">*</span></span>
            <div class="mk-radio-row" role="group" aria-label="Operating days">
              <?php foreach (MARKET_DAYS as $k => $label): ?>
                <label class="mk-chip"><input type="checkbox" name="operating_days[]" value="<?= e($k) ?>"<?= $k !== 'sun' ? ' checked' : '' ?>><span title="<?= e($label) ?>"><?= e(substr($label, 0, 3)) ?></span></label>
              <?php endforeach; ?>
            </div>
            <div class="invalid-feedback d-block" data-error-for="operating_days"></div>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="adm-btn-outline" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="adm-btn" id="mkSubmit"><svg viewBox="0 0 24 24"><use href="#icon-check"/></svg> <span>Save Market</span></button>
      </div>
    </form>
  </div>
</div>
