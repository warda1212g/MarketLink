/* Shared navbar: scroll background, mobile drawer, account dropdown. */
(function () {
  "use strict";

  var nav = document.getElementById("mlNav");
  if (!nav) return;

  function onScroll() { nav.classList.toggle("scrolled", window.scrollY > 40); }
  onScroll();
  window.addEventListener("scroll", onScroll, { passive: true });

  var toggle = document.getElementById("mlNavToggle");
  var links = document.getElementById("mlNavLinks");
  function setOpen(open) {
    links.classList.toggle("open", open);
    toggle.classList.toggle("open", open);
    toggle.setAttribute("aria-expanded", open ? "true" : "false");
    document.body.style.overflow = open ? "hidden" : "";
  }
  toggle.addEventListener("click", function () { setOpen(!links.classList.contains("open")); });
  links.querySelectorAll("a").forEach(function (a) { a.addEventListener("click", function () { setOpen(false); }); });

  var account = document.getElementById("mlAccount");
  if (account) {
    var btn = account.querySelector(".ml-account-btn");
    btn.addEventListener("click", function (e) {
      e.stopPropagation();
      var open = account.classList.toggle("open");
      btn.setAttribute("aria-expanded", open ? "true" : "false");
    });
    document.addEventListener("click", function (e) {
      if (!account.contains(e.target)) { account.classList.remove("open"); btn.setAttribute("aria-expanded", "false"); }
    });
  }

  document.addEventListener("keydown", function (e) {
    if (e.key !== "Escape") return;
    setOpen(false);
    if (account) account.classList.remove("open");
  });
})();
