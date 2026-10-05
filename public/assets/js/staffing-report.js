/* Staffing report: norm vs. actual headcount, drill-down organization -> group -> unit -> position. */
"use strict";

(() => {
  const { api, esc, fmt, fmtPct, fmtMinutes, statusBadge, gapCell, emptyState, fillSelect, toast, $, $$ } = App;

  const filters = $("#report-filters").elements;
  const num = (n) => Number(n).toLocaleString("en-US", { maximumFractionDigits: 2 });
  const range = (min, max) => (Number(min) === Number(max) ? num(min) : `${num(min)}–${num(max)}`);
  const hoursPerDay = (minutes) => fmt(minutes / 60, 1);

  const query = new URLSearchParams(location.search);
  const state = {
    group: Number(query.get("group")) || null,
    unit: Number(query.get("unit")) || null,
    position: Number(query.get("position")) || null,
    shifts: (query.get("shifts") || "").split(",").map(Number).filter(Boolean),
  };
  let shiftList = [];

  function syncUrl() {
    const params = new URLSearchParams();
    if (state.group) params.set("group", state.group);
    if (state.unit) params.set("unit", state.unit);
    if (state.position) params.set("position", state.position);
    if (state.shifts.length) params.set("shifts", state.shifts.join(","));
    history.replaceState(null, "", params.toString() ? `?${params}` : location.pathname);
  }

  // ------------------------------------------------------------------ filters

  async function fillFilters() {
    const [groups, units, positions] = await Promise.all([
      api("api/unit-groups.php"),
      state.group ? api("api/units.php", { params: { group_id: state.group } }) : [],
      state.unit ? api("api/positions.php", { params: { unit_id: state.unit } }) : [],
    ]);
    fillSelect(filters.group_id, groups, { placeholder: "All groups", value: state.group });
    fillSelect(filters.unit_id, units, { placeholder: "All units", value: state.unit });
    fillSelect(filters.position_id, positions, {
      placeholder: "All positions",
      value: state.position,
      label: (p) => `${p.title} · ${p.shift_name ?? "no shift"}`,
    });
    filters.unit_id.disabled = !state.group;
    filters.position_id.disabled = !state.unit;
  }

  function renderShiftOptions() {
    $("#r-shift-options").innerHTML =
      shiftList
        .map(
          (s) => `<div class="form-check">
            <input class="form-check-input" type="checkbox" value="${s.id}" id="shift-${s.id}" ${state.shifts.includes(s.id) ? "checked" : ""}>
            <label class="form-check-label" for="shift-${s.id}">${esc(s.name)} <span class="text-muted">${esc(s.start_time)}–${esc(s.end_time)}</span></label>
          </div>`
        )
        .join("") + `<button type="button" class="btn btn-sm btn-ghost w-100 mt-2" id="shift-clear">All shifts</button>`;
    updateShiftLabel();
  }

  function updateShiftLabel() {
    const selected = shiftList.filter((s) => state.shifts.includes(s.id));
    $("#r-shifts").textContent = !selected.length ? "All shifts" : selected.length === 1 ? selected[0].name : `${selected.length} shifts`;
  }

  async function select(selection) {
    Object.assign(state, selection);
    syncUrl();
    try {
      await fillFilters();
    } catch (error) {
      toast(error.message, "error");
    }
    loadReport();
  }

  filters.group_id.addEventListener("change", () => select({ group: Number(filters.group_id.value) || null, unit: null, position: null }));
  filters.unit_id.addEventListener("change", () => select({ unit: Number(filters.unit_id.value) || null, position: null }));
  filters.position_id.addEventListener("change", () => select({ position: Number(filters.position_id.value) || null }));
  $("#r-shift-options").addEventListener("change", () => {
    state.shifts = $$("#r-shift-options input:checked").map((input) => Number(input.value));
    updateShiftLabel();
    syncUrl();
    loadReport();
  });
  $("#r-shift-options").addEventListener("click", (event) => {
    if (event.target.id !== "shift-clear") return;
    state.shifts = [];
    renderShiftOptions();
    syncUrl();
    loadReport();
  });
  $("#print-report").addEventListener("click", () => window.print());

  // ------------------------------------------------------------------ report

  async function loadReport() {
    const body = $("#report-body");
    body.classList.add("is-loading");
    try {
      const report = await api("api/staffing-report.php", {
        params: {
          group_id: state.group,
          unit_id: state.unit,
          position_id: state.position,
          shift_ids: state.shifts.join(","),
        },
      });
      // Keep the selects in step with the level actually shown (e.g. when a deep link only names a position).
      const crumbId = (level) => report.breadcrumb.find((c) => c.level === level)?.id ?? null;
      if (crumbId("group") !== state.group || crumbId("unit") !== state.unit) {
        Object.assign(state, { group: crumbId("group"), unit: crumbId("unit") });
        syncUrl();
        fillFilters().catch((error) => toast(error.message, "error"));
      }
      render(report);
    } catch (error) {
      body.innerHTML = emptyState(error.message, "fa-triangle-exclamation");
    } finally {
      body.classList.remove("is-loading");
    }
  }

  function render(report) {
    const crumbs = report.breadcrumb;
    $("#report-crumbs").innerHTML = crumbs
      .map((c, i) =>
        i === crumbs.length - 1
          ? `<li aria-current="page">${esc(c.name)}</li>`
          : `<li><a href="#" data-level="${c.level}" data-id="${c.id ?? ""}">${esc(c.name)}</a></li>`
      )
      .join("");

    if (report.level === "position") {
      renderPosition(report);
      return;
    }

    const t = report.totals;
    $("#report-summary").innerHTML = `
      <div class="summary-strip">
        <div><div class="s-label">Positions</div><div class="s-value">${fmt(t.position_count)}</div></div>
        <div><div class="s-label">Workload (hours/day)</div><div class="s-value">${hoursPerDay(t.daily_workload_minutes)}</div></div>
        <div><div class="s-label">Required (FTE)</div><div class="s-value">${fmt(t.required_fte, 1)}</div></div>
        <div><div class="s-label">Current headcount</div><div class="s-value">${fmt(t.headcount)}</div></div>
        <div><div class="s-label">Gap</div><div class="s-value">${gapCell(t.gap)}</div></div>
        <div><div class="s-label">Workload vs. staff</div><div class="s-value">${fmtPct(t.utilization_pct)}</div></div>
      </div>`;

    if (!report.rows.length) {
      $("#report-body").innerHTML = emptyState(
        state.shifts.length ? "Nothing matches the selected shifts at this level." : "No positions defined at this level yet.",
        "fa-folder-open"
      );
      return;
    }
    $("#report-body").innerHTML = report.level === "unit" ? positionsTable(report.rows) : aggregateTable(report);
  }

  function aggregateTable(report) {
    const childLevel = report.level === "organization" ? "group" : "unit";
    const label = childLevel === "group" ? "Unit group" : "Unit";
    return `
      <div class="table-wrap">
        <table class="table-app">
          <thead><tr>
            <th scope="col">${label}</th>
            <th scope="col" class="num">Positions</th>
            <th scope="col" class="num">Workload (h/day)</th>
            <th scope="col" class="num">Required FTE</th>
            <th scope="col" class="num">Headcount</th>
            <th scope="col" class="num">Gap</th>
            <th scope="col" class="num">Workload vs. staff</th>
            <th scope="col">Positions off norm</th>
          </tr></thead>
          <tbody>
            ${report.rows
              .map(
                (r) => `<tr class="clickable" tabindex="0" data-level="${childLevel}" data-id="${r.id}" aria-label="Open ${esc(r.name)}">
                  <td>${esc(r.name)}</td>
                  <td class="num">${fmt(r.position_count)}</td>
                  <td class="num">${hoursPerDay(r.daily_workload_minutes)}</td>
                  <td class="num">${fmt(r.required_fte, 1)}</td>
                  <td class="num">${fmt(r.headcount)}</td>
                  <td class="num">${gapCell(r.gap)}</td>
                  <td class="num">${fmtPct(r.utilization_pct)}</td>
                  <td>${offNorm(r)}</td>
                </tr>`
              )
              .join("")}
          </tbody>
        </table>
      </div>`;
  }

  function offNorm(r) {
    const parts = [];
    if (r.understaffed_positions) parts.push(`<span class="status-badge status-understaffed"><i class="fa fa-arrow-down" aria-hidden="true"></i>${r.understaffed_positions} under</span>`);
    if (r.overstaffed_positions) parts.push(`<span class="status-badge status-overstaffed"><i class="fa fa-arrow-up" aria-hidden="true"></i>${r.overstaffed_positions} over</span>`);
    return parts.join(" ") || '<span class="text-muted">–</span>';
  }

  function positionsTable(rows) {
    return `
      <div class="table-wrap">
        <table class="table-app">
          <thead><tr>
            <th scope="col">Position</th>
            <th scope="col" class="num">Tasks</th>
            <th scope="col" class="num">Workload (h/day)</th>
            <th scope="col" class="num">Required FTE</th>
            <th scope="col" class="num">Headcount</th>
            <th scope="col" class="num">Gap</th>
            <th scope="col" class="num">Workload vs. staff</th>
            <th scope="col">Status</th>
          </tr></thead>
          <tbody>
            ${rows
              .map(
                (p) => `<tr class="clickable" tabindex="0" data-level="position" data-id="${p.id}" aria-label="Open ${esc(p.title)}">
                  <td>${esc(p.title)}<span class="cell-sub">${esc(p.shift_name ?? "No shift")}</span></td>
                  <td class="num">${fmt(p.task_count)}</td>
                  <td class="num">${hoursPerDay(p.daily_workload_minutes)}</td>
                  <td class="num">${fmt(p.required_fte, 1)}</td>
                  <td class="num">${fmt(p.headcount)}</td>
                  <td class="num">${gapCell(p.gap)}</td>
                  <td class="num">${fmtPct(p.utilization_pct)}</td>
                  <td>${statusBadge(p.status)}</td>
                </tr>`
              )
              .join("")}
          </tbody>
        </table>
      </div>`;
  }

  function frequency(t) {
    const parts = [];
    if (t.daily_max > 0) parts.push(`${range(t.daily_min, t.daily_max)} / day`);
    if (t.weekly_max > 0) parts.push(`${range(t.weekly_min, t.weekly_max)} / week`);
    if (t.monthly_max > 0) parts.push(`${range(t.monthly_min, t.monthly_max)} / month`);
    return parts.join("<br>") || "–";
  }

  function renderPosition({ position: p, tasks }) {
    $("#report-summary").innerHTML = `
      <div class="summary-strip">
        <div><div class="s-label">Shift</div><div class="s-value">${esc(p.shift_name ?? "None (defaults)")}</div></div>
        <div><div class="s-label">Daily workload</div><div class="s-value">${fmtMinutes(p.daily_workload_minutes)}</div></div>
        <div><div class="s-label">Capacity per person</div><div class="s-value">${fmtMinutes(p.effective_minutes)}</div></div>
        <div><div class="s-label">Coverage factor</div><div class="s-value">× ${fmt(p.coverage_factor, 2)}</div></div>
        <div><div class="s-label">Required (FTE)</div><div class="s-value">${fmt(p.required_fte, 2)}</div></div>
        <div><div class="s-label">Current headcount</div><div class="s-value">${fmt(p.headcount)}</div></div>
        <div><div class="s-label">Gap</div><div class="s-value">${gapCell(p.gap, 2)}</div><div class="mt-1">${statusBadge(p.status)}</div></div>
      </div>`;
    if (!tasks.length) {
      $("#report-body").innerHTML = emptyState("This position has no tasks, so its norm is zero. Add tasks on the Organization page.", "fa-list-check");
      return;
    }
    const sum = (key) => tasks.reduce((s, t) => s + t[key], 0);
    $("#report-body").innerHTML = `
      <div class="table-wrap">
        <table class="table-app">
          <thead><tr>
            <th scope="col">Task</th>
            <th scope="col" class="num">Duration (min)</th>
            <th scope="col" class="num">Frequency</th>
            <th scope="col" class="num">Mean load (min/day)</th>
            <th scope="col" class="num">Std. dev.</th>
            <th scope="col" class="num">With safety margin</th>
            <th scope="col" class="num">Allowances</th>
            <th scope="col" class="num">Standard time (min/day)</th>
          </tr></thead>
          <tbody>
            ${tasks
              .map(
                (t) => `<tr>
                  <td>${esc(t.name)}<span class="cell-sub">per ${esc(t.unit_of_measure || "item")}</span></td>
                  <td class="num">${range(t.duration_min, t.duration_max)}</td>
                  <td class="num">${frequency(t)}</td>
                  <td class="num">${fmt(t.mean_minutes, 1)}</td>
                  <td class="num">${fmt(t.std_dev, 2)}</td>
                  <td class="num">${fmt(t.safety_minutes, 1)}</td>
                  <td class="num">${num(t.personal_allowance_pct)}% + ${num(t.rest_allowance_pct)}%</td>
                  <td class="num">${fmt(t.standard_minutes, 1)}</td>
                </tr>`
              )
              .join("")}
          </tbody>
          <tfoot><tr>
            <td colspan="3">Total (${tasks.length} tasks)</td>
            <td class="num">${fmt(sum("mean_minutes"), 1)}</td>
            <td></td>
            <td class="num">${fmt(sum("safety_minutes"), 1)}</td>
            <td></td>
            <td class="num">${fmt(sum("standard_minutes"), 1)}</td>
          </tr></tfoot>
        </table>
      </div>`;
  }

  // ------------------------------------------------------------------ drill-down

  function drill(level, id) {
    id = Number(id) || null;
    if (level === "organization") select({ group: null, unit: null, position: null });
    else if (level === "group") select({ group: id, unit: null, position: null });
    else if (level === "unit") select({ unit: id, position: null });
    else if (level === "position") select({ position: id });
  }

  $("#report-body").addEventListener("click", (event) => {
    const row = event.target.closest("tr.clickable");
    if (row) drill(row.dataset.level, row.dataset.id);
  });
  $("#report-body").addEventListener("keydown", (event) => {
    const row = event.target.closest("tr.clickable");
    if (row && (event.key === "Enter" || event.key === " ")) {
      event.preventDefault();
      drill(row.dataset.level, row.dataset.id);
    }
  });
  $("#report-crumbs").addEventListener("click", (event) => {
    const link = event.target.closest("a[data-level]");
    if (!link) return;
    event.preventDefault();
    drill(link.dataset.level, link.dataset.id);
  });

  // ------------------------------------------------------------------ start

  (async () => {
    try {
      shiftList = await api("api/shifts.php");
      renderShiftOptions();
      await fillFilters();
    } catch (error) {
      toast(error.message, "error");
    }
    loadReport();
  })();
})();
