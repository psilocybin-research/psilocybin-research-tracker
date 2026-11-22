(() => {
  const chartControlsUrl = new URL('./chart-tools.mjs', document.currentScript.src);
  chartControlsUrl.search = new URL(document.currentScript.src).search;

  const canvas = document.querySelector("#visitor-insights-chart");
  const dataNode = document.querySelector("#visitor-chart-data");
  const controls = [...document.querySelectorAll("[data-visitor-chart-view]")];
  const title = document.querySelector("#visitor-chart-title");
  const note = document.querySelector("#visitor-chart-note");
  const fallback = document.querySelector("#visitor-chart-fallback");
  if (!canvas || !dataNode || typeof window.Chart !== "function") return;

  let source;
  try {
    source = JSON.parse(dataNode.textContent || "{}");
  } catch (error) {
    return;
  }

  let range = JSON.parse(document.querySelector("#visitor-range-config")?.textContent || "{}");
  let currentView = "countries";
  const palette = ["#1a6b54", "#62a684", "#b6d8c3", "#d6aa63", "#7796a8", "#836d91", "#a86b61", "#91a45c"];
  const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  const integer = new Intl.NumberFormat(document.documentElement.lang || "en");
  const percent = (value) => `${Math.round(value * 10) / 10}%`;
  const values = (rows) => rows.map((row) => Number(row.count || 0));
  const labels = (rows) => rows.map((row) => String(row.name || row.date || "Unknown"));
  const sum = (items) => items.reduce((total, item) => total + Number(item || 0), 0);

  const sharedScales = (horizontal = false) => ({
    x: {
      beginAtZero: true,
      grid: { color: "rgba(18,60,49,.08)", drawBorder: false },
      ticks: { color: "#607168", precision: 0, font: { size: 11 } },
    },
    y: {
      beginAtZero: true,
      grid: { display: !horizontal, color: "rgba(18,60,49,.08)", drawBorder: false },
      ticks: { color: "#607168", precision: 0, font: { size: 11 } },
    },
  });

  const views = {
    daily: () => {
      const rows = source.daily || [];
      const observed = rows.filter((row) => row.recorded !== false && row.visitors !== null);
      const peak = observed.reduce((best, row) => Number(row.visitors || 0) > Number(best.visitors || 0) ? row : best, observed[0] || { date: "—", visitors: 0 });
      return {
        heading: range.calendar ? `Daily visitors · ${range.label}` : "Distinct browser addresses · last 7 days",
        explanation: observed.length ? `Daily distinct browser addresses. Peak: ${peak.date} with ${integer.format(peak.visitors || 0)}.` : "No retained logs for this period. Gaps indicate unavailable observations.",
        type: "line",
        data: { labels: rows.map((row) => row.date), datasets: [{ label: "Browser addresses", data: rows.map((row) => row.recorded === false || row.visitors === null ? null : Number(row.visitors || 0)), spanGaps: false, borderColor: palette[0], backgroundColor: "rgba(98,166,132,.22)", fill: true, tension: .32, pointRadius: 4, pointHoverRadius: 6, pointBackgroundColor: "#fff", pointBorderWidth: 2 }] },
        options: { scales: sharedScales(), interaction: { mode: "index", intersect: false } },
      };
    },
    reach: () => {
      const periods = ["7", "30", "90", "all"];
      const rows = source.period_groups || periods.map((days) => ({ ...source.periods?.[days], label: days === "all" ? "Recorded reach" : `${days} days` }));
      const latest = source.periods?.["30"] || {};
      const pagesPerSession = latest.sessions ? Number(latest.page_views || 0) / Number(latest.sessions) : 0;
      return {
        heading: range.calendar ? `Monthly reach · ${range.label}` : "Reach and engagement windows",
        explanation: range.calendar ? `Selected period: ${pagesPerSession.toFixed(1)} page views per session. Each month shows its own distinct addresses; monthly unique counts should not be added together.` : `The 30-day window averages ${pagesPerSession.toFixed(1)} page views per session.`,
        type: "bar",
        data: { labels: rows.map((row) => row.label), datasets: [
          { label: "Browser addresses", data: rows.map((row) => row.visitors === null ? null : Number(row.visitors || 0)), backgroundColor: palette[0], borderRadius: 6 },
          { label: "Sessions", data: rows.map((row) => row.sessions === null ? null : Number(row.sessions || 0)), backgroundColor: palette[1], borderRadius: 6 },
          { label: "Page views", data: rows.map((row) => row.page_views === null ? null : Number(row.page_views || 0)), backgroundColor: palette[3], borderRadius: 6 },
        ] },
        options: { scales: sharedScales(), interaction: { mode: "index", intersect: false } },
      };
    },
    countries: () => rankedView(`Countries · ${range.label || "last 30 days"}`, source.countries || [], "Browser addresses by country."),
    devices: () => shareView(`Devices · ${range.label || "last 30 days"}`, source.devices || [], "Page requests by device."),
    browsers: () => shareView(`Browsers · ${range.label || "last 30 days"}`, source.browsers || [], "Page requests by browser."),
    pages: () => rankedView(`Popular pages · ${range.label || "last 30 days"}`, source.pages || [], "Page views by page."),
  };

  function rankedView(heading, rows, explanation) {
    const maximumLabels = rows.map((row) => String(row.name || "")).some((label) => label.length > 26) ? 10 : 14;
    return {
      heading, explanation, type: "bar",
      data: { labels: labels(rows), datasets: [{ label: "Count", data: values(rows), backgroundColor: rows.map((_, index) => palette[index % palette.length]), borderRadius: 6, barThickness: "flex", maxBarThickness: 28 }] },
      options: { indexAxis: "y", scales: sharedScales(true), plugins: { legend: { display: false } }, layout: { padding: { left: maximumLabels === 10 ? 0 : 4 } } },
    };
  }

  function shareView(heading, rows, explanation) {
    const total = sum(values(rows));
    return {
      heading, explanation, type: "doughnut",
      data: { labels: labels(rows), datasets: [{ label: "Requests", data: values(rows), backgroundColor: rows.map((_, index) => palette[index % palette.length]), borderColor: "#fff", borderWidth: 3, hoverOffset: 8 }] },
      options: {
        cutout: "64%",
        plugins: { tooltip: { callbacks: { label: (context) => ` ${context.label}: ${integer.format(context.raw)} (${percent(total ? 100 * context.raw / total : 0)})` } } },
      },
    };
  }

  const baseOptions = {
    responsive: true,
    maintainAspectRatio: false,
    animation: reducedMotion ? false : { duration: 480, easing: "easeOutQuart" },
    plugins: {
      legend: { position: "bottom", labels: { color: "#40594f", usePointStyle: true, boxWidth: 8, padding: 18, font: { size: 11, weight: 650 } } },
      tooltip: { backgroundColor: "rgba(18,60,49,.96)", titleFont: { weight: 750 }, padding: 11, cornerRadius: 9, displayColors: true },
    },
  };

  let chart = null;
  const render = (name) => {
    const selected = views[name] ? name : "countries";
    currentView = selected;
    const view = views[selected]();
    chart?.destroy();
    canvas.removeAttribute("hidden");
    canvas.setAttribute("aria-label", `${view.heading}. ${view.explanation}`);
    chart = new window.Chart(canvas.getContext("2d"), {
      type: view.type,
      data: view.data,
      options: { ...baseOptions, ...view.options, plugins: { ...baseOptions.plugins, ...(view.options?.plugins || {}) } },
    });
    if (title) title.textContent = view.heading;
    if (note) note.textContent = view.explanation;
    controls.forEach((button) => {
      const active = button.dataset.visitorChartView === selected;
      button.classList.toggle("is-active", active);
      button.setAttribute("aria-pressed", active ? "true" : "false");
    });
    if (fallback) fallback.hidden = true;
    const table = document.querySelector('#visitor-data-table');
    if (table) {
      table.querySelector('caption').textContent = view.heading;
      const tr = document.createElement('tr');
      ['Category', ...view.data.datasets.map(ds => ds.label)].forEach(label => { const th = document.createElement('th'); th.scope = 'col'; th.textContent = label; tr.append(th); });
      table.querySelector('thead').replaceChildren(tr);
      table.querySelector('tbody').replaceChildren(...view.data.labels.map((label,i) => {
        const row = document.createElement('tr'); const th = document.createElement('th'); th.scope = 'row'; th.textContent = label; row.append(th);
        view.data.datasets.forEach(ds => { const cell = document.createElement('td'); cell.textContent = ds.data[i] === null ? 'Logs unavailable' : integer.format(ds.data[i]); row.append(cell); }); return row;
      }));
    }
  };

  controls.forEach((button) => button.addEventListener("click", () => render(button.dataset.visitorChartView)));
  const frame = canvas.parentElement;
  const shell = document.createElement('div'); shell.className = 'research-chart-shell';
  frame.before(shell); shell.append(frame); frame.classList.add('research-chart-native-frame');
  document.addEventListener('visitor:range', event => { source = event.detail.chart; range = event.detail.range; render(currentView); });
  render("countries");
  import(chartControlsUrl.href).then(tools => tools.enableChartFullscreen(shell, { label: () => title?.textContent || 'Visitor statistics', resize: () => chart?.resize() })).catch(() => {});
})();
