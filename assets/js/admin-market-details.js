/* admin/market-details.php - KPIs, vendors, products, orders and analytics for one market. */
(function () {
  "use strict";

  var A = window.MLAdmin, esc = A.esc;
  var hero = document.getElementById("mkHero");
  var market = JSON.parse(hero.dataset.market);
  var API = "api/markets.php";
  var state = { period: "all", tab: "vendors", vendors: [], loaded: {} };
  var charts = {};
  var number = new Intl.NumberFormat();
  var C = { deepForest: "#0B2416", natural: "#2F6B3F", soft: "#6F9F72", gold: "#C79A3B", rust: "#B3543E", line: "rgba(19,42,28,0.08)", inkSoft: "rgba(19,42,28,0.66)" };
  var STATUS_ORDER = ["pending", "accepted", "ready", "completed", "cancelled"];
  var STATUS_COLORS = [C.gold, C.soft, C.natural, C.deepForest, C.rust];
  var PERIOD_NAMES = { today: "Today", week: "This week", month: "This month", year: "This year", all: "All time" };

  if (window.Chart) {
    Chart.defaults.font.family = "'Manrope', sans-serif";
    Chart.defaults.color = C.inkSoft;
    Chart.defaults.plugins.tooltip.backgroundColor = C.deepForest;
    Chart.defaults.plugins.tooltip.displayColors = false;
  }

  function fmtDate(v) {
    if (!v) return "-";
    var p = String(v).split(/[- :]/).map(Number);
    return new Date(p[0], p[1] - 1, p[2]).toLocaleDateString(undefined, { month: "short", day: "numeric", year: "numeric" });
  }
  function emptyRow(tbody, title, noteText) {
    tbody.innerHTML = '<tr><td colspan="' + tbody.dataset.cols + '"><div class="adm-empty-inline"><strong>' + esc(title) + "</strong>" + esc(noteText || "") + "</div></td></tr>";
  }
  function loadingRow(tbody) {
    tbody.innerHTML = '<tr><td colspan="' + tbody.dataset.cols + '"><span class="adm-skel adm-skel-row"></span></td></tr>';
  }
  function showError(err) {
    var box = document.getElementById("mdError");
    box.textContent = err.message;
    box.hidden = false;
  }
  function stars(r) {
    if (r === null || r === undefined) return '<span class="muted">No reviews</span>';
    return '<span class="mk-rating"><svg viewBox="0 0 24 24"><use href="#icon-star"/></svg>' + r.toFixed(1) + "</span>";
  }

  // KPIs + analytics
  function loadAnalytics() {
    document.querySelectorAll("[data-kpi] [data-role=value]").forEach(function (el) { el.innerHTML = '<span class="adm-skel" style="width:60%"></span>'; });
    return A.api(API, { query: { action: "analytics", id: market.id, period: state.period } })
      .then(function (d) {
        document.querySelector('[data-kpi="vendors"] [data-role=value]').textContent = number.format(d.kpis.vendors);
        document.querySelector('[data-kpi="products"] [data-role=value]').textContent = number.format(d.kpis.products);
        document.querySelector('[data-kpi="orders"] [data-role=value]').textContent = number.format(d.kpis.orders);
        document.querySelector('[data-kpi="orders"] [data-role=note]').textContent = PERIOD_NAMES[state.period].toLowerCase();
        var rev = document.querySelector('[data-kpi="revenue"] [data-role=value]');
        rev.textContent = A.money(d.kpis.revenue);
        rev.title = A.money(d.kpis.revenue);
        document.querySelector('[data-kpi="revenue"] [data-role=note]').textContent = "completed, " + PERIOD_NAMES[state.period].toLowerCase();
        state.vendors = d.vendors;
        renderVendors();
        renderCharts(d);
      })
      .catch(showError);
  }

  function chart(key, canvasId, config, empty) {
    if (charts[key]) charts[key].destroy();
    var box = document.querySelector('[data-chart="' + key + '"]');
    box.querySelector(".adm-empty").hidden = !empty;
    if (empty || !window.Chart) return;
    charts[key] = new Chart(document.getElementById(canvasId), config);
  }

  function renderCharts(d) {
    var daily = ["today", "week", "month"].indexOf(state.period) >= 0;
    document.querySelector("[data-series-label]").textContent = daily ? "Orders by day" : "Orders by month";
    document.querySelectorAll("[data-period-name]").forEach(function (el) { el.textContent = PERIOD_NAMES[state.period]; });
    var s = d.series;
    var common = {
      responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
      scales: { x: { grid: { display: false }, border: { display: false } }, y: { beginAtZero: true, grid: { color: C.line }, border: { display: false }, ticks: { precision: 0 } } }
    };
    chart("orders", "ordersChart", { type: "bar", data: { labels: s.labels, datasets: [{ data: s.orders, backgroundColor: C.natural, borderRadius: 4, maxBarThickness: 26 }] }, options: common },
      !s.orders.some(function (v) { return v > 0; }));
    chart("revenue", "revenueChart", {
      type: "line",
      data: { labels: s.labels, datasets: [{ data: s.revenue, borderColor: C.deepForest, backgroundColor: "rgba(47,107,63,0.12)", fill: true, tension: 0.35, pointRadius: 3, pointBackgroundColor: C.natural }] },
      options: Object.assign({}, common, {
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return A.money(c.parsed.y); } } } },
        scales: { x: common.scales.x, y: { beginAtZero: true, grid: { color: C.line }, border: { display: false }, ticks: { callback: function (v) { return A.money(v); } } } }
      })
    }, !s.revenue.some(function (v) { return v > 0; }));

    var st = STATUS_ORDER.map(function (k) { return d.orderStatus[k] || 0; });
    chart("status", "statusChart", {
      type: "bar",
      data: { labels: STATUS_ORDER.map(function (k) { return k.charAt(0).toUpperCase() + k.slice(1); }), datasets: [{ data: st, backgroundColor: STATUS_COLORS, borderRadius: 4, barThickness: 16 }] },
      options: { indexAxis: "y", responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true, grid: { color: C.line }, border: { display: false }, ticks: { precision: 0 } }, y: { grid: { display: false }, border: { display: false } } } }
    }, !st.some(function (v) { return v > 0; }));

    var vp = d.vendorPerformance;
    chart("vendorsPerf", "vendorChart", {
      type: "bar",
      data: { labels: vp.map(function (v) { return v.name; }), datasets: [{ data: vp.map(function (v) { return v.revenue; }), backgroundColor: C.soft, borderRadius: 4, barThickness: 16 }] },
      options: { indexAxis: "y", responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { var v = vp[c.dataIndex]; return A.money(v.revenue) + ", " + v.orders + " orders"; } } } },
        scales: { x: { beginAtZero: true, grid: { color: C.line }, border: { display: false }, ticks: { callback: function (v) { return A.money(v); } } }, y: { grid: { display: false }, border: { display: false } } } }
    }, !vp.some(function (v) { return v.revenue > 0 || v.orders > 0; }));

    var box = document.getElementById("categoryBars");
    var cats = d.categories;
    if (!cats.length) {
      box.innerHTML = '<div class="adm-empty-inline"><strong>No products yet</strong>Categories appear once farmers list products here.</div>';
    } else {
      var max = Math.max.apply(null, cats.map(function (c) { return c.count; }));
      box.innerHTML = cats.map(function (c) {
        return '<div class="adm-bar-row"><div class="adm-bar-head"><span>' + esc(c.name) + "</span><strong>" + c.count + '</strong></div><div class="adm-bar-track"><div class="adm-bar-fill" style="width:' + (c.count / max * 100).toFixed(1) + '%"></div></div></div>';
      }).join("");
    }
  }

  // vendors
  function renderVendors() {
    var tbody = document.getElementById("vendorsBody");
    if (!state.vendors.length) return emptyRow(tbody, "No vendors assigned yet", "Use \"Assign Vendors\" to add approved farmers to this market.");
    tbody.innerHTML = state.vendors.map(function (v) {
      var approvalNote = v.approval !== "approved" ? '<div class="muted">Account ' + esc(v.approval) + "</div>" : "";
      return '<tr data-vendor="' + v.vendor_id + '">' +
        '<td><div class="adm-person">' + (v.image ? '<img class="adm-avatar" src="' + esc(v.image) + '" alt="">' : '<span class="adm-avatar">' + esc(A.initials(v.name)) + "</span>") +
          "<div>" + esc(v.name) + '<div class="muted">' + esc(v.email) + "</div></div></div></td>" +
        "<td>" + esc(v.farm) + "</td>" +
        '<td class="muted">' + esc(v.categories || "-") + "</td>" +
        '<td class="text-end num">' + v.products + "</td>" +
        '<td class="text-end num">' + number.format(v.orders) + "</td>" +
        '<td class="text-end num">' + A.money(v.revenue) + "</td>" +
        "<td>" + stars(v.rating) + "</td>" +
        "<td>" + A.badge(v.status) + approvalNote + "</td>" +
        '<td class="text-end"><div class="adm-actions">' +
          '<a class="adm-act" href="farmers.php?id=' + v.user_id + '#farmer-' + v.user_id + '" title="View vendor account"><svg viewBox="0 0 24 24"><use href="#icon-eye"/></svg>View</a>' +
          '<a class="adm-act" href="farmers.php?manage=' + v.vendor_id + '" title="Edit markets and categories"><svg viewBox="0 0 24 24"><use href="#icon-edit"/></svg>Edit</a>' +
          (v.status === "active"
            ? '<button type="button" class="adm-act suspend" data-vact="suspended"><svg viewBox="0 0 24 24"><use href="#icon-pause"/></svg>Suspend</button>'
            : '<button type="button" class="adm-act approve" data-vact="active"><svg viewBox="0 0 24 24"><use href="#icon-check"/></svg>Reactivate</button>') +
          '<button type="button" class="adm-act suspend" data-vact="remove"><svg viewBox="0 0 24 24"><use href="#icon-trash"/></svg>Remove</button>' +
        "</div></td></tr>";
    }).join("");
  }

  document.getElementById("vendorsBody").addEventListener("click", function (ev) {
    var btn = ev.target.closest("[data-vact]");
    if (!btn) return;
    var vid = btn.closest("[data-vendor]").dataset.vendor;
    var v = state.vendors.filter(function (x) { return String(x.vendor_id) === vid; })[0];
    var act = btn.dataset.vact;
    var body;
    if (act === "remove") {
      if (!confirm("Remove " + v.farm + " from " + market.name + "?\n\nTheir products stay in their catalogue and order history is kept, but they will no longer sell in this market.")) return;
      body = { action: "unassign", id: market.id, vendor_id: vid };
    } else {
      if (act === "suspended" && !confirm("Suspend " + v.farm + " in " + market.name + "? Their products here are hidden until you reactivate them.")) return;
      body = { action: "vendor_status", id: market.id, vendor_id: vid, status: act };
    }
    btn.disabled = true;
    A.api(API, { body: body })
      .then(function (res) { A.toast(res.message); loadAnalytics(); })
      .catch(function (err) { btn.disabled = false; A.toast(err.message, true); });
  });

  // assign modal
  var assignModal = bootstrap.Modal.getOrCreateInstance(document.getElementById("assignModal"));
  var assignForm = document.getElementById("assignForm");
  var assignList = document.getElementById("assignList");

  function updateAssignCount() {
    document.getElementById("assignCount").textContent = assignList.querySelectorAll("input:checked").length + " selected";
  }

  document.getElementById("assignBtn").addEventListener("click", function () {
    A.clearErrors(assignForm);
    document.getElementById("assignSearch").value = "";
    assignList.innerHTML = '<span class="adm-skel adm-skel-row"></span>';
    updateAssignCount();
    assignModal.show();
    A.api(API, { query: { action: "assignable", id: market.id } }).then(function (d) {
      if (!d.vendors.length) {
        assignList.innerHTML = '<div class="adm-empty-inline"><strong>No vendors available</strong>Every approved farmer is already in this market. Approve new farmers in Manage Users.</div>';
        return;
      }
      assignList.innerHTML = d.vendors.map(function (v) {
        var text = (v.farm + " " + v.name + " " + v.email + " " + v.categories).toLowerCase();
        return '<label class="mk-assign-item" data-text="' + esc(text) + '"><input type="checkbox" name="vendor_ids[]" value="' + v.id + '">' +
          '<span class="adm-avatar">' + esc(A.initials(v.farm)) + "</span><span><strong>" + esc(v.farm) + "</strong><small>" + esc(v.name) +
          (v.categories ? ", " + esc(v.categories) : "") + (v.location ? ", " + esc(v.location) : "") + "</small></span></label>";
      }).join("");
    }).catch(function (err) { assignList.innerHTML = '<div class="adm-error">' + esc(err.message) + "</div>"; });
  });
  assignList.addEventListener("change", updateAssignCount);
  document.getElementById("assignSearch").addEventListener("input", function (ev) {
    var q = ev.target.value.trim().toLowerCase();
    assignList.querySelectorAll(".mk-assign-item").forEach(function (el) { el.hidden = q && el.dataset.text.indexOf(q) < 0; });
  });
  assignForm.addEventListener("submit", function (ev) {
    ev.preventDefault();
    var ids = Array.prototype.map.call(assignList.querySelectorAll("input:checked"), function (i) { return i.value; });
    if (!ids.length) { A.showErrors(assignForm, { vendor_ids: "Select at least one vendor." }); return; }
    var btn = document.getElementById("assignSubmit");
    btn.disabled = true;
    A.api(API, { body: { action: "assign", id: market.id, vendor_ids: ids } })
      .then(function (res) { A.toast(res.message); assignModal.hide(); loadAnalytics(); })
      .catch(function (err) { A.showErrors(assignForm, err.errors); A.toast(err.message, true); })
      .then(function () { btn.disabled = false; });
  });

  // products + orders
  function loadProducts() {
    var tbody = document.getElementById("productsBody");
    loadingRow(tbody);
    return A.api(API, { query: { action: "products", id: market.id, q: document.getElementById("productSearch").value.trim() } }).then(function (d) {
      if (!d.products.length) return emptyRow(tbody, "No products listed", "Products appear here when assigned farmers list them in this market.");
      tbody.innerHTML = d.products.map(function (p) {
        return "<tr><td><div class=\"adm-person\">" + (p.image ? '<img class="mk-thumb" src="' + esc(p.image) + '" alt="">' : '<span class="mk-thumb"></span>') + esc(p.name) + "</div></td>" +
          "<td>" + esc(p.vendor) + "</td>" +
          "<td>" + esc(p.category || "-") + (p.subcategory ? '<div class="muted">' + esc(p.subcategory) + "</div>" : "") + "</td>" +
          '<td class="text-end num">' + esc(p.price_label) + "</td>" +
          '<td class="text-end">' + esc(p.quantity_label) + "</td>" +
          "<td>" + (p.status === "available" ? '<span class="adm-badge active">In stock</span>' : '<span class="adm-badge inactive">Sold out</span>') + "</td>" +
          '<td class="muted">' + fmtDate(p.created_at) + "</td></tr>";
      }).join("");
    }).catch(showError);
  }

  function loadOrders() {
    var tbody = document.getElementById("ordersBody");
    loadingRow(tbody);
    return A.api(API, { query: { action: "orders", id: market.id } }).then(function (d) {
      if (!d.orders.length) return emptyRow(tbody, "No orders yet", "Orders for this market's products will appear here.");
      tbody.innerHTML = d.orders.map(function (o) {
        return '<tr><td class="ref">' + esc(o.number) + "</td><td>" + esc(o.customer) + "</td><td>" + esc(o.vendor) + "</td>" +
          '<td class="text-end num">' + o.items + '</td><td class="text-end num">' + esc(o.total_label) + "</td>" +
          "<td>" + A.badge(o.status === "declined" ? "cancelled" : o.status) + '</td><td class="muted">' + fmtDate(o.created_at) + "</td></tr>";
      }).join("");
    }).catch(showError);
  }

  var searchTimer;
  document.getElementById("productSearch").addEventListener("input", function () { clearTimeout(searchTimer); searchTimer = setTimeout(loadProducts, 280); });

  // tabs
  var tabs = document.querySelectorAll(".mk-tabs [data-tab]");
  function showTab(name) {
    if (!document.getElementById("tab-" + name)) name = "vendors";
    state.tab = name;
    tabs.forEach(function (t) {
      var on = t.dataset.tab === name;
      t.classList.toggle("active", on);
      t.setAttribute("aria-selected", on ? "true" : "false");
      document.getElementById("tab-" + t.dataset.tab).hidden = !on;
    });
    if (name === "products" && !state.loaded.products) { state.loaded.products = true; loadProducts(); }
    if (name === "orders" && !state.loaded.orders) { state.loaded.orders = true; loadOrders(); }
    if (name === "analytics") Object.keys(charts).forEach(function (k) { charts[k].resize(); });
    if (location.hash !== "#" + name) history.replaceState(null, "", "#" + name);
  }
  tabs.forEach(function (t) { t.addEventListener("click", function () { showTab(t.dataset.tab); }); });

  // hero actions
  function reloadPage() { window.location.reload(); }
  document.getElementById("editMarketBtn").addEventListener("click", function () { MLMarketForm.open(market, reloadPage); });
  hero.querySelectorAll("[data-set-status]").forEach(function (b) {
    b.addEventListener("click", function () {
      var status = b.dataset.setStatus;
      if (status === market.status) { A.toast("Market is already " + status + "."); return; }
      if (status !== "active" && !confirm("Change " + market.name + " to " + status + "? It will be hidden from customers.")) return;
      A.api(API, { body: { action: "status", id: market.id, status: status } })
        .then(function (res) {
          A.toast(res.message);
          market = res.market;
          var badge = document.getElementById("mkStatusBadge");
          badge.className = "adm-badge " + status;
          badge.textContent = status.charAt(0).toUpperCase() + status.slice(1);
        })
        .catch(function (err) { A.toast(err.message, true); });
    });
  });

  document.getElementById("periodFilter").addEventListener("change", function (ev) {
    state.period = ev.target.value;
    loadAnalytics();
  });

  loadingRow(document.getElementById("vendorsBody"));
  loadAnalytics();
  showTab((location.hash || "#vendors").slice(1));
})();
