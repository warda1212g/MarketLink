/* Order status buttons, used on the dashboard and orders page */
(function () {
  "use strict";

  var messages = {
    accepted: "Order accepted successfully.",
    declined: "Order rejected. Reserved stock was returned.",
    preparing: "Order marked as preparing. The customer was notified.",
    ready: "Order marked ready for pickup.",
    completed: "Order completed. Revenue recorded.",
    cancelled: "Order cancelled. Reserved stock was returned."
  };
  var confirmCopy = {
    declined: { title: "Reject this order?", text: "The customer is notified that the order was rejected and the reserved stock returns to your inventory.", confirmText: "Reject order", danger: true, reason: true },
    cancelled: { title: "Cancel this order?", text: "This cannot be undone. The customer is notified and reserved stock returns to your inventory.", confirmText: "Cancel order", danger: true, reason: true },
    completed: { title: "Mark as completed?", text: "The customer confirmed pickup. Complete the order once you've received payment.", confirmText: "Mark completed" }
  };

  document.addEventListener("click", function (e) {
    var btn = e.target.closest("[data-order-action]");
    if (!btn) return;
    var to = btn.getAttribute("data-order-action");
    var id = btn.getAttribute("data-order-id");
    var num = btn.getAttribute("data-order-number");

    var ask = confirmCopy[to]
      ? ML.confirm(Object.assign({}, confirmCopy[to], { title: confirmCopy[to].title.replace("this order", "#" + num) }))
      : Promise.resolve(true);

    ask.then(function (ok) {
      if (!ok) return;
      var reason = "";
      if (confirmCopy[to] && confirmCopy[to].reason) {
        reason = window.prompt("Reason for the customer (optional):", "") || "";
      }
      ML.setLoading(btn, true);
      ML.api("api/vendor/orders.php", { body: { action: "status", id: Number(id), status: to, reason: reason.trim() } })
        .then(function (res) {
          ML.toast(messages[to] || res.message);
          document.dispatchEvent(new CustomEvent("ml:order-updated", { detail: res }));
          // Default in-place update for simple tables (dashboard).
          var row = document.querySelector('tr[data-order-id="' + id + '"]');
          if (row) {
            var pill = row.querySelector(".pill");
            if (pill) { pill.className = "pill pill-" + res.order.status; pill.textContent = res.order.status_label; }
            var actions = row.querySelector(".row-actions");
            if (actions) actions.innerHTML = res.actions_html;
          }
        })
        .catch(function (err) { ML.toast(err.message, "error"); ML.setLoading(btn, false); });
    });
  });
})();
