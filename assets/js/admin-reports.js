/* Charts for the admin reports page. All numbers come from the server. */
(function () {
  "use strict";

  var C = {
    forest: "#123D24", natural: "#2F6B3F", soft: "#6F9F72", beige: "#EAE2CE",
    gold: "#C79A3B", rust: "#B3543E", sky: "#4F7FA8", inkSoft: "rgba(19,42,28,0.66)", line: "rgba(19,42,28,0.08)"
  };
  var dataEl = document.getElementById("raData");
  var D = dataEl ? JSON.parse(dataEl.textContent) : null;

  // custom date fields follow the period select
  document.querySelectorAll("[data-period-select]").forEach(function (sel) {
    var form = sel.closest("form");
    var sync = function () {
      form.querySelectorAll("[data-custom-date]").forEach(function (el) { el.hidden = sel.value !== "custom"; });
      var lbl = form.querySelector("[data-start-label]");
      if (lbl) lbl.textContent = sel.value === "custom" ? "Start Date" : ({ week: "Any date in the week", month: "Any date in the month", year: "Any date in the year" })[sel.value];
    };
    sel.addEventListener("change", sync);
    sync();
  });

  // show a loading state, the AI summary can take a few seconds
  var gen = document.querySelector("[data-generate-form]");
  if (gen) gen.addEventListener("submit", function () {
    var b = gen.querySelector("[data-generate-btn]");
    b.disabled = true;
    b.lastChild.textContent = " Generating...";
  });

  // print
  document.querySelectorAll("[data-print]").forEach(function (b) { b.addEventListener("click", function () { window.print(); }); });
  if (/[?&]print=1/.test(location.search) && document.getElementById("report-result")) {
    setTimeout(function () { window.print(); }, 600);
  }

  if (!D || typeof Chart === "undefined") return;

  Chart.defaults.font.family = "'Manrope', sans-serif";
  Chart.defaults.font.size = 12;
  Chart.defaults.color = C.inkSoft;
  Chart.defaults.plugins.tooltip.backgroundColor = "#0B2416";

  var money = function (v) { return D.currency + " " + Number(v).toLocaleString(); };

  // Orders / order value over time
  var seriesEl = document.getElementById("seriesChart");
  if (seriesEl) {
    var metric = "orders";
    var series = new Chart(seriesEl, {
      type: "bar",
      data: {
        labels: D.series.map(function (s) { return s.label; }),
        datasets: [{ label: "Orders", data: D.series.map(function (s) { return s.orders; }), backgroundColor: C.natural, borderRadius: 4, maxBarThickness: 36 }]
      },
      options: {
        maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (ctx) { return metric === "orders" ? ctx.raw + " orders" : money(ctx.raw); } } } },
        scales: {
          x: { grid: { display: false } },
          y: { beginAtZero: true, grid: { color: C.line }, ticks: { precision: 0, callback: function (v) { return metric === "orders" ? v : money(v); } } }
        }
      }
    });
    document.querySelectorAll("#seriesMetric button").forEach(function (btn) {
      btn.addEventListener("click", function () {
        metric = btn.dataset.value;
        document.querySelectorAll("#seriesMetric button").forEach(function (b) { b.classList.toggle("active", b === btn); });
        series.data.datasets[0].label = metric === "orders" ? "Orders" : "Order Value";
        series.data.datasets[0].data = D.series.map(function (s) { return s[metric]; });
        series.data.datasets[0].backgroundColor = metric === "orders" ? C.natural : C.gold;
        series.update();
      });
    });
  }

  // Order status distribution
  var statusEl = document.getElementById("statusChart");
  if (statusEl) {
    new Chart(statusEl, {
      type: "doughnut",
      data: {
        labels: Object.keys(D.status),
        datasets: [{ data: Object.values(D.status), backgroundColor: [C.gold, C.sky, C.soft, C.natural, C.rust], borderWidth: 0 }]
      },
      options: { maintainAspectRatio: false, cutout: "62%", plugins: { legend: { position: "bottom", labels: { boxWidth: 10, padding: 12 } } } }
    });
  }

  // Order value by market
  var marketEl = document.getElementById("marketChart");
  if (marketEl && D.markets.length) {
    new Chart(marketEl, {
      type: "bar",
      data: {
        labels: D.markets.map(function (m) { return m.name; }),
        datasets: [{ label: "Order Value", data: D.markets.map(function (m) { return m.value; }), backgroundColor: C.natural, borderRadius: 4, maxBarThickness: 28 }]
      },
      options: {
        indexAxis: "y", maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (ctx) { return money(ctx.raw) + ", " + D.markets[ctx.dataIndex].orders + " orders"; } } } },
        scales: { x: { beginAtZero: true, grid: { color: C.line }, ticks: { callback: function (v) { return money(v); } } }, y: { grid: { display: false } } }
      }
    });
  }

  // Rating distribution
  var ratingEl = document.getElementById("ratingChart");
  if (ratingEl) {
    var stars = ["5", "4", "3", "2", "1"];
    new Chart(ratingEl, {
      type: "bar",
      data: {
        labels: stars.map(function (s) { return s + (s === 1 ? " star" : " stars"); }),
        datasets: [{ data: stars.map(function (s) { return D.ratings[s] || 0; }), backgroundColor: [C.natural, C.soft, C.gold, "#D08A5A", C.rust], borderRadius: 4 }]
      },
      options: {
        indexAxis: "y", maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: C.line } }, y: { grid: { display: false } } }
      }
    });
  }
})();
