<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once APP_ROOT . '/includes/partials/vendor_layout.php';

require_once APP_ROOT . '/includes/services/markets.php';

$vendor = require_vendor();
$categories = categories_tree();          // top-level, grouped by market type, with subcategories
$myMarkets = vendor_markets((int) $vendor['id']);
$subcats = [];
foreach ($categories as $c) {
    $subcats[$c['id']] = $c['children'];
}
$catalog = [];
foreach (db_all('SELECT category_id, name FROM catalog_products ORDER BY sort_order, name') as $row) {
    $catalog[(int) $row['category_id']][] = $row['name'];
}
$categoryOptions = function () use ($categories): string {
    $html = '';
    $group = false;
    foreach ($categories as $c) {
        $label = $c['type_name'] ?? 'General';
        if ($group !== $label) {
            $html .= ($group !== false ? '</optgroup>' : '') . '<optgroup label="' . e($label) . '">';
            $group = $label;
        }
        $html .= '<option value="' . (int) $c['id'] . '" data-type="' . (int) $c['market_type_id'] . '">' . e($c['name']) . '</option>';
    }
    return $html . ($group !== false ? '</optgroup>' : '');
};

vendor_layout_start([
    'title' => 'My Products', 'active' => 'products',
    'heading' => 'My Products', 'subheading' => 'Add, price and manage the stock on your stall.',
]);
?>
<div class="page-head">
  <div><h2>Product catalogue</h2><p id="productCount">Loading products...</p></div>
  <div class="page-actions"><button class="btn btn-primary" data-new-product><svg><use href="#i-plus"/></svg>Add New Product</button></div>
</div>

<div class="toolbar" role="search">
  <div class="search-input">
    <svg><use href="#i-search"/></svg>
    <input class="input" type="search" id="fQ" placeholder="Search products..." aria-label="Search products" value="<?= e($_GET['q'] ?? '') ?>">
  </div>
  <select class="select" id="fCategory" aria-label="Filter by category">
    <option value="">All categories</option>
    <?= $categoryOptions() ?>
  </select>
  <select class="select" id="fStatus" aria-label="Filter by status">
    <option value="">Any status</option>
    <option value="available">Available</option>
    <option value="sold_out">Sold out</option>
  </select>
  <select class="select" id="fStock" aria-label="Filter by stock">
    <option value="">All stock levels</option>
    <option value="low" <?= ($_GET['stock'] ?? '') === 'low' ? 'selected' : '' ?>>Low stock only</option>
  </select>
  <select class="select" id="fSort" aria-label="Sort products">
    <option value="newest">Newest first</option>
    <option value="name">Name A-Z</option>
    <option value="price">Price high-low</option>
    <option value="stock">Lowest stock</option>
  </select>
  <div class="view-toggle" role="group" aria-label="View">
    <button type="button" class="active" data-view="grid" aria-label="Grid view" aria-pressed="true"><svg><use href="#i-grid"/></svg></button>
    <button type="button" data-view="table" aria-label="Table view" aria-pressed="false"><svg><use href="#i-menu"/></svg></button>
  </div>
</div>

<div id="productsRoot" aria-live="polite">
  <div class="product-grid">
    <?php for ($i = 0; $i < 4; $i++): ?><div class="skeleton sk-card"></div><?php endfor; ?>
  </div>
</div>

<!-- Add / Edit modal -->
<div class="modal" id="productModal" role="dialog" aria-modal="true" aria-labelledby="productModalTitle" hidden>
  <form class="modal-card" id="productForm" novalidate enctype="multipart/form-data"
        data-subcats="<?= e(json_encode($subcats, JSON_UNESCAPED_UNICODE)) ?>"
        data-catalog="<?= e(json_encode($catalog, JSON_UNESCAPED_UNICODE)) ?>">
    <div class="modal-head">
      <h3 id="productModalTitle">Add New Product</h3>
      <button type="button" class="icon-btn" data-modal-close aria-label="Close"><svg><use href="#i-close"/></svg></button>
    </div>
    <div class="modal-body">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="">
      <div class="form-grid">
        <div class="field full" data-field="image">
          <span class="field-label">Product Image</span>
          <label class="image-drop" data-image-drop>
            <span class="image-preview"><svg><use href="#i-image"/></svg></span>
            <span><strong>Upload Image</strong><small>JPG, PNG or WEBP, max 3 MB, drag &amp; drop supported</small></span>
            <input type="file" name="image" accept="image/jpeg,image/png,image/webp">
          </label>
        </div>
        <div class="field full">
          <label for="pMarket">Market<?= $myMarkets ? ' <span class="req">*</span>' : '' ?></label>
          <?php if ($myMarkets): ?>
            <select class="select" id="pMarket" name="market_id" required>
              <?php if (count($myMarkets) > 1): ?><option value="">Choose the market this product is sold in...</option><?php endif; ?>
              <?php foreach ($myMarkets as $m): ?><option value="<?= (int) $m['id'] ?>" data-type="<?= (int) $m['market_type_id'] ?>"><?= e($m['name'] . ', ' . $m['type_name']) ?></option><?php endforeach; ?>
            </select>
          <?php else: ?>
            <input type="hidden" name="market_id" value="">
            <p class="hint">You haven't been assigned to a market yet. Your product will be saved to your stall - <a class="link" href="<?= e(url('vendor/markets.php')) ?>">join a market</a> so customers can find it.</p>
          <?php endif; ?>
        </div>
        <div class="field full">
          <label for="pName">Product Name <span class="req">*</span></label>
          <input class="input" id="pName" name="name" maxlength="150" required placeholder="e.g. Organic Tomatoes" list="pCatalog" autocomplete="off">
          <datalist id="pCatalog"></datalist>
        </div>
        <div class="field">
          <label for="pCategory">Category <span class="req">*</span></label>
          <select class="select" id="pCategory" name="category_id" required>
            <option value="">Choose...</option>
            <?= $categoryOptions() ?>
          </select>
        </div>
        <div class="field" id="pSubField" hidden>
          <label for="pSubcategory">Subcategory</label>
          <select class="select" id="pSubcategory" name="subcategory_id">
            <option value="">None</option>
          </select>
        </div>
        <div class="field">
          <label for="pUnit">Unit <span class="req">*</span></label>
          <select class="select" id="pUnit" name="unit" required>
            <?php foreach (PRODUCT_UNITS as $u): ?><option value="<?= e($u) ?>"><?= e($u) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="pPrice">Price <span class="req">*</span></label>
          <div class="input-group"><span class="addon">Rs.</span><input class="input" id="pPrice" name="price" type="number" min="1" step="0.01" inputmode="decimal" required></div>
        </div>
        <div class="field">
          <label for="pQty">Available Quantity <span class="req">*</span></label>
          <input class="input" id="pQty" name="quantity" type="number" min="0" step="0.01" inputmode="decimal" required>
        </div>
        <div class="field">
          <label for="pHarvest">Harvest Date <span class="muted" style="font-weight:400">(used for the freshness score customers see)</span></label>
          <input class="input" id="pHarvest" name="harvest_date" type="date" max="<?= e(date('Y-m-d')) ?>">
        </div>
        <div class="field full">
          <label for="pDesc">Description</label>
          <textarea class="textarea" id="pDesc" name="description" maxlength="2000" placeholder="Freshness, origin, how it's grown..."></textarea>
        </div>
        <div class="field full">
          <span class="field-label">Availability</span>
          <div class="radio-cards">
            <label class="radio-card"><input type="radio" name="status" value="available" checked><span>Available</span></label>
            <label class="radio-card"><input type="radio" name="status" value="sold_out"><span>Sold Out</span></label>
          </div>
        </div>
      </div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
      <button type="submit" class="btn btn-primary"><svg><use href="#i-check"/></svg>Save Product</button>
    </div>
  </form>
</div>

<?php vendor_layout_end(['scripts' => ['assets/js/vendor/products.js']]);
