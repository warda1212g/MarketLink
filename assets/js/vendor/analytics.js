/* Vendor analytics page. Data comes from api/vendor/analytics.php */
(function () {
  "use strict";
  var C = ML.colors;
  var charts = {};
  var params = { range: "30d" };
  var data = null;
  var productMetric = "qty";

  function make(id, config) {
    if (charts[id]) charts[id].destroy();
    charts[id] = new Chart(document.getElementById(id), config);
    return charts[id];
  }

  function emptyNote(id, isEmpty, text) {
    var box = document.getElementById(id).parentNode;
    var n = box.querySelector(".chart-empty");
    if (!n) { n = document.createElement("div"); n.className = "chart-empty"; box.appendChild(n); }
    n.textContent = text || "No data for this period yet.";
    n.hidden = !isEmpty;
  }

  function allZero(arr) { return !arr.length || arr.every(function (v) { return !v; }); }

  function kpis(k) {
    Object.keys({ revenue: 1, orders: 1, aov: 1, sold: 1 }).forEach(function (key) {
      var card = document.querySelector('[data-kpi="' + key + '"]');
      card.querySelector(".stat-value").textContent = k[key].label;
      var ch = k[key].change, badge;
      if (ch === null) badge = '<span class="trend up">' + ML.icon("i-up") + "New</span>";
      else if (Math.abs(ch) < 0.05) badge = '<span class="trend flat">0%</span>';
      else badge = '<span class="trend ' + (ch > 0 ? "up" : "down") + '">' + ML.icon(ch > 0 ? "i-up" : "i-down") + Math.abs(ch) + "%</span>";
      card.querySelector(".stat-foot").innerHTML = badge + " vs previous period";
    });
  }

  function lineGradient(canvasId) {
    var cv = document.getElementById(canvasId);
    var g = cv.getContext("2d").createLinearGradient(0, 0, 0, cv.parentNode.clientHeight);
    g.addColorStop(0, "rgba(79,107,60,0.24)");
    g.addColorStop(1, "rgba(79,107,60,0)");
    return g;
  }

  function revenue(s) {
    var pts = s.revenue.length;
    make("revChart", {
      type: "line",
      data: { labels: s.labels, datasets: [{ label: "Revenue", data: s.revenue, borderColor: C.primary, backgroundColor: lineGradient("revChart"), fill: true, tension: 0.35, borderWidth: 2,
        pointRadius: pts > 45 ? 0 : 3, pointHoverRadius: 6, pointBackgroundColor: "#FFFDF7", pointBorderColor: C.primary, pointBorderWidth: 2 }] },
      options: { interaction: { mode: "index", intersect: false }, scales: ML.axes({ money: true }),
        plugins: { tooltip: { callbacks: { label: function (c) { return " Revenue: " + ML.money(c.parsed.y); } } } } }
    });
    emptyNote("revChart", allZero(s.revenue), "No completed-order revenue in this period yet.");
    document.getElementById("revSub").textContent = "By " + s.granularity + ", " + data.range.label;
    document.querySelectorAll("#granularity [data-g]").forEach(function (b) {
      var on = b.getAttribute("data-g") === s.granularity;
      b.classList.toggle("active", on); b.setAttribute("aria-pressed", on ? "true" : "false");
    });
    ML.dataTable(document.getElementById("revTable"), ["Period", "Revenue", "Orders"],
      s.labels.map(function (l, i) { return [l, ML.money(s.revenue[i]), s.orders[i]]; }));
  }

  function orders(s) {
    make("ordersChart", {
      type: "bar",
      data: { labels: s.labels, datasets: [{ label: "Orders", data: s.orders, backgroundColor: C.secondary, hoverBackgroundColor: C.primary, borderRadius: 4, borderSkipped: "start", maxBarThickness: 26 }] },
      options: { scales: ML.axes(), plugins: { tooltip: { callbacks: { label: function (c) { return " Orders: " + c.parsed.y; } } } } }
    });
    emptyNote("ordersChart", allZero(s.orders), "No orders in this period yet.");

    make("ccChart", {
      type: "bar",
      data: { labels: s.labels, datasets: [
        { label: "Completed", data: s.completed, backgroundColor: C.status.completed, borderRadius: 4, borderSkipped: "start", maxBarThickness: 18 },
        { label: "Cancelled / declined", data: s.cancelled, backgroundColor: C.status.declined, borderRadius: 4, borderSkipped: "start", maxBarThickness: 18 }
      ] },
      options: { interaction: { mode: "index", intersect: false }, scales: ML.axes() }
    });
    emptyNote("ccChart", allZero(s.completed) && allZero(s.cancelled));
  }

  function statuses(st) {
    var order = C.statusOrder;
    var total = order.reduce(function (sum, k) { return sum + st[k]; }, 0);
    document.querySelector("[data-status-total]").textContent = total;
    document.getElementById("statusLegend").innerHTML = order.map(function (s) {
      var pct = total ? Math.round(st[s] / total * 100) : 0;
      return '<div class="status-row"><i style="background:' + C.status[s] + '"></i>' + ML.statusLabel(s) + "<b>" + st[s] + "</b><small>" + pct + "%</small></div>";
    }).join("");
    make("statusChart", {
      type: "doughnut",
      data: { labels: order.map(ML.statusLabel), datasets: [{
        data: total ? order.map(function (s) { return st[s]; }) : [1],
        backgroundColor: total ? order.map(function (s) { return C.status[s]; }) : ["#E9ECE3"],
        borderColor: "#FFFDF7", borderWidth: 2, hoverOffset: 6 }] },
      options: { cutout: "72%", plugins: { tooltip: { enabled: total > 0 } } }
    });
  }

  function hbar(id, labels, values, label, money) {
    make(id, {
      type: "bar",
      data: { labels: labels, datasets: [{ label: label, data: values, backgroundColor: C.secondary, hoverBackgroundColor: C.primary, borderRadius: 4, borderSkipped: "start", maxBarThickness: 22 }] },
      options: {
        indexAxis: "y",
        scales: {
          x: { beginAtZero: true, border: { display: false }, grid: { color: C.grid }, ticks: { maxTicksLimit: 5, callback: money ? function (v) { return v >= 1000 ? "Rs." + v / 1000 + "k" : "Rs." + v; } : undefined } },
          y: { grid: { display: false }, border: { display: false }, ticks: { color: C.text } }
        },
        plugins: { tooltip: { callbacks: { label: function (c) { return " " + label + ": " + (money ? ML.money(c.parsed.x) : ML.num(c.parsed.x)); } } } }
      }
    });
    emptyNote(id, !values.length, "No completed sales in this period yet.");
  }

  function products(list) {
    var byQty = productMetric === "qty";
    hbar("productChart", list.map(function (p) { return p.name; }), list.map(function (p) { return byQty ? p.qty : p.revenue; }), byQty ? "Quantity sold" : "Revenue", !byQty);
    var wrap = document.getElementById("productTableWrap");
    if (!list.length) { wrap.innerHTML = '<p class="muted" style="padding:20px">No completed sales in this period yet.</p>'; return; }
    ML.dataTable(wrap, ["Product", "Sold", "Revenue", "Orders"], list.map(function (p) { return [p.name, p.qty_label, p.revenue_label, p.orders]; }));
  }

  function render() {
    document.querySelector("[data-range-label]").textContent = data.range.label;
    kpis(data.kpis);
    revenue(data.series);
    orders(data.series);
    statuses(data.statuses);
    products(data.products);
    hbar("catChart", data.categories.map(function (c) { return c.name; }), data.categories.map(function (c) { return c.revenue; }), "Revenue", true);
    make("weekdayChart", {
      type: "bar",
      data: { labels: data.weekdays.labels, datasets: [{ label: "Orders", data: data.weekdays.orders, backgroundColor: C.olive, hoverBackgroundColor: C.secondary, borderRadius: 4, borderSkipped: "start", maxBarThickness: 32 }] },
      options: { scales: ML.axes() }
    });
    emptyNote("weekdayChart", allZero(data.weekdays.orders), "No orders in this period yet.");
  }

  function load() {
    document.querySelectorAll(".chart-box").forEach(function (b) {
      if (!b.querySelector(".loading-overlay")) b.insertAdjacentHTML("beforeend", '<div class="loading-overlay"><span class="spinner"></span></div>');
    });
    return ML.api("api/vendor/analytics.php", { query: params })
      .then(function (res) { data = res; render(); })
      .catch(function (err) { ML.toast(err.message, "error"); })
      .then(function () { document.querySelectorAll(".chart-box .loading-overlay").forEach(function (o) { o.remove(); }); });
  }

  ML.rangeFilter(document.querySelector("#rangeRoot [data-range-filter]"), function (p) {
    params = p; // new range -> automatic granularity
    load();
  });

  document.getElementById("granularity").addEventListener("click", function (e) {
    var b = e.target.closest("[data-g]");
    if (!b) return;
    params = Object.assign({}, params, { granularity: b.getAttribute("data-g") });
    load();
  });

  document.getElementById("productMetric").addEventListener("click", function (e) {
    var b = e.target.closest("[data-pm]");
    if (!b || !data) return;
    productMetric = b.getAttribute("data-pm");
    this.querySelectorAll("[data-pm]").forEach(function (x) { x.classList.toggle("active", x === b); x.setAttribute("aria-pressed", x === b ? "true" : "false"); });
    products(data.products);
  });

  document.querySelectorAll("[data-table-toggle]").forEach(function (b) {
    b.addEventListener("click", function () {
      var t = document.getElementById(b.getAttribute("data-table-toggle"));
      t.hidden = !t.hidden;
      b.textContent = t.hidden ? "Show data table" : "Hide data table";
    });
  });

  load();
})();
