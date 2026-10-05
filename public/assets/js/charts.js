/* Shared Chart.js setup for the analytics pages: theme defaults, mark specs, labels and table views. */
"use strict";

const Charts = (() => {
  const css = getComputedStyle(document.documentElement);
  const token = (name) => css.getPropertyValue(name).trim();

  const color = {
    series: [token("--series-1"), token("--series-2"), token("--series-3")],
    shortage: "#E66767", // diverging warm pole (dark-mode red step)
    surplus: token("--series-1"), // diverging cool pole
    surface: token("--surface"),
    text: token("--text"),
    text2: token("--text-2"),
    muted: token("--text-muted"),
    grid: token("--chart-grid"),
    axis: token("--chart-axis"),
  };

  Chart.defaults.color = color.muted;
  Chart.defaults.font.family = '"Open Sans", system-ui, sans-serif';
  Chart.defaults.font.size = 12;
  Chart.defaults.maintainAspectRatio = false;
  Chart.defaults.animation.duration = 300;
  Chart.defaults.plugins.legend.display = false; // HTML legends are rendered next to the chart
  Object.assign(Chart.defaults.plugins.tooltip, {
    backgroundColor: token("--surface-3"),
    titleColor: color.text,
    bodyColor: color.text2,
    borderColor: "rgba(255,255,255,0.16)",
    borderWidth: 1,
    padding: 10,
    cornerRadius: 8,
    boxWidth: 10,
    boxHeight: 10,
    boxPadding: 4,
    usePointStyle: false,
  });

  const fmt = (v, digits = 0) => Number(v).toLocaleString("en-US", { maximumFractionDigits: digits, minimumFractionDigits: digits });

  /** Linear value axis with recessive hairline grid. */
  function valueAxis(extra = {}) {
    return {
      beginAtZero: true,
      grid: { color: color.grid, drawBorder: false, tickLength: 0 },
      ticks: { padding: 8, callback: (v) => fmt(v, Number.isInteger(v) ? 0 : 1) },
      ...extra,
    };
  }

  function categoryAxis(extra = {}) {
    return {
      grid: { display: false, drawBorder: true, borderColor: color.axis },
      ticks: { padding: 6, color: color.text2 },
      ...extra,
    };
  }

  /** Bar dataset following the mark specs: <= 24px thick, 4px rounded data end, square at the baseline. */
  function barDataset(label, data, fill, extra = {}) {
    return {
      label,
      data,
      backgroundColor: fill,
      hoverBackgroundColor: fill,
      borderRadius: 4,
      borderSkipped: "start",
      maxBarThickness: 24,
      barPercentage: 0.86,
      categoryPercentage: 0.8,
      ...extra,
    };
  }

  /** Draws the value at the tip of each bar (single-series charts with few bars). */
  const valueLabels = {
    id: "valueLabels",
    afterDatasetsDraw(chart, _args, options) {
      if (!options || !options.enabled) return;
      const { ctx } = chart;
      const horizontal = chart.options.indexAxis === "y";
      ctx.save();
      ctx.font = `600 12px ${Chart.defaults.font.family}`;
      ctx.fillStyle = color.text2;
      chart.data.datasets.forEach((dataset, di) => {
        const meta = chart.getDatasetMeta(di);
        if (meta.hidden) return;
        meta.data.forEach((bar, i) => {
          const value = dataset.data[i];
          if (value === null || value === undefined) return;
          const text = options.format ? options.format(value) : fmt(value);
          if (horizontal) {
            ctx.textAlign = value < 0 ? "right" : "left";
            ctx.textBaseline = "middle";
            ctx.fillText(text, bar.x + (value < 0 ? -6 : 6), bar.y);
          } else {
            ctx.textAlign = "center";
            ctx.textBaseline = "bottom";
            ctx.fillText(text, bar.x, bar.y - 4);
          }
        });
      });
      ctx.restore();
    },
  };

  /** Labels the last point of each line with its value. */
  const endLabel = {
    id: "endLabel",
    afterDatasetsDraw(chart, _args, options) {
      if (!options || !options.enabled) return;
      const { ctx } = chart;
      ctx.save();
      ctx.font = `600 12px ${Chart.defaults.font.family}`;
      ctx.fillStyle = color.text;
      ctx.textAlign = "right";
      ctx.textBaseline = "bottom";
      chart.data.datasets.forEach((dataset, di) => {
        const points = chart.getDatasetMeta(di).data;
        const last = points[points.length - 1];
        if (!last) return;
        ctx.fillText(fmt(dataset.data[dataset.data.length - 1]), last.x, last.y - 10);
      });
      ctx.restore();
    },
  };

  /** A zero line on diverging charts. */
  const zeroLine = {
    id: "zeroLine",
    beforeDatasetsDraw(chart, _args, options) {
      if (!options || !options.enabled) return;
      const scale = chart.scales[chart.options.indexAxis === "y" ? "x" : "y"];
      const { ctx, chartArea } = chart;
      const pos = scale.getPixelForValue(0);
      ctx.save();
      ctx.strokeStyle = color.axis;
      ctx.lineWidth = 1;
      ctx.beginPath();
      if (chart.options.indexAxis === "y") {
        ctx.moveTo(pos, chartArea.top);
        ctx.lineTo(pos, chartArea.bottom);
      } else {
        ctx.moveTo(chartArea.left, pos);
        ctx.lineTo(chartArea.right, pos);
      }
      ctx.stroke();
      ctx.restore();
    },
  };

  Chart.register(valueLabels, endLabel, zeroLine);

  /** HTML legend: swatch (or line key) + text in text tokens. */
  function legend(items) {
    return `<div class="legend" aria-hidden="true">${items
      .map(
        (i) => `<span class="legend-item"><span class="${i.line ? "legend-line" : "legend-swatch"}" style="background:${i.color}"></span>${App.esc(i.label)}</span>`
      )
      .join("")}</div>`;
  }

  /**
   * Create (or replace) a chart inside a panel that has a `.chart-box` and an optional
   * `[data-table-toggle]` button; the button swaps the canvas for a table of the same data.
   */
  function render(panel, config, { tableFormat = (v) => fmt(v, 1) } = {}) {
    const box = panel.querySelector(".chart-box");
    const old = Chart.getChart(box.querySelector("canvas"));
    old?.destroy();
    box.innerHTML = '<canvas role="img"></canvas><div class="chart-table d-none"></div>';
    const canvas = box.querySelector("canvas");
    canvas.setAttribute("aria-label", panel.querySelector(".panel-title")?.textContent ?? "Chart");
    const chart = new Chart(canvas, config);

    const tableBox = box.querySelector(".chart-table");
    const { labels, datasets } = config.data;
    tableBox.innerHTML = `<div class="table-wrap" style="max-height:100%;overflow:auto"><table class="table-app">
        <thead><tr><th scope="col"></th>${datasets.map((d) => `<th scope="col" class="num">${App.esc(d.label)}</th>`).join("")}</tr></thead>
        <tbody>${labels
          .map((label, i) => `<tr><td>${App.esc(label)}</td>${datasets.map((d) => `<td class="num">${d.data[i] === null ? "–" : tableFormat(d.data[i])}</td>`).join("")}</tr>`)
          .join("")}</tbody></table></div>`;
    tableBox.style.height = "100%";

    const toggle = panel.querySelector("[data-table-toggle]");
    if (toggle) {
      const showTable = toggle.getAttribute("aria-pressed") === "true";
      canvas.classList.toggle("d-none", showTable);
      tableBox.classList.toggle("d-none", !showTable);
      toggle.onclick = () => {
        const next = toggle.getAttribute("aria-pressed") !== "true";
        toggle.setAttribute("aria-pressed", String(next));
        toggle.innerHTML = next ? '<i class="fa fa-chart-column me-1" aria-hidden="true"></i>Chart' : '<i class="fa fa-table me-1" aria-hidden="true"></i>Table';
        canvas.classList.toggle("d-none", next);
        tableBox.classList.toggle("d-none", !next);
      };
    }
    return chart;
  }

  /** Download rows as a CSV file. */
  function downloadCsv(filename, header, rows) {
    const escape = (v) => {
      const s = String(v ?? "");
      return /[",\n]/.test(s) ? `"${s.replaceAll('"', '""')}"` : s;
    };
    const csv = "﻿" + [header, ...rows].map((r) => r.map(escape).join(",")).join("\n");
    const url = URL.createObjectURL(new Blob([csv], { type: "text/csv;charset=utf-8" }));
    const a = Object.assign(document.createElement("a"), { href: url, download: filename });
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  }

  return { color, fmt, valueAxis, categoryAxis, barDataset, legend, render, downloadCsv };
})();
