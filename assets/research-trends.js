import { mountChart } from './chart-tools.mjs';

const dataNode = document.querySelector('#research-trends-chart-data');
const host = document.querySelector('#research-trends-chart');
const select = document.querySelector('[data-research-trends-series]');
const note = document.querySelector('[data-research-trends-note]');

if (dataNode && host && select) {
  let data;
  try { data = JSON.parse(dataNode.textContent || '{}'); } catch { data = null; }

  const series = {
    total: { label: 'All indexed records', color: '#123c31', fill: 'rgba(18,60,49,.13)' },
    published: { label: 'Published records', color: '#1a6b54', fill: 'rgba(26,107,84,.14)' },
    preprints: { label: 'Preprints', color: '#8a5e14', fill: 'rgba(138,94,20,.14)' },
    trials: { label: 'Trial registrations', color: '#35698f', fill: 'rgba(53,105,143,.14)' },
    other: { label: 'Other records', color: '#756d86', fill: 'rgba(117,109,134,.14)' },
  };

  const format = new Intl.NumberFormat(document.documentElement.lang || 'en');
  const years = Array.isArray(data?.years) ? [...data.years].sort((left, right) => Number(left.year) - Number(right.year)) : [];
  const render = async () => {
    const key = series[select.value] ? select.value : 'total';
    const current = series[key];
    const labels = years.map(row => String(row.year));
    const values = years.map(row => Number(row[key]) || 0);
    const links = years.map(row => `/index.php?year=${encodeURIComponent(String(row.year))}`);
    const partial = Number(data?.current_year);
    if (note) note.textContent = `Select a series to compare recorded years. ${partial ? `${partial} is incomplete.` : ''}`;
    await mountChart(host, {
      type: 'line',
      data: {
        labels,
        datasets: [{
          label: current.label,
          data: values,
          borderColor: current.color,
          backgroundColor: current.fill,
          fill: true,
          tension: .24,
          borderWidth: 2.25,
          pointRadius: 3,
          pointHoverRadius: 5,
          pointBackgroundColor: current.color,
          pointBorderColor: '#fff',
          pointBorderWidth: 1.5,
        }],
      },
      options: {
        interaction: { intersect: false, mode: 'index' },
        scales: {
          x: { grid: { display: false }, ticks: { autoSkip: true, maxTicksLimit: 12, maxRotation: 0 } },
          y: { beginAtZero: true, ticks: { precision: 0, callback: value => format.format(value) }, grid: { color: '#d8d2c466' } },
        },
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: item => `${current.label}: ${format.format(item.parsed.y)}` } },
        },
      },
    }, {
      height: 330,
      label: `${current.label} by recorded year`,
      headers: ['Recorded year', current.label],
      rows: years.map((row, index) => [row.year, format.format(values[index])]),
      links,
      countsLabel: 'Show chart data table',
      downloadLabel: 'Download PNG',
      filename: `psilocybin-research-${key}-by-year.png`,
    });
  };
  select.addEventListener('change', () => { void render(); });
  void render();
}
