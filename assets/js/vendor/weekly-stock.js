/* Weekly stock template editor. */
(function () {
  "use strict";

  var DAYS = ["", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];
  var state = { products: [], days: {}, suggestions: {}, operating: [], day: 1, dirty: false };
  var rowsEl = document.getElementById("rows");

  function productById(id) { return state.products.find(function (p) { return p.id === id; }); }

  function rowHtml(item) {
    var p = productById(item.product_id);
    var options = '<option value="">Choose product...</option>' + state.products.map(function (x) {
      return '<option value="' + x.id + '"' + (x.id === item.product_id ? " selected" : "") + ">" + ML.esc(x.name) + "</option>";
    }).join("");
    return '<div class="stock-row" data-row>' +
      '<div class="field"><select class="select" data-k="product_id" aria-label="Product">' + options + "</select></div>" +
      '<div class="stock-qty"><div class="input-group"><input class="input" type="number" min="0" step="0.5" data-k="quantity" value="' + (item.quantity === "" ? "" : item.quantity) + '" aria-label="Quantity"><span class="addon" data-unit>' + ML.esc(p ? p.unit : "unit") + "</span></div></div>" +
      '<div class="stock-status"><label class="switch"><input type="checkbox" data-k="is_active"' + (item.is_active ? " checked" : "") + '><span class="track"></span><span class="sr">' + (item.is_active ? "On" : "Off") + "</span></label></div>" +
      '<button type="button" class="icon-btn" data-remove aria-label="Remove row">' + ML.icon("i-trash") + "</button></div>";
  }

  function renderDay() {
    var d = state.day;
    var items = state.days[d] || [];
    document.querySelectorAll("[data-day]").forEach(function (t) {
      var on = Number(t.getAttribute("data-day")) === d;
      t.classList.toggle("active", on);
      t.setAttribute("aria-selected", on ? "true" : "false");
    });
    document.getElementById("dayTitle").textContent = DAYS[d];
    var isOp = state.operating.indexOf(d) > -1;
    document.getElementById("daySub").textContent = items.length
      ? items.length + " product" + (items.length > 1 ? "s" : "") + " in template" + (isOp ? "" : ", not an operating day")
      : "No template for this day yet" + (isOp ? "" : ", not an operating day");

    if (!state.products.length) {
      rowsEl.innerHTML = '<div class="empty"><div class="empty-icon">' + ML.icon("i-box") + "</div><h4>Add products first</h4><p>Your weekly template is built from your product catalogue.</p>" +
        '<a class="btn btn-primary" href="' + ML.url("vendor/products.php?new=1") + '">' + ML.icon("i-plus") + "Add New Product</a></div>";
      document.getElementById("addRow").hidden = true;
      return;
    }
    rowsEl.innerHTML = items.length ? items.map(rowHtml).join("") :
      '<div class="empty" style="padding:28px 10px"><p>No products planned for ' + DAYS[d] + '. Use <strong>Add product</strong> below.</p></div>';

    document.getElementById("copyMenu").innerHTML = DAYS.slice(1).map(function (name, i) {
      return i + 1 === d ? "" : '<button type="button" role="menuitem" data-copy-to="' + (i + 1) + '">' + name + "</button>";
    }).join("");
  }

  function renderSummary() {
    for (var d = 1; d <= 7; d++) {
      var n = (state.days[d] || []).length;
      document.querySelector('[data-day-count="' + d + '"]').textContent = n ? n + " item" + (n > 1 ? "s" : "") : "Empty";
    }
    document.getElementById("weekOverview").innerHTML = DAYS.slice(1).map(function (name, i) {
      var d = i + 1, items = state.days[d] || [];
      return '<div class="wo-day' + (state.operating.indexOf(d) > -1 ? "" : " inactive") + '"><h5>' + name.slice(0, 3) + "</h5>" +
        (items.length ? items.map(function (it) {
          var p = productById(it.product_id);
          return p ? "<div" + (it.is_active ? "" : ' style="opacity:.5"') + "><span>" + ML.esc(p.name) + "</span><span>" + ML.num(it.quantity) + " " + ML.esc(p.unit) + "</span></div>" : "";
        }).join("") : '<div class="muted">-</div>') + "</div>";
    }).join("");
  }

  function readRows() {
    return Array.prototype.map.call(rowsEl.querySelectorAll("[data-row]"), function (r) {
      return {
        product_id: Number(r.querySelector('[data-k="product_id"]').value) || 0,
        quantity: r.querySelector('[data-k="quantity"]').value,
        is_active: r.querySelector('[data-k="is_active"]').checked
      };
    });
  }

  function load() {
    return ML.api("api/vendor/weekly-stock.php").then(function (res) {
      state.products = res.products;
      state.days = res.days;
      state.suggestions = res.suggestions || {};
      state.operating = res.operating_days;
      if (!state.loaded) { state.day = res.operating_days.indexOf(res.today) > -1 ? res.today : (res.operating_days[0] || res.today); state.loaded = true; }
      document.getElementById("appliedList").innerHTML = res.applied.length ? res.applied.map(function (a) {
        return '<div class="low-item"><span class="notif-icon">' + ML.icon("i-refresh") + "</span><span><strong>" + ML.esc(a.day) + "</strong> template, " + a.items + ' items</span><small class="muted" style="margin-left:auto">' + ML.esc(a.when) + "</small></div>";
      }).join("") : '<p class="muted" style="font-size:13px">No template applied yet.</p>';
      renderDay();
      renderSummary();
      state.dirty = false;
    }).catch(function (err) { ML.toast(err.message, "error"); });
  }

  function guardDirty() {
    if (!state.dirty) return Promise.resolve(true);
    return ML.confirm({ title: "Discard unsaved changes?", text: "You have edits to " + DAYS[state.day] + " that are not saved yet.", confirmText: "Discard" });
  }

  /* events */
  document.getElementById("dayTabs").addEventListener("click", function (e) {
    var t = e.target.closest("[data-day]");
    if (!t) return;
    guardDirty().then(function (ok) {
      if (!ok) return;
      state.day = Number(t.getAttribute("data-day"));
      state.dirty = false;
      renderDay();
    });
  });

  document.getElementById("addRow").addEventListener("click", function () {
    var used = readRows().map(function (r) { return r.product_id; });
    var next = state.products.find(function (p) { return used.indexOf(p.id) === -1; });
    if (!next) { ML.toast("All your products are already in this template.", "error"); return; }
    if (!rowsEl.querySelector("[data-row]")) rowsEl.innerHTML = "";
    rowsEl.insertAdjacentHTML("beforeend", rowHtml({ product_id: next.id, quantity: "", is_active: true }));
    rowsEl.lastElementChild.querySelector('[data-k="quantity"]').focus();
    state.dirty = true;
  });

  rowsEl.addEventListener("click", function (e) {
    var rm = e.target.closest("[data-remove]");
    if (rm) { rm.closest("[data-row]").remove(); state.dirty = true; }
  });
  rowsEl.addEventListener("change", function (e) {
    state.dirty = true;
    if (e.target.matches('[data-k="product_id"]')) {
      var p = productById(Number(e.target.value));
      e.target.closest("[data-row]").querySelector("[data-unit]").textContent = p ? p.unit : "unit";
    }
    if (e.target.matches('[data-k="is_active"]')) e.target.parentNode.querySelector(".sr").textContent = e.target.checked ? "On" : "Off";
  });
  rowsEl.addEventListener("input", function () { state.dirty = true; });

  document.getElementById("saveDay").addEventListener("click", function () {
    var btn = this, items = readRows(), bad = null, seen = {};
    rowsEl.querySelectorAll(".invalid").forEach(function (x) { x.classList.remove("invalid"); });
    rowsEl.querySelectorAll("[data-row]").forEach(function (r, i) {
      var it = items[i];
      if (!it.product_id || seen[it.product_id]) { r.querySelector('[data-k="product_id"]').classList.add("invalid"); bad = bad || (it.product_id ? "Each product can appear only once per day." : "Choose a product for every row."); }
      if (it.quantity === "" || Number(it.quantity) < 0) { r.querySelector('[data-k="quantity"]').classList.add("invalid"); bad = bad || "Enter a quantity for every row."; }
      seen[it.product_id] = true;
    });
    if (bad) { ML.toast(bad, "error"); return; }
    ML.setLoading(btn, true);
    ML.api("api/vendor/weekly-stock.php", { body: { action: "save_day", day: state.day, items: items } })
      .then(function (res) { ML.toast(res.message); return load(); })
      .catch(function (err) { ML.toast(err.message, "error"); })
      .then(function () { ML.setLoading(btn, false); });
  });

  document.getElementById("clearDay").addEventListener("click", function () {
    ML.confirm({ title: "Delete the " + DAYS[state.day] + " template?", text: "Live product stock is not changed.", confirmText: "Delete template", danger: true })
      .then(function (ok) {
        if (!ok) return;
        ML.api("api/vendor/weekly-stock.php", { body: { action: "clear_day", day: state.day } })
          .then(function (res) { ML.toast(res.message); load(); })
          .catch(function (err) { ML.toast(err.message, "error"); });
      });
  });

  document.getElementById("copyMenu").addEventListener("click", function (e) {
    var b = e.target.closest("[data-copy-to]");
    if (!b) return;
    var to = Number(b.getAttribute("data-copy-to"));
    ML.confirm({ title: "Copy to " + DAYS[to] + "?", text: "This replaces the saved " + DAYS[to] + " template with " + DAYS[state.day] + "'s saved template.", confirmText: "Copy template" })
      .then(function (ok) {
        if (!ok) return;
        ML.api("api/vendor/weekly-stock.php", { body: { action: "copy_day", from: state.day, to: to } })
          .then(function (res) { ML.toast(res.message); load(); })
          .catch(function (err) { ML.toast(err.message, "error"); });
      });
  });

  document.getElementById("applyDay").addEventListener("click", function () {
    var btn = this;
    ML.confirm({ title: "Apply " + DAYS[state.day] + " template?", text: "Quantities of the products in this template will be reset to the template values and marked available. Save any edits first.", confirmText: "Apply to stock" })
      .then(function (ok) {
        if (!ok) return;
        ML.setLoading(btn, true);
        ML.api("api/vendor/weekly-stock.php", { body: { action: "apply", day: state.day } })
          .then(function (res) { ML.toast(res.message); load(); })
          .catch(function (err) { ML.toast(err.message, "error"); })
          .then(function () { ML.setLoading(btn, false); });
      });
  });

  /* suggest quantities from past sales */
  document.getElementById("aiPrefill").addEventListener("click", function () {
    var sugg = state.suggestions[state.day] || [];
    sugg = sugg.filter(function (it) { return productById(it.product_id); });
    if (!sugg.length) {
      ML.toast("No AI suggestion for " + DAYS[state.day] + " yet - it learns from your completed sales over the last 6 weeks.", "error");
      return;
    }
    guardDirty().then(function (ok) {
      if (!ok) return;
      state.days[state.day] = sugg.map(function (it) {
        return { product_id: it.product_id, quantity: it.quantity, is_active: true };
      });
      state.dirty = true;
      renderDay();
      renderSummary();
      ML.toast("AI pre-filled " + sugg.length + " item" + (sugg.length > 1 ? "s" : "") + " for " + DAYS[state.day] + ". Review and press Save Weekly Template.");
    });
  });

  window.addEventListener("beforeunload", function (e) { if (state.dirty) { e.preventDefault(); e.returnValue = ""; } });

  load();
})();
