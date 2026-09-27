(function () {
  "use strict";

  var A = window.MLAdmin, esc = A.esc;
  var body = document.getElementById("farmersBody");

  var highlighted = body.querySelector(".is-highlight");
  if (highlighted) highlighted.scrollIntoView({ block: "center" });

  var CONFIRM = {
    suspend: "Suspend this account? You can approve it again later.",
    reject: "Reject this sign-up? The account stays locked; you can still approve it later."
  };

  body.addEventListener("click", function (ev) {
    var manage = ev.target.closest("button[data-manage]");
    if (manage) { openManage(manage.dataset.manage); return; }
    var btn = ev.target.closest("button[data-action]");
    if (!btn) return;
    var action = btn.dataset.action;
    if (CONFIRM[action] && !window.confirm(CONFIRM[action])) return;
    btn.disabled = true;
    A.farmerAction(btn.dataset.farmer, action)
      .then(function () { window.location.reload(); })
      .catch(function (err) {
        btn.disabled = false;
        A.toast(err.message, true);
      });
  });

  // markets & categories modal
  var modalEl = document.getElementById("manageModal");
  var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
  var form = document.getElementById("manageForm");

  function openManage(vendorId) {
    A.clearErrors(form);
    form.querySelector('[data-error-for="_form"]').textContent = "";
    form.vendor_id.value = vendorId;
    document.getElementById("manageTypes").innerHTML = '<span class="adm-skel adm-skel-row"></span>';
    document.getElementById("manageMarkets").innerHTML = '<span class="adm-skel adm-skel-row"></span>';
    modal.show();
    A.api("api/farmer-markets.php", { query: { vendor_id: vendorId } }).then(function (d) {
      document.getElementById("manageTitle").textContent = d.vendor.farm;
      document.getElementById("manageTypes").innerHTML = d.types.map(function (t) {
        return '<label class="mk-assign-item"><input type="checkbox" name="type_ids[]" value="' + t.id + '"' + (t.selected ? " checked" : "") + ">" +
          "<span>" + esc(t.label) + "</span></label>";
      }).join("");
      document.getElementById("manageMarkets").innerHTML = d.markets.length ? d.markets.map(function (m) {
        return '<label class="mk-assign-item"><input type="checkbox" name="market_ids[]" value="' + m.id + '"' + (m.assigned ? " checked" : "") + ">" +
          "<span><strong>" + esc(m.name) + "</strong><small>" + esc(m.type + ", " + m.city) +
          (m.status !== "active" ? ", " + esc(m.status) : "") + (m.assignment === "suspended" ? ", suspended here" : "") + "</small></span></label>";
      }).join("") : '<p class="adm-panel-sub">No markets yet. <a href="markets.php?action=add">Create one</a>.</p>';
    }).catch(function (err) {
      form.querySelector('[data-error-for="_form"]').textContent = err.message;
    });
  }

  form.addEventListener("submit", function (ev) {
    ev.preventDefault();
    var btn = form.querySelector('[type="submit"]');
    btn.disabled = true;
    A.api("api/farmer-markets.php", { body: new FormData(form) })
      .then(function (res) { A.toast(res.message); modal.hide(); setTimeout(function () { window.location.reload(); }, 400); })
      .catch(function (err) { A.showErrors(form, err.errors); A.toast(err.message, true); })
      .then(function () { btn.disabled = false; });
  });

  var qsManage = new URLSearchParams(location.search).get("manage");
  if (qsManage) openManage(qsManage);
})();
