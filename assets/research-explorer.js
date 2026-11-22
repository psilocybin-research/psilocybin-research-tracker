(() => {
  'use strict';
  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
  const readData = (id) => { try { return JSON.parse(document.getElementById(id)?.textContent || 'null'); } catch { return null; } };
  const el = (tag, text, className) => { const node = document.createElement(tag); if (text !== undefined) node.textContent = text; if (className) node.className = className; return node; };
  const link = (text, href) => { const node = el('a', text); node.href = href; return node; };
  const removeButton = (label) => {
    const button = el('button', undefined, 'secondary');
    button.type = 'button'; button.title = label; button.setAttribute('aria-label', label);
    button.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 6h18M9 6V4h6v2M5 6l1 14h12l1-14M10 10v6M14 10v6"/></svg>';
    return button;
  };
  const toast = (message, workspace = false) => {
    let node = $('[data-explorer-toast]');
    if (!node) { node = el('div', '', 'explorer-toast'); node.setAttribute('role', 'status'); node.setAttribute('data-explorer-toast',''); document.body.append(node); }
    node.hidden = false; if(window.TrackerToast)window.TrackerToast.render(node,message);else node.textContent=message;
    if(workspace){node.append(link('Open reading list','research-workspace.php'));}
    const host=document.querySelector('dialog[open]')||document.body;if(node.parentElement!==host)host.append(node);
    if(typeof node.showPopover==='function'){node.popover='manual';if(!node.matches(':popover-open'))node.showPopover();}
    clearTimeout(toast.timer); toast.timer = setTimeout(() => { if(!node.contains(document.activeElement)){if(node.popover&&node.matches(':popover-open'))node.hidePopover();node.hidden = true;} }, workspace ? 12000 : 5000);
  };
  const download = (value, filename, mime = 'application/json') => {
    const url = URL.createObjectURL(new Blob([value], {type: mime}));
    const anchor = link('', url); anchor.download = filename; anchor.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
  };
  // Spreadsheet-safe exports keep source identifiers intact without interpreting formulas.
  const csvCell = (value) => { let text = String(value ?? ''); if (/^[\s]*[=+@-]/.test(text)) text = "'" + text; return '"' + text.replaceAll('"', '""') + '"'; };
  const csv = (rows, fields) => [fields.map(csvCell).join(','), ...rows.map(row => fields.map(field => csvCell(row[field])).join(','))].join('\r\n');
  const key = 'psilocybinResearchWorkspaceV1';
  const paths = new Set(['/', '/index.php', '/research-hub.php', '/research-questions.php', '/research-network.php', '/trial-pipeline.php', '/research-countries.php', '/research-results.php', '/research-updates.php', '/research-library.php', '/germany-psilocybin-research.php', '/research-economics.php', '/citation-network.php', '/trials-map.php', '/trials.php']);
  const safeView = (value) => {
    try { const url = new URL(value, location.origin); return url.origin === location.origin && paths.has(url.pathname) && url.search.length <= 2000 ? url.pathname + url.search + url.hash : null; } catch { return null; }
  };
  const cleanWorkspace = (data) => ({version: 1, ids: [...new Set((Array.isArray(data?.ids) ? data.ids : []).map(Number).filter(id => Number.isSafeInteger(id) && id > 0))].slice(0, 50), searches: (Array.isArray(data?.searches) ? data.searches : []).filter(s => s && typeof s.title === 'string' && typeof s.url === 'string' && safeView(s.url)).slice(0, 30).map(s => ({title: s.title.slice(0, 150), url: safeView(s.url)}))});
  const load = () => { try { return cleanWorkspace(JSON.parse(localStorage.getItem(key) || '{}')); } catch { return cleanWorkspace({}); } };
  const save = (data) => { try { localStorage.setItem(key, JSON.stringify(cleanWorkspace(data))); window.dispatchEvent(new CustomEvent('research-workspace-change')); return true; } catch { toast('Browser storage is unavailable. Enable it or export records directly.'); return false; } };
  const refreshSavedButtons = () => {
    const ids = load().ids;
    $$('[data-save-paper]').forEach(button => {
      const saved = ids.includes(Number(button.dataset.savePaper));
      button.setAttribute('aria-pressed', String(saved));
      let label = button.querySelector('span');
      if (!label) { label = el('span'); button.replaceChildren(label); }
      label.textContent = saved ? 'Saved ✓' : 'Save to reading list';
    });
  };
  refreshSavedButtons();
  ['storage', 'research-workspace-change', 'tracker:results-loaded'].forEach(event => window.addEventListener(event, refreshSavedButtons));
  document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-save-paper]');
    if (!button) return;
    const id = Number(button.dataset.savePaper); if (!Number.isSafeInteger(id) || id < 1) return;
    const data = load(); if (data.ids.includes(id)) { toast('Already in your reading list.', true); return; }
    if (data.ids.length >= 50) { toast('Your reading list contains 50 records. Export or remove some before adding more.'); return; }
    data.ids.push(id); if (save(data)) { refreshSavedButtons(); toast('Saved on this device.', true); }
  });
  $$('[data-save-search]').forEach(button => button.addEventListener('click', () => {
    const url = safeView(location.href); if (!url) return;
    const data = load(); if (!data.searches.some(s => s.url === url)) data.searches.unshift({title: $('h1')?.textContent || document.title, url});
    if (save(data)) toast('View saved on this device.', true);
  }));
  const graph = readData('research-network-data');
  if (graph) {
    const host = $('[data-research-network]'), detail = $('[data-research-selection]');
    const ns = 'http://www.w3.org/2000/svg';
    const svgEl = (tag, attrs) => { const node = document.createElementNS(ns, tag); Object.entries(attrs).forEach(([key, value]) => node.setAttribute(key, value)); return node; };
    const svg = svgEl('svg', {viewBox: '0 0 1000 700', role: 'group', 'aria-label': 'Co-authorship connections; select a researcher for publication evidence'});
    const layer = svgEl('g', {}); svg.append(layer); host.replaceChildren(svg);
    const nodeById = new Map(graph.nodes.map(n => [n.id, n]));
    const papers = new Map(graph.papers.map(p => [p.id, p]));
    let selected = null, layout = 'circle', scale = 1, panX = 0, panY = 0;
    const transform = () => layer.setAttribute('transform', `translate(${500 + panX} ${350 + panY}) scale(${scale}) translate(-500 -350)`);
    const select = (id) => {
      id = Number(id);
      const node = nodeById.get(id); if (!node) return;
      selected = id;
      const neighbours = graph.edges.filter(e => e.source === id || e.target === id);
      const neighboursSet = new Set(neighbours.flatMap(e => [e.source,e.target]));
      $$('[data-network-node]', svg).forEach(g => { g.classList.toggle('is-selected', Number(g.dataset.networkNode) === id); g.classList.toggle('is-muted', !neighboursSet.has(Number(g.dataset.networkNode)) && Number(g.dataset.networkNode) !== id); });
      $$('[data-network-edge]', svg).forEach(line => line.classList.toggle('is-selected', Number(line.dataset.source) === id || Number(line.dataset.target) === id));
      detail.replaceChildren(el('h2', node.name), el('p', `${node.papers.length} indexed works · ${node.connections} collaborators in the topic scope · ${neighbours.length} collaborators in this visual view.`), link("Browse this name's publications", 'authors.php?author=' + encodeURIComponent(node.name)));
      detail.append(link('Explore author in citation network', 'citation-network.php?author=' + encodeURIComponent(node.name) + '&limit=12'));
      detail.append(el('p', 'Identity basis: ' + (node.identity_basis || 'name only') + '. Metadata associations are not independently verified identities.'));
      if (node.identity_uri) detail.append(link('Inspect source identifier', node.identity_uri));
      if (node.aliases?.length > 1) detail.append(el('p', 'Associated name variants: ' + node.aliases.join('; ')));
      detail.append(el('h3', 'Collaborators in this view'));
      const list = el('ul');
      neighbours.sort((a,b) => b.weight-a.weight).forEach(edge => { const other = nodeById.get(edge.source === id ? edge.target : edge.source); const li = el('li'); const button = el('button', `${other.name} · ${edge.weight} shared works`, 'explorer-text-button'); button.addEventListener('click', () => select(other.id)); li.append(button); list.append(li); });
      detail.append(list, el('h3', 'Indexed publication evidence'));
      const evidence = el('div', undefined, 'compact-paper-list');
      node.papers.slice(0, 50).forEach(id => { const p = papers.get(id); if (p) evidence.append(link(`${p.title} (${p.publication_date || 'Date unknown'}; ${p.publication_status === 'preprint' ? 'PREPRINT — not peer reviewed' : p.publication_status})`, 'publication.php?id=' + p.id)); });
      detail.append(evidence); if (node.papers.length > 50) detail.append(el('p','First 50 works shown; the author profile and full JSON export provide further records.'));
    };
    const render = () => {
      layer.replaceChildren();
      const positions = new Map();
      graph.nodes.forEach((node, i) => {
        let x,y;
        if (layout === 'groups') { x = 70+(i%10)*95; y = 50+Math.floor(i/10)*Math.min(95,600/Math.ceil(graph.nodes.length/10)); }
        else { const angle=i*2.399963229728653; const radius=310*Math.sqrt((i+1)/Math.max(1,graph.nodes.length)); x=500+Math.cos(angle)*radius*1.4; y=350+Math.sin(angle)*radius; }
        positions.set(node.id,{x,y});
      });
      graph.edges.forEach(edge => { const a=positions.get(edge.source), b=positions.get(edge.target); const line=svgEl('line',{x1:a.x,y1:a.y,x2:b.x,y2:b.y,'stroke-width':Math.min(5,1+Math.log2(edge.weight)),'data-network-edge':'','data-source':edge.source,'data-target':edge.target}); layer.append(line); });
      graph.nodes.forEach((node,i) => { const p=positions.get(node.id); const group=svgEl('g',{transform:`translate(${p.x} ${p.y})`,tabindex:0,role:'button','aria-label':`${node.name}, ${node.papers.length} indexed works`, 'data-network-node':node.id}); const title=svgEl('title',{}); title.textContent=node.name; group.append(title,svgEl('circle',{r:Math.min(15,5+Math.sqrt(node.papers.length))})); if(i<25){const text=svgEl('text',{x:12,y:4});text.textContent=node.name.length>24?node.name.slice(0,22)+'…':node.name;group.append(text);} group.addEventListener('click',()=>select(node.id));group.addEventListener('keydown',e=>{if(['Enter',' '].includes(e.key)){e.preventDefault();select(node.id);}});layer.append(group); });
      transform(); if(selected)select(selected);
    };
    render();
    $$('[data-research-node]').forEach(button=>button.addEventListener('click',()=>{select(button.dataset.researchNode);host.scrollIntoView({behavior:'smooth',block:'center'});}));
    $$('[data-network-zoom]').forEach(button=>button.addEventListener('click',()=>{scale=Math.max(.35,Math.min(4,scale*Number(button.dataset.networkZoom)));transform();}));
    $('[data-network-reset]')?.addEventListener('click',()=>{scale=1;panX=panY=0;transform();});
    $('[data-network-layout]')?.addEventListener('change',e=>{layout=e.target.value;render();});
    $('[data-network-fullscreen]')?.addEventListener('click',()=>{if(document.fullscreenElement)document.exitFullscreen?.();else host.requestFullscreen?.().catch(()=>toast('Fullscreen is unavailable in this browser.'));});
    let drag=null;
    svg.addEventListener('pointerdown',e=>{if(e.target.closest('[data-network-node]'))return;drag={x:e.clientX,y:e.clientY,px:panX,py:panY};svg.setPointerCapture(e.pointerId);});
    svg.addEventListener('pointermove',e=>{if(!drag)return;const factor=1000/svg.getBoundingClientRect().width;panX=drag.px+(e.clientX-drag.x)*factor;panY=drag.py+(e.clientY-drag.y)*factor;transform();});
    ['pointerup','pointercancel'].forEach(type=>svg.addEventListener(type,()=>{drag=null;}));
    $('[data-network-csv]')?.addEventListener('click',()=>download(csv(graph.edges.map(e=>({researcher_a:nodeById.get(e.source).name,researcher_b:nodeById.get(e.target).name,shared_works:e.weight,record_ids:e.papers.join(';')})),['researcher_a','researcher_b','shared_works','record_ids']),'psilocybin-coauthorship.csv','text/csv;charset=utf-8'));
  }
  const trials=readData('research-trials-data');
  $('[data-trials-csv]')?.addEventListener('click',()=>download(csv(trials.map(t=>({nct_id:t.nct_id,title:t.title,sponsor:t.sponsor,phase:t.phases.join(';'),status:t.status,enrollment:t.enrollment.count,enrollment_type:t.enrollment.type,start:t.start_date,primary_completion:t.primary_completion_date,completion:t.completion_date,countries:t.countries.join(';'),registry_url:t.registry_url})),['nct_id','title','sponsor','phase','status','enrollment','enrollment_type','start','primary_completion','completion','countries','registry_url']),'psilocybin-trials.csv','text/csv;charset=utf-8'));
  const studies=readData('research-results-data');
  $('[data-filter-results]')?.addEventListener('input',e=>{const q=e.target.value.toLocaleLowerCase();const rows=$$('[data-result-index]');rows.forEach(row=>{row.hidden=!row.textContent.toLocaleLowerCase().includes(q);});$('[data-result-count]').textContent=`${rows.filter(r=>!r.hidden).length} reports shown`;});
  $('[data-results-csv]')?.addEventListener('click',()=>{const visible=$$('[data-result-index]').filter(r=>!r.hidden).map(r=>studies[Number(r.dataset.resultIndex)]);download(csv(visible,['label','doi','topic','population','design','sample','endpoint','result','limitation','outcome','source_checked']),'psilocybin-selected-findings.csv','text/csv;charset=utf-8');});
  if ($('[data-workspace-records]')) {
    let generation = 0;
    let available = { papers: [], trials: [] };
    const selected = new Set();
    const status = $('[data-workspace-status]');
    const advancedStatus = $('[data-workspace-advanced-status]');
    const comparisonHost = $('[data-workspace-comparison]');
    const compareButtons = $$('[data-workspace-compare]');
    const clearButtons = $$('[data-workspace-selection-clear]');
    const syncSelection = () => {
      const count = selected.size;
      $('[data-workspace-selection]').textContent = `${count} selected${count === 1 ? ' · Select one more to compare' : ''}`;
      compareButtons.forEach(button => { button.disabled = count < 2; });
      $('[data-workspace-selection-bar]').hidden = count === 0;
      $('[data-workspace-selection-count]').textContent = `${count} selected`;
      clearButtons.forEach(button => { button.disabled = count === 0; });
      $$('[data-workspace-pick]').forEach(box => { box.checked = selected.has(Number(box.value)); box.disabled = count >= 6 && !box.checked; });
      comparisonHost.replaceChildren();
    };
    clearButtons.forEach(button => button.addEventListener('click', () => { selected.clear(); comparisonHost.focus({ preventScroll: true }); syncSelection(); }));
    compareButtons.forEach(button => button.addEventListener('click', () => {
      const papers = available.papers.filter(paper => selected.has(paper.id));
      if (papers.length < 2) return;
      const trialById = new Map(available.trials.map(trial => [trial.id, trial]));
      const wrap = el('div', undefined, 'research-table-wrap');
      wrap.tabIndex = 0; wrap.setAttribute('role', 'region'); wrap.setAttribute('aria-label', 'Selected record comparison; scroll horizontally for more columns');
      const table = el('table', undefined, 'research-table explorer-table reading-list-comparison');
      table.append(el('caption', `${papers.length} selected records · Source metadata and registry facts`));
      const thead = el('thead'), heading = el('tr'), field = el('th', 'Field'); field.scope = 'col'; heading.append(field);
      papers.forEach(paper => { const th = el('th'); th.scope = 'col'; th.append(link(paper.title, 'publication.php?id=' + paper.id)); heading.append(th); });
      thead.append(heading); table.append(thead);
      const tbody = el('tbody');
      const fields = [
        ['Authors', paper => paper.authors], ['Publication date', paper => paper.publication_date],
        ['Journal / venue', paper => paper.journal], ['Source', paper => paper.source_name],
        ['Record type', paper => paper.publication_status === 'preprint' ? 'Preprint — not peer reviewed' : paper.publication_status],
        ['DOI', paper => paper.doi ? link(paper.doi, 'https://doi.org/' + paper.doi) : null],
      ];
      if (papers.some(paper => trialById.has(paper.id))) {
        const fact = getter => paper => { const trial = trialById.get(paper.id); return trial ? getter(trial) : 'Not a trial registry record'; };
        fields.push(['Trial registry', fact(t => link(t.nct_id, t.registry_url))],
          ['Trial design', fact(t => Object.values(t.design).filter(Boolean).join(' · '))],
          ['Phase / recruitment', fact(t => t.phases.join(', ') + ' / ' + t.status_label)],
          ['Enrollment', fact(t => (t.enrollment.count ?? 'Not reported') + ' ' + t.enrollment.type)],
          ['Primary completion', fact(t => t.primary_completion_date)],
          ['Conditions', fact(t => t.conditions.join('; '))]);
      }
      fields.forEach(([name, value]) => {
        const row = el('tr'), th = el('th', name); th.scope = 'row'; row.append(th);
        papers.forEach(paper => { const td = el('td'), content = value(paper); td.append(content || 'Not reported'); row.append(td); });
        tbody.append(row);
      });
      table.append(tbody); wrap.append(table); comparisonHost.replaceChildren(wrap);
      comparisonHost.focus({ preventScroll: true });
      comparisonHost.scrollIntoView({ block: 'nearest', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' });
    }));
    const refresh = async () => {
      const current = ++generation, data = load();
      const records = $('[data-workspace-records]'), exports = $('[data-workspace-exports]'), searches = $('[data-workspace-searches]');
      available = { papers: [], trials: [] };
      compareButtons.forEach(button => { button.disabled = true; });
      comparisonHost.replaceChildren();
      searches.replaceChildren();
      data.searches.forEach((search, index) => {
        const row = el('div', undefined, 'reading-list-saved-search');
        const remove = removeButton('Remove saved search: ' + search.title);
        remove.addEventListener('click', () => { const next = load(); next.searches.splice(index, 1); save(next); });
        const text = el('div'); text.append(link(search.title, search.url), el('small', search.url)); row.append(text, remove); searches.append(row);
      });
      if (!data.searches.length) searches.append(el('p', 'No saved searches yet. Use Save this view in the trial and update tools.'));
      records.replaceChildren(); exports.replaceChildren();
      if (!data.ids.length) { selected.clear(); syncSelection(); status.textContent = 'No saved records yet. Find a publication above to start your reading list.'; return; }
      status.textContent = 'Loading saved records…';
      let requestFailed = false;
      try {
        const query = new URLSearchParams({ resource: 'research_comparison' }); data.ids.forEach(id => query.append('ids[]', id));
        const response = await fetch('api.php?' + query); if (!response.ok) throw new Error();
        const result = await response.json(); if (!Array.isArray(result.papers) || !Array.isArray(result.trials)) throw new Error();
        if (current !== generation) return;
        available = result;
      } catch { requestFailed = true; }
      if (current !== generation) return;
      const byId = new Map(available.papers.map(paper => [paper.id, paper]));
      for (const id of selected) if (!byId.has(id)) selected.delete(id);
      const table = el('table', undefined, 'research-table reading-list-table'); table.setAttribute('role', 'table');
      table.append(el('caption', 'Saved records'));
      const thead = el('thead'), head = el('tr');
      ['Publication','Date and source','Identifier','Remove'].forEach(name => { const th = el('th', name); th.scope = 'col'; head.append(th); });
      thead.append(head); table.append(thead);
      const tbody = el('tbody'), visible = [];
      data.ids.forEach(id => {
        const paper = byId.get(id), row = el('tr'); row.setAttribute('role', 'row');
        const title = el('th', undefined, 'reading-list-publication'); title.scope = 'row'; title.setAttribute('role','rowheader');
        title.append(paper ? link(paper.title, 'publication.php?id=' + id) : el('span', requestFailed ? 'Unable to load record ' + id : 'Record ' + id + ' is no longer public'));
        if (paper) {
          title.append(el('p', paper.authors || 'Authors unavailable', 'reading-list-authors'));
          title.append(link('View in citation network', 'citation-network.php?paper=' + id));
          if (paper.journal) title.append(el('p', paper.journal, 'reading-list-journal'));
          const pick = el('label', undefined, 'reading-list-pick'), box = el('input'); box.type = 'checkbox'; box.value = String(id); box.setAttribute('data-workspace-pick', ''); box.setAttribute('aria-label', 'Select for comparison: ' + paper.title);
          box.addEventListener('change', () => { if (box.checked && selected.size < 6) selected.add(id); else selected.delete(id); syncSelection(); });
          pick.append(box, el('span', 'Compare')); title.append(pick);
        }
        const metadata = el('td', undefined, 'reading-list-metadata'); metadata.setAttribute('role','cell');
        metadata.append(el('span', paper?.publication_date || 'Date unavailable'));
        if (paper) metadata.append(el('span', paper.source_name || 'Source unavailable'), el('span', paper.publication_status === 'preprint' ? 'Preprint — not peer reviewed' : paper.publication_status));
        const identifier = el('td', undefined, 'reading-list-identifier'); identifier.setAttribute('role','cell');
        if (paper?.doi) { const doi = link(paper.doi, 'https://doi.org/' + paper.doi); doi.setAttribute('aria-label', 'DOI ' + paper.doi); identifier.append(el('span','DOI','reading-list-field-label'), doi); } else identifier.append(el('span','DOI unavailable'));
        const actions = el('td', undefined, 'reading-list-actions'); actions.setAttribute('role','cell');
        const remove = removeButton('Remove from reading list: ' + (paper?.title || 'Record ' + id));
        remove.addEventListener('click', () => { const next = load(); next.ids = next.ids.filter(value => value !== id); save(next); });
        actions.append(remove); row.append(title,metadata,identifier,actions); tbody.append(row); if (paper) visible.push(id);
      });
      table.append(tbody); records.append(table);
      ['bibtex','ris','csv','json','latex'].forEach(format => { if (visible.length) { const query = new URLSearchParams({format}); visible.forEach(id => query.append('ids[]', id)); exports.append(link('Export ' + (format === 'bibtex' ? 'BibTeX' : format === 'latex' ? 'LaTeX' : format.toUpperCase()), 'export.php?' + query)); } });
      syncSelection();
      status.textContent = requestFailed ? 'Saved records could not be loaded. Your reading list is still saved; reopen this page to retry.' : `${visible.length} saved records${data.ids.length > visible.length ? ` · ${data.ids.length - visible.length} unavailable and excluded from comparison and exports` : ''}.`;
    };
    $('[data-workspace-add]').addEventListener('submit', event => {
      event.preventDefault(); const data = load();
      const ids = String(new FormData(event.target).get('ids')).split(/[\s,;]+/).map(Number).filter(id => Number.isSafeInteger(id) && id > 0);
      if (!ids.length) { advancedStatus.textContent = 'Enter valid positive record IDs.'; return; }
      if (new Set([...data.ids,...ids]).size > 50) { advancedStatus.textContent = 'Keep the reading list to 50 records; export or remove some first.'; return; }
      data.ids.push(...ids); if (save(data)) { advancedStatus.textContent = 'Records added to your reading list.'; event.target.reset(); }
    });
    $('[data-workspace-backup]').addEventListener('click', () => download(JSON.stringify(load(),null,2), 'psilocybin-workspace.json'));
    $('[data-workspace-import]').addEventListener('change', async event => {
      const file = event.target.files[0]; if (!file) return;
      if (file.size > 100000) { advancedStatus.textContent = 'Backup exceeds the 100 KB limit.'; event.target.value = ''; return; }
      try {
        const data = JSON.parse(await file.text()); if (data.version !== 1 || !Array.isArray(data.ids) || !Array.isArray(data.searches)) throw new Error();
        if (load().ids.length || load().searches.length) { if (!confirm('Replace this device’s reading list and saved searches with the backup?')) return; }
        if (save(data)) advancedStatus.textContent = 'Reading list and saved searches restored.';
      } catch { advancedStatus.textContent = 'Unable to restore this reading list backup.'; }
      finally { event.target.value = ''; }
    });
    $('[data-workspace-clear]').addEventListener('click', () => { if (confirm('Clear saved records and searches from this device?')) { if (save({})) advancedStatus.textContent = 'Reading list and saved searches cleared.'; } });
    window.addEventListener('storage', refresh); window.addEventListener('research-workspace-change', refresh); refresh();
  }
  const economics=$('[data-economics]');
  if(economics){let scenario=null;economics.addEventListener('submit',e=>{e.preventDefault();const formData=new FormData(economics);const authorizationDate=String(formData.get('authorizationDate')||'');const inputs=Object.fromEntries([...formData].filter(([k])=>k!=='authorizationDate').map(([k,v])=>[k,Number(v)]));if(Object.values(inputs).some(v=>!Number.isFinite(v))||inputs.patients<1)return;let accessDate=null;if(authorizationDate){const [year,month,day]=authorizationDate.split('-').map(Number);const target=new Date(Date.UTC(year,month-1+inputs.delay,1));const last=new Date(Date.UTC(target.getUTCFullYear(),target.getUTCMonth()+1,0)).getUTCDate();target.setUTCDate(Math.min(day,last));accessDate=target.toISOString().slice(0,10);}const delta=inputs.cost-inputs.comparator;const nmb=inputs.threshold*inputs.qaly-delta;scenario={schema_version:1,method:'One-period user-assumption scenario; no observed treatment effect, discounting or approval prediction.',inputs:{...inputs,hypothetical_authorization_date:authorizationDate||null},outputs:{hypothetical_access_date:accessDate,incremental_cost:delta,incremental_qalys:inputs.qaly,icer:inputs.qaly>0?delta/inputs.qaly:null,net_monetary_benefit:nmb,incremental_cohort_budget:delta*inputs.patients,assumed_access_delay_months:inputs.delay},formulas:{delta_cost:'cost - comparator',icer:'delta_cost / qaly when qaly > 0',nmb:'threshold * qaly - delta_cost',budget:'patients * delta_cost'},sensitivity:[]};const output=$('[data-economics-result]');output.replaceChildren(el('h3','Your illustrative scenario'));const euro=n=>new Intl.NumberFormat('en',{style:'currency',currency:'EUR',maximumFractionDigits:2}).format(n);const list=el('dl',undefined,'detail-list');[['Incremental cost per patient',euro(delta)],['ICER',inputs.qaly>0?euro(delta/inputs.qaly)+' per assumed QALY':'Not reported: incremental QALYs are zero or negative'],['Net monetary benefit per patient',euro(nmb)],['Incremental cohort budget',euro(delta*inputs.patients)],['Assumed access delay',inputs.delay+' months; no authorisation date is forecast'],['Hypothetical access date',accessDate||'No hypothetical authorisation date supplied']].forEach(([name,value])=>{const row=el('div');row.append(el('dt',name),el('dd',value));list.append(row);});output.append(list,el('h3','One-at-a-time sensitivity'));const table=el('table',undefined,'research-table');const head=el('tr');['Assumption change','Net monetary benefit per patient'].forEach(v=>head.append(el('th',v)));table.append(head);[['QALYs × 0.75',.75,1],['QALYs × 1.25',1.25,1],['Intervention cost × 0.75',1,.75],['Intervention cost × 1.25',1,1.25]].forEach(([name,q,c])=>{const value=inputs.threshold*inputs.qaly*q-(inputs.cost*c-inputs.comparator);scenario.sensitivity.push({scenario:name,net_monetary_benefit:value});const row=el('tr');row.append(el('th',name),el('td',euro(value)));table.append(row);});output.append(table,el('p','These scenario values depend entirely on your assumptions. A positive value does not establish clinical effectiveness, reimbursement or affordability.'));});$('[data-economics-export]').addEventListener('click',()=>{if(!scenario){toast('Calculate a scenario before exporting.');return;}download(JSON.stringify(scenario,null,2),'psilocybin-economic-scenario.json');});}
})();
