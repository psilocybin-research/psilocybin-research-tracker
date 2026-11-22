(() => {
  'use strict';
  const scriptUrl = new URL(document.currentScript.src);
  const root = document.querySelector('#visitor-world-map');
  if (!root) return;
  const list = document.querySelector('#visitor-live-countries');
  const detail = document.querySelector('#visitor-map-detail');
  const status = document.querySelector('#visitor-live-updated');
  const refreshButton = document.querySelector('#visitor-live-refresh');
  const count = new Intl.NumberFormat(document.documentElement.lang || 'en');
  const ns = 'http://www.w3.org/2000/svg';
  let data = JSON.parse(document.querySelector('#visitor-live-data').textContent);
  let paths = [], selection = '', pending = false;
  const node = (tag, text, className) => { const el = document.createElement(tag); if (text !== undefined) el.textContent = text; if (className) el.className = className; return el; };
  const countryRows = () => Array.isArray(data.countries) ? data.countries : [];
  function selectCountry(code) {
    selection = code;
    const row = countryRows().find(row => row.code === code);
    const name = row?.name || paths.find(path => path.dataset.code === code)?.dataset.name || code;
    detail.textContent = `${name} · ${count.format(row?.count || 0)} online in the last 5 minutes`;
    paths.forEach(path => path.classList.toggle('is-selected', path.dataset.code === code));
    list.querySelectorAll('button').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.code === code)));
  }
  function renderLive() {
    document.querySelector('#visitor-live-total').textContent = data.available === false ? '—' : count.format(data.online);
    document.querySelector('#visitor-active-count').textContent = count.format(data.active_sessions || 0);
    document.querySelector('#visitor-pageview-count').textContent = count.format(data.page_views || 0);
    const rows = countryRows();
    list.replaceChildren(...rows.map(row => {
      const li = node('li'); const button = node('button'); button.type = 'button'; button.dataset.code = row.code;
      button.append(node('span', row.name), node('strong', count.format(row.count)));
      button.setAttribute('aria-pressed', String(selection === row.code));
      button.addEventListener('click', () => selectCountry(row.code)); li.append(button); return li;
    }));
    document.querySelector('#visitor-live-empty').hidden = rows.length > 0;
    const maximum = Math.max(1, ...rows.map(row => row.count));
    paths.forEach(path => {
      const row = rows.find(row => row.code === path.dataset.code);
      const total = row?.count || 0;
      path.style.fill = total ? `hsl(157 43% ${48 - 24 * Math.log1p(total) / Math.log1p(maximum)}%)` : '';
      path.classList.toggle('is-active', total > 0);
      path.setAttribute('tabindex', total > 0 ? '0' : '-1');
      path.setAttribute('aria-label', `${row?.name || path.dataset.name}: ${count.format(total)} online`);
      path.querySelector('title').textContent = `${row?.name || path.dataset.name} · ${count.format(total)} online`;
    });
    if (selection) selectCountry(selection);
    else if (!rows.length) detail.textContent = 'No qualifying activity in this five-minute window.';
    else detail.textContent = 'Choose a highlighted country or a country in the list to see its live count.';
  }
  function drawMap(geo) {
    const svg = document.createElementNS(ns, 'svg'); svg.setAttribute('viewBox', '0 0 900 375'); svg.setAttribute('role', 'group'); svg.setAttribute('aria-label', 'Live visitor activity by country');
    const project = ([lon, lat]) => [(lon + 180) * 2.5, (85 - lat) * 2.5];
    for (const feature of geo.features) {
      if (feature.properties.code === 'AQ') continue;
      const polygons = feature.geometry.type === 'Polygon' ? [feature.geometry.coordinates] : feature.geometry.coordinates;
      const d = polygons.map(polygon => polygon.map(ring => ring.map((point, i) => `${i ? 'L' : 'M'}${project(point).map(n => n.toFixed(2)).join(',')}`).join('') + 'Z').join('')).join('');
      const path = document.createElementNS(ns, 'path'); path.setAttribute('d', d); path.dataset.code = feature.properties.code; path.dataset.name = feature.properties.name;
      path.setAttribute('role', 'button'); path.setAttribute('fill-rule', 'evenodd');
      path.append(document.createElementNS(ns, 'title'));
      path.addEventListener('click', () => selectCountry(path.dataset.code));
      path.addEventListener('focus', () => selectCountry(path.dataset.code));
      path.addEventListener('keydown', event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); selectCountry(path.dataset.code); } });
      svg.append(path);
    }
    root.replaceChildren(svg); paths = [...svg.querySelectorAll('path')]; renderLive();
  }
  fetch(new URL('./world-110m.geojson', scriptUrl)).then(response => { if (!response.ok) throw Error(); return response.json(); }).then(drawMap).catch(() => { root.replaceChildren(node('p', 'The map is unavailable. Live country counts are listed alongside it.')); });
  renderLive();
  if (data.available === false) { status.textContent = 'Live reading unavailable. Retrying automatically.'; document.querySelector('#visitor-live-empty').textContent = 'Live activity cannot currently be read.'; }
  async function refresh() {
    if (document.hidden || pending) return;
    pending = true; refreshButton.disabled = true; root.setAttribute('aria-busy', 'true');
    try {
      const response = await fetch('visitor-activity.php', { cache: 'no-store', headers: { Accept: 'application/json' } });
      if (!response.ok) throw Error();
      const next = await response.json();
      if (!Number.isInteger(next.online) || !Array.isArray(next.countries)) throw Error();
      data = next; renderLive();
      status.textContent = `Updated ${new Date(next.updated_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })} · refreshes every 30 seconds`;
    } catch (_) { status.textContent = 'Live update unavailable · showing the last successful reading. Retrying automatically.'; }
    finally { pending = false; refreshButton.disabled = false; root.removeAttribute('aria-busy'); }
  }
  refreshButton.addEventListener('click', refresh);
  window.setInterval(refresh, 30000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });

  const form = document.querySelector('#visitor-period-form');
  if (!form) return;
  let config = JSON.parse(document.querySelector('#visitor-range-config').textContent);
  const from = form.elements.from, to = form.elements.to;
  const rangeStatus = document.querySelector('#visitor-period-status');
  const submit = form.querySelector('[type="submit"]');
  let request = 0, controller;
  const presetButtons = [...form.querySelectorAll('[data-visitor-period]')];
  const presetRange = preset => {
    const months = config.months, last = months.length - 1;
    if (preset === 'previous') return [months[Math.max(0, last - 1)], months[Math.max(0, last - 1)]];
    return [months[preset === 'all' ? 0 : preset === 'three' ? Math.max(0, last - 2) : last], months[last]];
  };
  const syncPresets = () => presetButtons.forEach(button => { const [a,b] = presetRange(button.dataset.visitorPeriod); button.setAttribute('aria-pressed', String(a === from.value && b === to.value)); });
  from.addEventListener('change', () => { if (to.value < from.value) to.value = from.value; to.min = from.value; syncPresets(); });
  to.addEventListener('change', syncPresets);
  async function apply(push = true) {
    if (!form.reportValidity()) return;
    const current = ++request; controller?.abort(); controller = new AbortController();
    const url = new URL(form.action); url.searchParams.set('from', from.value); url.searchParams.set('to', to.value);
    const endpoint = new URL(url); endpoint.searchParams.set('format', 'json');
    submit.disabled = true; form.setAttribute('aria-busy', 'true'); rangeStatus.textContent = 'Loading your selected period…';
    try {
      const response = await fetch(endpoint, { signal: controller.signal, headers: { Accept: 'application/json' }, cache: 'no-store' });
      const result = await response.json(); if (!response.ok) throw Error(result.error || 'Unable to load the selected period.');
      if (current !== request) return;
      config = result.range;
      document.dispatchEvent(new CustomEvent('visitor:range', { detail: result }));
      document.querySelectorAll('[data-visitor-total]').forEach(el => { el.textContent = result.totals[el.dataset.visitorTotal] === null ? '—' : count.format(result.totals[el.dataset.visitorTotal] || 0); });
      document.querySelectorAll('.visitor-breakdown-period').forEach(el => { el.textContent = config.label; });
      document.querySelectorAll('[data-visitor-breakdown]').forEach(el => {
        const rows = el.dataset.visitorBreakdown === 'referrers' ? result.referrers : result.chart[el.dataset.visitorBreakdown] || [];
        el.replaceChildren(...(rows.length ? rows.map(row => { const li = node('li'), span = node('span'); span.append(node('b', row.name)); li.append(span, node('strong', count.format(row.count))); return li; }) : [node('li', 'No recorded data for this period')]));
      });
      rangeStatus.textContent = `${config.label} · ${result.covered_dates} recorded UTC dates.`;
      if (push) history.pushState({}, '', url);
      syncPresets();
    } catch (error) { if (error.name !== 'AbortError' && current === request) rangeStatus.textContent = `${error.message} The previous results remain visible.`; }
    finally { if (current === request) { submit.disabled = false; form.removeAttribute('aria-busy'); } }
  }
  form.addEventListener('submit', event => { event.preventDefault(); apply(); });
  presetButtons.forEach(button => button.addEventListener('click', () => { [from.value,to.value] = presetRange(button.dataset.visitorPeriod); to.min = from.value; syncPresets(); apply(); }));
  window.addEventListener('popstate', event => { event.stopImmediatePropagation(); const url = new URL(location.href); from.value = url.searchParams.get('from') || config.months.at(-1); to.value = url.searchParams.get('to') || from.value; to.min = from.value; apply(false); });
  syncPresets();
})();
