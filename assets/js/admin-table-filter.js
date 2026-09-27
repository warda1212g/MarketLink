/* Search, filter and sort for the tables in admin/reports.php (see ra_table_tools()).
   Each row has data-tf-text for search, data-tf-<key> for filters and data-sort-<key> for sorting. */
(function () {
  "use strict";

  document.querySelectorAll("[data-tf-for]").forEach(function (bar) {
    var table = document.getElementById(bar.getAttribute("data-tf-for"));
    if (!table) return;
    var tbody = table.tBodies[0];
    var rows = Array.prototype.slice.call(tbody.querySelectorAll("tr[data-tf-row]"));
    var original = rows.slice();
    var search = bar.querySelector("[data-tf-search]");
    var filters = Array.prototype.slice.call(bar.querySelectorAll("[data-tf-filter]"));
    var sortSel = bar.querySelector("[data-tf-sort]");
    var reset = bar.querySelector("[data-tf-reset]");
    var count = bar.querySelector("[data-tf-count]");
    var cols = table.tHead ? table.tHead.rows[0].cells.length : 1;

    var empty = document.createElement("tr");
    empty.className = "tf-empty";
    empty.hidden = true;
    empty.innerHTML = '<td colspan="' + cols + '"><strong>No matching rows</strong><span>Try a different search or clear the filters.</span></td>';
    tbody.appendChild(empty);

    function matches(row, key, value) {
      var days = row.getAttribute("data-tf-" + key + "-days");
      if (days !== null) return Number(days) <= Number(value);
      var v = row.getAttribute("data-tf-" + key) || "";
      return v.charAt(0) === "|" ? v.indexOf("|" + value + "|") !== -1 : v === value;
    }

    function apply() {
      var terms = search.value.trim().toLowerCase().split(/\s+/).filter(Boolean);
      var active = filters.filter(function (f) { return f.value !== ""; });
      var shown = 0;

      var order = original.slice();
      if (sortSel && sortSel.value) {
        var parts = sortSel.value.split(":"), key = "data-sort-" + parts[0], dir = parts[1] === "asc" ? 1 : -1;
        var isNum = /^-?\d+(\.\d+)?$/;
        order.sort(function (a, b) {
          var x = a.getAttribute(key) || "", y = b.getAttribute(key) || "";
          var cmp = isNum.test(x) && isNum.test(y) ? parseFloat(x) - parseFloat(y) : x.localeCompare(y);
          return cmp * dir;
        });
      }

      order.forEach(function (row) {
        var text = row.getAttribute("data-tf-text") || "";
        var ok = terms.every(function (t) { return text.indexOf(t) !== -1; }) &&
                 active.every(function (f) { return matches(row, f.getAttribute("data-tf-filter"), f.value); });
        row.hidden = !ok;
        if (ok) shown++;
        tbody.insertBefore(row, empty);
      });

      empty.hidden = shown > 0;
      count.textContent = shown + " of " + original.length;
      var dirty = terms.length || active.length || (sortSel && sortSel.value);
      reset.hidden = !dirty;
      bar.classList.toggle("is-filtered", !!(terms.length || active.length));
      filters.forEach(function (f) { f.classList.toggle("is-set", f.value !== ""); });
    }

    var t;
    search.addEventListener("input", function () { clearTimeout(t); t = setTimeout(apply, 120); });
    search.addEventListener("keydown", function (e) { if (e.key === "Escape") { search.value = ""; apply(); } });
    filters.forEach(function (f) { f.addEventListener("change", apply); });
    if (sortSel) sortSel.addEventListener("change", apply);
    reset.addEventListener("click", function () {
      search.value = "";
      filters.forEach(function (f) { f.value = ""; });
      if (sortSel) sortSel.value = "";
      apply();
      search.focus();
    });
    apply();
  });
})();
