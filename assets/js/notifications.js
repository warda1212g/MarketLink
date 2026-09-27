/* Notifications: bell dropdown, unread badges, polling and the notifications page.
   Used on the site, vendor and admin pages. */
(function () {
  "use strict";

  var meta = function (n) { var m = document.querySelector('meta[name="' + n + '"]'); return m ? m.content : ""; };
  var csrf = meta("csrf-token");
  var base = meta("base-url");
  var API = base + "/api/account/notifications.php";
  var POLL_MS = 20000;

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }
  function icon(id) { return '<svg><use href="#' + esc(id) + '"/></svg>'; }

  function get(params) {
    return fetch(API + "?" + new URLSearchParams(params).toString(), { credentials: "same-origin", headers: { Accept: "application/json" } })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (!d.ok) throw new Error(d.message || "Request failed"); return d; });
  }
  function post(body) {
    return fetch(API, {
      method: "POST", credentials: "same-origin",
      headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf, Accept: "application/json" },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json(); })
      .then(function (d) { if (!d.ok) throw new Error(d.message || "Request failed"); return d; });
  }

  /* badges */
  function setBadges(counts) {
    ["notifications", "messages", "cart"].forEach(function (k) {
      if (counts[k] === undefined) return;
      document.querySelectorAll('[data-badge="' + k + '"]').forEach(function (el) {
        var n = Number(counts[k]) || 0, txt = n > 99 ? "99+" : String(n);
        if (el.textContent !== txt && n > 0) {
          el.classList.remove("bump"); void el.offsetWidth; el.classList.add("bump");
        }
        el.textContent = txt;
        el.hidden = n === 0;
      });
    });
  }

  var polling = null;
  function refresh(withLatest) {
    if (!csrf) return Promise.resolve();
    return get({ action: "summary", with_latest: withLatest ? 1 : "" })
      .then(function (d) {
        setBadges(d);
        if (d.latest) renderPanels(d.latest, d.notifications);
        return d;
      })
      .catch(function () { /* offline or signed out: keep last counts */ });
  }

  /* dropdown panels */
  var roots = Array.prototype.slice.call(document.querySelectorAll("[data-notif]"));

  function itemHtml(n) {
    var tag = n.link ? "a" : "div";
    return "<" + tag + ' class="nf-item' + (n.is_read ? "" : " unread") + '" data-notif-id="' + n.id + '"' + (n.link ? ' href="' + esc(n.link) + '"' : "") + ">" +
      '<span class="nf-icon">' + icon(n.icon) + "</span><span><strong>" + esc(n.title) + "</strong>" +
      (n.message ? '<span class="nf-msg">' + esc(n.message) + "</span>" : "") + "<small>" + esc(n.time) + "</small></span></" + tag + ">";
  }

  function renderPanels(items, unread) {
    roots.forEach(function (root) {
      var list = root.querySelector("[data-notif-list]");
      var sub = root.querySelector("[data-notif-sub]");
      if (sub) sub.textContent = unread ? unread + " unread" : "All caught up";
      if (!list) return;
      list.innerHTML = items.length ? items.map(itemHtml).join("")
        : '<div class="nf-empty">' + icon("i-bell") + "<p>No notifications yet.</p></div>";
    });
  }

  roots.forEach(function (root) {
    var toggle = root.querySelector("[data-notif-toggle]");
    var panel = root.querySelector("[data-notif-panel]");
    var standalone = root.hasAttribute("data-notif-standalone");

    function setOpen(open) {
      panel.hidden = !open;
      toggle.setAttribute("aria-expanded", open ? "true" : "false");
    }
    if (toggle) {
      toggle.addEventListener("click", function (e) {
        if (standalone) {
          e.preventDefault();
          e.stopPropagation();
          var willOpen = panel.hidden;
          setOpen(willOpen);
          if (willOpen) refresh(true);
        } else {
          refresh(true); // vendor dropdown is opened by core.js
        }
      });
    }
    if (standalone) {
      document.addEventListener("click", function (e) { if (!panel.hidden && !root.contains(e.target)) setOpen(false); });
      document.addEventListener("keydown", function (e) { if (e.key === "Escape" && !panel.hidden) setOpen(false); });
    }

    root.addEventListener("click", function (e) {
      var readAll = e.target.closest("[data-notif-readall]");
      if (readAll) {
        e.preventDefault();
        post({ action: "read_all" }).then(function () { refresh(true); refreshCenter(); });
        return;
      }
      var item = e.target.closest("[data-notif-id]");
      if (!item) return;
      e.preventDefault();
      var href = item.getAttribute("href");
      post({ action: "read", id: Number(item.getAttribute("data-notif-id")) })
        .catch(function () {})
        .then(function () { if (href) window.location.href = href; else refresh(true); });
    });
  });

  /* notification centre page */
  var center = document.querySelector("[data-notif-center]");
  var cState = { filter: "all", page: 1, items: [] };

  function centerItem(n) {
    var title = n.link ? '<a href="' + esc(n.link) + '" data-open="' + n.id + '">' + esc(n.title) + "</a>" : esc(n.title);
    return '<div class="nc-item' + (n.is_read ? "" : " unread") + '">' +
      '<span class="nf-icon">' + icon(n.icon) + "</span>" +
      '<div><div class="nc-title">' + (n.is_read ? "" : '<span class="nc-dot" aria-label="Unread"></span>') + title + "</div>" +
        (n.message ? '<div class="nc-msg">' + esc(n.message) + "</div>" : "") +
        '<div class="nc-time">' + esc(n.date) + ", " + esc(n.time) + "</div></div>" +
      '<div class="nc-actions">' +
        (n.link ? '<a class="btn btn-ghost btn-sm" href="' + esc(n.link) + '" data-open="' + n.id + '">Open</a>' : "") +
        '<button type="button" class="btn btn-ghost btn-sm" data-nc="' + (n.is_read ? "unread" : "read") + '" data-id="' + n.id + '">' + (n.is_read ? "Mark unread" : "Mark read") + "</button>" +
        '<button type="button" class="btn btn-danger-ghost btn-sm" data-nc="delete" data-id="' + n.id + '" aria-label="Delete notification">' + icon("i-trash") + "</button>" +
      "</div></div>";
  }

  function refreshCenter(append) {
    if (!center) return;
    var list = center.querySelector("[data-nc-list]");
    var more = center.querySelector("[data-nc-more]");
    if (!append) cState.page = 1;
    get({ action: "list", filter: cState.filter, page: cState.page }).then(function (d) {
      cState.items = append ? cState.items.concat(d.items) : d.items;
      more.hidden = !d.has_more;
      var labels = { all: "No notifications yet", unread: "No unread notifications", read: "No read notifications" };
      list.innerHTML = cState.items.length ? cState.items.map(centerItem).join("")
        : '<div class="empty"><div class="empty-icon">' + icon("i-bell") + "</div><h4>" + labels[cState.filter] + "</h4><p>Order updates, messages and reviews will appear here.</p></div>";
      setBadges({ notifications: d.unread });
    }).catch(function (err) { list.innerHTML = '<div class="alert alert-error">' + esc(err.message) + "</div>"; });
  }

  if (center) {
    center.addEventListener("click", function (e) {
      var tab = e.target.closest("[data-filter]");
      if (tab) {
        center.querySelectorAll("[data-filter]").forEach(function (t) { t.classList.toggle("active", t === tab); t.setAttribute("aria-selected", t === tab ? "true" : "false"); });
        cState.filter = tab.getAttribute("data-filter");
        refreshCenter();
        return;
      }
      if (e.target.closest("[data-nc-more]")) { cState.page++; refreshCenter(true); return; }
      if (e.target.closest("[data-nc-readall]")) { post({ action: "read_all" }).then(function () { refreshCenter(); refresh(); }); return; }
      var open = e.target.closest("[data-open]");
      if (open) {
        e.preventDefault();
        post({ action: "read", id: Number(open.getAttribute("data-open")) }).catch(function () {}).then(function () { window.location.href = open.getAttribute("href"); });
        return;
      }
      var act = e.target.closest("[data-nc]");
      if (act) {
        act.disabled = true;
        post({ action: act.getAttribute("data-nc"), id: Number(act.getAttribute("data-id")) }).then(function () { refreshCenter(); refresh(); })
          .catch(function () { act.disabled = false; });
      }
    });
    refreshCenter();
  }

  /* polling */
  function startPolling() {
    if (polling) return;
    polling = setInterval(function () { if (!document.hidden) refresh(); }, POLL_MS);
  }
  document.addEventListener("visibilitychange", function () { if (!document.hidden) refresh(); });
  window.addEventListener("ml:counts-refresh", function () { refresh(); });

  window.MLNotify = { refresh: refresh, setBadges: setBadges };
  if (csrf && document.querySelector("[data-notif], [data-badge]")) {
    refresh();
    startPolling();
  }
})();
