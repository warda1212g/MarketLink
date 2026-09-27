/* Customer <-> vendor messaging: inbox, thread, send, polling for replies, older history. */
(function () {
  "use strict";

  var root = document.getElementById("chat");
  if (!root) return;
  var API = "api/account/messages.php";
  var role = root.getAttribute("data-role");
  var canReport = root.getAttribute("data-report") === "1";
  var convsEl = document.getElementById("chatConvs");
  var thread = document.getElementById("chatThread");
  var emptyThread = thread.innerHTML;
  var state = { current: Number(root.getAttribute("data-initial")) || 0, lastId: 0, firstId: 0, timer: null, sending: false, loaded: 0, req: 0, firstLoad: true };
  var convsById = {};
  var desktop = function () { return window.matchMedia("(min-width: 821px)").matches; };

  function avatar(c) {
    return '<span class="avatar"' + (c.image ? ' style="background-image:url(\'' + ML.esc(c.image) + '\')"' : "") + ">" + (c.image ? "" : ML.esc(c.initial)) + "</span>";
  }

  /* inbox */
  function renderConvs(list) {
    convsById = {};
    list.forEach(function (c) { convsById[c.id] = c; });
    if (!list.length) {
      convsEl.innerHTML = '<div class="chat-empty">' + ML.icon("i-chat") + "<p>No messages yet.</p><small>" +
        (role === "customer" ? "Open one of your orders and choose \"Message vendor\"." : "Customers who order from you can message you here.") + "</small></div>";
      return;
    }
    var keepScroll = convsEl.scrollTop; // re-rendering must not jump the list back to the top
    convsEl.innerHTML = list.map(function (c) {
      var isOpen = c.id === state.current;
      if (isOpen) c.unread = 0; // the open thread is being read right now
      return '<button type="button" class="chat-conv' + (isOpen ? " active" : "") + (c.unread ? " has-unread" : "") + '" data-conv="' + c.id + '"' + (isOpen ? ' aria-current="true"' : "") + ">" +
        avatar(c) +
        "<span><strong>" + ML.esc(c.name) + "</strong>" + (c.order_number ? "<small>Order #" + ML.esc(c.order_number) + "</small>" : "") +
        "<p>" + (c.last ? (c.last_mine ? "You: " : "") + ML.esc(c.last) : "<em>No messages yet</em>") + "</p></span>" +
        '<span class="chat-conv-meta">' + ML.esc(c.time) + (c.unread ? '<span class="count-badge">' + c.unread + "</span>" : "") + "</span></button>";
    }).join("");
    convsEl.scrollTop = keepScroll;
  }

  function loadConvs() {
    return ML.api(API, { query: { action: "conversations", include: state.current || "" } })
      .then(function (res) {
        renderConvs(res.conversations);
        // on desktop, open the latest conversation by default
        if (state.firstLoad) {
          state.firstLoad = false;
          if (!state.current && res.conversations.length && root.hasAttribute("data-autoselect") && desktop()) open(res.conversations[0].id);
        }
      })
      .catch(function (err) { convsEl.innerHTML = '<div class="alert alert-error" style="margin:12px">' + ML.esc(err.message) + "</div>"; });
  }

  /* thread */
  function bubble(m) {
    var report = canReport && !m.mine && !m.removed
      ? '<button type="button" data-report-type="message" data-report-id="' + m.id + '">Report</button>' : "";
    return '<div class="bubble' + (m.mine ? " mine" : "") + (m.removed ? " removed" : "") + '" data-msg="' + m.id + '">' + ML.esc(m.body) +
      '<div class="bubble-meta"><span>' + ML.esc(m.time) + "</span>" + (m.order_number ? "<span>#" + ML.esc(m.order_number) + "</span>" : "") +
      (m.mine && m.is_read ? "<span>Seen</span>" : "") + report + "</div></div>";
  }

  function scrollBottom() { var box = document.getElementById("chatMsgs"); if (box) box.scrollTop = box.scrollHeight; }

  function renderThread(res) {
    var c = res.conversation;
    var sub = [];
    if (c.order_number) sub.push(c.order_link ? '<a href="' + ML.esc(c.order_link) + '">Order #' + ML.esc(c.order_number) + "</a>" : "Order #" + ML.esc(c.order_number));
    if (c.profile_link) sub.push('<a href="' + ML.esc(c.profile_link) + '">View stall</a>');
    thread.innerHTML =
      '<div class="chat-thread-head"><button type="button" class="btn btn-ghost btn-sm btn-icon-only chat-back" data-back aria-label="Back to conversations">' +
        '<svg style="transform:rotate(180deg)"><use href="#i-arrow"/></svg></button>' + avatar(c) +
        "<div><strong>" + ML.esc(c.name) + "</strong><small>" + sub.join(", ") + "</small></div></div>" +
      '<div class="chat-msgs" id="chatMsgs" aria-live="polite">' +
        (res.has_older ? '<button type="button" class="btn btn-ghost btn-sm" style="align-self:center" data-older>Load earlier messages</button>' : "") +
        (res.messages.length ? res.messages.map(bubble).join("") : '<div class="chat-empty" data-first>' + ML.icon("i-chat") + "<p>Say hello - ask about your order, pickup time or products.</p></div>") +
      "</div>" +
      '<form class="chat-compose" id="chatForm" autocomplete="off"><label class="sr-only" for="chatInput">Message</label>' +
        '<textarea id="chatInput" name="body" rows="1" maxlength="2000" placeholder="Type your message..." required></textarea>' +
        '<button type="submit" class="btn btn-primary">' + ML.icon("i-send") + "Send</button></form>";
    root.classList.add("is-open");
    state.lastId = res.messages.length ? res.messages[res.messages.length - 1].id : 0;
    state.firstId = res.messages.length ? res.messages[0].id : 0;
    scrollBottom();
    var input = document.getElementById("chatInput");
    if (desktop()) input.focus();
  }

  /* placeholder shown while a conversation loads */
  function renderThreadLoading(c) {
    thread.innerHTML =
      '<div class="chat-thread-head"><button type="button" class="btn btn-ghost btn-sm btn-icon-only chat-back" data-back aria-label="Back to conversations">' +
        '<svg style="transform:rotate(180deg)"><use href="#i-arrow"/></svg></button>' + (c ? avatar(c) : "") +
        "<div><strong>" + (c ? ML.esc(c.name) : "Loading...") + "</strong><small>" + (c && c.order_number ? "Order #" + ML.esc(c.order_number) : "Loading conversation...") + "</small></div></div>" +
      '<div class="chat-msgs chat-loading" aria-busy="true">' +
        '<div class="bubble skeleton-bubble"></div><div class="bubble mine skeleton-bubble"></div><div class="bubble skeleton-bubble short"></div>' +
      "</div>" +
      '<div class="chat-compose"><textarea rows="1" disabled placeholder="Loading..."></textarea><button type="button" class="btn btn-primary" disabled>' + ML.icon("i-send") + "Send</button></div>";
    root.classList.add("is-open");
  }

  function markActive(id) {
    convsEl.querySelectorAll(".chat-conv").forEach(function (b) {
      var on = Number(b.getAttribute("data-conv")) === id;
      b.classList.toggle("active", on);
      if (on) {
        b.setAttribute("aria-current", "true");
        b.classList.remove("has-unread");
        var badge = b.querySelector(".count-badge");
        if (badge) badge.remove();
      } else {
        b.removeAttribute("aria-current");
      }
    });
  }

  function open(id, push) {
    // clicking the open conversation again just shows it (mobile), no reload
    if (id === state.current && state.loaded === id) { root.classList.add("is-open"); return; }
    state.current = id;
    state.loaded = 0;
    var req = ++state.req;
    clearInterval(state.timer);
    markActive(id);
    renderThreadLoading(convsById[id]);
    ML.api(API, { query: { action: "messages", conversation_id: id } })
      .then(function (res) {
        if (req !== state.req) return; // user already clicked another conversation
        state.loaded = id;
        renderThread(res);
        if (push !== false) {
          var u = new URL(location.href);
          u.searchParams.set("c", id);
          ["vendor", "order"].forEach(function (k) { u.searchParams.delete(k); });
          history.replaceState(null, "", u);
        }
        window.dispatchEvent(new Event("ml:counts-refresh"));
        loadConvs();
        state.timer = setInterval(poll, 8000);
      })
      .catch(function (err) {
        if (req !== state.req) return;
        thread.innerHTML = '<div class="chat-empty">' + ML.icon("i-alert") + "<p>" + ML.esc(err.message) + '</p><button type="button" class="btn btn-ghost btn-sm" data-retry>Try again</button></div>';
      });
  }

  function poll() {
    if (!state.current || document.hidden) return;
    ML.api(API, { query: { action: "messages", conversation_id: state.current, after_id: state.lastId || 0 } })
      .then(function (res) {
        if (!res.messages.length) return;
        var box = document.getElementById("chatMsgs");
        if (!box) return;
        var first = box.querySelector("[data-first]");
        if (first) first.remove();
        var nearBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 120;
        res.messages.forEach(function (m) { if (!box.querySelector('[data-msg="' + m.id + '"]')) box.insertAdjacentHTML("beforeend", bubble(m)); });
        state.lastId = res.messages[res.messages.length - 1].id;
        if (nearBottom) scrollBottom();
        window.dispatchEvent(new Event("ml:counts-refresh"));
        loadConvs();
      }).catch(function () {});
  }

  /* events */
  convsEl.addEventListener("click", function (e) {
    var b = e.target.closest("[data-conv]");
    if (b) open(Number(b.getAttribute("data-conv")));
  });

  thread.addEventListener("click", function (e) {
    if (e.target.closest("[data-retry]")) { var again = state.current; state.current = 0; open(again); return; }
    if (e.target.closest("[data-back]")) {
      root.classList.remove("is-open");
      clearInterval(state.timer);
      state.req++;
      state.current = 0;
      state.loaded = 0;
      markActive(0);
      thread.innerHTML = emptyThread;
      var u = new URL(location.href); u.searchParams.delete("c"); history.replaceState(null, "", u);
      loadConvs();
      return;
    }
    var older = e.target.closest("[data-older]");
    if (older) {
      ML.setLoading(older, true);
      ML.api(API, { query: { action: "messages", conversation_id: state.current, before_id: state.firstId } }).then(function (res) {
        var box = document.getElementById("chatMsgs");
        var h = box.scrollHeight;
        older.remove();
        box.insertAdjacentHTML("afterbegin", (res.messages.length === 40 ? '<button type="button" class="btn btn-ghost btn-sm" style="align-self:center" data-older>Load earlier messages</button>' : "") + res.messages.map(bubble).join(""));
        if (res.messages.length) state.firstId = res.messages[0].id;
        box.scrollTop = box.scrollHeight - h;
      }).catch(function (err) { ML.toast(err.message, "error"); ML.setLoading(older, false); });
    }
  });

  thread.addEventListener("submit", function (e) {
    if (e.target.id !== "chatForm") return;
    e.preventDefault();
    var input = e.target.elements.body;
    var body = input.value.trim();
    if (!body || state.sending) return;
    var btn = e.target.querySelector('[type="submit"]');
    state.sending = true;
    ML.setLoading(btn, true);
    ML.api(API, { body: { action: "send", conversation_id: state.current, body: body } })
      .then(function (res) {
        input.value = "";
        input.style.height = "";
        var box = document.getElementById("chatMsgs");
        var first = box.querySelector("[data-first]");
        if (first) first.remove();
        box.insertAdjacentHTML("beforeend", bubble(res.message));
        state.lastId = Math.max(state.lastId, res.message.id);
        scrollBottom();
        loadConvs();
      })
      .catch(function (err) { ML.toast(err.message, "error"); })
      .then(function () { state.sending = false; ML.setLoading(btn, false); input.focus(); });
  });

  thread.addEventListener("keydown", function (e) {
    if (e.target.id === "chatInput" && e.key === "Enter" && !e.shiftKey) {
      e.preventDefault();
      e.target.form.requestSubmit ? e.target.form.requestSubmit() : e.target.form.dispatchEvent(new Event("submit", { cancelable: true }));
    }
  });
  thread.addEventListener("input", function (e) {
    if (e.target.id !== "chatInput") return;
    e.target.style.height = "auto";
    e.target.style.height = Math.min(140, e.target.scrollHeight) + "px";
  });

  if (state.current) { state.firstLoad = false; var start = state.current; state.current = 0; open(start, false); }
  loadConvs();
  setInterval(function () { if (!document.hidden) loadConvs(); }, 25000);
})();
