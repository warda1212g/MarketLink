/* products.php: refresh prices and stock every 30 seconds */
(function () {
  "use strict";

  var REFRESH_MS = 30000;
  var lastAt = Date.now();
  var atEl = document.querySelector("[data-live-at]");

  function setText(el, value) {
    if (!el || el.textContent === value) return;
    el.textContent = value;
    el.classList.remove("bumped");
    void el.offsetWidth;
    el.classList.add("bumped");
  }

  function refresh() {
    ML.api("api/public/home.php").then(function (data) {
      document.querySelectorAll(".shop-card[data-product]").forEach(function (card) {
        var s = data.stock && data.stock[card.dataset.product];
        var available = !!(s && s.available);
        if (s) setText(card.querySelector("[data-live-price]"), s.price);
        setText(card.querySelector("[data-live-qty]"), s ? (available ? s.qty : "Sold out") : "No longer listed");
        card.classList.toggle("sold-out", !available);
        var pill = card.querySelector("[data-live-pill]");
        if (pill && !available) { pill.textContent = "Sold out"; pill.className = "pill pill-sold_out"; }
        var row = card.querySelector("[data-cart-row]");
        if (row) row.hidden = !available;
        var qty = card.querySelector("[data-qty]");
        if (qty && s) qty.max = parseFloat(s.qty) || qty.max;
      });
      document.querySelectorAll("[data-live-cat]").forEach(function (el) {
        var n = data.categories && data.categories[el.dataset.liveCat];
        if (n !== undefined) setText(el, String(n));
      });
      var total = document.querySelector("[data-live-total]");
      if (total && data.stats) setText(total, String(data.stats.products).replace(/,/g, ""));
      lastAt = Date.now();
      tick();
    }).catch(function () { /* keep what the server rendered */ });
  }

  function tick() {
    if (!atEl) return;
    var s = Math.round((Date.now() - lastAt) / 1000);
    atEl.textContent = s < 10 ? "just now" : s < 60 ? s + "s ago" : Math.floor(s / 60) + " min ago";
  }

  setInterval(function () { if (!document.hidden) refresh(); }, REFRESH_MS);
  setInterval(tick, 5000);
  document.addEventListener("visibilitychange", function () {
    if (!document.hidden && Date.now() - lastAt > REFRESH_MS) refresh();
  });
})();
