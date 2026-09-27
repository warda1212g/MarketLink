/* Admin moderation page: tabs, filters, tables and actions */
(function () {
  "use strict";
  var A = window.MLAdmin, esc = A.esc;
  var API = "api/moderation.php";
  var init = JSON.parse(document.getElementById("modInit").textContent);
  var body = document.getElementById("modBody");
  var more = document.getElementById("modMore");
  var filters = document.getElementById("modFilters");
  var state = { tab: init.tab, page: 1, rows: [], report: init.report || 0 };
  var STATUS = {
    products: [["", "Any status"], ["active", "Active"], ["inactive", "Inactive"], ["flagged", "Flagged"], ["removed", "Removed"]],
    reviews: [["", "Any status"], ["visible", "Visible"], ["removed", "Removed"]],
    vendors: [["", "Any status"], ["approved", "Approved"], ["pending", "Pending"], ["suspended", "Suspended"], ["rejected", "Rejected"]],
    reports: [["open", "Open"], ["resolved", "Resolved"], ["dismissed", "Dismissed"], ["all", "All reports"]]
  };

  function btn(label, data, cls) {
    return '<button type="button" class="' + (cls || "adm-btn-outline") + '" ' + Object.keys(data).map(function (k) { return "data-" + k + '="' + esc(data[k]) + '"'; }).join(" ") + ">" + esc(label) + "</button>";
  }
  function stars(n) {
    var star = '<svg class="icon" style="width:14px;height:14px"><use href="#icon-star"/></svg>';
    return star.repeat(n) + '<span style="opacity:.25">' + star.repeat(5 - n) + "</span>";
  }
  function table(head, rows, empty) {
    if (!rows.length) return '<section class="adm-card adm-panel"><div class="adm-empty-inline"><strong>' + esc(empty[0]) + "</strong>" + esc(empty[1]) + "</div></section>";
    return '<section class="adm-card adm-panel"><div class="table-responsive"><table class="table adm-table mod-table"><thead><tr>' +
      head.map(function (h) { return "<th>" + esc(h) + "</th>"; }).join("") + "</tr></thead><tbody>" + rows.join("") + "</tbody></table></div></section>";
  }
  function td(label, html, cls) { return '<td data-label="' + esc(label) + '"' + (cls ? ' class="' + cls + '"' : "") + ">" + html + "</td>"; }
  function reportsBadge(n) { return n ? ' <span class="adm-badge open">' + n + " report" + (n > 1 ? "s" : "") + "</span>" : ""; }

  var render = {
    products: function (rows) {
      return table(["Product", "Vendor", "Category", "Status", ""], rows.map(function (p) {
        return "<tr>" +
          td("Product", '<div class="adm-person"><span class="mod-thumb"' + (p.image ? ' style="background-image:url(\'' + esc(p.image) + '\')"' : "") + '></span><div><strong>' + esc(p.name) + "</strong>" + reportsBadge(p.reports) +
            '<div class="mod-text">' + esc(p.price) + (p.description ? ", " + esc(p.description) : "") + "</div></div></div>") +
          td("Vendor", esc(p.vendor)) + td("Category", esc([p.market, p.category].filter(Boolean).join(" › ") || "-")) + td("Status", A.badge(p.status)) +
          td("Actions", '<div class="mod-actions"><a class="adm-btn-outline" target="_blank" rel="noopener" href="' + esc(p.view) + '">View</a>' +
            (p.status !== "active" ? btn("Activate", { act: "product", id: p.id, status: "active", label: p.name }) : btn("Deactivate", { act: "product", id: p.id, status: "inactive", label: p.name })) +
            (p.status !== "flagged" ? btn("Flag", { act: "product", id: p.id, status: "flagged", label: p.name }) : "") +
            (p.status !== "removed" ? btn("Remove", { act: "product", id: p.id, status: "removed", label: p.name }, "adm-btn adm-btn-danger") : "") + "</div>", "text-end") + "</tr>";
      }), ["No products found", "Try another search or filter."]);
    },
    reviews: function (rows) {
      return table(["Review", "Customer -> Vendor", "Rating", "Status", ""], rows.map(function (r) {
        return "<tr>" +
          td("Review", '<div class="mod-text">' + (r.text ? '"' + esc(r.text) + '"' : '<em>No written comment</em>') + reportsBadge(r.reports) + "</div>" +
            '<small class="text-muted">' + esc(r.date) + (r.order ? ", order #" + esc(r.order) : "") + (r.products ? ", " + esc(r.products) : "") + "</small>") +
          td("Customer -> Vendor", esc(r.customer) + " " + esc(r.vendor)) + td("Rating", stars(r.rating)) + td("Status", A.badge(r.status)) +
          td("Actions", '<div class="mod-actions"><a class="adm-btn-outline" target="_blank" rel="noopener" href="' + esc(r.view) + '">View</a>' +
            (r.status === "visible" ? btn("Remove", { act: "review", id: r.id, op: "remove", label: "review by " + r.customer }, "adm-btn adm-btn-danger")
              : btn("Restore", { act: "review", id: r.id, op: "restore", label: "review by " + r.customer })) + "</div>", "text-end") + "</tr>";
      }), ["No reviews found", "Reviews appear here once customers rate completed orders."]);
    },
    vendors: function (rows) {
      return table(["Stall", "Owner", "Categories", "Products", "Status", ""], rows.map(function (v) {
        return "<tr>" +
          td("Stall", '<div class="adm-person"><span class="mod-thumb"' + (v.image ? ' style="background-image:url(\'' + esc(v.image) + '\')"' : "") + '></span><div><strong>' + esc(v.name) + "</strong>" + reportsBadge(v.reports) +
            (v.description ? '<div class="mod-text">' + esc(v.description) + "</div>" : "") + "</div></div>") +
          td("Owner", esc(v.owner) + '<div class="text-muted">' + esc(v.email) + "</div>") + td("Categories", esc(v.categories || "-")) + td("Products", String(v.products)) + td("Status", A.badge(v.status)) +
          td("Actions", '<div class="mod-actions"><a class="adm-btn-outline" target="_blank" rel="noopener" href="' + esc(v.view) + '">View</a><a class="adm-btn-outline" href="' + esc(v.manage) + '">Account</a>' +
            (v.status === "suspended" ? btn("Restore", { act: "vendor", id: v.id, op: "restore", label: v.name })
              : v.status === "approved" ? btn("Suspend", { act: "vendor", id: v.id, op: "suspend", label: v.name }, "adm-btn adm-btn-danger") : "") + "</div>", "text-end") + "</tr>";
      }), ["No vendors found", "Try another search or status."]);
    },
    reports: function (rows) {
      return table(["Reported content", "Reason", "Reporter", "Status", ""], rows.map(function (r) {
        var take = "";
        if (r.status === "open") {
          if (r.type === "product" && r.content_state !== "removed") take += btn("Remove product", { act: "product", id: r.content_id, status: "removed", report: r.id, label: "reported product" }, "adm-btn adm-btn-danger");
          if (r.type === "review" && r.content_state === "visible") take += btn("Remove review", { act: "review", id: r.content_id, op: "remove", report: r.id, label: "reported review" }, "adm-btn adm-btn-danger");
          if (r.type === "vendor" && r.content_state === "approved") take += btn("Suspend vendor", { act: "vendor", id: r.content_id, op: "suspend", report: r.id, label: "reported vendor" }, "adm-btn adm-btn-danger");
          if (r.type === "message" && r.content_state === "visible") take += btn("Hide message", { act: "message", id: r.content_id, op: "remove", report: r.id, label: "reported message" }, "adm-btn adm-btn-danger");
          take += btn("Resolve", { act: "report", id: r.id, op: "resolve", label: "report" }) + btn("Dismiss", { act: "report", id: r.id, op: "dismiss", label: "report" });
        }
        return "<tr" + (state.report === r.id ? ' class="is-highlight"' : "") + ">" +
          td("Reported content", '<span class="adm-badge">' + esc(r.type) + '</span><div class="mod-text" style="margin-top:6px">' + esc(r.preview) + "</div>" + (r.content_state ? '<small class="text-muted">Currently: ' + esc(r.content_state) + "</small>" : "")) +
          td("Reason", "<strong>" + esc(r.reason) + "</strong>" + (r.details ? '<div class="mod-text">' + esc(r.details) + "</div>" : "")) +
          td("Reporter", esc(r.reporter) + '<div class="text-muted">' + esc(r.date) + "</div>") +
          td("Status", A.badge(r.status) + (r.action_taken ? '<div class="text-muted">' + esc(r.action_taken) + "</div>" : "")) +
          td("Actions", '<div class="mod-actions">' + (r.view ? '<a class="adm-btn-outline" target="_blank" rel="noopener" href="' + esc(r.view) + '">View</a>' : "") + take + "</div>", "text-end") + "</tr>";
      }), ["No reported content", "Reports from customers and vendors appear here."]);
    },
    log: function (rows) {
      return table(["When", "Admin", "Content", "Action", "Reason"], rows.map(function (l) {
        return "<tr>" + td("When", esc(l.date)) + td("Admin", esc(l.admin)) + td("Content", esc(l.type) + " #" + l.content_id) + td("Action", A.badge(l.action)) + td("Reason", '<div class="mod-text">' + esc(l.reason || "-") + "</div>") + "</tr>";
      }), ["No moderation actions yet", "Every moderation action is recorded here."]);
    }
  };

  function overview(res) {
    var k = res.kpis;
    var tiles = [["open_reports", "Open reports", "reports"], ["products_active", "Active products", "products"], ["products_hidden", "Hidden products", "products"],
      ["reviews_visible", "Visible reviews", "reviews"], ["reviews_removed", "Removed reviews", "reviews"], ["vendors_suspended", "Suspended vendors", "vendors"]];
    body.innerHTML = '<div class="mod-kpis">' + tiles.map(function (t) {
      return '<button type="button" class="adm-card mod-kpi text-start" data-goto="' + t[2] + '" style="border:1px solid var(--line-soft)"><b>' + k[t[0]] + "</b><span>" + t[1] + "</span></button>";
    }).join("") + "</div>" + '<h2 class="adm-h2" style="margin:8px 0 12px">Latest moderation activity</h2>' + render.log(res.recent);
    more.hidden = true;
  }

  function query() {
    var q = { tab: state.tab, page: state.page };
    new FormData(filters).forEach(function (v, k) {
      var el = filters.elements[k];
      var forTabs = el.getAttribute && el.getAttribute("data-for");
      if (v !== "" && (!forTabs || forTabs.split(" ").indexOf(state.tab) > -1)) q[k] = v;
    });
    if (state.report && state.tab === "reports") q.report = state.report;
    return q;
  }

  function syncFilters() {
    filters.hidden = state.tab === "overview";
    filters.querySelectorAll("[data-for]").forEach(function (el) { el.hidden = el.getAttribute("data-for").split(" ").indexOf(state.tab) < 0; });
    var st = filters.elements.status;
    if (STATUS[state.tab]) st.innerHTML = STATUS[state.tab].map(function (o) { return '<option value="' + o[0] + '">' + o[1] + "</option>"; }).join("");
  }

  function load(append) {
    if (!append) state.page = 1;
    return A.api(API, { query: query() }).then(function (res) {
      if (state.tab === "overview") return overview(res);
      state.rows = append ? state.rows.concat(res.rows) : res.rows;
      body.innerHTML = render[state.tab](state.rows);
      more.hidden = !res.has_more;
    }).catch(function (err) { body.innerHTML = '<div class="adm-error">' + esc(err.message) + "</div>"; });
  }

  function setTab(tab) {
    state.tab = tab;
    document.querySelectorAll("#modTabs [data-tab]").forEach(function (b) { b.classList.toggle("active", b.dataset.tab === tab); b.setAttribute("aria-selected", b.dataset.tab === tab ? "true" : "false"); });
    var u = new URL(location.href); u.searchParams.set("tab", tab); if (tab !== "reports") { u.searchParams.delete("report"); state.report = 0; } history.replaceState(null, "", u);
    filters.reset();
    syncFilters();
    load();
  }

  document.getElementById("modTabs").addEventListener("click", function (e) { var b = e.target.closest("[data-tab]"); if (b) setTab(b.dataset.tab); });
  filters.addEventListener("submit", function (e) { e.preventDefault(); state.report = 0; load(); });
  more.addEventListener("click", function () { state.page++; load(true); });

  /* actions (reason modal) */
  var modalEl = document.getElementById("modAction");
  var modal = new bootstrap.Modal(modalEl);
  var form = document.getElementById("modActionForm");
  var pending = null;
  var NEED_REASON = { removed: 1, flagged: 1, remove: 1, suspend: 1 };

  body.addEventListener("click", function (e) {
    var go = e.target.closest("[data-goto]");
    if (go) { setTab(go.dataset.goto); return; }
    var b = e.target.closest("[data-act]");
    if (!b) return;
    var d = b.dataset;
    var what = d.status || d.op;
    pending = { action: d.act, id: d.id, status: d.status, op: d.op, report_id: d.report || "", required: !!NEED_REASON[what] };
    document.getElementById("modActionTitle").textContent = b.textContent + (d.act === "report" ? "" : " - " + d.label);
    document.getElementById("modActionText").textContent = d.act === "report"
      ? (d.op === "dismiss" ? "Close this report without action." : "Mark this report as handled.")
      : "This change is logged and the affected account is notified.";
    document.getElementById("modReasonReq").hidden = !pending.required;
    form.reset();
    A.clearErrors(form);
    modal.show();
  });

  form.addEventListener("submit", function (e) {
    e.preventDefault();
    var reason = form.elements.reason.value.trim();
    if (pending.required && !reason) { A.showErrors(form, { reason: "A reason is required for this action." }); return; }
    var ok = document.getElementById("modActionOk");
    ok.disabled = true;
    A.api(API, { body: { action: pending.action, id: pending.id, status: pending.status, op: pending.op, report_id: pending.report_id, reason: reason } })
      .then(function (res) { A.toast(res.message); modal.hide(); load(); })
      .catch(function (err) { if (err.errors && Object.keys(err.errors).length) A.showErrors(form, err.errors); else A.toast(err.message, true); })
      .then(function () { ok.disabled = false; });
  });

  syncFilters();
  load();
})();
