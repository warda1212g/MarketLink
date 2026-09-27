/* Admin Settings: section nav highlight, password strength meter, live announcement preview. */
(function () {
  "use strict";

  /* highlight the section in view */
  var links = Array.prototype.slice.call(document.querySelectorAll(".st-nav a"));
  function setActive(id) {
    links.forEach(function (a) { a.classList.toggle("active", a.getAttribute("href") === "#" + id); });
  }
  if (location.hash && document.querySelector(location.hash + ".st-section")) setActive(location.hash.slice(1));
  if ("IntersectionObserver" in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) { if (en.isIntersecting) setActive(en.target.id); });
    }, { rootMargin: "-35% 0px -60% 0px" });
    document.querySelectorAll(".st-section").forEach(function (s) { io.observe(s); });
  }
  links.forEach(function (a) { a.addEventListener("click", function () { setActive(a.getAttribute("href").slice(1)); }); });

  /* password strength */
  var pw = document.querySelector("[data-strength]");
  if (pw) {
    var bar = pw.parentNode.querySelector(".st-strength i");
    var txt = pw.parentNode.querySelector("[data-strength-text]");
    pw.addEventListener("input", function () {
      var v = pw.value, score = 0;
      if (v.length >= 10) score++;
      if (v.length >= 14) score++;
      if (/[a-z]/.test(v) && /[A-Z]/.test(v)) score++;
      if (/\d/.test(v)) score++;
      if (/[^A-Za-z0-9]/.test(v)) score++;
      var levels = [["Too short", "#B3543E", 12], ["Weak", "#B3543E", 30], ["Fair", "#C79A3B", 55], ["Good", "#6F9F72", 78], ["Strong", "#2F6B3F", 100], ["Strong", "#2F6B3F", 100]];
      var l = v ? levels[score] : ["not set", "#B3543E", 0];
      bar.style.width = l[2] + "%";
      bar.style.background = l[1];
      txt.textContent = "Strength: " + l[0];
    });
  }

  /* announcement preview */
  var toggle = document.querySelector("[data-ann-toggle]");
  var fields = document.querySelector("[data-ann-fields]");
  var text = document.querySelector("[data-ann-text]");
  var tone = document.querySelector("[data-ann-tone]");
  var preview = document.querySelector("[data-ann-preview]");
  var count = document.querySelector("[data-ann-count]");
  if (toggle && fields) {
    var sync = function () {
      fields.classList.toggle("is-off", !toggle.checked);
      preview.textContent = text.value.trim() || "Your announcement will appear like this.";
      preview.className = "st-preview st-preview-" + tone.value;
      count.textContent = text.value.length;
    };
    [toggle, tone].forEach(function (el) { el.addEventListener("change", sync); });
    text.addEventListener("input", sync);
    sync();
  }
})();
