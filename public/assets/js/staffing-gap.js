/* Staffing gap analysis: required FTE vs. current headcount. */
"use strict";

(() => {
  const { api, esc, fmt, fmtPct, fmtSigned, statusBadge, gapCell, emptyState, fillSelect, toast, $, $$ } = App;
  const { color, valueAxis, categoryAxis, barDataset, legend, render, downloadCsv } = Charts;

  const filters = $("#gap-filters").elements;
  const query = new URLSearchParams(location.search);
  let data = null;
  let tableView = "understaffed";

  async function init() {
    try {
      const [groups, shifts] = await Promise.all([api("api/unit-groups.php"), api("api/shifts.php")]);
      fillSelect(filters.group_id, groups, { placeholder: "All groups", value: query.get("group") });
      fillSelect(filters.shift_id, shifts, { placeholder: "All shifts", value: query.get("shift") });
    } catch (error) {
      toast(error.message, "error");
    }
    load();
  }

  async function load() {
    const params = new URLSearchParams();
    if (filters.group_id.value) params.set("group", filters.group_id.value);
    if (filters.shift_id.value) params.set("shift", filters.shift_id.value);
    history.replaceState(null, "", params.toString() ? `?${params}` : location.pathname);

    document.querySelector(".chart-grid").classList.add("is-loading");
    try {
      data = await api("api/analytics/staffing-gap.php", {
        params: { group_id: filters.group_id.value, shift_id: filters.shift_id.value },
      });
      renderKpis();
      renderCharts();
      renderTable();
    } catch (error) {
      $("#gap-kpis").innerHTML = "";
      toast(error.message, "error");
    } finally {
      document.querySelector(".chart-grid").classList.remove("is-loading");
    }
  }

  filters.group_id.addEventListener("change", load);
  filters.shift_id.addEventListener("change", load);

  // ------------------------------------------------------------------ KPIs

  function renderKpis() {
    const k = data.kpis;
    const tile = (label, value, sub = "") =>
      `<div class="kpi"><div class="kpi-label">${label}</div><div class="kpi-value">${value}</div>${sub ? `<div class="kpi-sub">${sub}</div>` : ""}</div>`;
    $("#gap-kpis").innerHTML = [
      tile("Required FTE", fmt(k.required_fte, 1), "Norm from task workload"),
      tile("Current headcount", fmt(k.headcount), `${fmt(k.position_count)} positions`),
      tile("Net gap", `${gapCell(k.gap)} <span class="fs-6 fw-normal text-muted">FTE</span>`, `${fmt(k.shortage_fte, 1)} short · ${fmt(k.surplus_fte, 1)} surplus`),
      tile("Workload vs. staff", fmtPct(k.utilization_pct), "Above 100% means staff are overloaded"),
      tile("Understaffed positions", fmt(k.understaffed), statusBadge("understaffed")),
      tile("Overstaffed positions", fmt(k.overstaffed), statusBadge("overstaffed")),
    ].join("");
  }

  // ------------------------------------------------------------------ charts

  function renderCharts() {
    const unitLabel = data.breakdown_by === "unit" ? "unit" : "unit group";
    $("#chart-headcount .panel-subtitle").textContent = `FTE per ${unitLabel}`;

    if (!data.breakdown.length) {
      ["#chart-headcount", "#chart-gap", "#chart-utilization"].forEach((id) => {
        $(`${id} .chart-box`).innerHTML = emptyState("No positions match these filters.", "fa-folder-open");
        $(`${id} [data-legend]`).innerHTML = "";
      });
      return;
    }

    // Required vs current: magnitude, two series, sorted by size.
    const bySize = [...data.breakdown].sort((a, b) => b.required_fte - a.required_fte);
    const headcountPanel = $("#chart-headcount");
    headcountPanel.querySelector("[data-legend]").innerHTML = legend([
      { label: "Required FTE", color: color.series[0] },
      { label: "Current headcount", color: color.series[1] },
    ]);
    headcountPanel.querySelector(".chart-box").style.height = `${Math.max(300, bySize.length * 44 + 60)}px`;
    render(headcountPanel, {
      type: "bar",
      data: {
        labels: bySize.map((r) => r.name),
        datasets: [
          barDataset("Required FTE", bySize.map((r) => r.required_fte), color.series[0]),
          barDataset("Current headcount", bySize.map((r) => r.headcount), color.series[1]),
        ],
      },
      options: {
        indexAxis: "y",
        interaction: { mode: "index", axis: "y", intersect: false },
        scales: { x: valueAxis(), y: categoryAxis() },
        plugins: {
          tooltip: {
            callbacks: {
              label: (ctx) => ` ${ctx.dataset.label}: ${fmt(ctx.raw, ctx.datasetIndex === 0 ? 1 : 0)}`,
              footer: (items) => `Gap: ${fmtSigned(bySize[items[0].dataIndex].gap)} FTE`,
            },
          },
        },
      },
    });

    // Net gap: polarity -> diverging colors, sorted from biggest shortage to biggest surplus.
    const byGap = [...data.breakdown].sort((a, b) => a.gap - b.gap);
    const gapPanel = $("#chart-gap");
    gapPanel.querySelector("[data-legend]").innerHTML = legend([
      { label: "Shortage (fewer staff than needed)", color: color.shortage },
      { label: "Surplus", color: color.surplus },
    ]);
    gapPanel.querySelector(".chart-box").style.height = `${Math.max(300, byGap.length * 34 + 60)}px`;
    render(
      gapPanel,
      {
        type: "bar",
        data: {
          labels: byGap.map((r) => r.name),
          datasets: [
            barDataset("Gap (FTE)", byGap.map((r) => r.gap), byGap.map((r) => (r.gap < 0 ? color.shortage : color.surplus))),
          ],
        },
        options: {
          indexAxis: "y",
          scales: {
            // Leave room on both sides for the value labels at the bar ends.
            x: valueAxis({
              suggestedMin: Math.min(0, ...byGap.map((r) => r.gap)) * 1.35 - 0.5,
              suggestedMax: Math.max(0, ...byGap.map((r) => r.gap)) * 1.35 + 0.5,
              ticks: { padding: 8, callback: (v) => fmtSigned(v, 0) },
            }),
            y: categoryAxis(),
          },
          plugins: {
            zeroLine: { enabled: true },
            valueLabels: { enabled: true, format: (v) => fmtSigned(v, 1) },
            tooltip: { callbacks: { label: (ctx) => ` ${ctx.raw < 0 ? "Short by" : "Surplus of"} ${fmt(Math.abs(ctx.raw), 1)} FTE` } },
          },
        },
      },
      { tableFormat: (v) => fmtSigned(v, 1) }
    );

    // Utilization distribution: one series over ordered buckets.
    const buckets = data.utilization_buckets.filter((b) => b.label !== "No staff" || b.count > 0);
    render(
      $("#chart-utilization"),
      {
        type: "bar",
        data: {
          labels: buckets.map((b) => b.label),
          datasets: [barDataset("Positions", buckets.map((b) => b.count), color.series[0], { maxBarThickness: 48 })],
        },
        options: {
          layout: { padding: { top: 20 } },
          scales: { x: categoryAxis(), y: valueAxis({ ticks: { precision: 0, padding: 8 } }) },
          plugins: {
            valueLabels: { enabled: true },
            tooltip: {
              callbacks: {
                title: (items) => `Workload ${items[0].label} of staff capacity`,
                label: (ctx) => ` ${fmt(ctx.raw)} positions`,
              },
            },
          },
        },
      },
      { tableFormat: (v) => fmt(v) }
    );
  }

  // ------------------------------------------------------------------ table

  function renderTable() {
    const rows =
      tableView === "understaffed"
        ? data.positions.filter((p) => p.status === "understaffed").slice(0, 15)
        : data.positions.filter((p) => p.status === "overstaffed").reverse().slice(0, 15);
    if (!rows.length) {
      $("#gap-table").innerHTML = emptyState(`No ${tableView} positions with these filters.`, "fa-circle-check");
      return;
    }
    $("#gap-table").innerHTML = `
      <div class="table-wrap">
        <table class="table-app">
          <thead><tr>
            <th scope="col">Position</th><th scope="col">Unit</th>
            <th scope="col" class="num">Required FTE</th><th scope="col" class="num">Headcount</th>
            <th scope="col" class="num">Gap</th><th scope="col" class="num">Workload vs. staff</th><th scope="col">Status</th>
          </tr></thead>
          <tbody>${rows
            .map(
              (p) => `<tr class="clickable" tabindex="0" data-id="${p.id}" aria-label="Open ${esc(p.title)} in the staffing report">
                <td>${esc(p.title)}<span class="cell-sub">${esc(p.shift_name ?? "No shift")}</span></td>
                <td>${esc(p.unit_name)}<span class="cell-sub">${esc(p.group_name)}</span></td>
                <td class="num">${fmt(p.required_fte, 1)}</td>
                <td class="num">${fmt(p.headcount)}</td>
                <td class="num">${gapCell(p.gap)}</td>
                <td class="num">${fmtPct(p.utilization_pct)}</td>
                <td>${statusBadge(p.status)}</td>
              </tr>`
            )
            .join("")}</tbody>
        </table>
      </div>`;
  }

  $$("[data-view]").forEach((button) =>
    button.addEventListener("click", () => {
      tableView = button.dataset.view;
      $$("[data-view]").forEach((b) => {
        b.classList.toggle("active", b === button);
        b.setAttribute("aria-pressed", String(b === button));
      });
      renderTable();
    })
  );

  const openPosition = (row) => (location.href = `staffing-report.php?position=${row.dataset.id}`);
  $("#gap-table").addEventListener("click", (event) => {
    const row = event.target.closest("tr.clickable");
    if (row) openPosition(row);
  });
  $("#gap-table").addEventListener("keydown", (event) => {
    const row = event.target.closest("tr.clickable");
    if (row && event.key === "Enter") openPosition(row);
  });

  $("#g-download").addEventListener("click", () => {
    if (!data) return;
    downloadCsv(
      `staffing-gap-${new Date().toLocaleDateString("sv-SE")}.csv`,
      ["Unit group", "Unit", "Position", "Shift", "Tasks", "Workload (min/day)", "Required FTE", "Headcount", "Gap", "Workload vs staff %", "Status"],
      data.positions.map((p) => [
        p.group_name, p.unit_name, p.title, p.shift_name ?? "", p.task_count, p.daily_workload_minutes,
        p.required_fte, p.headcount, p.gap, p.utilization_pct ?? "", p.status,
      ])
    );
  });

  init();
})();
