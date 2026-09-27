/* Chat widget for the MarketLink assistant.
   Replies are escaped first, then a bit of markdown (bold, lists, links) is added. */
(function () {
  "use strict";

  var root = document.getElementById("mlAssistant");
  if (!root) return;
  var panel = root.querySelector(".mla-panel");
  var log = root.querySelector("[data-mla-log]");
  var form = root.querySelector("[data-mla-form]");
  var input = form.querySelector("input");
  var sendBtn = form.querySelector("button");
  var suggest = root.querySelector("[data-mla-suggest]");
  var endpoint = root.dataset.endpoint;
  var base = root.dataset.base.replace(/\/?$/, "/");
  var csrfMeta = document.querySelector('meta[name="csrf-token"]');
  var KEY = "mlAssistantChat";
  var history = [];
  var busy = false;

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]; });
  }

  // **bold** and links to our own pages like market.php?id=5
  function inline(s) {
    return esc(s)
      .replace(/\*\*(.+?)\*\*/g, "<strong>$1</strong>")
      .replace(/\b((?:market|market-view|market-category|products|order|my-orders|markets|cart|checkout)\.php(?:\?[\w=&;.-]*)?)/g, function (m) {
        return '<a href="' + base + m.replace(/&amp;/g, "&") + '">' + m + "</a>";
      });
  }

  function render(text) {
    var lines = String(text).split(/\r?\n/);
    var html = "", list = false, table = null;
    function closeList() { if (list) { html += "</ul>"; list = false; } }
    function closeTable() {
      if (!table) return;
      html += '<div class="mla-table"><table>' + table.map(function (row, i) {
        var tag = i === 0 ? "th" : "td";
        return "<tr>" + row.map(function (c) { return "<" + tag + ">" + inline(c) + "</" + tag + ">"; }).join("") + "</tr>";
      }).join("") + "</table></div>";
      table = null;
    }
    lines.forEach(function (raw) {
      var line = raw.trim();
      if (/^\|.*\|$/.test(line)) {
        closeList();
        if (/^\|[\s:|-]+\|$/.test(line)) return; // separator row
        table = table || [];
        table.push(line.slice(1, -1).split("|").map(function (c) { return c.trim(); }));
        return;
      }
      closeTable();
      var m;
      if ((m = line.match(/^#{1,6}\s*(.+)$/))) { closeList(); html += "<h5>" + inline(m[1]) + "</h5>"; }
      else if ((m = line.match(/^(?:[-*\u2022]|\d+\.)\s+(.+)$/))) { if (!list) { html += "<ul>"; list = true; } html += "<li>" + inline(m[1]) + "</li>"; }
      else if (line === "") { closeList(); }
      else { closeList(); html += "<p>" + inline(line) + "</p>"; }
    });
    closeList(); closeTable();
    return html;
  }

  function add(role, text, cls) {
    if (suggest) suggest.hidden = true;
    var el = document.createElement("div");
    el.className = "mla-msg " + (role === "user" ? "user" : "bot") + (cls ? " " + cls : "");
    if (role === "user") el.textContent = text; else el.innerHTML = render(text);
    log.appendChild(el);
    log.scrollTop = log.scrollHeight;
    return el;
  }

  function save() {
    try { sessionStorage.setItem(KEY, JSON.stringify(history.slice(-12))); } catch (e) { /* storage unavailable */ }
  }

  function restore() {
    try {
      var saved = JSON.parse(sessionStorage.getItem(KEY) || "[]");
      if (Array.isArray(saved)) saved.forEach(function (m) {
        if ((m.role === "user" || m.role === "assistant") && typeof m.content === "string") { history.push(m); add(m.role, m.content); }
      });
    } catch (e) { /* ignore */ }
  }

  function toggle(open) {
    var show = open === undefined ? panel.hidden : open;
    panel.hidden = !show;
    root.classList.toggle("open", show);
    root.querySelector(".mla-fab").setAttribute("aria-expanded", show ? "true" : "false");
    if (show) setTimeout(function () { input.focus(); }, 50);
  }

  function ask(question) {
    question = String(question || "").trim();
    if (!question || busy) return;
    busy = true; sendBtn.disabled = true;
    add("user", question);
    var typing = add("assistant", "");
    typing.classList.add("muted");
    typing.innerHTML = '<span class="mla-typing" aria-label="Thinking"><span></span><span></span><span></span></span>';

    fetch(endpoint, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json", "Accept": "application/json", "X-CSRF-Token": csrfMeta ? csrfMeta.content : "" },
      body: JSON.stringify({ message: question, history: history.slice(-6) })
    })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { return { ok: r.ok, d: d }; }); })
      .then(function (res) {
        typing.remove();
        if (!res.ok || !res.d.reply) {
          add("assistant", res.d.message || "The MarketLink AI Assistant is temporarily unavailable. Please try again later.", "error");
          return;
        }
        add("assistant", res.d.reply, res.d.unavailable ? "error" : "");
        if (!res.d.unavailable) {
          history.push({ role: "user", content: question }, { role: "assistant", content: res.d.reply });
          save();
        }
      })
      .catch(function () {
        typing.remove();
        add("assistant", "The MarketLink AI Assistant is temporarily unavailable. Please try again later.", "error");
      })
      .then(function () { busy = false; sendBtn.disabled = false; input.focus(); });
  }

  root.addEventListener("click", function (e) {
    if (e.target.closest("[data-mla-toggle]")) { toggle(); return; }
    var s = e.target.closest("[data-mla-ask]");
    if (s) { ask(s.textContent); return; }
    if (e.target.closest("[data-mla-clear]")) {
      history = []; save();
      log.querySelectorAll(".mla-msg").forEach(function (m) { m.remove(); });
      if (suggest) suggest.hidden = false;
    }
  });
  form.addEventListener("submit", function (e) { e.preventDefault(); var q = input.value; input.value = ""; ask(q); });
  document.addEventListener("keydown", function (e) { if (e.key === "Escape" && !panel.hidden) toggle(false); });

  // any <form data-assistant-ask> with an input named q opens the chat with that question
  document.addEventListener("submit", function (e) {
    var f = e.target.closest("[data-assistant-ask]");
    if (!f) return;
    e.preventDefault();
    var q = f.querySelector("[name=q]");
    toggle(true);
    if (q && q.value.trim()) { ask(q.value); q.value = ""; }
  });

  restore();
})();
