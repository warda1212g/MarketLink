/* Vendor profile form. */
(function () {
  "use strict";
  var form = document.getElementById("profileForm");
  var el = form.elements;

  function validate() {
    var e = {};
    if (el.stall_name.value.trim().length < 2) e.stall_name = "Enter your stall or business name.";
    if (el.contact_person.value.trim().length < 2) e.contact_person = "Enter a contact person.";
    if (!/^[0-9+\-\s()]{7,30}$/.test(el.phone.value.trim())) e.phone = "Enter a valid phone number.";
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(el.email.value.trim())) e.email = "Enter a valid email address.";
    if (el.address.value.trim().length < 5) e.address = "Enter your stall address.";
    if (!form.querySelector('input[name="operating_days[]"]:checked')) e.operating_days = "Choose at least one operating day.";
    if (!el.pickup_start.value) e.pickup_start = "Enter a pickup start time.";
    if (!el.pickup_end.value) e.pickup_end = "Enter a pickup end time.";
    else if (el.pickup_start.value && el.pickup_end.value <= el.pickup_start.value) e.pickup_end = "Pickup must end after it starts.";
    return e;
  }

  form.addEventListener("submit", function (ev) {
    ev.preventDefault();
    var errs = validate();
    if (Object.keys(errs).length) { ML.showErrors(form, errs); ML.toast("Please correct the highlighted fields.", "error"); return; }
    var btn = form.querySelector('[type="submit"]');
    ML.setLoading(btn, true);
    ML.api("api/vendor/profile.php", { body: new FormData(form) })
      .then(function (res) {
        ML.clearErrors(form);
        ML.toast(res.message);
        el.image.value = "";
        document.querySelector(".sb-stall strong").textContent = el.stall_name.value.trim();
      })
      .catch(function (err) { ML.showErrors(form, err.errors); ML.toast(err.message, "error"); })
      .then(function () { ML.setLoading(btn, false); });
  });
})();
