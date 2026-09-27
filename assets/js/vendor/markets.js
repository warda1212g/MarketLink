/* My Markets: filter the market list, join / leave a market. */
(function () {
  "use strict";

  var grid = document.getElementById("vmGrid");
  if (!grid) return;
  var q = document.getElementById("vmQ"), type = document.getElementById("vmType"), show = document.getElementById("vmShow");

  function filter() {
    var term = q.value.trim().toLowerCase(), visible = 0;
    grid.querySelectorAll(".vm-card").forEach(function (c) {
      var ok = (!term || c.dataset.search.indexOf(term) >= 0) &&
        (!type.value || c.dataset.type === type.value) &&
        (!show.value || (show.value === "joined") === (c.dataset.joined === "1"));
      c.hidden = !ok;
      if (ok) visible++;
    });
    document.getElementById("vmNone").hidden = visible > 0;
  }
  q.addEventListener("input", filter);
  type.addEventListener("change", filter);
  show.addEventListener("change", filter);

  grid.addEventListener("click", function (e) {
    var btn = e.target.closest("[data-join], [data-leave]");
    if (!btn) return;
    var card = btn.closest(".vm-card");
    var leaving = btn.hasAttribute("data-leave");
    var name = card.querySelector("h3").textContent;
    if (leaving && !confirm("Remove your stall from " + name + "? Your products there will be hidden from customers until you rejoin.")) return;
    ML.setLoading(btn, true);
    ML.api("api/vendor/markets.php", { body: { action: leaving ? "leave" : "join", market_id: card.dataset.id } })
      .then(function (res) {
        ML.toast(res.message);
        // after registering a stall, continue to My Profile where the stall now shows
        setTimeout(function () { if (res.redirect) location.href = res.redirect; else location.reload(); }, 700);
      })
      .catch(function (err) { ML.toast(err.message, "error"); ML.setLoading(btn, false); });
  });
})();
