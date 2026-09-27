/* Reviews: list, filters and reply modal. */
(function () {
  "use strict";

  var list = document.getElementById("reviewList");
  var more = document.getElementById("moreReviews");
  var modal = document.getElementById("replyModal");
  var form = document.getElementById("replyForm");
  var el = form.elements;
  var state = { filter: JSON.parse(document.getElementById("reviewsInit").textContent).filter, rating: "", page: 1, reviews: [] };

  function stars(n) {
    var h = '<span class="stars" aria-label="' + n + ' out of 5 stars">';
    for (var i = 1; i <= 5; i++) h += '<svg class="' + (i <= n ? "" : "off") + '"><use href="#i-star"/></svg>';
    return h + "</span>";
  }

  function card(r) {
    function report(x) { return '<button type="button" class="report-link" style="margin-right:auto" data-report-type="review" data-report-id="' + x.id + '" data-report-label="review">' + ML.icon("i-flag") + "Report</button>"; }
    return '<article class="review-card fade-in" data-review="' + r.id + '">' +
      '<div class="review-head"><span class="avatar">' + ML.esc(r.customer.charAt(0).toUpperCase()) + '</span><div style="flex:1;min-width:0"><strong>' + ML.esc(r.customer) + "</strong><small>" +
      ML.esc(r.created) + (r.order_number ? ", Order #" + ML.esc(r.order_number) : "") + "</small></div>" + stars(r.rating) + "</div>" +
      '<p class="review-text">' + (r.review ? '"' + ML.esc(r.review) + '"' : '<span class="muted">No written comment.</span>') + "</p>" +
      (r.reply
        ? '<div class="vendor-reply"><strong>Your response, ' + ML.esc(r.replied) + "</strong>" + ML.esc(r.reply) + "</div>" +
          '<div class="form-foot" style="margin-top:12px">' + report(r) + '<button class="btn btn-ghost btn-sm" data-reply="' + r.id + '">' + ML.icon("i-edit") + "Edit reply</button></div>"
        : '<div class="form-foot" style="margin-top:4px">' + report(r) + '<button class="btn btn-primary btn-sm" data-reply="' + r.id + '">' + ML.icon("i-reply") + "Reply</button></div>") +
      "</article>";
  }

  function render() {
    list.innerHTML = state.reviews.length ? state.reviews.map(card).join("") :
      '<div class="card" style="grid-column:1/-1"><div class="empty"><div class="empty-icon">' + ML.icon("i-star-o") + "</div><h4>No reviews here</h4><p>" +
      (state.filter === "unanswered" ? "You've replied to every review. Nice work!" : "Customers can review your stall after a completed pickup.") + "</p></div></div>";
  }

  function load(append) {
    if (!append) state.page = 1;
    return ML.api("api/vendor/reviews.php", { query: { filter: state.filter, rating: state.rating, page: state.page } })
      .then(function (res) {
        state.reviews = append ? state.reviews.concat(res.reviews) : res.reviews;
        more.hidden = !res.has_more;
        document.getElementById("unansweredCount").textContent = res.summary.unanswered;
        render();
      })
      .catch(function (err) { ML.toast(err.message, "error"); });
  }

  document.getElementById("reviewTabs").addEventListener("click", function (e) {
    var t = e.target.closest("[data-filter]");
    if (!t) return;
    this.querySelectorAll("[data-filter]").forEach(function (x) { x.classList.toggle("active", x === t); x.setAttribute("aria-selected", x === t ? "true" : "false"); });
    state.filter = t.getAttribute("data-filter");
    load();
  });
  document.getElementById("ratingFilter").addEventListener("change", function () { state.rating = this.value; load(); });
  more.addEventListener("click", function () { state.page++; load(true); });

  list.addEventListener("click", function (e) {
    var b = e.target.closest("[data-reply]");
    if (!b) return;
    var r = state.reviews.find(function (x) { return x.id === Number(b.getAttribute("data-reply")); });
    ML.clearErrors(form);
    el.id.value = r.id;
    el.reply.value = r.reply || "";
    document.getElementById("replyChars").textContent = el.reply.value.length;
    document.getElementById("replyTitle").textContent = r.reply ? "Edit your reply" : "Reply to " + r.customer;
    document.getElementById("replyContext").innerHTML = '<div class="review-head"><strong style="flex:1">' + ML.esc(r.customer) + "</strong>" + stars(r.rating) + '</div><p class="review-text" style="margin-bottom:0">' + ML.esc(r.review || "No written comment.") + "</p>";
    ML.openModal(modal);
    el.reply.focus();
  });

  el.reply.addEventListener("input", function () { document.getElementById("replyChars").textContent = this.value.length; });

  form.addEventListener("submit", function (e) {
    e.preventDefault();
    if (el.reply.value.trim().length < 2) { ML.showErrors(form, { reply: "Write a short response." }); return; }
    var btn = form.querySelector('[type="submit"]');
    ML.setLoading(btn, true);
    ML.api("api/vendor/reviews.php", { body: { action: "reply", id: Number(el.id.value), reply: el.reply.value.trim() } })
      .then(function (res) { ML.toast(res.message); ML.closeModal(modal); return load(); })
      .catch(function (err) { ML.showErrors(form, err.errors); ML.toast(err.message, "error"); })
      .then(function () { ML.setLoading(btn, false); });
  });

  load();
})();
