(async () => {
  'use strict';
  const script = document.currentScript;
  const moduleUrl = new URL('../chart-tools.mjs', script.src);
  moduleUrl.search = new URL(script.src).search;
  let tools;
  try { tools = await import(moduleUrl.href); } catch { return; }
  let trials = [];
  try { trials = JSON.parse(document.querySelector('#research-trials-data')?.textContent || '[]'); } catch { /* Native source counts remain usable. */ }
  const statusCodes = new Map(trials.map(trial => [trial.status_label, trial.status]));
  const green = getComputedStyle(document.documentElement).getPropertyValue('--green-mid').trim() || '#1a6b54';
  const labelLines = label => label.length > 29 ? (label.match(/.{1,25}(?:\s|$)|.{1,25}/g) || [label]).map(part => part.trim()) : [label];
  await Promise.all([...document.querySelectorAll('.explorer-charts .explorer-chart')].map(async card => {
    const rows = [...card.querySelectorAll('.explorer-bar-row')];
    if (!rows.length) return;
    const heading = card.querySelector('h2')?.textContent || 'Indexed trial counts';
    const yearChart = heading === 'Recorded study-start years';
    const filter = heading === 'Current indexed registry status' ? 'status' : heading === 'Countries with reported trial locations' ? 'country' : heading === 'Sponsors recorded in this index' ? 'sponsor' : 'condition';
    const labels = rows.map(row => row.querySelector('span').textContent.trim());
    const values = rows.map(row => Number(row.dataset.chartCount ?? row.querySelector('meter')?.value));
    if (values.some(value => !Number.isFinite(value) || value < 0)) return;
    const links = labels.map(label => {
      if (yearChart) return null;
      const value = filter === 'status' ? statusCodes.get(label) : label;
      if (!value) return null;
      const url = new URL(location.href); url.searchParams.set(filter, value); url.hash = ''; return url.href;
    });
    const host = document.createElement('div'); card.append(host);
    const chart = await tools.mountChart(host, {
      type: 'bar', data: { labels, datasets: [{ label: 'Indexed trial records', data: values, backgroundColor: green, hoverBackgroundColor: '#123c31', borderRadius: 4, maxBarThickness: 26 }] },
      options: {
        indexAxis: yearChart ? 'x' : 'y',
        scales: {
          [yearChart ? 'y' : 'x']: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#d8d2c466' }, title: { display: true, text: 'Indexed trial records' } },
          [yearChart ? 'x' : 'y']: { grid: { display: false }, ticks: { autoSkip: yearChart, maxTicksLimit: yearChart ? 8 : undefined, font: { size: 11 }, callback(value) { const label = this.getLabelForValue(value); return yearChart ? label : labelLines(label); } } },
        },
        plugins: { legend: { display: false }, tooltip: { callbacks: { title: items => labels[items[0].dataIndex] } } },
        onHover(event, elements, chart) { chart.canvas.style.cursor = elements.length && links[elements[0].index] ? 'pointer' : 'default'; },
        onClick(event, elements) { const url = elements.length ? links[elements[0].index] : null; if (url) location.assign(url); },
      },
    }, { height: yearChart ? 280 : Math.max(280, labels.reduce((height, label) => height + Math.max(30, labelLines(label).length * 13 + 12), 42)), label: heading, rows: labels.map((label, i) => [label, values[i]]), links, filename: `psilocybin-trials-${yearChart ? 'start-years' : filter}.png` });
    rows.forEach(row => row.remove());
    if (chart) card.classList.add('pipeline-chart');
    if (chart && links.some(Boolean)) { const hint = document.createElement('p'); hint.className = 'explorer-method'; hint.textContent = 'Select a bar or a count label to filter this view.'; host.append(hint); }
  }));
})();
