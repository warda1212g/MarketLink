(function () {
  "use strict";

  // navbar code is in navbar.js

  // Scroll reveal
  var revealEls = document.querySelectorAll(".reveal-on-scroll");
  if ("IntersectionObserver" in window) {
    var observer = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.classList.add("in-view");
            observer.unobserve(entry.target);
          }
        });
      },
      { threshold: 0.15, rootMargin: "0px 0px -60px 0px" }
    );
    revealEls.forEach(function (el) { observer.observe(el); });
  } else {
    revealEls.forEach(function (el) { el.classList.add("in-view"); });
  }

  // hearts are handled in home.js

  // Product rail arrows
  var rail = document.getElementById("productRail");
  var railPrev = document.getElementById("railPrev");
  var railNext = document.getElementById("railNext");
  if (rail && railPrev && railNext) {
    var scrollByCard = function (dir) {
      var card = rail.querySelector(".product-card");
      var step = card ? card.getBoundingClientRect().width + 22 : 280;
      rail.scrollBy({ left: dir * step, behavior: "smooth" });
    };
    railPrev.addEventListener("click", function () { scrollByCard(-1); });
    railNext.addEventListener("click", function () { scrollByCard(1); });
  }

  // hero dots follow the section in view
  var dots = document.querySelectorAll(".hero-side-nav .dot");
  var heroSections = ["home", "markets", "products", "farmers"];
  if (dots.length) {
    var heroObserver = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            var idx = heroSections.indexOf(entry.target.id);
            if (idx > -1) {
              dots.forEach(function (d, i) {
                d.classList.toggle("active", i === idx);
              });
            }
          }
        });
      },
      { threshold: 0.5 }
    );
    heroSections.forEach(function (id) {
      var el = document.getElementById(id);
      if (el) heroObserver.observe(el);
    });
  }
})();