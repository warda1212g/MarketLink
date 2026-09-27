(function () {
  "use strict";

  var A = window.MLAdmin;
  var esc = A.esc;

  // chart colours
  var C = {
    deepForest: "#0B2416", forest: "#123D24", natural: "#2F6B3F", soft: "#6F9F72",
    beige: "#EAE2CE", ink: "#132A1C", inkSoft: "rgba(19,42,28,0.66)",
    line: "rgba(19,42,28,0.08)", gold: "#C79A3B", rust: "#B3543E"
  };
  var STATUS_ORDER = ["pending", "accepted", "ready", "completed", "cancelled"];
  var STATUS_COLORS = { pending: C.gold, accepted: C.soft, ready: C.natural, completed: C.deepForest, cancelled: C.rust };
  var PERIOD_NAMES = { today: "today", week: "this week", month: "this month", year: "this year", all: "all time" };
  var PREV_NAMES = { today: "vs yesterday", week: "vs last week", month: "vs last month", year: "vs last year" };
  var DEFAULT_GRANULARITY = { today: "weekly", week: "weekly", month: "monthly", year: "monthly", all: "yearly" };

  var state = { period: "month", granularity: "monthly", metric: "orders", data: null, firstLoad: true };
  var charts = {};
  var requestSeq = 0;

  var money = new Intl.NumberFormat(undefined, { style: "currency", currency: "PKR", maximumFractionDigits: 0 });
  var moneyShort = new Intl.NumberFormat(undefined, { style: "currency", currency: "PKR", notation: "compact", maximumFractionDigits: 1 });
  var number = new Intl.NumberFormat();

  if (window.Chart) {
    Chart.defaults.font.family = "'Manrope', sans-serif";
    Chart.defaults.font.size = 12;
    Chart.defaults.color = C.inkSoft;
    Chart.defaults.plugins.tooltip.backgroundColor = C.deepForest;
    Chart.defaults.plugins.tooltip.titleFont = { family: "'Montserrat', sans-serif", size: 11, weight: "600" };
    Chart.defaults.plugins.tooltip.bodyFont = { family: "'Manrope', sans-serif", size: 12 };
    Chart.defaults.plugins.tooltip.padding = 10;
    Chart.defaults.plugins.tooltip.cornerRadius = 6;
    Chart.defaults.plugins.tooltip.displayColors = false;
  }

  // helpers
  function formatDate(value, withTime) {
    if (!value) return "-";
    var d = parseDbDate(value);
    if (isNaN(d)) return esc(value);
    var opts = { month: "short", day: "numeric", year: "numeric" };
    if (withTime) { opts.hour = "numeric"; opts.minute = "2-digit"; }
    return d.toLocaleString(undefined, opts);
  }
  // dates come from MySQL in server time, so "time ago" is measured against the server's NOW()
  function parseDbDate(value) {
    var p = String(value).split(/[- :]/).map(Number);
    return new Date(p[0], (p[1] || 1) - 1, p[2] || 1, p[3] || 0, p[4] || 0, p[5] || 0);
  }
  function timeAgo(value) {
    var ref = state.data && state.data.serverNow ? parseDbDate(state.data.serverNow) : new Date();
    var s = Math.max(0, (ref - parseDbDate(value)) / 1000);
    if (s < 60) return "Just now";
    if (s < 3600) return Math.floor(s / 60) + " min ago";
    if (s < 86400) return Math.floor(s / 3600) + " h ago";
    if (s < 604800) return Math.floor(s / 86400) + " d ago";
    return formatDate(value);
  }
  function emptyRow(tbody, title, note) {
    tbody.innerHTML = '<tr><td colspan="' + tbody.dataset.cols + '"><div class="adm-empty-inline"><strong>' +
      esc(title) + "</strong>" + esc(note || "") + "</div></td></tr>";
  }
  function skeletonRows(tbody, n) {
    var cols = +tbody.dataset.cols, html = "";
    for (var i = 0; i < n; i++) {
      html += "<tr>";
      for (var j = 0; j < cols; j++) html += '<td><span class="adm-skel" style="width:' + (50 + ((i + j) * 13) % 45) + '%"></span></td>';
      html += "</tr>";
    }
    tbody.innerHTML = html;
  }
  function setChartState(key, loading, empty) {
    var box = document.querySelector('[data-chart="' + key + '"]');
    if (!box) return;
    box.classList.toggle("is-loading", !!loading);
    if (empty !== undefined) box.querySelector(".adm-empty").hidden = !empty;
  }
  function countUp(el, target, formatter) {
    if (!state.firstLoad || window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
      el.textContent = formatter(target);
      return;
    }
    var start = performance.now(), dur = 900;
    (function tick(now) {
      var t = Math.min(1, (now - start) / dur);
      var eased = 1 - Math.pow(1 - t, 3);
      el.textContent = formatter(target * eased);
      if (t < 1) requestAnimationFrame(tick);
      else el.textContent = formatter(target);
    })(start);
  }

  // KPIs
  var STATIC_NOTES = { marketTypes: "active types", vendors: "approved, selling in a market" };

  function renderKpis(kpis) {
    Object.keys(kpis).forEach(function (key) {
      var card = document.querySelector('[data-kpi="' + key + '"]');
      if (!card) return;
      var k = kpis[key];
      var valueEl = card.querySelector('[data-role="value"]');
      var changeEl = card.querySelector('[data-role="change"]');
      var noteEl = card.querySelector('[data-role="note"]');
      var isMoney = key === "revenue";
      var fmt = isMoney
        ? function (v) { return v >= 100000 ? moneyShort.format(v) : money.format(v); }
        : function (v) { return number.format(Math.round(v)); };

      card.classList.remove("is-loading");
      countUp(valueEl, k.value, fmt);
      if (isMoney) valueEl.title = money.format(k.value);

      // change pill
      changeEl.className = "adm-change";
      changeEl.textContent = "";
      if (state.period !== "all" && k.change !== null && k.change !== undefined) {
        var dir = k.change > 0 ? "up" : k.change < 0 ? "down" : "flat";
        changeEl.classList.add(dir);
        changeEl.textContent = (dir === "up" ? "+" : dir === "down" ? "-" : "") + Math.abs(k.change).toFixed(1) + "%";
      }

      // small note under the number
      var note;
      if (STATIC_NOTES[key]) {
        note = STATIC_NOTES[key]; // point-in-time counts, not tied to the period
      } else if (state.period === "all") {
        note = isMoney ? "completed orders, all time" : "all time";
      } else if (k.newInPeriod !== null && k.newInPeriod !== undefined) {
        note = "+" + number.format(k.newInPeriod) + " new " + PERIOD_NAMES[state.period];
      } else if (k.change !== null && k.change !== undefined) {
        note = PREV_NAMES[state.period];
      } else {
        note = PERIOD_NAMES[state.period] + ", no prior data";
      }
      noteEl.textContent = note;
    });
  }

  // charts
  function renderTrend(trend) {
    var isRevenue = state.metric === "revenue";
    var values = isRevenue ? trend.revenue : trend.orders;
    var hasData = trend.hasData && values.some(function (v) { return v > 0; });
    document.getElementById("trendSub").textContent = isRevenue
      ? "Revenue from completed orders, by " + trend.granularity.replace("ly", "")
      : "Orders placed, by " + trend.granularity.replace("ly", "");

    setChartState("trend", false, !hasData);
    if (!hasData) {
      if (charts.trend) { charts.trend.destroy(); charts.trend = null; }
      return;
    }

    var ctx = document.getElementById("trendChart").getContext("2d");
    var grad = ctx.createLinearGradient(0, 0, 0, 320);
    grad.addColorStop(0, "rgba(47,107,63,0.18)");
    grad.addColorStop(1, "rgba(47,107,63,0)");

    var dataset = {
      label: isRevenue ? "Revenue" : "Orders",
      data: values,
      borderColor: C.natural,
      backgroundColor: grad,
      fill: true,
      tension: 0.35,
      borderWidth: 2,
      pointRadius: 3,
      pointHoverRadius: 5,
      pointBackgroundColor: "#fff",
      pointBorderColor: C.natural,
      pointBorderWidth: 2
    };

    if (charts.trend) {
      charts.trend.data.labels = trend.labels;
      charts.trend.data.datasets[0] = dataset;
      charts.trend.update();
      return;
    }
    charts.trend = new Chart(ctx, {
      type: "line",
      data: { labels: trend.labels, datasets: [dataset] },
      options: {
        maintainAspectRatio: false,
        interaction: { mode: "index", intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: function (c) {
                return state.metric === "revenue" ? money.format(c.parsed.y) : number.format(c.parsed.y) + " orders";
              }
            }
          }
        },
        scales: {
          x: { grid: { display: false }, border: { display: false } },
          y: {
            beginAtZero: true,
            grid: { color: C.line },
            border: { display: false },
            ticks: {
              precision: 0,
              callback: function (v) { return state.metric === "revenue" ? moneyShort.format(v) : number.format(v); }
            }
          }
        }
      }
    });
  }

  function renderUsers(dist) {
    var total = dist.customers + dist.farmers;
    var legend = document.getElementById("userLegend");
    var center = document.getElementById("userTotal");
    setChartState("users", false, total === 0);
    if (total === 0) {
      legend.innerHTML = "";
      center.innerHTML = "";
      if (charts.users) { charts.users.destroy(); charts.users = null; }
      return;
    }
    var rows = [["Customers", dist.customers, C.natural], ["Farmers", dist.farmers, C.beige]];
    legend.innerHTML = rows.map(function (r) {
      return '<li><i style="background:' + r[2] + '"></i>' + r[0] + '<strong>' + number.format(r[1]) +
        '</strong><span class="pct">' + Math.round(r[1] / total * 100) + "%</span></li>";
    }).join("");
    center.innerHTML = number.format(total) + "<small>USERS</small>";

    var data = { labels: ["Customers", "Farmers"], datasets: [{ data: [dist.customers, dist.farmers], backgroundColor: [C.natural, C.beige], borderColor: "#fff", borderWidth: 3, hoverOffset: 4 }] };
    if (charts.users) { charts.users.data = data; charts.users.update(); return; }
    charts.users = new Chart(document.getElementById("userChart"), {
      type: "doughnut",
      data: data,
      options: { maintainAspectRatio: false, cutout: "72%", plugins: { legend: { display: false } } }
    });
  }

  function renderStatus(status) {
    var values = STATUS_ORDER.map(function (s) { return status[s] || 0; });
    var total = values.reduce(function (a, b) { return a + b; }, 0);
    setChartState("status", false, total === 0);
    if (total === 0) {
      if (charts.status) { charts.status.destroy(); charts.status = null; }
      return;
    }
    var labels = STATUS_ORDER.map(function (s) { return s.charAt(0).toUpperCase() + s.slice(1); });
    var data = { labels: labels, datasets: [{ data: values, backgroundColor: STATUS_ORDER.map(function (s) { return STATUS_COLORS[s]; }), borderRadius: 4, barThickness: 18 }] };
    if (charts.status) { charts.status.data = data; charts.status.update(); return; }
    charts.status = new Chart(document.getElementById("statusChart"), {
      type: "bar",
      data: data,
      options: {
        indexAxis: "y",
        maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return number.format(c.parsed.x) + " orders"; } } } },
        scales: {
          x: { beginAtZero: true, grid: { color: C.line }, border: { display: false }, ticks: { precision: 0 } },
          y: { grid: { display: false }, border: { display: false }, ticks: { font: { family: "'Montserrat', sans-serif", size: 11, weight: "600" }, color: C.ink } }
        }
      }
    });
  }

  function renderCategories(categories) {
    var box = document.getElementById("categoryBars");
    var total = categories.reduce(function (a, c) { return a + c.count; }, 0);
    if (!categories.length || total === 0) {
      box.innerHTML = '<div class="adm-empty-inline"><strong>No products available</strong>Products appear here once farmers publish their weekly stock.</div>';
      return;
    }
    var max = Math.max.apply(null, categories.map(function (c) { return c.count; }));
    box.innerHTML = categories.map(function (c) {
      return '<div class="adm-bar-row"><div class="adm-bar-head"><span>' + esc(c.name) + "</span><strong>" +
        number.format(c.count) + '</strong></div><div class="adm-bar-track"><div class="adm-bar-fill" data-w="' +
        (c.count / max * 100).toFixed(1) + '"></div></div></div>';
    }).join("");
    requestAnimationFrame(function () {
      box.querySelectorAll(".adm-bar-fill").forEach(function (el) { el.style.width = el.dataset.w + "%"; });
    });
  }

  // markets
  function renderBars(boxId, rows, emptyTitle, emptyNote) {
    var box = document.getElementById(boxId);
    if (!rows.length) {
      box.innerHTML = '<div class="adm-empty-inline"><strong>' + esc(emptyTitle) + "</strong>" + esc(emptyNote) + "</div>";
      return;
    }
    var max = Math.max.apply(null, rows.map(function (r) { return r.count; })) || 1;
    box.innerHTML = rows.map(function (r) {
      return '<div class="adm-bar-row"><div class="adm-bar-head"><span>' + esc(r.name) + "</span><strong>" + number.format(r.count) +
        '</strong></div><div class="adm-bar-track"><div class="adm-bar-fill" data-w="' + (r.count / max * 100).toFixed(1) + '"></div></div></div>';
    }).join("");
    requestAnimationFrame(function () {
      box.querySelectorAll(".adm-bar-fill").forEach(function (el) { el.style.width = el.dataset.w + "%"; });
    });
  }

  function renderMarketRevenue(rows) {
    var withSales = rows.filter(function (r) { return r.revenue > 0 || r.orders > 0; });
    setChartState("marketRevenue", false, withSales.length === 0);
    if (charts.marketRevenue) charts.marketRevenue.destroy();
    if (!withSales.length) return;
    charts.marketRevenue = new Chart(document.getElementById("marketRevenueChart"), {
      type: "bar",
      data: { labels: withSales.map(function (r) { return r.name; }), datasets: [{ data: withSales.map(function (r) { return r.revenue; }), backgroundColor: C.natural, borderRadius: 4, barThickness: 16 }] },
      options: {
        indexAxis: "y", responsive: true, maintainAspectRatio: false, animation: state.firstLoad ? undefined : false,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { var r = withSales[c.dataIndex]; return money.format(r.revenue) + ", " + number.format(r.orders) + " orders"; } } } },
        scales: {
          x: { beginAtZero: true, grid: { color: C.line }, border: { display: false }, ticks: { callback: function (v) { return moneyShort.format(v); } } },
          y: { grid: { display: false }, border: { display: false }, ticks: { color: C.ink } }
        }
      }
    });
  }

  function renderTopMarkets(rows) {
    var tbody = document.getElementById("topMarketsBody");
    if (!rows.length) return emptyRow(tbody, "No markets yet", "Create markets in Manage Markets and assign farmers to them.");
    tbody.innerHTML = rows.map(function (r) {
      return '<tr><td><a href="market-details.php?id=' + r.id + '"><strong>' + esc(r.name) + '</strong></a><div class="muted">' + esc(r.city) + "</div></td>" +
        '<td class="muted">' + esc(r.type) + "</td>" +
        '<td class="text-end num">' + number.format(r.vendors) + "</td>" +
        '<td class="text-end num">' + number.format(r.orders) + "</td>" +
        '<td class="text-end num">' + money.format(r.revenue) + "</td>" +
        "<td>" + A.badge(r.status) + "</td></tr>";
    }).join("");
  }

  // tables & lists
  function renderTopFarmers(rows) {
    var tbody = document.getElementById("topFarmersBody");
    if (!rows.length) return emptyRow(tbody, "No farmer sales yet", "Farmers will be ranked here once they receive orders " + PERIOD_NAMES[state.period] + ".");
    tbody.innerHTML = rows.map(function (r) {
      return "<tr>" +
        '<td><div class="adm-person"><span class="adm-avatar">' + esc(A.initials(r.farmer)) + "</span>" + esc(r.farmer) + "</div></td>" +
        "<td>" + esc(r.stall) + '<div class="muted">' + esc(r.market || "No market assigned") + "</div></td>" +
        '<td class="text-end num">' + number.format(r.orders) + "</td>" +
        '<td class="text-end num">' + money.format(r.revenue) + "</td>" +
        "<td>" + A.badge(r.status) + "</td></tr>";
    }).join("");
  }

  function renderRecentOrders(rows) {
    var tbody = document.getElementById("recentOrdersBody");
    if (!rows.length) return emptyRow(tbody, "No orders yet", "New reservations will show up here as soon as they are placed.");
    tbody.innerHTML = rows.map(function (r) {
      return '<tr><td class="ref">#ML' + esc(r.id) + "</td>" +
        "<td>" + esc(r.customer) + "</td>" +
        "<td>" + esc(r.farmer) + "</td>" +
        '<td class="text-end num">' + money.format(r.total) + "</td>" +
        "<td>" + formatDate(r.pickup_date) + (r.pickup_slot ? '<div class="muted">' + esc(r.pickup_slot) + "</div>" : "") + "</td>" +
        "<td>" + A.badge(r.status) + "</td>" +
        '<td class="muted">' + formatDate(r.created_at) + "</td></tr>";
    }).join("");
  }

  function renderRecentFarmers(rows) {
    var tbody = document.getElementById("recentFarmersBody");
    if (!rows.length) return emptyRow(tbody, "No sign-ups yet", "New customer and vendor sign-ups will appear here for review.");
    tbody.innerHTML = rows.map(function (r) {
      var actions = "";
      if (r.approval_status !== "approved") {
        actions += '<button type="button" class="adm-act approve" data-farmer="' + r.id + '" data-action="approve"><svg viewBox="0 0 24 24"><use href="#icon-check"/></svg>Approve</button>';
      }
      if (r.approval_status !== "suspended") {
        actions += '<button type="button" class="adm-act suspend" data-farmer="' + r.id + '" data-action="suspend"><svg viewBox="0 0 24 24"><use href="#icon-pause"/></svg>Suspend</button>';
      }
      actions += '<a class="adm-act" href="farmers.php?id=' + r.id + '"><svg viewBox="0 0 24 24"><use href="#icon-eye"/></svg>View</a>';
      return '<tr><td><div class="adm-person"><span class="adm-avatar">' + esc(A.initials(r.name)) + "</span>" + esc(r.name) + "</div></td>" +
        "<td>" + esc(r.stall_name) + "</td>" +
        '<td class="muted">' + esc(r.email) + "</td>" +
        '<td class="muted">' + formatDate(r.created_at) + "</td>" +
        "<td>" + A.badge(r.approval_status) + "</td>" +
        '<td class="text-end"><div class="adm-actions">' + actions + "</div></td></tr>";
    }).join("");
  }

  var ACTIVITY_ICONS = {
    customer_registered: "icon-user", farmer_registered: "icon-farmer", order_placed: "icon-receipt",
    farmer_approved: "icon-check", farmer_suspended: "icon-pause", farmer_pending: "icon-clock",
    product_added: "icon-basket", product_removed: "icon-basket", review_submitted: "icon-star",
    admin_login: "icon-settings"
  };
  function renderActivity(rows) {
    var list = document.getElementById("activityList");
    if (!rows.length) {
      list.innerHTML = '<li><div class="adm-empty-inline" style="width:100%"><strong>No activity yet</strong>Registrations, orders and approvals will be logged here.</div></li>';
      return;
    }
    list.innerHTML = rows.map(function (r) {
      var warn = /removed|suspended/.test(r.type) ? " warn" : "";
      return '<li><span class="adm-act-icon' + warn + '"><svg viewBox="0 0 24 24"><use href="#' + (ACTIVITY_ICONS[r.type] || "icon-clock") +
        '"/></svg></span><div><p>' + esc(r.message) + '</p><time datetime="' + esc(r.created_at) + '">' + timeAgo(r.created_at) + "</time></div></li>";
    }).join("");
  }

  // data flow
  function setLoading() {
    ["trend", "users", "status", "marketRevenue"].forEach(function (k) { setChartState(k, true); });
    if (!state.data) {
      ["topMarketsBody", "topFarmersBody", "recentOrdersBody", "recentFarmersBody"].forEach(function (id) {
        skeletonRows(document.getElementById(id), 4);
      });
    }
  }

  function load() {
    var seq = ++requestSeq;
    var errorBox = document.getElementById("dashError");
    setLoading();
    var url = "api/dashboard-data.php?period=" + encodeURIComponent(state.period) +
      "&granularity=" + encodeURIComponent(state.granularity);

    return fetch(url, { credentials: "same-origin", headers: { Accept: "application/json" } })
      .then(function (res) {
        if (res.status === 401) { window.location.href = "login.php"; throw new Error("Session expired"); }
        if (!res.ok) throw new Error("Server responded with " + res.status);
        return res.json();
      })
      .then(function (data) {
        if (seq !== requestSeq) return; // a newer request superseded this one
        errorBox.hidden = true;
        state.data = data;
        document.querySelectorAll("[data-period-name]").forEach(function (el) { el.textContent = PERIOD_NAMES[state.period]; });
        document.querySelectorAll("[data-period-label]").forEach(function (el) {
          el.textContent = "Orders placed " + PERIOD_NAMES[state.period];
        });
        renderKpis(data.kpis);
        renderTrend(data.trend);
        renderUsers(data.userDistribution);
        renderStatus(data.orderStatus);
        renderCategories(data.categories);
        renderBars("marketTypeBars", data.marketsByType || [], "No markets yet", "Markets appear here grouped by type once created.");
        renderBars("farmerCategoryBars", data.farmersByCategory || [], "No farmer categories yet", "Assign farmers to markets or tag their categories in Manage Users.");
        renderMarketRevenue(data.topMarkets || []);
        renderTopMarkets(data.topMarkets || []);
        renderTopFarmers(data.topFarmers);
        renderRecentOrders(data.recentOrders);
        renderRecentFarmers(data.recentFarmers);
        renderActivity(data.activity);
        state.firstLoad = false;
      })
      .catch(function (err) {
        if (seq !== requestSeq) return;
        ["trend", "users", "status", "marketRevenue"].forEach(function (k) { setChartState(k, false); });
        errorBox.textContent = "We couldn't load dashboard data (" + err.message + "). Check that MySQL is running and the database is imported.";
        errorBox.hidden = false;
      });
  }

  function bindSegment(id, key, reload) {
    var group = document.getElementById(id);
    group.addEventListener("click", function (ev) {
      var btn = ev.target.closest("button");
      if (!btn || btn.classList.contains("active")) return;
      group.querySelectorAll("button").forEach(function (b) { b.classList.toggle("active", b === btn); });
      state[key] = btn.dataset.value;
      if (reload) load();
      else if (state.data) renderTrend(state.data.trend);
    });
  }
  function selectSegment(id, value) {
    document.getElementById(id).querySelectorAll("button").forEach(function (b) {
      b.classList.toggle("active", b.dataset.value === value);
    });
  }

  // events
  var periodFilter = document.getElementById("periodFilter");
  try {
    var saved = localStorage.getItem("ml-admin-period");
    if (saved && PERIOD_NAMES[saved]) periodFilter.value = saved;
  } catch (e) {}
  state.period = periodFilter.value;
  state.granularity = DEFAULT_GRANULARITY[state.period];
  selectSegment("trendGranularity", state.granularity);

  periodFilter.addEventListener("change", function () {
    state.period = periodFilter.value;
    state.granularity = DEFAULT_GRANULARITY[state.period];
    selectSegment("trendGranularity", state.granularity);
    try { localStorage.setItem("ml-admin-period", state.period); } catch (e) {}
    load();
  });
  bindSegment("trendMetric", "metric", false);
  bindSegment("trendGranularity", "granularity", true);

  document.getElementById("recentFarmersBody").addEventListener("click", function (ev) {
    var btn = ev.target.closest("button[data-action]");
    if (!btn) return;
    var action = btn.dataset.action;
    if (action === "suspend" && !window.confirm("Suspend this account? You can approve it again later.")) return;
    btn.disabled = true;
    A.farmerAction(btn.dataset.farmer, action)
      .then(function () {
        A.toast(action === "approve" ? "Account approved." : "Account suspended.");
        load();
      })
      .catch(function (err) {
        btn.disabled = false;
        A.toast(err.message, true);
      });
  });

  load();
})();
