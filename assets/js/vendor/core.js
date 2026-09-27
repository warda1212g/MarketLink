/* Shared code for the vendor dashboard (window.ML) */
(function () {
  "use strict";

  var csrf = document.querySelector('meta[name="csrf-token"]').content;
  var base = document.querySelector('meta[name="base-url"]').content || "";

  var ML = (window.ML = {});
  ML.url = function (p) { return base + "/" + String(p).replace(/^\//, ""); };

  /* Api */
  ML.api = function (path, opts) {
    opts = opts || {};
    var method = opts.method || (opts.body ? "POST" : "GET");
    var headers = { "X-CSRF-Token": csrf, "Accept": "application/json" };
    var body = opts.body;
    if (body && !(body instanceof FormData)) {
      headers["Content-Type"] = "application/json";
      body = JSON.stringify(body);
    }
    var url = ML.url(path);
    if (opts.query) {
      var qs = new URLSearchParams();
      Object.keys(opts.query).forEach(function (k) {
        if (opts.query[k] !== undefined && opts.query[k] !== null && opts.query[k] !== "") qs.append(k, opts.query[k]);
      });
      var s = qs.toString();
      if (s) url += (url.indexOf("?") > -1 ? "&" : "?") + s;
    }
    return fetch(url, { method: method, headers: headers, body: body, credentials: "same-origin" })
      .then(function (res) {
        return res.json().catch(function () { return { ok: false, message: "Unexpected server response (" + res.status + ")." }; })
          .then(function (data) {
            if (res.status === 401) { window.location.href = ML.url("auth/login.php"); }
            if (!res.ok || data.ok === false) {
              var err = new Error(data.message || "Request failed.");
              err.errors = data.errors || {};
              err.status = res.status;
              throw err;
            }
            return data;
          });
      }, function () { throw new Error("Network error - check your connection and try again."); });
  };

  /* helpers */
  ML.esc = function (s) {
    return String(s === null || s === undefined ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  };
  ML.money = function (n) { return "Rs. " + Math.round(Number(n) || 0).toLocaleString("en-US"); };
  ML.num = function (n) { return (Number(n) || 0).toLocaleString("en-US", { maximumFractionDigits: 2 }); };
  ML.icon = function (id, cls) { return '<svg' + (cls ? ' class="' + cls + '"' : "") + '><use href="#' + id + '"/></svg>'; };
  ML.debounce = function (fn, ms) { var t; return function () { var a = arguments, c = this; clearTimeout(t); t = setTimeout(function () { fn.apply(c, a); }, ms); }; };
  ML.setLoading = function (btn, on) { if (!btn) return; btn.classList.toggle("is-loading", !!on); btn.disabled = !!on; };

  /* toasts */
  var stack = document.getElementById("toastStack");
  // ML.toast(message, type, opts). type "error" shows a red toast, anything else is success.
  ML.toast = function (message, type, opts) {
    opts = opts || {};
    var isErr = type === "error", ms = isErr ? 5200 : (opts.action ? 5000 : 3400);
    var el = document.createElement("div");
    el.className = "toast" + (isErr ? " error" : "");
    el.setAttribute("role", isErr ? "alert" : "status");
    el.style.setProperty("--toast-ms", ms + "ms");
    el.innerHTML = '<span class="t-icon">' + ML.icon(opts.icon || (isErr ? "i-alert" : "i-check")) + "</span>" +
      '<div class="t-text">' + (opts.title ? "<strong></strong>" : "") + "<span></span>" +
      (opts.action ? '<a class="t-action" href="' + ML.esc(opts.action.href) + '"></a>' : "") + "</div>" +
      '<button type="button" class="t-close" aria-label="Dismiss">' + ML.icon("i-close") + "</button>" +
      '<i class="t-bar" aria-hidden="true"></i>';
    if (opts.title) el.querySelector("strong").textContent = opts.title;
    el.querySelector(".t-text > span").textContent = message;
    if (opts.action) el.querySelector(".t-action").textContent = opts.action.label;

    var timer, closed = false;
    function close() {
      if (closed) return; closed = true; clearTimeout(timer);
      el.classList.add("out"); setTimeout(function () { el.remove(); }, 320);
    }
    el.querySelector(".t-close").addEventListener("click", close);
    // pause while hovered so people can read / click the action
    el.addEventListener("mouseenter", function () { clearTimeout(timer); el.classList.add("paused"); });
    el.addEventListener("mouseleave", function () { el.classList.remove("paused"); timer = setTimeout(close, 1800); });
    // keep at most three on screen
    while (stack.children.length >= 3) stack.firstChild.remove();
    stack.appendChild(el);
    timer = setTimeout(close, ms);
  };

  /* modals */
  var lastFocus = null;
  ML.openModal = function (modal) {
    lastFocus = document.activeElement;
    modal.hidden = false;
    document.body.classList.add("modal-open");
    var f = modal.querySelector("[autofocus], input:not([type=hidden]), select, textarea, button");
    if (f) setTimeout(function () { f.focus(); }, 30);
  };
  ML.closeModal = function (modal) {
    modal.hidden = true;
    if (!document.querySelector(".modal:not([hidden])")) document.body.classList.remove("modal-open");
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  };
  document.addEventListener("click", function (e) {
    var closer = e.target.closest("[data-modal-close]");
    if (closer) { ML.closeModal(closer.closest(".modal")); return; }
    if (e.target.classList && e.target.classList.contains("modal") && e.target.id !== "confirmModal") ML.closeModal(e.target);
  });
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") {
      var open = document.querySelectorAll(".modal:not([hidden])");
      if (open.length) ML.closeModal(open[open.length - 1]);
      document.body.classList.remove("sidebar-open");
    }
  });

  /* confirm dialog (returns Promise<boolean>) */
  var cm = document.getElementById("confirmModal");
  ML.confirm = function (opts) {
    opts = typeof opts === "string" ? { text: opts } : opts;
    cm.querySelector("#confirmTitle").textContent = opts.title || "Are you sure?";
    cm.querySelector("#confirmText").textContent = opts.text || "";
    var ok = cm.querySelector("[data-confirm-ok]");
    var cancel = cm.querySelector("[data-confirm-cancel]");
    ok.textContent = opts.confirmText || "Confirm";
    ok.className = "btn " + (opts.danger ? "btn-danger" : "btn-primary");
    ML.openModal(cm);
    ok.focus();
    return new Promise(function (resolve) {
      function done(v) { ok.onclick = cancel.onclick = null; ML.closeModal(cm); resolve(v); }
      ok.onclick = function () { done(true); };
      cancel.onclick = function () { done(false); };
    });
  };

  /* form errors */
  ML.clearErrors = function (form) {
    form.querySelectorAll(".invalid").forEach(function (el) { el.classList.remove("invalid"); el.removeAttribute("aria-invalid"); });
    form.querySelectorAll(".field-error").forEach(function (el) { el.remove(); });
  };
  ML.showErrors = function (form, errors) {
    ML.clearErrors(form);
    var first = null;
    Object.keys(errors || {}).forEach(function (name) {
      var input = form.querySelector('[name="' + name + '"], [name="' + name + '[]"]');
      var field = input ? input.closest(".field") : form.querySelector('[data-field="' + name + '"]');
      if (input) { input.classList.add("invalid"); input.setAttribute("aria-invalid", "true"); }
      if (field) {
        var m = document.createElement("span");
        m.className = "field-error";
        m.textContent = errors[name];
        field.appendChild(m);
      }
      if (!first && input) first = input;
    });
    if (first) first.focus();
  };

  /* image picker preview */
  ML.bindImageDrop = function (drop) {
    var input = drop.querySelector('input[type="file"]');
    var preview = drop.querySelector(".image-preview");
    function show(file) {
      if (!file) return;
      if (!/^image\/(jpeg|png|webp)$/.test(file.type)) { ML.toast("Choose a JPG, PNG or WEBP image.", "error"); input.value = ""; return; }
      if (file.size > 3 * 1024 * 1024) { ML.toast("Image must be smaller than 3 MB.", "error"); input.value = ""; return; }
      var r = new FileReader();
      r.onload = function () { preview.style.backgroundImage = "url('" + r.result + "')"; preview.innerHTML = ""; };
      r.readAsDataURL(file);
    }
    input.addEventListener("change", function () { show(input.files[0]); });
    ["dragenter", "dragover"].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add("drag"); }); });
    ["dragleave", "drop"].forEach(function (ev) { drop.addEventListener(ev, function () { drop.classList.remove("drag"); }); });
    drop.addEventListener("drop", function (e) {
      e.preventDefault();
      if (e.dataTransfer.files.length) { input.files = e.dataTransfer.files; show(input.files[0]); }
    });
  };
  document.querySelectorAll("[data-image-drop]").forEach(ML.bindImageDrop);

  /* sidebar */
  document.querySelectorAll("[data-sidebar-open]").forEach(function (b) {
    b.addEventListener("click", function () { document.body.classList.add("sidebar-open"); });
  });
  document.querySelectorAll("[data-sidebar-close]").forEach(function (b) {
    b.addEventListener("click", function () { document.body.classList.remove("sidebar-open"); });
  });

  /* dropdowns */
  document.querySelectorAll("[data-dropdown]").forEach(function (dd) {
    var t = dd.querySelector("[data-dropdown-toggle]");
    t.addEventListener("click", function (e) {
      e.stopPropagation();
      var willOpen = !dd.classList.contains("open");
      document.querySelectorAll("[data-dropdown].open").forEach(function (o) { o.classList.remove("open"); o.querySelector("[data-dropdown-toggle]").setAttribute("aria-expanded", "false"); });
      dd.classList.toggle("open", willOpen);
      t.setAttribute("aria-expanded", willOpen ? "true" : "false");
    });
  });
  document.addEventListener("click", function (e) {
    if (!e.target.closest("[data-dropdown]")) document.querySelectorAll("[data-dropdown].open").forEach(function (o) { o.classList.remove("open"); });
  });

  /* topbar */
  var topbar = document.querySelector(".topbar");
  if (topbar) window.addEventListener("scroll", function () { topbar.classList.toggle("scrolled", window.scrollY > 8); }, { passive: true });

  var gs = document.querySelector("[data-global-search]");
  if (gs) {
    gs.addEventListener("submit", function (e) {
      var q = gs.q.value.trim();
      var orderUrl = gs.getAttribute("data-order-url") || "vendor/orders.php";
      if (/^#?ML\d+$/i.test(q)) { e.preventDefault(); window.location.href = ML.url(orderUrl + "?q=" + encodeURIComponent(q.replace("#", ""))); }
    });
    document.addEventListener("keydown", function (e) {
      if (e.key === "/" && !/input|textarea|select/i.test(document.activeElement.tagName)) { e.preventDefault(); gs.q.focus(); }
    });
  }

  /* Chart.js defaults */
  ML.colors = {
    primary: "#183D2B", secondary: "#4F6B3C", olive: "#879B62", grid: "#E9ECE3", muted: "#747A70", text: "#17231B",
    // chart colours
    status: { pending: "#C77D1A", accepted: "#1C84C6", preparing: "#4F6B3C", ready: "#6E9A22", picked_up: "#3E7C8C", cancelled: "#8A5CC8", completed: "#2E8A5A", declined: "#C9483A" },
    statusOrder: ["pending", "accepted", "preparing", "ready", "picked_up", "cancelled", "completed", "declined"]
  };
  ML.statusLabel = function (s) { return { pending: "New", declined: "Rejected", ready: "Ready for Pickup", picked_up: "Picked Up" }[s] || s.charAt(0).toUpperCase() + s.slice(1); };

  if (window.Chart) {
    Chart.defaults.font.family = "Manrope, system-ui, sans-serif";
    Chart.defaults.font.size = 12;
    Chart.defaults.color = ML.colors.muted;
    Chart.defaults.plugins.legend.display = false;
    Chart.defaults.plugins.tooltip.backgroundColor = "#17231B";
    Chart.defaults.plugins.tooltip.padding = 12;
    Chart.defaults.plugins.tooltip.cornerRadius = 10;
    Chart.defaults.plugins.tooltip.titleFont = { weight: "700" };
    Chart.defaults.plugins.tooltip.displayColors = true;
    Chart.defaults.plugins.tooltip.boxPadding = 4;
    Chart.defaults.maintainAspectRatio = false;
    Chart.defaults.animation.duration = 600;
  }

  ML.axes = function (opts) {
    opts = opts || {};
    return {
      x: { grid: { display: false }, border: { display: false }, ticks: { maxRotation: 0, autoSkip: true, autoSkipPadding: 14 } },
      y: {
        beginAtZero: true, border: { display: false }, grid: { color: ML.colors.grid },
        ticks: { precision: 0, maxTicksLimit: 6, callback: opts.money ? function (v) { return v >= 1000 ? "Rs." + (v / 1000) + "k" : "Rs." + v; } : undefined }
      }
    };
  };

  // simple table under a chart (for screen readers)
  ML.dataTable = function (container, headers, rows) {
    var html = '<table class="table table-sm"><thead><tr>' + headers.map(function (h, i) { return "<th" + (i ? ' class="num"' : "") + ">" + ML.esc(h) + "</th>"; }).join("") + "</tr></thead><tbody>";
    html += rows.map(function (r) { return "<tr>" + r.map(function (c, i) { return "<td" + (i ? ' class="num"' : "") + ">" + ML.esc(c) + "</td>"; }).join("") + "</tr>"; }).join("");
    container.innerHTML = html + "</tbody></table>";
  };

  /* date range filter, calls onChange({range, from, to}) */
  ML.rangeFilter = function (root, onChange) {
    var state = { range: root.getAttribute("data-default") || "30d" };
    var custom = root.querySelector(".custom-range");
    root.querySelectorAll("[data-range]").forEach(function (b) {
      b.addEventListener("click", function () {
        root.querySelectorAll("[data-range]").forEach(function (x) { x.classList.toggle("active", x === b); x.setAttribute("aria-pressed", x === b ? "true" : "false"); });
        state.range = b.getAttribute("data-range");
        if (custom) custom.hidden = state.range !== "custom";
        if (state.range !== "custom") { delete state.from; delete state.to; onChange(Object.assign({}, state)); }
      });
    });
    if (custom) {
      custom.querySelector("[data-apply]").addEventListener("click", function () {
        var f = custom.querySelector('[name="from"]').value, t = custom.querySelector('[name="to"]').value;
        if (!f || !t) { ML.toast("Choose both a start and an end date.", "error"); return; }
        state.from = f; state.to = t; onChange(Object.assign({}, state));
      });
    }
    return state;
  };
})();
