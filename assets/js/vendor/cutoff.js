/* Cut-off settings page. */
(function () {
  "use strict";

  var rulesEl = document.getElementById("rules");
  var modal = document.getElementById("ruleModal");
  var form = document.getElementById("ruleForm");
  var el = form.elements;
  var rules = [];

  function render(data) {
    rules = data.rules;
    document.getElementById("nowLabel").textContent = "Current time: " + data.now;

    var prev = document.getElementById("customerPreview");
    var s = data.summary;
    prev.className = "cutoff-status " + (!data.rules.length ? "off" : s.current_closed ? "closed" : s.accepting ? "open" : "off");
    prev.innerHTML = ML.icon(s.current_closed ? "i-lock" : s.accepting ? "i-check" : "i-clock") + "<span></span>";
    prev.lastChild.textContent = !data.rules.length
      ? "No market days configured - orders fall back to your profile's operating days and close when pickup starts."
      : (s.message || "No upcoming market days are active.") + (s.current_closed && s.next_open ? " Next pickup: " + s.next_open + "." : "");

    if (!rules.length) {
      rulesEl.innerHTML = '<div class="card" style="grid-column:1/-1"><div class="empty"><div class="empty-icon">' + ML.icon("i-clock") + "</div><h4>No market days yet</h4><p>Add each day you trade, its pickup window and when pre-orders should close.</p>" +
        '<button class="btn btn-primary" data-new-rule>' + ML.icon("i-plus") + "Add Market Day</button></div></div>";
      return;
    }
    rulesEl.innerHTML = rules.map(function (r) {
      var st = !r.is_active ? ["off", "i-clock", "Paused - not accepting pre-orders"]
        : !r.next ? ["off", "i-clock", "No upcoming market in the next 7 days"]
        : r.next.open ? ["open", "i-check", "Orders open for " + r.next.date + ", closes " + r.next.deadline]
        : ["closed", "i-lock", "Orders closed for " + r.next.date];
      return '<article class="card cutoff-card fade-in">' +
        '<div class="card-head" style="margin-bottom:6px"><div><span class="o-label">Market</span><div class="day-name">' + ML.esc(r.day_name) + "</div></div>" +
        '<label class="switch" title="Active"><input type="checkbox" data-toggle="' + r.id + '"' + (r.is_active ? " checked" : "") + ' aria-label="Accept pre-orders on ' + ML.esc(r.day_name) + '"><span class="track"></span></label></div>' +
        '<dl class="kv"><dt>Pickup</dt><dd>' + ML.esc(r.pickup_label) + "</dd><dt>Order deadline</dt><dd>" + ML.esc(r.deadline_label) + "</dd></dl>" +
        '<div class="cutoff-status ' + st[0] + '">' + ML.icon(st[1]) + "<span>" + ML.esc(st[2]) + "</span></div>" +
        '<div class="form-foot" style="margin-top:16px"><button class="btn btn-danger-ghost btn-sm" data-delete="' + r.id + '">' + ML.icon("i-trash") + "Remove</button>" +
        '<button class="btn btn-ghost btn-sm" data-edit="' + r.id + '">' + ML.icon("i-edit") + "Edit</button></div></article>";
    }).join("");
  }

  function load() {
    return ML.api("api/vendor/cutoff.php").then(render).catch(function (err) { ML.toast(err.message, "error"); });
  }

  function openForm(r) {
    form.reset();
    ML.clearErrors(form);
    document.getElementById("ruleTitle").textContent = r ? "Edit " + r.day_name + " Market" : "Add Market Day";
    el.id.value = r ? r.id : "";
    if (r) {
      el.market_day.value = r.market_day;
      el.cutoff_days_before.value = r.cutoff_days_before;
      el.cutoff_time.value = r.cutoff_time;
      el.pickup_start.value = r.pickup_start;
      el.pickup_end.value = r.pickup_end;
      el.is_active.checked = r.is_active;
    } else {
      var used = rules.map(function (x) { return x.market_day; });
      for (var d = 6; d <= 13; d++) { var day = ((d - 1) % 7) + 1; if (used.indexOf(day) === -1) { el.market_day.value = day; break; } }
    }
    ML.openModal(modal);
  }

  form.addEventListener("submit", function (e) {
    e.preventDefault();
    var errs = {};
    if (!el.cutoff_time.value) errs.cutoff_time = "Enter a cut-off time.";
    if (!el.pickup_start.value) errs.pickup_start = "Enter a pickup start time.";
    if (!el.pickup_end.value) errs.pickup_end = "Enter a pickup end time.";
    if (el.pickup_start.value && el.pickup_end.value && el.pickup_end.value <= el.pickup_start.value) errs.pickup_end = "Pickup must end after it starts.";
    if (Object.keys(errs).length) { ML.showErrors(form, errs); return; }

    var btn = form.querySelector('[type="submit"]');
    ML.setLoading(btn, true);
    ML.api("api/vendor/cutoff.php", { body: {
      action: "save", id: el.id.value || null, market_day: el.market_day.value, cutoff_days_before: el.cutoff_days_before.value,
      cutoff_time: el.cutoff_time.value, pickup_start: el.pickup_start.value, pickup_end: el.pickup_end.value, is_active: el.is_active.checked
    } })
      .then(function (res) { ML.toast(res.message); ML.closeModal(modal); render(res); })
      .catch(function (err) { ML.showErrors(form, err.errors); ML.toast(err.message, "error"); })
      .then(function () { ML.setLoading(btn, false); });
  });

  document.addEventListener("click", function (e) {
    if (e.target.closest("[data-new-rule]")) { openForm(null); return; }
    var ed = e.target.closest("[data-edit]");
    if (ed) { openForm(rules.find(function (r) { return r.id === Number(ed.getAttribute("data-edit")); })); return; }
    var del = e.target.closest("[data-delete]");
    if (del) {
      var r = rules.find(function (x) { return x.id === Number(del.getAttribute("data-delete")); });
      ML.confirm({ title: "Remove " + r.day_name + " market?", text: "Customers will no longer be able to pre-order for this day.", confirmText: "Remove", danger: true })
        .then(function (ok) {
          if (!ok) return;
          ML.api("api/vendor/cutoff.php", { body: { action: "delete", id: r.id } })
            .then(function (res) { ML.toast(res.message); render(res); })
            .catch(function (err) { ML.toast(err.message, "error"); });
        });
    }
  });

  rulesEl.addEventListener("change", function (e) {
    var t = e.target.closest("[data-toggle]");
    if (!t) return;
    ML.api("api/vendor/cutoff.php", { body: { action: "toggle", id: Number(t.getAttribute("data-toggle")), is_active: t.checked } })
      .then(function (res) { ML.toast(res.message); render(res); })
      .catch(function (err) { ML.toast(err.message, "error"); t.checked = !t.checked; });
  });

  load();
})();
