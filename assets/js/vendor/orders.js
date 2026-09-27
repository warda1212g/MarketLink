/* Orders page: tabs, search, paging and order cards */
(function () {
  "use strict";

  var init = JSON.parse(document.getElementById("ordersInit").textContent);
  var list = document.getElementById("orderList");
  var more = document.getElementById("loadMore");
  var search = document.getElementById("orderSearch");
  var state = { status: init.status, page: 1, orders: [] };
  var STEPS = ["pending", "accepted", "preparing", "ready", "picked_up", "completed"];
  var ACTIONS = {
    accepted: ["Accept Order", "btn-primary", "i-check"],
    declined: ["Reject", "btn-danger-ghost", "i-x"],
    preparing: ["Start Preparing", "btn-secondary", "i-box"],
    ready: ["Mark Ready", "btn-secondary", "i-bag"],
    completed: ["Mark Completed", "btn-primary", "i-check"],
    cancelled: ["Cancel Order", "btn-danger-ghost", "i-x"]
  };

  function card(o) {
    var step = STEPS.indexOf(o.status);
    var progress = step > -1
      ? '<div class="progress-mini" aria-label="Progress: ' + ML.esc(o.status_label) + '">' + STEPS.map(function (s, i) { return '<i class="' + (i <= step ? "on" : "") + '"></i>'; }).join("") + "</div>"
      : "";
    var actions = o.next.map(function (to) {
      var a = ACTIONS[to];
      return '<button type="button" class="btn ' + a[1] + ' btn-sm" data-order-action="' + to + '" data-order-id="' + o.id + '" data-order-number="' + ML.esc(o.order_number) + '">' + ML.icon(a[2]) + a[0] + "</button>";
    }).join("");
    var initial = (o.customer || "?").charAt(0).toUpperCase();

    return '<article class="order-card fade-in' + (o.id === init.open ? " highlight" : "") + '" id="order-' + o.id + '" data-id="' + o.id + '">' +
      "<div>" +
        '<div class="o-num">Order #' + ML.esc(o.order_number) + ' <span class="pill pill-' + o.status + '">' + ML.esc(o.status_label) + "</span></div>" +
        '<div class="o-meta">Placed ' + ML.esc(o.created_label) + "</div>" +
        '<div class="o-customer"><span class="avatar">' + ML.esc(initial) + "</span><div><strong>" + ML.esc(o.customer) + "</strong>" +
          (o.customer_phone ? '<small><a href="tel:' + ML.esc(o.customer_phone) + '">' + ML.esc(o.customer_phone) + "</a></small>" : "") + "</div></div>" +
        progress +
      "</div>" +
      "<div>" +
        '<div class="o-label">Products, ' + o.items.length + "</div>" +
        '<div class="o-items">' + o.items.map(function (i) {
          return "<div><span>" + ML.esc(i.name) + " x " + ML.esc(i.quantity) + " " + ML.esc(i.unit) + "</span><span>" + ML.esc(i.subtotal) + "</span></div>";
        }).join("") + "</div>" +
        (o.note ? '<div class="o-note"><strong>Note:</strong> ' + ML.esc(o.note) + "</div>" : "") +
        (o.modified ? '<div class="o-note"><strong>Modified</strong> by the customer after placing.</div>' : "") +
        (o.cancel_reason ? '<div class="o-note"><strong>Reason:</strong> ' + ML.esc(o.cancel_reason) + "</div>" : "") +
      "</div>" +
      "<div>" +
        '<div class="o-label">Total</div><div class="o-total">' + ML.esc(o.total_label) + "</div>" +
        '<div class="o-pickup">' + ML.icon("i-clock") + "<span><strong>Pickup</strong><br>" + ML.esc(o.pickup_label) + "</span></div>" +
      "</div>" +
      '<div class="o-actions">' + (actions || '<span class="muted" style="font-size:12.5px">' + (o.status === "ready" ? "Waiting for the customer to confirm pickup" : "No further actions") + "</span>") +
        '<a class="btn btn-ghost btn-sm" href="' + ML.esc(o.message_link) + '">' + ML.icon("i-chat") + "Message customer</a></div>" +
    "</article>";
  }

  function render() {
    if (!state.orders.length) {
      var label = state.status ? document.querySelector('.tab[data-status="' + state.status + '"]').firstChild.textContent.trim().toLowerCase() + " " : "";
      list.innerHTML = '<div class="card"><div class="empty"><div class="empty-icon">' + ML.icon("i-cart") + "</div><h4>No " + ML.esc(label) + "orders</h4><p>" +
        (search.value ? "No orders match your search." : "Pre-orders from customers will appear here.") + "</p></div></div>";
      return;
    }
    list.innerHTML = state.orders.map(card).join("");
  }

  function setCounts(counts) {
    Object.keys(counts).forEach(function (k) {
      var el = document.querySelector('[data-count="' + k + '"]');
      if (el) el.textContent = counts[k];
    });
  }

  function load(append) {
    if (!append) state.page = 1;
    var query = { status: state.status, q: search.value.trim(), page: state.page };
    return ML.api("api/vendor/orders.php", { query: query })
      .then(function (res) {
        state.orders = append ? state.orders.concat(res.orders) : res.orders;
        setCounts(res.counts);
        more.hidden = !res.has_more;
        render();
      })
      .catch(function (err) { list.innerHTML = '<div class="alert alert-error">' + ML.esc(err.message) + "</div>"; });
  }

  document.getElementById("orderTabs").addEventListener("click", function (e) {
    var t = e.target.closest("[data-status]");
    if (!t) return;
    document.querySelectorAll("#orderTabs .tab").forEach(function (x) { x.classList.toggle("active", x === t); x.setAttribute("aria-selected", x === t ? "true" : "false"); });
    state.status = t.getAttribute("data-status");
    init.open = 0;
    var u = new URL(location.href);
    if (state.status) u.searchParams.set("status", state.status); else u.searchParams.delete("status");
    u.searchParams.delete("open");
    history.replaceState(null, "", u);
    load();
  });
  search.addEventListener("input", ML.debounce(function () { load(); }, 300));
  document.getElementById("refreshOrders").addEventListener("click", function () { load().then(function () { ML.toast("Orders refreshed."); }); });
  more.addEventListener("click", function () { state.page++; load(true); });

  document.addEventListener("ml:order-updated", function (e) {
    var o = e.detail.order;
    state.orders = state.orders.map(function (x) { return x.id === o.id ? o : x; });
    var el = document.getElementById("order-" + o.id);
    if (el) {
      if (state.status && state.status !== o.status) {
        el.style.transition = "opacity .3s"; el.style.opacity = "0";
        setTimeout(function () { state.orders = state.orders.filter(function (x) { return x.id !== o.id; }); render(); }, 300);
      } else {
        el.outerHTML = card(o);
      }
    }
    ML.api("api/vendor/orders.php", { query: { counts_only: 1 } }).then(function (res) { setCounts(res.counts); }).catch(function () {});
  });

  // Deep link: ?open=ID shows that order first.
  if (init.open) {
    ML.api("api/vendor/orders.php", { query: { id: init.open } }).then(function (res) {
      setCounts(res.counts);
      state.orders = res.orders;
      more.hidden = true;
      render();
      if (res.orders.length) {
        list.insertAdjacentHTML("beforeend", '<div style="text-align:center"><button class="btn btn-ghost" id="showAll">Show all orders</button></div>');
        document.getElementById("showAll").addEventListener("click", function () { init.open = 0; load(); });
      }
    }).catch(function (err) { ML.toast(err.message, "error"); load(); });
  } else {
    load();
  }
})();
