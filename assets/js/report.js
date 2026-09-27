/* Report links: ask for a reason and send it to api/account/report.php */
(function () {
  "use strict";
  var REASONS = {
    spam: "Spam or misleading", inappropriate: "Inappropriate or offensive", abusive: "Abusive language or harassment",
    fake: "Fake, fraudulent or unsafe", wrong_info: "Wrong product information", other: "Something else"
  };
  var modal = null;

  function build() {
    modal = document.createElement("div");
    modal.className = "modal";
    modal.hidden = true;
    modal.setAttribute("role", "dialog");
    modal.setAttribute("aria-modal", "true");
    modal.setAttribute("aria-labelledby", "reportTitle");
    modal.innerHTML =
      '<form class="modal-card" novalidate><div class="modal-head"><h3 id="reportTitle">Report content</h3>' +
      '<button type="button" class="icon-btn" data-modal-close aria-label="Close"><svg><use href="#i-close"/></svg></button></div>' +
      '<div class="modal-body"><p class="muted" style="margin-bottom:14px">Tell our moderators what is wrong. Reports are confidential.</p>' +
      '<div class="field"><label for="reportReason">Reason</label><select class="select" id="reportReason" name="reason" required>' +
      Object.keys(REASONS).map(function (k) { return '<option value="' + k + '">' + REASONS[k] + "</option>"; }).join("") +
      '</select></div><div class="field mt"><label for="reportDetails">Details <span class="muted">(optional)</span></label>' +
      '<textarea class="textarea" id="reportDetails" name="details" maxlength="1000"></textarea></div></div>' +
      '<div class="modal-foot"><button type="button" class="btn btn-ghost" data-modal-close>Cancel</button><button type="submit" class="btn btn-danger">Send report</button></div></form>';
    document.body.appendChild(modal);
    modal.querySelector("form").addEventListener("submit", function (e) {
      e.preventDefault();
      var f = e.target, btn = f.querySelector('[type="submit"]');
      ML.setLoading(btn, true);
      ML.api("api/account/report.php", { body: { content_type: modal.dataset.type, content_id: Number(modal.dataset.id), reason: f.reason.value, details: f.details.value.trim() } })
        .then(function (res) { ML.toast(res.message); ML.closeModal(modal); })
        .catch(function (err) { ML.toast(err.message, "error"); })
        .then(function () { ML.setLoading(btn, false); });
    });
  }

  document.addEventListener("click", function (e) {
    var b = e.target.closest("[data-report-type]");
    if (!b) return;
    e.preventDefault();
    if (b.hasAttribute("data-login")) { window.location.href = b.getAttribute("data-login"); return; }
    if (!modal) build();
    modal.dataset.type = b.getAttribute("data-report-type");
    modal.dataset.id = b.getAttribute("data-report-id");
    modal.querySelector("form").reset();
    modal.querySelector("#reportTitle").textContent = "Report " + (b.getAttribute("data-report-label") || modal.dataset.type);
    ML.openModal(modal);
  });
})();
