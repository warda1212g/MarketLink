/* Add / edit market modal. Use MLMarketForm.open(market, onSaved). */
(function () {
  "use strict";

  var A = window.MLAdmin;
  var modalEl = document.getElementById("marketModal");
  if (!modalEl) return;
  var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
  var form = document.getElementById("marketForm");
  var preview = document.getElementById("mkImagePreview");
  var removeBtn = document.getElementById("mkRemoveImage");
  var submitBtn = document.getElementById("mkSubmit");
  var onSaved = null;

  // map location
  var mapEl = document.getElementById("mkMap");
  var locText = document.getElementById("mkLocText");
  var clearLoc = document.getElementById("mkClearLoc");
  var placeResults = document.getElementById("mkPlaceResults");
  var map = null, marker = null, pending = null;
  var GEO = "../api/public/geocode.php";

  function ensureMap() {
    if (map || !window.L || !window.MLMap) return;
    map = MLMap.create(mapEl);
    map.on("click", function (e) { setPin(e.latlng.lat, e.latlng.lng, true); });
  }

  function setPin(lat, lng, lookup, zoom) {
    lat = Number(lat); lng = Number(lng);
    form.latitude.value = lat.toFixed(7);
    form.longitude.value = lng.toFixed(7);
    clearLoc.hidden = false;
    locText.textContent = "Pinned at " + lat.toFixed(5) + ", " + lng.toFixed(5) + " - drag the pin to adjust.";
    if (!map) { pending = [lat, lng]; return; }
    if (!marker) {
      marker = L.marker([lat, lng], { draggable: true, icon: MLMap.pinIcon() }).addTo(map);
      marker.on("dragend", function () { var p = marker.getLatLng(); setPin(p.lat, p.lng, true); });
    } else {
      marker.setLatLng([lat, lng]);
    }
    map.setView([lat, lng], zoom || Math.max(map.getZoom(), 15));
    if (lookup) reverse(lat, lng);
  }

  function clearPin() {
    form.latitude.value = "";
    form.longitude.value = "";
    clearLoc.hidden = true;
    pending = null;
    if (marker) { map.removeLayer(marker); marker = null; }
    locText.textContent = "Optional: search a place or click the map to pin the market. The pin can be dragged.";
  }

  // fill the address from the pin if the admin left it empty
  function reverse(lat, lng) {
    A.api(GEO, { query: { lat: lat.toFixed(5), lng: lng.toFixed(5) } }).then(function (d) {
      if (!d.result || !d.result.label) return;
      locText.textContent = d.result.label;
      if (!form.address.value.trim()) form.address.value = d.result.label.split(",").slice(0, 3).join(",").trim().slice(0, 255);
    }).catch(function () { /* the pin is saved even if the lookup fails */ });
  }


  clearLoc.addEventListener("click", clearPin);

  function searchPlace() {
    var q = document.getElementById("mkPlace").value.trim();
    if (q.length < 3) { A.toast("Type at least 3 characters to search.", true); return; }
    placeResults.hidden = false;
    placeResults.innerHTML = '<span class="adm-skel adm-skel-row"></span>';
    A.api(GEO, { query: { q: q } }).then(function (d) {
      var list = d.results || [];
      placeResults.innerHTML = list.length ? list.map(function (r, i) {
        return '<button type="button" data-i="' + i + '">' + A.esc(r.label) + "</button>";
      }).join("") : '<p class="form-text m-0">No places found. Try a nearby landmark.</p>';
      placeResults.querySelectorAll("button").forEach(function (b) {
        b.addEventListener("click", function () {
          var r = list[b.dataset.i];
          setPin(r.lat, r.lng, false, 16);
          locText.textContent = r.label;
          placeResults.hidden = true;
        });
      });
    }).catch(function (err) { placeResults.innerHTML = '<p class="form-text m-0">' + A.esc(err.message) + "</p>"; });
  }
  document.getElementById("mkPlaceBtn").addEventListener("click", searchPlace);
  document.getElementById("mkPlace").addEventListener("keydown", function (e) {
    if (e.key === "Enter") { e.preventDefault(); searchPlace(); }
  });

  // build the map after the modal is visible, Leaflet needs the size
  modalEl.addEventListener("shown.bs.modal", function () {
    ensureMap();
    if (!map) return;
    map.invalidateSize();
    if (pending) { var p = pending; pending = null; setPin(p[0], p[1], false, 15); }
  });

  function setPreview(src) {
    preview.style.backgroundImage = src ? "url('" + src.replace(/'/g, "%27") + "')" : "";
    preview.classList.toggle("has-image", !!src);
  }

  function open(m, callback) {
    onSaved = callback || null;
    form.reset();
    A.clearErrors(form);
    form.id.value = m ? m.id : "";
    form.remove_image.value = "";
    document.getElementById("marketModalTitle").textContent = m ? "Edit Market" : "Add New Market";
    submitBtn.querySelector("span").textContent = m ? "Save Changes" : "Create Market";
    setPreview(m && m.image);
    clearPin();
    placeResults.hidden = true;
    document.getElementById("mkPlace").value = "";
    if (m && m.latitude !== null && m.latitude !== undefined) {
      setPin(m.latitude, m.longitude, false, 15);
      if (map) { pending = [m.latitude, m.longitude]; } // re-centre after the modal opens
    } else if (map) {
      map.setView([Number(mapEl.dataset.lat), Number(mapEl.dataset.lng)], Number(mapEl.dataset.zoom));
    }
    removeBtn.hidden = !(m && m.image);
    if (m) {
      form.name.value = m.name;
      form.market_type_id.value = m.market_type_id;
      form.description.value = m.description || "";
      form.city.value = m.city;
      form.area.value = m.area || "";
      form.address.value = m.address || "";
      form.opening_time.value = m.opening_time || "";
      form.closing_time.value = m.closing_time || "";
      form.querySelector('input[name="status"][value="' + m.status + '"]').checked = true;
      form.querySelectorAll('input[name="operating_days[]"]').forEach(function (cb) {
        cb.checked = m.operating_days.indexOf(cb.value) >= 0;
      });
    }
    modal.show();
  }

  function validate() {
    var e = {};
    var name = form.name.value.trim();
    if (name.length < 3) e.name = "Market name must be at least 3 characters.";
    else if (!/^[\p{L}\p{N}][\p{L}\p{N} &'().,\/-]*$/u.test(name)) e.name = "Use letters, numbers, spaces and & ' ( ) . , / - only.";
    if (!form.market_type_id.value) e.market_type_id = "Choose a market type.";
    var city = form.city.value.trim();
    if (city.length < 2 || !/^[\p{L} .'-]+$/u.test(city)) e.city = "Enter a valid city (letters only).";
    if (!form.opening_time.value) e.opening_time = "Enter an opening time.";
    if (!form.closing_time.value) e.closing_time = "Enter a closing time.";
    else if (form.opening_time.value && form.closing_time.value <= form.opening_time.value) e.closing_time = "Closing time must be after opening time.";
    if (!form.querySelector('input[name="operating_days[]"]:checked')) e.operating_days = "Select at least one operating day.";
    var file = form.image.files[0];
    if (file) {
      if (["image/jpeg", "image/png", "image/webp"].indexOf(file.type) < 0) e.image = "Only JPG, PNG or WEBP images are allowed.";
      else if (file.size > 3 * 1024 * 1024) e.image = "Image must be smaller than 3 MB.";
    }
    return e;
  }

  form.image.addEventListener("change", function () {
    var f = form.image.files[0];
    if (f && f.type.indexOf("image/") === 0) {
      var reader = new FileReader();
      reader.onload = function () { setPreview(reader.result); };
      reader.readAsDataURL(f);
      form.remove_image.value = "";
    }
  });
  removeBtn.addEventListener("click", function () {
    form.remove_image.value = "1";
    form.image.value = "";
    setPreview(null);
    removeBtn.hidden = true;
  });

  // live-clear an error once the user edits that field
  form.addEventListener("input", function (ev) {
    var t = ev.target;
    if (!t.name) return;
    t.classList.remove("is-invalid");
    var key = t.name.replace("[]", "");
    var box = form.querySelector('[data-error-for="' + key + '"]');
    if (box) box.textContent = "";
  });

  form.addEventListener("submit", function (ev) {
    ev.preventDefault();
    var errs = validate();
    if (Object.keys(errs).length) { A.showErrors(form, errs); return; }
    A.clearErrors(form);
    submitBtn.disabled = true;
    A.api("api/markets.php", { body: new FormData(form) })
      .then(function (res) {
        A.toast(res.message);
        modal.hide();
        if (onSaved) onSaved(res.market);
      })
      .catch(function (err) {
        A.showErrors(form, err.errors);
        if (!Object.keys(err.errors || {}).length) form.querySelector('[data-error-for="_form"]').textContent = err.message;
        A.toast(err.message, true);
      })
      .then(function () { submitBtn.disabled = false; });
  });

  window.MLMarketForm = { open: open };
})();
