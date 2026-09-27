(function () {
  "use strict";

  var body = document.body;
  var toggle = document.getElementById("admSidebarToggle");
  var closeBtn = document.getElementById("admSidebarClose");
  var backdrop = document.getElementById("admBackdrop");
  var drawerQuery = window.matchMedia("(max-width: 1099.98px)");

  // Sidebar: collapsible rail on desktop, drawer on tablet/mobile
  try {
    if (localStorage.getItem("ml-admin-sidebar") === "collapsed") body.classList.add("sidebar-collapsed");
  } catch (e) { /* storage unavailable */ }

  function setDrawer(open) {
    body.classList.toggle("sidebar-open", open);
    toggle.setAttribute("aria-expanded", open ? "true" : "false");
    body.style.overflow = open ? "hidden" : "";
  }

  toggle.addEventListener("click", function () {
    if (drawerQuery.matches) {
      setDrawer(!body.classList.contains("sidebar-open"));
    } else {
      var collapsed = body.classList.toggle("sidebar-collapsed");
      toggle.setAttribute("aria-expanded", collapsed ? "false" : "true");
      try { localStorage.setItem("ml-admin-sidebar", collapsed ? "collapsed" : "expanded"); } catch (e) {}
    }
  });
  closeBtn.addEventListener("click", function () { setDrawer(false); });
  backdrop.addEventListener("click", function () { setDrawer(false); });
  document.addEventListener("keydown", function (ev) {
    if (ev.key === "Escape" && body.classList.contains("sidebar-open")) setDrawer(false);
  });
  drawerQuery.addEventListener("change", function () { setDrawer(false); });

  // Shared helpers for admin pages
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || "";

  function esc(value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
  }

  var STATUS_LABELS = {
    pending: "Pending", accepted: "Accepted", ready: "Ready", completed: "Completed",
    cancelled: "Cancelled", approved: "Approved", suspended: "Suspended", rejected: "Rejected",
    active: "Active", inactive: "Inactive"
  };
  function badge(status) {
    var key = String(status || "").toLowerCase();
    return '<span class="adm-badge ' + esc(key) + '">' + esc(STATUS_LABELS[key] || status) + "</span>";
  }

  var toastEl, toastTimer;
  function toast(message, isError) {
    if (!toastEl) {
      toastEl = document.createElement("div");
      toastEl.className = "adm-toast";
      toastEl.setAttribute("role", "status");
      document.body.appendChild(toastEl);
    }
    toastEl.textContent = message;
    toastEl.classList.toggle("error", !!isError);
    toastEl.classList.add("show");
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastEl.classList.remove("show"); }, 3200);
  }

  function farmerAction(farmerId, action) {
    var form = new URLSearchParams();
    form.append("farmer_id", farmerId);
    form.append("action", action);
    return fetch("api/farmer-action.php", {
      method: "POST",
      credentials: "same-origin",
      headers: { "X-CSRF-Token": csrf, "Content-Type": "application/x-www-form-urlencoded" },
      body: form.toString()
    }).then(function (res) {
      return res.json().then(function (data) {
        if (!res.ok) throw new Error(data.message || data.error || "Request failed");
        return data;
      });
    });
  }

  function initials(name) {
    return String(name || "?").trim().split(/\s+/).slice(0, 2)
      .map(function (p) { return p.charAt(0); }).join("").toUpperCase();
  }

  // fetch wrapper for the admin APIs. GET uses opts.query, POST sends opts.body with the CSRF token.
  // Throws an Error with .status, .errors and .data when the request fails.
  function api(url, opts) {
    opts = opts || {};
    var init = { credentials: "same-origin", headers: { Accept: "application/json" } };
    if (opts.query) {
      var q = new URLSearchParams();
      Object.keys(opts.query).forEach(function (k) {
        var v = opts.query[k];
        if (v !== undefined && v !== null && v !== "") q.append(k, v);
      });
      url += (url.indexOf("?") < 0 ? "?" : "&") + q.toString();
    }
    if (opts.body) {
      init.method = "POST";
      init.headers["X-CSRF-Token"] = csrf;
      if (opts.body instanceof FormData) {
        init.body = opts.body;
      } else {
        var form = new URLSearchParams();
        Object.keys(opts.body).forEach(function (k) {
          var v = opts.body[k];
          if (Array.isArray(v)) v.forEach(function (x) { form.append(k + "[]", x); });
          else if (v !== undefined && v !== null) form.append(k, v);
        });
        init.headers["Content-Type"] = "application/x-www-form-urlencoded";
        init.body = form.toString();
      }
    }
    return fetch(url, init).then(function (res) {
      if (res.status === 401) { window.location.href = "login.php"; throw new Error("Session expired"); }
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (!res.ok || data.ok === false) {
          var err = new Error(data.message || "Request failed (" + res.status + ")");
          err.status = res.status; err.errors = data.errors || {}; err.data = data;
          throw err;
        }
        return data;
      });
    });
  }

  // show field errors from the API on the form
  function clearErrors(form) {
    form.querySelectorAll(".is-invalid").forEach(function (el) { el.classList.remove("is-invalid"); });
    form.querySelectorAll("[data-error-for]").forEach(function (el) { el.textContent = ""; });
  }
  function showErrors(form, errors) {
    clearErrors(form);
    var first = null;
    Object.keys(errors || {}).forEach(function (name) {
      var field = form.querySelector('[name="' + name + '"], [name="' + name + '[]"]');
      var box = form.querySelector('[data-error-for="' + name + '"]');
      if (field) { field.classList.add("is-invalid"); first = first || field; }
      if (box) box.textContent = errors[name];
    });
    if (first && first.focus) first.focus({ preventScroll: false });
  }

  function money(v) {
    return "Rs. " + Number(v || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });
  }

  window.MLAdmin = {
    esc: esc, badge: badge, toast: toast, farmerAction: farmerAction, initials: initials,
    api: api, clearErrors: clearErrors, showErrors: showErrors, money: money
  };
})();
