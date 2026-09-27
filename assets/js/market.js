/* Stall page: small map, quantity buttons and add to cart */
(function () {
  "use strict";

  var mini = document.getElementById("miniMap");
  if (mini) {
    var m = MLMap.create(mini, { scrollWheelZoom: false });
    L.marker([Number(mini.dataset.lat), Number(mini.dataset.lng)], { icon: MLMap.pinIcon() }).addTo(m);
  }

  document.addEventListener("click", function (e) {
    var b = e.target.closest("[data-step]");
    if (b) {
      var input = b.parentNode.querySelector("[data-qty]");
      var step = Number(input.step) || 1;
      var max = Number(input.max) || Infinity;
      var v = Math.round(((Number(input.value) || 0) + step * Number(b.getAttribute("data-step"))) * 100) / 100;
      input.value = Math.min(max, Math.max(step, v));
      return;
    }
    var add = e.target.closest("[data-add-cart]");
    if (!add) return;
    var card = add.closest("[data-product]");
    var qty = Number(card.querySelector("[data-qty]").value) || 0;
    if (qty <= 0) { ML.toast("Choose a quantity.", "error"); return; }
    ML.setLoading(add, true);
    ML.api("api/public/cart.php", { body: { action: "add", product_id: Number(card.getAttribute("data-product")), quantity: qty } })
      .then(function (res) {
        var name = card.querySelector(".p-name");
        ML.toast((name ? name.textContent.trim() + ", " : "") + res.count + " item" + (res.count === 1 ? "" : "s") + " in your cart", "success",
          { title: "Added to cart", icon: "i-cart", action: { label: "View cart", href: ML.url ? ML.url("cart.php") : "cart.php" } });
        document.querySelectorAll('[data-badge-plain="cart"]').forEach(function (el) { el.textContent = res.count; });
        if (window.MLNotify) window.MLNotify.setBadges({ cart: res.count });
      })
      .catch(function (err) { ML.toast(err.message, "error"); })
      .then(function () { ML.setLoading(add, false); });
  });

  document.addEventListener("input", function (e) {
    if (!e.target.matches("[data-qty]")) return;
    var max = Number(e.target.max);
    if (max && Number(e.target.value) > max) { e.target.value = max; ML.toast("Only " + max + " available.", "error"); }
  });
})();
