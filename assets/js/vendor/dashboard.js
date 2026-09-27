/* Vendor dashboard: sales and order status charts */
(function () {
  "use strict";
  var C = ML.colors;

  /* Sales overview */
  var card = document.getElementById("salesCard");
  var canvas = document.getElementById("salesChart");
  var loading = card.querySelector("[data-loading]");
  var metric = "revenue";
  var data = null;
  var chart = null;

  function draw() {
    if (!data) return;
    var isRev = metric === "revenue";
    var values = data.series[metric];
    var ctx = canvas.getContext("2d");
    var grad = ctx.createLinearGradient(0, 0, 0, canvas.parentNode.clientHeight);
    grad.addColorStop(0, "rgba(79,107,60,0.22)");
    grad.addColorStop(1, "rgba(79,107,60,0)");

    var dataset = isRev
      ? { type: "line", label: "Revenue", data: values, borderColor: C.primary, backgroundColor: grad, fill: true, tension: 0.35, borderWidth: 2,
          pointRadius: values.length > 40 ? 0 : 3, pointHoverRadius: 6, pointBackgroundColor: C.card || "#FFFDF7", pointBorderColor: C.primary, pointBorderWidth: 2 }
      : { type: "bar", label: "Orders", data: values, backgroundColor: C.secondary, hoverBackgroundColor: C.primary, borderRadius: 4, borderSkipped: "start", maxBarThickness: 28 };

    if (chart) chart.destroy();
    chart = new Chart(canvas, {
      data: { labels: data.series.labels, datasets: [dataset] },
      options: {
        interaction: { mode: "index", intersect: false },
        scales: ML.axes({ money: isRev }),
        plugins: { tooltip: { callbacks: { label: function (c) { return " " + c.dataset.label + ": " + (isRev ? ML.money(c.parsed.y) : c.parsed.y); } } } }
      }
    });

    var empty = values.every(function (v) { return !v; });
    var note = card.querySelector(".chart-empty");
    if (empty && !note) {
      note = document.createElement("div");
      note.className = "chart-empty";
      canvas.parentNode.appendChild(note);
    }
    if (note) {
      note.hidden = !empty;
      note.textContent = isRev ? "No completed-order revenue in this period yet." : "No orders in this period yet.";
    }

    ML.dataTable(document.getElementById("salesTable"), ["Period", "Revenue", "Orders"],
      data.series.labels.map(function (l, i) { return [l, ML.money(data.series.revenue[i]), data.series.orders[i]]; }));
  }

  function load(params) {
    loading.hidden = false;
    ML.api("api/vendor/sales.php", { query: params })
      .then(function (res) {
        data = res;
        card.querySelector("[data-range-label]").textContent = res.range.label;
        card.querySelector('[data-total="revenue"]').textContent = res.totals.revenue.label;
        card.querySelector('[data-total="orders"]').textContent = res.totals.orders.label;
        card.querySelector('[data-total="aov"]').textContent = res.totals.aov.label;
        draw();
      })
      .catch(function (err) { ML.toast(err.message, "error"); })
      .then(function () { loading.hidden = true; });
  }

  card.querySelectorAll("[data-metric]").forEach(function (b) {
    b.addEventListener("click", function () {
      metric = b.getAttribute("data-metric");
      card.querySelectorAll("[data-metric]").forEach(function (x) { x.classList.toggle("active", x === b); x.setAttribute("aria-pressed", x === b ? "true" : "false"); });
      draw();
    });
  });

  ML.rangeFilter(card.querySelector("[data-range-filter]"), load);
  load({ range: "30d" });

  document.querySelectorAll("[data-table-toggle]").forEach(function (b) {
    b.addEventListener("click", function () {
      var t = document.getElementById(b.getAttribute("data-table-toggle"));
      t.hidden = !t.hidden;
      b.textContent = t.hidden ? "Show data table" : "Hide data table";
    });
  });

  /* Order status doughnut */
  var counts = JSON.parse(document.getElementById("statusData").textContent);
  var total = Object.keys(counts).reduce(function (s, k) { return s + counts[k]; }, 0);
  var order = C.statusOrder;
  var legend = document.getElementById("statusLegend");
  legend.innerHTML = order.map(function (s) {
    var pct = total ? Math.round(counts[s] / total * 100) : 0;
    return '<div class="status-row"><i style="background:' + C.status[s] + '"></i>' + ML.statusLabel(s) + "<b>" + counts[s] + "</b><small>" + pct + "%</small></div>";
  }).join("");

  new Chart(document.getElementById("statusChart"), {
    type: "doughnut",
    data: {
      labels: order.map(ML.statusLabel),
      datasets: [{
        data: total ? order.map(function (s) { return counts[s]; }) : [1],
        backgroundColor: total ? order.map(function (s) { return C.status[s]; }) : ["#E9ECE3"],
        borderColor: "#FFFDF7", borderWidth: 2, hoverOffset: 6
      }]
    },
    options: { cutout: "72%", plugins: { tooltip: { enabled: total > 0 } } }
  });
})();
