/* Find Markets Near You: search a place or use the browser location, then load nearby stalls */
(function () {
  "use strict";

  var init = JSON.parse(document.getElementById("finderInit").textContent);
  var form = document.getElementById("finderForm");
  var list = document.getElementById("marketList");
  var choices = document.getElementById("placeChoices");
  var radiusSel = document.getElementById("radius");
  var map = MLMap.create(document.getElementById("map"));
  var layer = L.layerGroup().addTo(map);
  var circle = null;
  var me = null;
  var markers = {};
  var current = null;

  function searching(text) {
    list.innerHTML = '<div class="card"><div class="searching"><span class="spinner"></span>' + ML.esc(text) + "</div></div>";
  }

  function stars(r) {
    return r === null ? '<span class="muted">No ratings yet</span>' : '<span class="stars" style="vertical-align:-2px"><svg><use href="#i-star"/></svg></span> <strong>' + r.toFixed(1) + "</strong>";
  }

  function item(v) {
    var status = v.closed
      ? '<span class="pill pill-declined">Orders closed</span>'
      : v.accepting ? '<span class="pill pill-completed">Taking pre-orders</span>' : '<span class="pill pill-muted">No upcoming market</span>';
    return '<article class="market-item fade-in" data-vendor="' + v.id + '">' +
      '<span class="market-thumb"' + (v.image ? ' style="background-image:url(\'' + ML.esc(v.image) + '\')"' : "") + ">" + (v.image ? "" : ML.icon("i-store")) + "</span>" +
      "<div><h3><span>" + ML.esc(v.name) + '</span><span class="dist">' + ML.esc(v.distance) + "</span></h3>" +
      '<p class="muted" style="font-size:13px;margin-top:2px">' + ML.esc(v.address || "") + "</p>" +
      '<div class="market-meta"><span>' + ML.icon("i-calendar") + ML.esc(v.days) + "</span>" +
      (v.pickup ? "<span>" + ML.icon("i-clock") + ML.esc(v.pickup) + "</span>" : "") +
      "<span>" + stars(v.rating) + (v.review_count ? " (" + v.review_count + ")" : "") + "</span>" +
      "<span>" + ML.icon("i-box") + v.product_count + " in stock</span></div>" +
      '<div class="market-foot">' + status + (v.closed && v.next_pickup ? '<small class="muted">Next pickup: ' + ML.esc(v.next_pickup) + "</small>" : "") +
      '<a class="btn btn-primary btn-sm" href="' + ML.esc(v.url) + '">View Market ' + ML.icon("i-arrow") + "</a></div></div></article>";
  }

  function search(lat, lng, label) {
    current = { lat: lat, lng: lng, label: label };
    var radius = Number(radiusSel.value);
    choices.hidden = true;
    searching("Searching nearby markets...");

    layer.clearLayers();
    markers = {};
    if (me) me.remove();
    if (circle) circle.remove();
    me = L.marker([lat, lng], { icon: MLMap.meIcon(), title: "You are here", zIndexOffset: 1000 }).addTo(map).bindPopup("You are here");
    circle = L.circle([lat, lng], { radius: radius * 1000, color: "#4F6B3C", weight: 1.5, fillColor: "#879B62", fillOpacity: 0.07 }).addTo(map);
    map.fitBounds(circle.getBounds(), { padding: [20, 20] });

    var u = new URL(location.href);
    u.searchParams.set("lat", lat.toFixed(5)); u.searchParams.set("lng", lng.toFixed(5)); u.searchParams.set("radius", radius);
    history.replaceState(null, "", u);

    return ML.api("api/public/nearby.php", { query: { lat: lat, lng: lng, radius: radius } })
      .then(function (res) {
        document.getElementById("resultsTitle").textContent = res.count ? "Markets near you" : "No nearby markets found";
        document.getElementById("resultsSub").textContent = res.count
          ? res.count + " market" + (res.count > 1 ? "s" : "") + " within " + res.radius + " km" + (label ? " of " + label : "") + ", nearest first"
          : "Nothing within " + res.radius + " km" + (label ? " of " + label : "") + ".";
        if (!res.count) {
          var bigger = [5, 10, 20, 50].filter(function (r) { return r > res.radius; })[0];
          list.innerHTML = '<div class="card"><div class="empty"><div class="empty-icon">' + ML.icon("i-pin") + "</div><h4>No nearby markets found</h4><p>Try a wider search radius or a different area.</p>" +
            (bigger ? '<button class="btn btn-primary" data-widen="' + bigger + '">Search within ' + bigger + " km</button>" : "") + "</div></div>";
          return;
        }
        list.innerHTML = res.vendors.map(item).join("");
        res.vendors.forEach(function (v) {
          var popup = document.createElement("div");
          popup.innerHTML = "<strong></strong><br><small></small><br>";
          popup.querySelector("strong").textContent = v.name;
          popup.querySelector("small").textContent = v.distance;
          var a = document.createElement("a"); a.href = v.url; a.textContent = "View market"; a.className = "link";
          popup.appendChild(a);
          markers[v.id] = L.marker([v.lat, v.lng], { icon: MLMap.pinIcon(), title: v.name }).addTo(layer).bindPopup(popup);
        });
      })
      .catch(function (err) {
        list.innerHTML = '<div class="alert alert-error">' + ML.esc(err.message) + "</div>";
      });
  }

  // scroll down to the results after searching
  function goToResults() {
    var sec = document.getElementById("near-you");
    if (!sec) return;
    var reduce = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    sec.scrollIntoView({ behavior: reduce ? "auto" : "smooth", block: "start" });
    if (location.hash !== "#near-you") history.replaceState(null, "", location.pathname + location.search + "#near-you");
  }

  form.addEventListener("submit", function (e) {
    e.preventDefault();
    var q = form.q.value.trim();
    if (q.length < 3) { ML.toast("Type at least 3 characters, or use your current location.", "error"); form.q.focus(); return; }
    form.q.blur();
    goToResults();
    var btn = form.querySelector('[type="submit"]');
    ML.setLoading(btn, true);
    searching("Finding \"" + q + "\"...");
    ML.api("api/public/geocode.php", { query: { q: q } })
      .then(function (res) {
        if (!res.results.length) {
          list.innerHTML = '<div class="card"><div class="empty"><div class="empty-icon">' + ML.icon("i-search") + "</div><h4>We couldn't find that place</h4><p>Try a nearby landmark, area or city name.</p></div></div>";
          return;
        }
        if (res.results.length === 1) return search(res.results[0].lat, res.results[0].lng, q);
        list.innerHTML = '<div class="card"><p class="card-sub" style="margin-bottom:8px">Which one did you mean?</p><div class="geo-results" style="max-height:none">' +
          res.results.map(function (r, i) { return '<button type="button" data-pick="' + i + '">' + ML.icon("i-pin") + "<span>" + ML.esc(r.label) + "</span></button>"; }).join("") + "</div></div>";
        list.onclick = function (ev) {
          var b = ev.target.closest("[data-pick]");
          if (!b) return;
          list.onclick = null;
          var r = res.results[Number(b.getAttribute("data-pick"))];
          search(r.lat, r.lng, r.label.split(",")[0]);
        };
      })
      .catch(function (err) { list.innerHTML = '<div class="alert alert-error">' + ML.esc(err.message) + "</div>"; })
      .then(function () { ML.setLoading(btn, false); });
  });

  document.getElementById("useLocation").addEventListener("click", function () {
    var btn = this;
    ML.setLoading(btn, true);
    goToResults();
    searching("Getting your location...");
    // coordinates only go to our own server
    MLMap.locate()
      .then(function (p) { return search(p.lat, p.lng, "your location"); })
      .catch(function (err) {
        list.innerHTML = '<div class="alert alert-warn">' + ML.esc(err.message) + "</div>";
      })
      .then(function () { ML.setLoading(btn, false); });
  });

  radiusSel.addEventListener("change", function () { if (current) search(current.lat, current.lng, current.label); });

  list.addEventListener("click", function (e) {
    var w = e.target.closest("[data-widen]");
    if (w) { radiusSel.value = w.getAttribute("data-widen"); search(current.lat, current.lng, current.label); }
  });
  list.addEventListener("mouseover", function (e) {
    var it = e.target.closest("[data-vendor]");
    if (it && markers[it.getAttribute("data-vendor")]) markers[it.getAttribute("data-vendor")].openPopup();
  });

  if (init.locate) document.getElementById("useLocation").click();
  else if (form.q.value.trim().length >= 3) form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event("submit", { cancelable: true }));
  else if (init.start) search(Number(init.start.lat), Number(init.start.lng), "");
  setTimeout(function () { map.invalidateSize(); }, 200);
})();
