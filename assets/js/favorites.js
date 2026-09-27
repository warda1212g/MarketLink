/* Heart buttons (data-fav-type / data-fav-id) -> api/public/favorite.php. */
(function () {
  "use strict";

  document.addEventListener("click", function (e) {
    var btn = e.target.closest("[data-fav-type]");
    if (!btn) return;
    e.preventDefault();
    if (btn.hasAttribute("data-login")) { location.href = btn.getAttribute("data-login"); return; }
    btn.disabled = true;
    ML.api("api/public/favorite.php", { body: { type: btn.dataset.favType, id: Number(btn.dataset.favId) } })
      .then(function (res) {
        // update every heart for this item on the page
        var sel = '[data-fav-type="' + btn.dataset.favType + '"][data-fav-id="' + btn.dataset.favId + '"]';
        document.querySelectorAll(sel).forEach(function (b) {
          b.classList.toggle("active", res.saved);
          b.setAttribute("aria-pressed", res.saved ? "true" : "false");
          var txt = b.querySelector("[data-fav-text]");
          if (txt) txt.textContent = res.saved ? b.dataset.favOn : b.dataset.favOff;
        });
        ML.toast(res.message, "success", { icon: "i-heart", action: res.saved ? { label: "View favorites", href: ML.url("customer/favorites.php" + (btn.dataset.favType === "product" ? "?tab=products" : "")) } : null });
        // on the favourites page, remove the card
        var card = btn.closest("[data-fav-card]");
        if (card && !res.saved) {
          card.style.transition = "opacity .3s, transform .3s";
          card.style.opacity = "0";
          card.style.transform = "scale(.96)";
          setTimeout(function () { card.remove(); }, 300);
        }
      })
      .catch(function (err) { ML.toast(err.message, "error"); })
      .then(function () { btn.disabled = false; });
  });
})();
