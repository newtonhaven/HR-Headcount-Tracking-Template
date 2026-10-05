/* Workforce analytics: headcount trend, hires/exits, turnover, tenure, age and contract mix. */
"use strict";

(() => {
  const { api, esc, fmt, fmtPct, fmtSigned, emptyState, fillSelect, toast, $ } = App;
  const { color, valueAxis, categoryAxis, barDataset, legend, render } = Charts;

  const filters = $("#wf-filters").elements;
  const query = new URLSearchParams(location.search);
  const TYPES = { permanent: "Permanent", contract: "Contract", temporary: "Temporary", intern: "Intern" };

  async function init() {
    if (["12", "24", "36"].includes(query.get("months"))) filters.months.value = query.get("months");
    try {
      fillSelect(filters.group_id, await api("api/unit-groups.php"), { placeholder: "All groups", value: query.get("group") });
    } catch (error) {
      toast(error.message, "error");
    }
    load();
  }

  async function load() {
    const params = new URLSearchParams();
    if (filters.group_id.value) params.set("group", filters.group_id.value);
    if (filters.months.value !== "24") params.set("months", filters.months.value);
    history.replaceState(null, "", params.toString() ? `?${params}` : location.pathname);

    const grid = document.querySelector(".chart-grid");
    grid.classList.add("is-loading");
    try {
      const data = await api("api/analytics/workforce.php", {
        params: { group_id: filters.group_id.value, months: filters.months.value },
      });
      renderKpis(data);
      renderCharts(data);
      renderTable(data);
    } catch (error) {
      toast(error.message, "error");
    } finally {
      grid.classList.remove("is-loading");
    }
  }

  filters.group_id.addEventListener("change", load);
  filters.months.addEventListener("change", load);

  function renderKpis({ kpis: k, months }) {
    const tile = (label, value, sub = "") =>
      `<div class="kpi"><div class="kpi-label">${label}</div><div class="kpi-value">${value}</div>${sub ? `<div class="kpi-sub">${sub}</div>` : ""}</div>`;
    const period = `last ${months} months`;
    $("#wf-kpis").innerHTML = [
      tile("Active headcount", fmt(k.headcount), `${fmtSigned(k.net_change, 0)} over the ${period}`),
      tile("Hires", fmt(k.hires), period),
      tile("Exits", fmt(k.exits), period),
      tile("Annual turnover", fmtPct(k.turnover_pct, 1), "Exits ÷ average headcount"),
      tile("Average tenure", k.avg_tenure_years === null ? "–" : `${fmt(k.avg_tenure_years, 1)} yrs`, "Active employees"),
      tile("Average age", k.avg_age === null ? "–" : `${fmt(k.avg_age, 1)}`, `${fmtPct(k.female_pct, 0)} women`),
    ].join("");
  }

  function renderCharts(data) {
    const months = data.series.map((s) => s.label);

    // Headcount trend: one series, change over time -> line with an end label.
    const headcounts = data.series.map((s) => s.headcount);
    render(
      $("#chart-trend"),
      {
        type: "line",
        data: {
          labels: months,
          datasets: [
            {
              label: "Active employees",
              data: headcounts,
              borderColor: color.series[0],
              // No area fill: the axis does not start at zero, so a filled area would exaggerate changes.
              fill: false,
              borderWidth: 2,
              tension: 0,
              pointRadius: headcounts.map((_, i) => (i === headcounts.length - 1 ? 4 : 0)),
              pointBackgroundColor: color.series[0],
              pointBorderColor: color.surface,
              pointBorderWidth: 2,
              pointHoverRadius: 5,
              pointHitRadius: 12,
            },
          ],
        },
        options: {
          layout: { padding: { top: 24, right: 8 } },
          interaction: { mode: "index", intersect: false },
          scales: {
            x: categoryAxis({ ticks: { color: color.muted, maxRotation: 0, autoSkip: true, autoSkipPadding: 16 } }),
            y: valueAxis({ beginAtZero: false, grace: "10%", ticks: { precision: 0, padding: 8 } }),
          },
          plugins: {
            endLabel: { enabled: true },
            tooltip: { callbacks: { label: (ctx) => ` ${fmt(ctx.raw)} active employees` } },
          },
        },
      },
      { tableFormat: (v) => fmt(v) }
    );

    // Hires vs exits: two series of counts per month -> grouped columns on one axis.
    $("#chart-flow [data-legend]").innerHTML = legend([
      { label: "Hires", color: color.series[0] },
      { label: "Exits", color: color.series[1] },
    ]);
    render(
      $("#chart-flow"),
      {
        type: "bar",
        data: {
          labels: months,
          datasets: [
            barDataset("Hires", data.series.map((s) => s.hires), color.series[0], { maxBarThickness: 14 }),
            barDataset("Exits", data.series.map((s) => s.exits), color.series[1], { maxBarThickness: 14 }),
          ],
        },
        options: {
          interaction: { mode: "index", intersect: false },
          scales: {
            x: categoryAxis({ ticks: { color: color.muted, maxRotation: 0, autoSkip: true, autoSkipPadding: 16 } }),
            y: valueAxis({ ticks: { precision: 0, padding: 8 } }),
          },
          plugins: {
            tooltip: {
              callbacks: {
                footer: (items) => {
                  const s = data.series[items[0].dataIndex];
                  return `Net: ${fmtSigned(s.hires - s.exits, 0)}`;
                },
              },
            },
          },
        },
      },
      { tableFormat: (v) => fmt(v) }
    );

    singleSeriesBars($("#chart-tenure"), data.tenure.map((t) => t.label), data.tenure.map((t) => t.count), "Employees");

    $("#chart-age [data-legend]").innerHTML = legend([
      { label: "Women", color: color.series[0] },
      { label: "Men", color: color.series[1] },
    ]);
    render(
      $("#chart-age"),
      {
        type: "bar",
        data: {
          labels: data.age_bands.map((a) => a.label),
          datasets: [
            barDataset("Women", data.age_bands.map((a) => a.female), color.series[0]),
            barDataset("Men", data.age_bands.map((a) => a.male), color.series[1]),
          ],
        },
        options: {
          interaction: { mode: "index", intersect: false },
          scales: { x: categoryAxis(), y: valueAxis({ ticks: { precision: 0, padding: 8 } }) },
        },
      },
      { tableFormat: (v) => fmt(v) }
    );

    if (data.exit_reasons.length) {
      singleSeriesBars($("#chart-reasons"), data.exit_reasons.map((r) => r.label), data.exit_reasons.map((r) => r.count), "Exits", true);
    } else {
      $("#chart-reasons .chart-box").innerHTML = emptyState("Nobody left in this period.", "fa-circle-check");
    }

    const types = Object.entries(data.employment_types).sort((a, b) => b[1] - a[1]);
    singleSeriesBars($("#chart-types"), types.map(([t]) => TYPES[t] ?? t), types.map(([, n]) => n), "Employees", true);
  }

  /** One series: one color, value at the bar tip, no legend (the panel title names it). */
  function singleSeriesBars(panel, labels, values, label, horizontal = false) {
    render(
      panel,
      {
        type: "bar",
        data: { labels, datasets: [barDataset(label, values, color.series[0], horizontal ? {} : { maxBarThickness: 40 })] },
        options: {
          indexAxis: horizontal ? "y" : "x",
          layout: { padding: horizontal ? { right: 36 } : { top: 20 } },
          scales: horizontal
            ? { x: valueAxis({ ticks: { precision: 0, padding: 8 } }), y: categoryAxis() }
            : { x: categoryAxis(), y: valueAxis({ ticks: { precision: 0, padding: 8 } }) },
          plugins: {
            valueLabels: { enabled: true },
            tooltip: { callbacks: { label: (ctx) => ` ${fmt(ctx.raw)} ${label.toLowerCase()}` } },
          },
        },
      },
      { tableFormat: (v) => fmt(v) }
    );
  }

  function renderTable({ by_group: rows, breakdown_by: by }) {
    $("#wf-table-title").textContent = `Turnover by ${by === "unit" ? "unit" : "unit group"}`;
    if (!rows.length) {
      $("#wf-table").innerHTML = emptyState("No employees in this selection.", "fa-users");
      return;
    }
    const maxTurnover = Math.max(...rows.map((r) => r.turnover_pct ?? 0), 1);
    $("#wf-table").innerHTML = `
      <div class="table-wrap">
        <table class="table-app">
          <thead><tr>
            <th scope="col">${by === "unit" ? "Unit" : "Unit group"}</th>
            <th scope="col" class="num">Headcount now</th>
            <th scope="col" class="num">At period start</th>
            <th scope="col" class="num">Hires</th>
            <th scope="col" class="num">Exits</th>
            <th scope="col" class="num">Net</th>
            <th scope="col">Annual turnover</th>
          </tr></thead>
          <tbody>${rows
            .map(
              (r) => `<tr>
                <td>${esc(r.name)}</td>
                <td class="num">${fmt(r.headcount)}</td>
                <td class="num">${fmt(r.start_headcount)}</td>
                <td class="num">${fmt(r.hires)}</td>
                <td class="num">${fmt(r.exits)}</td>
                <td class="num">${fmtSigned(r.net_change, 0)}</td>
                <td><div class="bar-cell">
                  <div class="bar-track"><div class="bar-fill" style="width:${((r.turnover_pct ?? 0) / maxTurnover) * 100}%"></div></div>
                  <span class="num" style="min-width:3.5em">${fmtPct(r.turnover_pct, 1)}</span>
                </div></td>
              </tr>`
            )
            .join("")}</tbody>
        </table>
      </div>`;
  }

  init();
})();
