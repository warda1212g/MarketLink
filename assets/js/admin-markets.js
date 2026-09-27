/* admin/markets.php - AJAX search/filter, market cards, status changes and safe delete. */
(function () {
  "use strict";

  var A = window.MLAdmin, esc = A.esc;
  var grid = document.getElementById("marketGrid");
  var filters = document.getElementById("mkFilters");
  var summary = document.getElementById("mkSummary");
  var errorBox = document.getElementById("mkError");
  var note = document.getElementById("mkResultNote");
  var confirmEl = document.getElementById("confirmModal");
  var confirmModal = bootstrap.Modal.getOrCreateInstance(confirmEl);
  var markets = {};
  var seq = 0;

  function params() {
    return {
      action: "list",
      q: filters.q.value.trim(),
      type: filters.type.value,
      city: filters.city.value,
      status: filters.status.value
    };
  }

  function load() {
    var my = ++seq;
    grid.setAttribute("aria-busy", "true");
    return A.api("api/markets.php", { query: params() })
      .then(function (data) {
        if (my !== seq) return;
        errorBox.hidden = true;
        render(data.markets);
        renderCounts(data.counts);
        syncCities(data.cities);
      })
      .catch(function (err) {
        if (my !== seq) return;
        errorBox.textContent = "Couldn't load markets: " + err.message + ". Make sure database/markets-migration.sql has been imported.";
        errorBox.hidden = false;
        grid.innerHTML = "";
      })
      .then(function () { grid.removeAttribute("aria-busy"); });
  }

  function renderCounts(c) {
    ["total", "active", "inactive", "suspended"].forEach(function (k) {
      summary.querySelector('[data-count="' + k + '"]').textContent = Number(c[k] || 0).toLocaleString();
    });
  }

  function syncCities(cities) {
    var sel = filters.city, current = sel.value;
    var list = document.getElementById("mkCityList");
    sel.innerHTML = '<option value="">All cities</option>' + cities.map(function (c) {
      return '<option value="' + esc(c.city) + '"' + (c.city === current ? " selected" : "") + ">" + esc(c.city) + "</option>";
    }).join("");
    if (list) list.innerHTML = cities.map(function (c) { return '<option value="' + esc(c.city) + '">'; }).join("");
  }

  function statusMenu(m) {
    var items = [];
    if (m.status !== "active") items.push('<li><button class="dropdown-item" data-act="status" data-status="active"><svg viewBox="0 0 24 24"><use href="#icon-check"/></svg> Activate</button></li>');
    if (m.status !== "inactive") items.push('<li><button class="dropdown-item" data-act="status" data-status="inactive"><svg viewBox="0 0 24 24"><use href="#icon-pause"/></svg> Deactivate</button></li>');
    if (m.status !== "suspended") items.push('<li><button class="dropdown-item" data-act="status" data-status="suspended"><svg viewBox="0 0 24 24"><use href="#icon-pause"/></svg> Suspend</button></li>');
    items.push('<li><hr class="dropdown-divider"></li><li><button class="dropdown-item text-danger" data-act="delete"><svg viewBox="0 0 24 24"><use href="#icon-trash"/></svg> Delete</button></li>');
    return items.join("");
  }

  function card(m) {
    var media = m.image
      ? '<div class="mk-card-media" style="background-image:url(\'' + esc(m.image) + '\')"></div>'
      : '<div class="mk-card-media mk-card-media-icon"><span>' + '<svg class="icon"><use href="#icon-stall"/></svg>' + "</span></div>";
    return '<article class="adm-card mk-card is-' + esc(m.status) + '" data-id="' + m.id + '">' +
      media +
      '<div class="mk-card-body">' +
        '<div class="mk-card-top"><span class="mk-type">' + esc(m.type_name) + "</span>" + A.badge(m.status) + "</div>" +
        '<h3 class="mk-card-title"><a href="market-details.php?id=' + m.id + '">' + esc(m.name) + "</a></h3>" +
        '<p class="mk-card-loc"><svg viewBox="0 0 24 24"><use href="#icon-location"/></svg>' + esc(m.location || m.city) + "</p>" +
        (m.hours ? '<p class="mk-card-loc"><svg viewBox="0 0 24 24"><use href="#icon-clock"/></svg>' + esc(m.hours) + (m.days_label ? ", " + esc(m.days_label) : "") + "</p>" : "") +
        '<div class="mk-card-stats"><div><strong>' + m.vendors + "</strong><span>Vendor" + (m.vendors === 1 ? "" : "s") + "</span></div>" +
          "<div><strong>" + m.products + "</strong><span>Product" + (m.products === 1 ? "" : "s") + "</span></div></div>" +
      "</div>" +
      '<footer class="mk-card-actions">' +
        '<a class="adm-act" href="market-details.php?id=' + m.id + '"><svg viewBox="0 0 24 24"><use href="#icon-eye"/></svg>View</a>' +
        '<button type="button" class="adm-act" data-act="edit"><svg viewBox="0 0 24 24"><use href="#icon-edit"/></svg>Edit</button>' +
        '<a class="adm-act" href="market-details.php?id=' + m.id + '#vendors"><svg viewBox="0 0 24 24"><use href="#icon-farmer"/></svg>Vendors</a>' +
        '<div class="dropdown ms-auto"><button type="button" class="adm-act" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More actions for ' + esc(m.name) + '">⋯</button>' +
          '<ul class="dropdown-menu dropdown-menu-end adm-dropdown">' + statusMenu(m) + "</ul></div>" +
      "</footer></article>";
  }

  function render(list) {
    markets = {};
    list.forEach(function (m) { markets[m.id] = m; });
    var filtered = filters.q.value || filters.type.value || filters.city.value || filters.status.value;
    note.textContent = filtered ? list.length + " market" + (list.length === 1 ? "" : "s") + " match your filters" : "";
    if (!list.length) {
      grid.innerHTML = '<div class="adm-card mk-empty"><svg viewBox="0 0 24 24"><use href="#icon-stall"/></svg>' +
        (filtered ? "<strong>No markets match these filters</strong><p>Try a different search or clear the filters.</p>"
                  : '<strong>No markets yet</strong><p>Create your first market, then assign farmers to it.</p><button type="button" class="adm-btn" data-act="add"><svg viewBox="0 0 24 24"><use href="#icon-plus"/></svg> Add Market</button>') +
        "</div>";
      return;
    }
    grid.innerHTML = list.map(card).join("");
  }

  // confirm dialog
  function confirmDialog(title, text, buttons) {
    document.getElementById("confirmTitle").textContent = title;
    document.getElementById("confirmText").textContent = text;
    var box = document.getElementById("confirmActions");
    box.innerHTML = '<button type="button" class="adm-btn-outline" data-bs-dismiss="modal">Cancel</button>';
    buttons.forEach(function (b) {
      var el = document.createElement("button");
      el.type = "button";
      el.className = b.danger ? "adm-btn adm-btn-danger" : "adm-btn";
      el.textContent = b.label;
      el.addEventListener("click", function () {
        el.disabled = true;
        Promise.resolve(b.run()).then(function () { el.disabled = false; });
      });
      box.appendChild(el);
    });
    confirmModal.show();
  }

  var STATUS_TEXT = {
    active: "Customers will see this market and its approved vendors again.",
    inactive: "The market will be hidden from customers. Admins can still manage it and reactivate it later.",
    suspended: "The market will be hidden from customers and flagged as suspended. Vendors and products are kept."
  };
  var STATUS_VERB = { active: "Activate", inactive: "Deactivate", suspended: "Suspend" };

  function setStatus(m, status) {
    return A.api("api/markets.php", { body: { action: "status", id: m.id, status: status } })
      .then(function (res) { A.toast(res.message); confirmModal.hide(); load(); })
      .catch(function (err) { A.toast(err.message, true); });
  }

  function askStatus(m, status) {
    confirmDialog(STATUS_VERB[status] + " " + m.name + "?", STATUS_TEXT[status], [
      { label: STATUS_VERB[status], danger: status !== "active", run: function () { return setStatus(m, status); } }
    ]);
  }

  function askDelete(m) {
    confirmDialog("Delete " + m.name + "?", "This permanently removes the market. It is only allowed when the market has no active vendors, products or orders.", [
      {
        label: "Delete permanently", danger: true, run: function () {
          return A.api("api/markets.php", { body: { action: "delete", id: m.id } })
            .then(function (res) { A.toast(res.message); confirmModal.hide(); load(); })
            .catch(function (err) {
              if (err.status !== 409) { A.toast(err.message, true); return; }
              // Has dependents: offer the safe alternatives instead.
              confirmDialog("This market can't be deleted", err.message, [
                { label: "Deactivate", run: function () { return setStatus(m, "inactive"); } },
                { label: "Suspend", danger: true, run: function () { return setStatus(m, "suspended"); } }
              ]);
            });
        }
      }
    ]);
  }

  // events
  grid.addEventListener("click", function (ev) {
    var btn = ev.target.closest("[data-act]");
    if (!btn) return;
    var act = btn.dataset.act;
    if (act === "add") { MLMarketForm.open(null, load); return; }
    var m = markets[btn.closest("[data-id]").dataset.id];
    if (act === "edit") MLMarketForm.open(m, load);
    else if (act === "status") askStatus(m, btn.dataset.status);
    else if (act === "delete") askDelete(m);
  });

  document.getElementById("addMarketBtn").addEventListener("click", function () { MLMarketForm.open(null, load); });

  var timer;
  filters.q.addEventListener("input", function () { clearTimeout(timer); timer = setTimeout(load, 280); });
  ["type", "city", "status"].forEach(function (n) {
    filters[n].addEventListener("change", function () {
      if (n === "status") syncSummary();
      load();
    });
  });

  function syncSummary() {
    summary.querySelectorAll(".mk-sum").forEach(function (b) { b.classList.toggle("active", b.dataset.status === filters.status.value); });
  }
  summary.addEventListener("click", function (ev) {
    var b = ev.target.closest(".mk-sum");
    if (!b) return;
    filters.status.value = b.dataset.status;
    syncSummary();
    load();
  });

  load().then(function () {
    var qs = new URLSearchParams(location.search);
    if (qs.get("action") === "add") MLMarketForm.open(null, load);
    var editId = qs.get("edit");
    if (editId && markets[editId]) MLMarketForm.open(markets[editId], load);
  });
})();
