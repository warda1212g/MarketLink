/* Homepage: favourites, add to cart, and refreshing the numbers from api/public/home.php */
(function () {
  "use strict";

  var csrf = document.querySelector('meta[name="csrf-token"]').content;
  var base = document.querySelector('meta[name="base-url"]').content || "";
  var toastEl = document.getElementById("homeToast");
  var toastTimer;

  function api(path, body) {
    return fetch(base + "/" + path, {
      method: body ? "POST" : "GET",
      headers: { "X-CSRF-Token": csrf, "Accept": "application/json", "Content-Type": "application/json" },
      body: body ? JSON.stringify(body) : undefined,
      credentials: "same-origin",
      cache: "no-store"
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (data) {
        if (!r.ok || data.ok === false) throw new Error(data.message || "Something went wrong. Please try again.");
        return data;
      });
    });
  }

  function toast(msg, isError) {
    if (!toastEl || !msg) return;
    toastEl.textContent = msg;
    toastEl.className = "home-toast" + (isError ? " error" : "");
    toastEl.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastEl.hidden = true; }, 3200);
  }

  function setText(el, value) {
    if (el.textContent === value) return;
    el.textContent = value;
    el.classList.remove("bumped");
    void el.offsetWidth; // restart the highlight animation
    el.classList.add("bumped");
  }

  document.addEventListener("click", function (e) {
    // Favourites
    var fav = e.target.closest("[data-fav-type]");
    if (fav) {
      e.preventDefault();
      if (fav.hasAttribute("data-login")) { location.href = fav.getAttribute("data-login"); return; }
      fav.disabled = true;
      api("api/public/favorite.php", { type: fav.dataset.favType, id: Number(fav.dataset.favId) })
        .then(function (res) {
          // update every heart for this item
          document.querySelectorAll('[data-fav-type="' + fav.dataset.favType + '"][data-fav-id="' + fav.dataset.favId + '"]').forEach(function (b) {
            b.classList.toggle("active", res.saved);
            b.setAttribute("aria-pressed", res.saved ? "true" : "false");
          });
          toast(res.message);
          var card = fav.closest("[data-fav-card]");
          if (card && !res.saved) {
            card.style.transition = "opacity .3s";
            card.style.opacity = "0";
            setTimeout(function () { card.remove(); }, 300);
          }
        })
        .catch(function (err) { toast(err.message, true); })
        .then(function () { fav.disabled = false; });
      return;
    }

    // Reserve for pickup (add to cart)
    var add = e.target.closest("[data-add-cart]");
    if (add) {
      e.preventDefault();
      add.disabled = true;
      api("api/public/cart.php", { action: "add", product_id: Number(add.dataset.addCart), quantity: Number(add.dataset.qty || 1) })
        .then(function (res) {
          toast((res.message || "Added to your cart.") + " ");
          var badge = document.querySelector("[data-cart-count]");
          if (badge && res.count !== undefined) badge.textContent = res.count;
          refresh();
        })
        .catch(function (err) { toast(err.message, true); })
        .then(function () { add.disabled = false; });
    }
  });

  // Live refresh
  var atEl = document.querySelector("[data-live-at]");
  var lastAt = Date.now();

  function refresh() {
    return api("api/public/home.php").then(function (data) {
      Object.keys(data.stats || {}).forEach(function (key) {
        document.querySelectorAll('[data-live="' + key + '"]').forEach(function (el) { setText(el, String(data.stats[key])); });
      });
      document.querySelectorAll("[data-live-cat]").forEach(function (el) {
        var n = data.categories && data.categories[el.dataset.liveCat];
        if (n !== undefined) setText(el, String(n));
      });
      document.querySelectorAll(".product-card[data-product]").forEach(function (card) {
        var s = data.stock && data.stock[card.dataset.product];
        var qtyEl = card.querySelector("[data-live-qty]");
        var priceEl = card.querySelector("[data-live-price]");
        if (!s) { card.classList.add("sold-out"); if (qtyEl) setText(qtyEl, "No longer listed"); return; }
        if (priceEl) setText(priceEl, s.price);
        if (qtyEl) setText(qtyEl, s.available ? s.qty : "Sold out");
        card.classList.toggle("sold-out", !s.available);
      });
      lastAt = Date.now();
      tick();
    }).catch(function () { /* keep the server-rendered values if the refresh fails */ });
  }

  function tick() {
    if (!atEl) return;
    var s = Math.round((Date.now() - lastAt) / 1000);
    atEl.textContent = s < 10 ? "just now" : s < 60 ? s + "s ago" : Math.floor(s / 60) + " min ago";
  }

  var REFRESH_MS = 30000;
  setInterval(function () { if (!document.hidden) refresh(); }, REFRESH_MS);
  setInterval(tick, 5000);
  document.addEventListener("visibilitychange", function () {
    if (!document.hidden && Date.now() - lastAt > REFRESH_MS) refresh();
  });
})();

// Discover nearby map with all the markets
(function () {
  var el = document.getElementById("homeMap");
  if (!el || !window.L) return;
  var markets = [];
  try { markets = JSON.parse(el.dataset.markets || "[]"); } catch (e) {}
  var map = L.map(el, { zoomControl: false, scrollWheelZoom: false, attributionControl: true });
  map.attributionControl.setPrefix(false);
  L.control.zoom({ position: "topright" }).addTo(map);
  L.tileLayer(el.dataset.tiles, {
    maxZoom: 18,
    attribution: el.dataset.attribution
  }).addTo(map);

  var icon = L.divIcon({ className: "home-pin", html: "<span></span>", iconSize: [26, 26], iconAnchor: [13, 13] });
  var base = document.querySelector('meta[name="base-url"]');
  var root = base ? base.content : "";
  var bounds = [];
  markets.forEach(function (m) {
    var card = document.createElement("a");
    card.href = root + "/market-view.php?id=" + m.id;
    card.className = "home-pop";
    card.innerHTML = "<strong></strong><small></small>";
    card.firstChild.textContent = m.name;
    card.lastChild.textContent = (m.type ? m.type + ", " : "") + m.city;
    L.marker([m.lat, m.lng], { icon: icon, title: m.name }).addTo(map).bindPopup(card, { closeButton: false, offset: [0, -8] });
    bounds.push([m.lat, m.lng]);
  });
  if (bounds.length) map.fitBounds(bounds, { padding: [36, 36], maxZoom: 7 });
  else map.setView([30.4, 69.3], 5);
  // map is inside a scroll-reveal block, so fix its size once it shows
  setTimeout(function () { map.invalidateSize(); if (bounds.length) map.fitBounds(bounds, { padding: [36, 36], maxZoom: 7 }); }, 900);
})();
