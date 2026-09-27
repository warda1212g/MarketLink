/* Apply the suggested discounts */
(function () {
  "use strict";

  document.addEventListener("click", function (e) {
    var btn = e.target.closest("[data-apply-discount]");
    if (!btn) return;
    var item = btn.closest("[data-discount-product]");
    if (!item) return;

    btn.disabled = true;
    ML.api("api/vendor/ai-suggest.php", {
      body: {
        action: "apply_discount",
        product_id: Number(item.getAttribute("data-discount-product")),
        discount: Number(item.getAttribute("data-discount-pct"))
      }
    }).then(function (res) {
      ML.toast(res.message);
      var row = item;
      row.style.opacity = ".5";
      row.querySelector("[data-apply-discount]").outerHTML = '<span class="pill pill-completed">Discount applied</span>';
    }).catch(function (err) {
      ML.toast(err.message, "error");
      btn.disabled = false;
    });
  });
})();
