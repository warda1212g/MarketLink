/* My Products page */
(function () {
  "use strict";

  var root = document.getElementById("productsRoot");
  var countEl = document.getElementById("productCount");
  var modal = document.getElementById("productModal");
  var form = document.getElementById("productForm");
  var el = form.elements;
  var f = { q: document.getElementById("fQ"), category: document.getElementById("fCategory"), status: document.getElementById("fStatus"), stock: document.getElementById("fStock"), sort: document.getElementById("fSort") };
  var view = localGet("ml.products.view") || "grid";
  var products = [];
  var threshold = 5;

  function localGet(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function localSet(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }

  function thumb(p, cls) {
    return p.image
      ? '<span class="' + cls + '" style="background-image:url(\'' + ML.esc(p.image) + '\')"></span>'
      : '<span class="' + cls + '">' + ML.icon("i-sprout") + "</span>";
  }
  function statusPill(p) {
    var mod = p.moderation && p.moderation !== "active"
      ? ' <span class="pill pill-' + p.moderation + '" title="Set by a MarketLink admin - hidden from customers">' + (p.moderation === "inactive" ? "Deactivated by admin" : p.moderation === "flagged" ? "Flagged by admin" : "Removed by admin") + "</span>" : "";
    return '<span class="pill pill-' + p.status + '">' + (p.status === "available" ? "Available" : "Sold out") + "</span>" + mod;
  }
  function stockLabel(p) {
    var low = p.quantity <= threshold;
    return '<span class="p-stock' + (low ? " low" : "") + '">' + (p.quantity <= 0 ? "Out of stock" : ML.esc(p.quantity_label + " " + p.unit + (low ? ", low" : ""))) + "</span>";
  }
  function toggleBtn(p) {
    return p.status === "available"
      ? '<button class="btn btn-ghost btn-sm" data-act="status" data-status="sold_out" data-id="' + p.id + '" title="Mark sold out">' + ML.icon("i-x") + "Sold out</button>"
      : '<button class="btn btn-soft btn-sm" data-act="status" data-status="available" data-id="' + p.id + '" title="Mark available">' + ML.icon("i-check") + "Available</button>";
  }
  function qtyControl(p) {
    return '<span class="qty-inline"><button type="button" data-act="dec" data-id="' + p.id + '" aria-label="Decrease">−</button>' +
      '<input type="number" min="0" step="0.5" value="' + p.quantity + '" data-qty="' + p.id + '" aria-label="Quantity for ' + ML.esc(p.name) + '">' +
      '<button type="button" data-act="inc" data-id="' + p.id + '" aria-label="Increase">+</button></span>';
  }

  function render() {
    countEl.textContent = products.length + " product" + (products.length === 1 ? "" : "s") + " shown";
    if (!products.length) {
      var filtered = f.q.value || f.category.value || f.status.value || f.stock.value;
      root.innerHTML = '<div class="card"><div class="empty"><div class="empty-icon">' + ML.icon("i-box") + "</div><h4>No products found</h4><p>" +
        (filtered ? "Try a different search or clear the filters." : "Add your first product to start receiving pre-orders.") + "</p>" +
        (filtered ? "" : '<button class="btn btn-primary" data-new-product>' + ML.icon("i-plus") + "Add New Product</button>") + "</div></div>";
      return;
    }

    if (view === "grid") {
      root.innerHTML = '<div class="product-grid">' + products.map(function (p) {
        return '<article class="p-card fade-in">' +
          '<div class="p-img"' + (p.image ? ' style="background-image:url(\'' + ML.esc(p.image) + '\')"' : "") + ">" + (p.image ? "" : ML.icon("i-sprout")) + statusPill(p) + "</div>" +
          '<div class="p-body"><span class="p-cat">' + ML.esc((p.category || "Uncategorised") + (p.market ? ", " + p.market : "")) + '</span><h3 class="p-name">' + ML.esc(p.name) + "</h3>" +
          '<div class="p-row"><span class="p-price">' + ML.esc(p.price_label) + " <small>/ " + ML.esc(p.unit) + "</small></span>" + stockLabel(p) + "</div>" +
          '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-top:10px">' + qtyControl(p) + toggleBtn(p) + "</div></div>" +
          '<div class="p-actions"><button class="btn btn-ghost btn-sm" data-act="edit" data-id="' + p.id + '">' + ML.icon("i-edit") + "Edit</button>" +
          '<button class="btn btn-danger-ghost btn-sm" data-act="delete" data-id="' + p.id + '">' + ML.icon("i-trash") + "Delete</button></div></article>";
      }).join("") + "</div>";
    } else {
      root.innerHTML = '<div class="card card-flush"><div class="table-wrap"><table class="table table-cards"><thead><tr><th>Product</th><th>Category</th><th class="num">Price</th><th>Unit</th><th>Quantity</th><th>Status</th><th class="num">Actions</th></tr></thead><tbody>' +
        products.map(function (p) {
          return "<tr>" +
            '<td data-label="Product"><div class="product-cell">' + thumb(p, "thumb") + '<span class="cell-strong">' + ML.esc(p.name) + "</span></div></td>" +
            '<td data-label="Category" class="cell-muted">' + ML.esc(p.category || "-") + "</td>" +
            '<td data-label="Price" class="num cell-strong">' + ML.esc(p.price_label) + "</td>" +
            '<td data-label="Unit">' + ML.esc(p.unit) + "</td>" +
            '<td data-label="Quantity">' + qtyControl(p) + "</td>" +
            '<td data-label="Status">' + statusPill(p) + "</td>" +
            '<td data-label="Actions"><div class="row-actions">' + toggleBtn(p) +
            '<button class="btn btn-ghost btn-sm" data-act="edit" data-id="' + p.id + '">' + ML.icon("i-edit") + "Edit</button>" +
            '<button class="btn btn-danger-ghost btn-sm" data-act="delete" data-id="' + p.id + '" aria-label="Delete ' + ML.esc(p.name) + '">' + ML.icon("i-trash") + "</button></div></td></tr>";
        }).join("") + "</tbody></table></div></div>";
    }
  }

  function load() {
    return ML.api("api/vendor/products.php", { query: { q: f.q.value.trim(), category: f.category.value, status: f.status.value, stock: f.stock.value, sort: f.sort.value } })
      .then(function (res) { products = res.products; threshold = res.threshold; render(); })
      .catch(function (err) { root.innerHTML = '<div class="alert alert-error">' + ML.esc(err.message) + "</div>"; });
  }

  function replace(p) {
    products = products.map(function (x) { return x.id === p.id ? p : x; });
    render();
  }

  /* market -> category -> subcategory */
  var subcats = JSON.parse(form.dataset.subcats || "{}");
  var catalog = JSON.parse(form.dataset.catalog || "{}");
  var subField = document.getElementById("pSubField");
  var marketSel = document.getElementById("pMarket");

  function syncCategory(subId) {
    var list = subcats[el.category_id.value] || [];
    el.subcategory_id.innerHTML = '<option value="">None</option>' + list.map(function (s) {
      return '<option value="' + s.id + '">' + ML.esc(s.name) + "</option>";
    }).join("");
    el.subcategory_id.value = subId || "";
    subField.hidden = !list.length;
    document.getElementById("pCatalog").innerHTML = (catalog[el.category_id.value] || []).map(function (n) {
      return '<option value="' + ML.esc(n) + '">';
    }).join("");
  }
  el.category_id.addEventListener("change", function () { syncCategory(""); });

  // select the first category of the market if none is picked
  function syncMarket() {
    if (!marketSel || el.category_id.value) return;
    var opt = marketSel.selectedOptions[0];
    var type = opt && opt.dataset.type;
    var match = type && el.category_id.querySelector('option[data-type="' + type + '"]');
    if (match) { el.category_id.value = match.value; syncCategory(""); }
  }
  if (marketSel) marketSel.addEventListener("change", syncMarket);

  /* modal */
  function openForm(p) {
    form.reset();
    ML.clearErrors(form);
    syncCategory("");
    el.id.value = p ? p.id : "";
    document.getElementById("productModalTitle").textContent = p ? "Edit Product" : "Add New Product";
    var prev = form.querySelector(".image-preview");
    prev.style.backgroundImage = p && p.image ? "url('" + p.image + "')" : "";
    prev.innerHTML = p && p.image ? "" : ML.icon("i-image");
    if (p) {
      el.name.value = p.name;
      el.category_id.value = p.category_id || "";
      syncCategory(p.subcategory_id);
      // a product whose market the vendor has since left must be moved to a current one
      if (marketSel) marketSel.value = p.market_id && marketSel.querySelector('option[value="' + p.market_id + '"]') ? p.market_id : (marketSel.options.length === 1 ? marketSel.options[0].value : "");
      el.unit.value = p.unit;
      el.price.value = p.price;
      el.quantity.value = p.quantity;
      el.description.value = p.description || "";
      el.harvest_date.value = p.harvest_date || "";
      form.querySelector('input[name="status"][value="' + p.status + '"]').checked = true;
    }
    ML.openModal(modal);
  }

  function validate() {
    var e = {};
    if (el.name.value.trim().length < 2) e.name = "Enter a product name.";
    if (!el.category_id.value) e.category_id = "Choose a category.";
    if (marketSel && !marketSel.value) e.market_id = "Choose a market.";
    if (!(Number(el.price.value) > 0)) e.price = "Enter a price greater than 0.";
    if (el.quantity.value === "" || Number(el.quantity.value) < 0) e.quantity = "Enter a quantity of 0 or more.";
    return e;
  }

  form.addEventListener("submit", function (ev) {
    ev.preventDefault();
    var errs = validate();
    if (Object.keys(errs).length) { ML.showErrors(form, errs); return; }
    var btn = form.querySelector('[type="submit"]');
    ML.setLoading(btn, true);
    ML.api("api/vendor/products.php", { body: new FormData(form) })
      .then(function (res) {
        ML.toast(res.message);
        ML.closeModal(modal);
        return load();
      })
      .catch(function (err) { ML.showErrors(form, err.errors); ML.toast(err.message, "error"); })
      .then(function () { ML.setLoading(btn, false); });
  });

  /* actions */
  document.addEventListener("click", function (e) {
    if (e.target.closest("[data-new-product]")) { openForm(null); return; }
    var b = e.target.closest("[data-act]");
    if (!b || !root.contains(b)) return;
    var id = Number(b.getAttribute("data-id"));
    var p = products.find(function (x) { return x.id === id; });
    var act = b.getAttribute("data-act");

    if (act === "edit") openForm(p);
    if (act === "delete") {
      ML.confirm({ title: "Delete " + p.name + "?", text: "The product is removed from your stall and weekly templates. Past orders keep their history.", confirmText: "Delete product", danger: true })
        .then(function (ok) {
          if (!ok) return;
          ML.api("api/vendor/products.php", { body: { action: "delete", id: id } })
            .then(function (res) { ML.toast(res.message); products = products.filter(function (x) { return x.id !== id; }); render(); })
            .catch(function (err) { ML.toast(err.message, "error"); });
        });
    }
    if (act === "status") {
      ML.setLoading(b, true);
      ML.api("api/vendor/products.php", { body: { action: "status", id: id, status: b.getAttribute("data-status") } })
        .then(function (res) { ML.toast(res.message); replace(res.product); })
        .catch(function (err) { ML.toast(err.message, "error"); ML.setLoading(b, false); });
    }
    if (act === "inc" || act === "dec") {
      var input = root.querySelector('[data-qty="' + id + '"]');
      input.value = Math.max(0, Number(input.value) + (act === "inc" ? 1 : -1));
      saveQty(id, input.value);
    }
  });

  var saveQty = ML.debounce(function (id, value) {
    ML.api("api/vendor/products.php", { body: { action: "quantity", id: id, quantity: value } })
      .then(function (res) { ML.toast(res.message); replace(res.product); })
      .catch(function (err) { ML.toast(err.message, "error"); load(); });
  }, 600);

  root.addEventListener("change", function (e) {
    if (e.target.matches("[data-qty]")) saveQty(Number(e.target.getAttribute("data-qty")), e.target.value);
  });

  /* filters & view */
  f.q.addEventListener("input", ML.debounce(load, 300));
  [f.category, f.status, f.stock, f.sort].forEach(function (s) { s.addEventListener("change", load); });
  document.querySelectorAll("[data-view]").forEach(function (b) {
    b.classList.toggle("active", b.getAttribute("data-view") === view);
    b.setAttribute("aria-pressed", b.getAttribute("data-view") === view ? "true" : "false");
    b.addEventListener("click", function () {
      view = b.getAttribute("data-view");
      localSet("ml.products.view", view);
      document.querySelectorAll("[data-view]").forEach(function (x) { x.classList.toggle("active", x === b); x.setAttribute("aria-pressed", x === b ? "true" : "false"); });
      render();
    });
  });

  load().then(function () {
    if (/[?&]new=1/.test(location.search)) openForm(null);
  });
})();
