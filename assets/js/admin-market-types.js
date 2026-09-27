/* admin/market-types.php - market types, categories, subcategories and suggested products. */
(function () {
  "use strict";

  var A = window.MLAdmin, esc = A.esc;
  var API = "api/market-types.php";
  var list = document.getElementById("typeList");
  var typeModal = bootstrap.Modal.getOrCreateInstance(document.getElementById("typeModal"));
  var catModal = bootstrap.Modal.getOrCreateInstance(document.getElementById("catModal"));
  var typeForm = document.getElementById("typeForm");
  var catForm = document.getElementById("catForm");
  var types = [];
  var open = {};

  function load() {
    return A.api(API).then(function (d) {
      types = d.types;
      render();
    }).catch(function (err) {
      var box = document.getElementById("mtError");
      box.textContent = "Couldn't load market types: " + err.message;
      box.hidden = false;
    });
  }

  function findType(id) { return types.filter(function (t) { return t.id === id; })[0]; }
  function findCat(id) {
    var hit = null;
    types.forEach(function (t) {
      t.categories.forEach(function (c) {
        if (c.id === id) hit = c;
        c.children.forEach(function (s) { if (s.id === id) hit = s; });
      });
    });
    return hit;
  }

  function catalogChips(c) {
    return '<div class="mt-catalog">' + c.catalog.map(function (p) {
      return '<span class="mt-chip">' + esc(p.name) + '<button type="button" data-act="catalog-del" data-id="' + p.id + '" aria-label="Remove ' + esc(p.name) + '">x</button></span>';
    }).join("") +
      '<form class="mt-chip-add" data-cat="' + c.id + '"><input name="name" maxlength="120" placeholder="+ product" aria-label="Add suggested product to ' + esc(c.name) + '"></form></div>';
  }

  function catRow(c, isSub) {
    return '<div class="mt-cat' + (isSub ? " mt-sub" : "") + (c.is_active ? "" : " is-off") + '" data-cat-id="' + c.id + '">' +
      '<div class="mt-cat-head"><div><strong>' + esc(c.name) + "</strong>" + (c.is_active ? "" : ' <span class="adm-badge inactive">Hidden</span>') +
        '<span class="muted">, ' + c.products + " product" + (c.products === 1 ? "" : "s") + (!isSub && c.children.length ? ", " + c.children.length + " subcategories" : "") + "</span></div>" +
        '<div class="adm-actions">' +
          (!isSub ? '<button type="button" class="adm-act" data-act="sub-add"><svg viewBox="0 0 24 24"><use href="#icon-plus"/></svg>Subcategory</button>' : "") +
          '<button type="button" class="adm-act" data-act="cat-edit"><svg viewBox="0 0 24 24"><use href="#icon-edit"/></svg>Edit</button>' +
          '<button type="button" class="adm-act" data-act="cat-toggle">' + (c.is_active ? "Hide" : "Show") + "</button>" +
          '<button type="button" class="adm-act suspend" data-act="cat-del" aria-label="Delete ' + esc(c.name) + '"><svg viewBox="0 0 24 24"><use href="#icon-trash"/></svg></button>' +
        "</div></div>" +
      (!isSub ? catalogChips(c) : "") +
      (!isSub && c.children.length ? '<div class="mt-subs">' + c.children.map(function (s) { return catRow(s, true); }).join("") + "</div>" : "") +
      "</div>";
  }

  function render() {
    list.innerHTML = types.map(function (t) {
      var isOpen = !!open[t.id];
      return '<article class="adm-card mt-type' + (t.is_active ? "" : " is-off") + '" data-type-id="' + t.id + '">' +
        '<header class="mt-type-head">' +
          '<button type="button" class="mt-toggle" data-act="expand" aria-expanded="' + isOpen + '"><span class="mt-icon"' + (t.image ? ' style="background:url(\'' + esc(t.image) + '\') center/cover;color:transparent"' : "") + ">" + (t.image ? "" : '<svg class="icon"><use href="#icon-tag"/></svg>') + "</span>" +
            '<span class="mt-title"><strong>' + esc(t.name) + "</strong><small>" +
              (t.general ? esc(t.description) : t.farmers + " vendor stall" + (t.farmers === 1 ? "" : "s") + ", " + t.markets + " market location" + (t.markets === 1 ? "" : "s")) +
              ", " + t.categories.length + " categor" + (t.categories.length === 1 ? "y" : "ies") + "</small></span>" +
            '<svg viewBox="0 0 24 24" class="adm-chev"><use href="#icon-chevron"/></svg></button>' +
          (t.general ? "" : "<div class=\"adm-actions\">" + A.badge(t.is_active ? "active" : "inactive") +
            (t.markets ? '<a class="adm-act" href="markets.php?type=' + t.id + '">Markets</a>' : "") +
            '<button type="button" class="adm-act" data-act="type-view"><svg viewBox="0 0 24 24"><use href="#icon-eye"/></svg>Vendors &amp; products</button>' +
            '<button type="button" class="adm-act" data-act="type-edit"><svg viewBox="0 0 24 24"><use href="#icon-edit"/></svg>Edit</button>' +
            '<button type="button" class="adm-act" data-act="type-toggle">' + (t.is_active ? "Deactivate" : "Activate") + "</button>" +
            '<button type="button" class="adm-act suspend" data-act="type-del" aria-label="Delete ' + esc(t.name) + '"><svg viewBox="0 0 24 24"><use href="#icon-trash"/></svg></button></div>') +
        "</header>" +
        '<div class="mt-body"' + (isOpen ? "" : " hidden") + ">" +
          (t.categories.length ? t.categories.map(function (c) { return catRow(c, false); }).join("") : '<p class="adm-panel-sub">No categories yet.</p>') +
          '<button type="button" class="adm-btn-outline mt-add-cat" data-act="cat-add"><svg viewBox="0 0 24 24"><use href="#icon-plus"/></svg> Add Category</button>' +
        "</div></article>";
    }).join("");
  }

  function post(body) {
    return A.api(API, { body: body }).then(function (res) { A.toast(res.message); return load(); })
      .catch(function (err) { A.toast(err.message, true); throw err; });
  }

  // modals
  function openType(t) {
    typeForm.reset();
    A.clearErrors(typeForm);
    document.getElementById("typeModalTitle").textContent = t ? "Edit Market Category" : "Add Market Category";
    document.getElementById("tImageRemoveWrap").hidden = !(t && t.image);
    typeForm.id.value = t ? t.id : "";
    if (t) {
      typeForm.name.value = t.name;
      typeForm.icon.value = t.icon || "";
      typeForm.farmer_label.value = t.farmer_label;
      typeForm.sort_order.value = t.sort_order;
      typeForm.description.value = t.description || "";
      typeForm.is_active.checked = t.is_active;
    } else {
      typeForm.sort_order.value = types.length;
    }
    typeModal.show();
  }

  function openCat(typeId, parent, cat) {
    catForm.reset();
    A.clearErrors(catForm);
    var t = findType(typeId);
    document.getElementById("catModalKicker").textContent = (t ? t.name : "") + (parent ? " › " + parent.name : "");
    document.getElementById("catModalTitle").textContent = (cat ? "Edit " : "Add ") + (parent || (cat && cat.parent_id) ? "Subcategory" : "Category");
    catForm.id.value = cat ? cat.id : "";
    catForm.parent_id.value = parent ? parent.id : "";
    catForm.market_type_id.value = typeId || "";
    if (cat) { catForm.name.value = cat.name; catForm.description.value = cat.description || ""; }
    catModal.show();
  }

  function submitModal(form, modal) {
    form.addEventListener("submit", function (ev) {
      ev.preventDefault();
      if (form.name.value.trim().length < 2) { A.showErrors(form, { name: "Enter a name." }); return; }
      var btn = form.querySelector('[type="submit"]');
      btn.disabled = true;
      A.api(API, { body: new FormData(form) })
        .then(function (res) { A.toast(res.message); modal.hide(); return load(); })
        .catch(function (err) { A.showErrors(form, err.errors); A.toast(err.message, true); })
        .then(function () { btn.disabled = false; });
    });
  }
  submitModal(typeForm, typeModal);
  submitModal(catForm, catModal);

  // events
  document.getElementById("addTypeBtn").addEventListener("click", function () { openType(null); });

  list.addEventListener("click", function (ev) {
    var btn = ev.target.closest("[data-act]");
    if (!btn) return;
    var typeEl = btn.closest("[data-type-id]");
    var t = findType(Number(typeEl.dataset.typeId));
    var catEl = btn.closest("[data-cat-id]");
    var c = catEl ? findCat(Number(catEl.dataset.catId)) : null;

    switch (btn.dataset.act) {
      case "expand":
        open[t.id] = !open[t.id];
        btn.setAttribute("aria-expanded", open[t.id]);
        typeEl.querySelector(".mt-body").hidden = !open[t.id];
        break;
      case "type-edit": openType(t); break;
      case "type-view": viewType(t); break;
      case "type-toggle": post({ action: "type_toggle", id: t.id }).catch(function () {}); break;
      case "type-del":
        if (confirm("Delete the market category \"" + t.name + "\"? This only works while no vendor stalls or market locations use it. Its product categories are kept as general categories.")) post({ action: "type_delete", id: t.id }).catch(function () {});
        break;
      case "cat-add": openCat(t.general ? null : t.id, null, null); break;
      case "sub-add": openCat(t.general ? null : t.id, c, null); break;
      case "cat-edit": openCat(t.general ? null : t.id, null, c); break;
      case "cat-toggle": post({ action: "category_toggle", id: c.id }).catch(function () {}); break;
      case "cat-del":
        if (confirm("Delete \"" + c.name + "\"? Subcategories and suggested products under it are removed too.")) post({ action: "category_delete", id: c.id }).catch(function () {});
        break;
      case "catalog-del": post({ action: "catalog_delete", id: btn.dataset.id }).catch(function () {}); break;
    }
  });

  var viewModal = new bootstrap.Modal(document.getElementById("typeView"));
  function viewType(t) {
    var bodyEl = document.getElementById("typeViewBody");
    document.getElementById("typeViewTitle").textContent = t.name;
    bodyEl.innerHTML = '<span class="adm-skel adm-skel-row"></span>';
    viewModal.show();
    A.api(API, { query: { action: "detail", id: t.id } }).then(function (d) {
      var v = d.vendors.length ? '<table class="table adm-table"><thead><tr><th>Stall</th><th>Owner</th><th class="text-end">Products</th><th>Status</th></tr></thead><tbody>' +
        d.vendors.map(function (x) { return '<tr><td><a href="' + esc(x.view) + '" target="_blank" rel="noopener">' + esc(x.name) + "</a></td><td>" + esc(x.owner) + '</td><td class="text-end">' + x.products + "</td><td>" + A.badge(x.status) + "</td></tr>"; }).join("") + "</tbody></table>"
        : '<div class="adm-empty-inline"><strong>No vendor stalls yet</strong>Vendors choose this category when they set up their stall.</div>';
      var p = d.products.length ? '<table class="table adm-table"><thead><tr><th>Product</th><th>Stall</th><th>Price</th><th>Status</th></tr></thead><tbody>' +
        d.products.map(function (x) { return "<tr><td>" + esc(x.name) + "</td><td>" + esc(x.vendor) + "</td><td>" + esc(x.price) + "</td><td>" + A.badge(x.status) + "</td></tr>"; }).join("") + "</tbody></table>"
        : '<div class="adm-empty-inline"><strong>No products yet</strong>Products from stalls in this category appear here.</div>';
      bodyEl.innerHTML = (d.type.description ? '<p class="adm-lede">' + esc(d.type.description) + "</p>" : "") +
        '<h3 class="adm-h2" style="font-size:18px;margin:12px 0 8px">Vendors / stalls (' + d.vendors.length + ')</h3><div class="table-responsive">' + v + "</div>" +
        '<h3 class="adm-h2" style="font-size:18px;margin:18px 0 8px">Products (' + d.products.length + ')</h3><div class="table-responsive">' + p + "</div>" +
        '<p class="form-text">Moderate products from <a href="moderation.php?tab=products&amp;category=' + d.type.id + '">Content &amp; Moderation</a>.</p>';
    }).catch(function (err) { bodyEl.innerHTML = '<div class="adm-error">' + esc(err.message) + "</div>"; });
  }

  list.addEventListener("submit", function (ev) {
    var f = ev.target.closest(".mt-chip-add");
    if (!f) return;
    ev.preventDefault();
    var name = f.name.value.trim();
    if (name.length < 2) return;
    post({ action: "catalog_add", category_id: f.dataset.cat, name: name }).catch(function () {});
  });

  load();
})();
