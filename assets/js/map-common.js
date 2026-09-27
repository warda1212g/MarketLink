/* Leaflet helpers used by the vendor location page and the market finder */
(function () {
  "use strict";

  var PIN = '<svg viewBox="0 0 40 48" aria-hidden="true"><path d="M20 46s16-14.7 16-27.5A16 16 0 0 0 4 18.5C4 31.3 20 46 20 46Z" fill="#183D2B" stroke="#FFFDF7" stroke-width="2.5"/><circle cx="20" cy="18.5" r="6" fill="#879B62"/></svg>';
  var PIN_ME = '<svg viewBox="0 0 40 40" aria-hidden="true"><circle cx="20" cy="20" r="16" fill="rgba(28,132,198,.18)"/><circle cx="20" cy="20" r="8" fill="#1C84C6" stroke="#fff" stroke-width="3"/></svg>';

  window.MLMap = {
    // make a map in el using its data-tiles, data-attribution, data-lat, data-lng and data-zoom
    create: function (el, opts) {
      opts = opts || {};
      var map = L.map(el, { scrollWheelZoom: opts.scrollWheelZoom !== false, zoomControl: true })
        .setView([Number(el.dataset.lat), Number(el.dataset.lng)], Number(el.dataset.zoom) || 12);
      // no tile prefetching (OSM usage policy)
      L.tileLayer(el.dataset.tiles, { maxZoom: 19, attribution: el.dataset.attribution }).addTo(map);
      return map;
    },
    pinIcon: function () {
      return L.divIcon({ className: "ml-marker", html: PIN, iconSize: [40, 48], iconAnchor: [20, 46], popupAnchor: [0, -40] });
    },
    meIcon: function () {
      return L.divIcon({ className: "ml-marker", html: PIN_ME, iconSize: [40, 40], iconAnchor: [20, 20] });
    },
    // geolocation as a promise, with readable error messages
    locate: function () {
      return new Promise(function (resolve, reject) {
        if (!("geolocation" in navigator)) { reject(new Error("Your browser does not support location access.")); return; }
        navigator.geolocation.getCurrentPosition(
          function (pos) { resolve({ lat: pos.coords.latitude, lng: pos.coords.longitude, accuracy: pos.coords.accuracy }); },
          function (err) {
            var msg = err.code === 1 ? "Location permission was denied. Allow it in your browser, or search instead."
              : err.code === 3 ? "Finding your location took too long. Try again or search instead."
              : "We couldn't determine your location. Try searching instead.";
            reject(new Error(msg));
          },
          { enableHighAccuracy: true, timeout: 12000, maximumAge: 60000 }
        );
      });
    }
  };
})();
