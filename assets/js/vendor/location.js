/* Stall location page: search, drag the pin, use my location, save */
(function () {
  "use strict";

  var mapEl = document.getElementById("map");
  var form = document.getElementById("locationForm");
  var el = form.elements;
  var results = document.getElementById("geoResults");
  var hint = document.getElementById("addrHint");
  var map = MLMap.create(mapEl);
  var marker = null;
  var dirty = false;

  function setPoint(lat, lng, opts) {
    opts = opts || {};
    lat = Number(lat); lng = Number(lng);
    if (!marker) {
      marker = L.marker([lat, lng], { draggable: true, icon: MLMap.pinIcon(), keyboard: true, title: "Stall location" }).addTo(map);
      marker.on("dragend", function () {
        var p = marker.getLatLng();
        setPoint(p.lat, p.lng, { reverse: true });
      });
    } else {
      marker.setLatLng([lat, lng]);
    }
    el.latitude.value = lat.toFixed(7);
    el.longitude.value = lng.toFixed(7);
    document.getElementById("latOut").textContent = lat.toFixed(6);
    document.getElementById("lngOut").textContent = lng.toFixed(6);
    if (opts.zoom) map.setView([lat, lng], opts.zoom);
    if (opts.label) el.location_address.value = opts.label;
    if (opts.reverse) reverse(lat, lng);
    dirty = true;
  }

  // look up the address after the pin is dropped
  var reverse = ML.debounce(function (lat, lng) {
    hint.textContent = "Looking up address...";
    ML.api("api/public/geocode.php", { query: { lat: lat.toFixed(5), lng: lng.toFixed(5) } })
      .then(function (res) {
        if (res.result && res.result.label) el.location_address.value = res.result.label;
        hint.textContent = "You can edit this text - coordinates come from the pin.";
      })
      .catch(function (err) { hint.textContent = err.message + " You can type the address yourself."; });
  }, 1200);

  if (el.latitude.value && el.longitude.value) {
    setPoint(el.latitude.value, el.longitude.value);
    dirty = false;
  }

  map.on("click", function (e) { setPoint(e.latlng.lat, e.latlng.lng, { reverse: true }); });

  /* search */
  document.getElementById("geoForm").addEventListener("submit", function (e) {
    e.preventDefault();
    var q = document.getElementById("geoQuery").value.trim();
    if (q.length < 3) { ML.toast("Type at least 3 characters to search.", "error"); return; }
    var btn = this.querySelector('[type="submit"]');
    ML.setLoading(btn, true);
    ML.api("api/public/geocode.php", { query: { q: q } })
      .then(function (res) {
        if (!res.results.length) {
          results.hidden = false;
          results.innerHTML = '<p class="map-hint">No places found for "' + ML.esc(q) + '". Try a nearby landmark or area name.</p>';
          return;
        }
        if (res.results.length === 1) {
          results.hidden = true;
          setPoint(res.results[0].lat, res.results[0].lng, { zoom: 17, label: res.results[0].label });
          return;
        }
        results.hidden = false;
        results.innerHTML = res.results.map(function (r, i) {
          return '<button type="button" data-i="' + i + '">' + ML.icon("i-pin") + "<span>" + ML.esc(r.label) + "</span></button>";
        }).join("");
        results.onclick = function (ev) {
          var b = ev.target.closest("[data-i]");
          if (!b) return;
          var r = res.results[Number(b.getAttribute("data-i"))];
          setPoint(r.lat, r.lng, { zoom: 17, label: r.label });
          results.hidden = true;
        };
      })
      .catch(function (err) { ML.toast(err.message, "error"); })
      .then(function () { ML.setLoading(btn, false); });
  });

  /* browser geolocation */
  document.getElementById("useMyLocation").addEventListener("click", function () {
    var btn = this;
    ML.setLoading(btn, true);
    MLMap.locate()
      .then(function (p) { setPoint(p.lat, p.lng, { zoom: 17, reverse: true }); ML.toast("Pin moved to your current location."); })
      .catch(function (err) { ML.toast(err.message, "error"); })
      .then(function () { ML.setLoading(btn, false); });
  });

  /* save */
  form.addEventListener("submit", function (e) {
    e.preventDefault();
    if (!el.latitude.value || !el.longitude.value) { ML.toast("Place the marker on the map first.", "error"); return; }
    if (el.location_address.value.trim().length < 3) { ML.showErrors(form, { location_address: "Enter a readable address for customers." }); return; }
    var btn = form.querySelector('[type="submit"]');
    ML.setLoading(btn, true);
    ML.api("api/vendor/location.php", { body: { latitude: el.latitude.value, longitude: el.longitude.value, location_address: el.location_address.value.trim() } })
      .then(function (res) { ML.toast(res.message); dirty = false; ML.clearErrors(form); })
      .catch(function (err) { ML.showErrors(form, err.errors); ML.toast(err.message, "error"); })
      .then(function () { ML.setLoading(btn, false); });
  });

  window.addEventListener("beforeunload", function (e) { if (dirty) { e.preventDefault(); e.returnValue = ""; } });
  setTimeout(function () { map.invalidateSize(); }, 200);
})();
