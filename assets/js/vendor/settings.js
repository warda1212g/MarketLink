/* Settings forms. */
(function () {
  "use strict";
  document.querySelectorAll("[data-settings]").forEach(function (form) {
    form.addEventListener("submit", function (e) {
      e.preventDefault();
      var action = form.getAttribute("data-settings");
      var body = { action: action };
      Array.prototype.forEach.call(form.elements, function (i) { if (i.name) body[i.name] = i.value; });

      if (action === "password") {
        var errs = {};
        if (!body.current_password) errs.current_password = "Enter your current password.";
        if ((body.new_password || "").length < 8) errs.new_password = "Use at least 8 characters.";
        if (body.new_password !== body.confirm_password) errs.confirm_password = "Passwords do not match.";
        if (Object.keys(errs).length) { ML.showErrors(form, errs); return; }
      }
      var btn = form.querySelector('[type="submit"]');
      ML.setLoading(btn, true);
      ML.api("api/vendor/settings.php", { body: body })
        .then(function (res) { ML.clearErrors(form); ML.toast(res.message); if (action === "password") form.reset(); })
        .catch(function (err) { ML.showErrors(form, err.errors); ML.toast(err.message, "error"); })
        .then(function () { ML.setLoading(btn, false); });
    });
  });
})();
