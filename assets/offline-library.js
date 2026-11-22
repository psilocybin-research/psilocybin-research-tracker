import {preparePack,removePacks,verifyPack} from './offline-pack.mjs';
import {tags, selectRecords, changesBetween, exportRecords, searchUrl, readSearch} from './offline-tools.mjs';

(() => {
  'use strict';
  if (window.TrackerOffline) return;
  const releaseParams=new URL(document.currentScript?.src||document.querySelector('script[data-offline-library-loader]')?.src||location.href).searchParams;
  const VERSION=(releaseParams.get('v')||'20261110-install-visibility-v2')+(releaseParams.get('r')?'-'+releaseParams.get('r'):'');
  const workspaceKey='psilocybinResearchWorkspaceV1';
  const url=path=>new URL(path,location.origin+'/').href;
  const el=(tag,text)=>{const n=document.createElement(tag);if(text!==undefined)n.textContent=String(text);return n;};
  let lastIntegrityCheck=0;
  let database,panel,dialog,current,savedState={},busy=false,active,operation=0,page=1,selectedId=null,renderTimer;
  const $=selector=>panel.querySelector(selector);
  const message=text=>{$('[data-status]').textContent=text;};
  const openDB=()=>database||(database=new Promise((resolve,reject)=>{
    const req=indexedDB.open('publication-tracker-offline',1);
    req.onupgradeneeded=()=>req.result.createObjectStore('library');
    req.onsuccess=()=>{req.result.onversionchange=()=>{req.result.close();database=null;};resolve(req.result);};
    req.onerror=req.onblocked=()=>{database=null;reject(new Error('Device storage unavailable. Close other database tabs and try again.'));};
  }));
  async function state(){
    const db=await openDB();return new Promise((resolve,reject)=>{
      const req=db.transaction('library').objectStore('library').get('active');
      req.onsuccess=()=>resolve(req.result||{enabled:false,snapshot:null});req.onerror=()=>reject(new Error('Cannot read device storage.'));
    });
  }
  async function change(update){
    const db=await openDB();return new Promise((resolve,reject)=>{
      const tx=db.transaction('library','readwrite'),store=tx.objectStore('library'),req=store.get('active');let next;
      req.onsuccess=()=>{try{next=update(req.result||{enabled:false,snapshot:null});store.put(next,'active');}catch(_){tx.abort();}};
      tx.oncomplete=()=>resolve(next);tx.onabort=tx.onerror=()=>reject(new Error('Storage is full or unavailable. Your previous complete copy has been kept.'));
    });
  }
  function workspace(){
    try{
      const data=JSON.parse(localStorage.getItem(workspaceKey)||'{}');
      return {version:1,ids:[...new Set((Array.isArray(data.ids)?data.ids:[]).map(Number).filter(id=>Number.isSafeInteger(id)&&id>0))].slice(0,50),searches:(Array.isArray(data.searches)?data.searches:[]).filter(s=>s&&typeof s.title==='string'&&typeof s.url==='string').slice(0,30)};
    }catch(_){return {version:1,ids:[],searches:[]};}
  }
  function saveWorkspace(data){
    try{localStorage.setItem(workspaceKey,JSON.stringify(data));window.dispatchEvent(new CustomEvent('research-workspace-change'));return true;}
    catch(_){message('Unable to save your reading list or searches. Device storage may be disabled or full.');return false;}
  }
  const mb=bytes=>(Number(bytes||0)/1048576).toFixed(1)+' MB';
  function busyControls(){
    $('[data-download]').disabled=busy||navigator.onLine===false;
    $('[data-download]').textContent=busy?(current?'Updating offline copy…':'Downloading offline app…'):current?(savedState.pack?'Check for updates':'Complete offline download'):'Download offline app';
    $('[data-cancel]').hidden=!busy;
    $('[data-cancel]').disabled=!active;
    $('[data-progress-wrap]').hidden=!busy;
  }
  async function network(path,download=false){
    const controller=new AbortController();active=controller;busyControls();
    const timer=setTimeout(()=>controller.abort(),90000);
    try{
      const response=await fetch(url(path),{cache:'no-store',signal:controller.signal,credentials:'same-origin'});
      if(!response.ok)throw new Error('Download unavailable. Try again when connected.');
      let result;
      if(download&&response.body?.getReader){
        const reader=response.body.getReader(),decoder=new TextDecoder(),chunks=[];
        const length=Number(response.headers.get('Content-Length'));
        const exact=length>0&&!response.headers.get('Content-Encoding');
        let bytes=0,lastPaint=0;
        while(true){
          const part=await reader.read();if(part.done)break;
          bytes+=part.value.byteLength;chunks.push(decoder.decode(part.value,{stream:true}));
          if(Date.now()-lastPaint>100){
            $('[data-progress-label]').textContent=mb(bytes)+' received'+(exact?' of '+mb(length):'');
            if(exact){$('[data-progress]').max=length;$('[data-progress]').value=bytes;}
            lastPaint=Date.now();
          }
        }
        chunks.push(decoder.decode());result=JSON.parse(chunks.join(''));
        $('[data-progress-label]').textContent=mb(bytes)+' received · verifying catalogue…';
      }else result=await response.json();
      if(result.format!=='public-offline-library-v1'||!/^[a-f0-9]{64}$/.test(result.revision)||!Number.isSafeInteger(result.record_count)||result.record_count<0)throw new Error('Invalid catalogue response; previous copy kept.');
      return result;
    }finally{clearTimeout(timer);if(active===controller)active=null;busyControls();}
  }
  async function saveAppPack(snapshot){
    const controller=new AbortController();active=controller;busyControls();
    try{return await preparePack(snapshot,VERSION,controller.signal,progress=>{
      message('Saving the app, maps and research tools for offline use…');
      $('[data-progress-label]').textContent=progress.done+' of '+progress.total+' resources · '+mb(progress.bytes)+' received';
      $('[data-progress]').max=Math.max(1,progress.total);$('[data-progress]').value=progress.done;
    });}finally{if(active===controller)active=null;busyControls();}
  }
  const invalidate=()=>navigator.serviceWorker?.controller?.postMessage({type:'OFFLINE_STATE_CHANGED'});
  async function refresh(explicit=false,locked=false){
    if(!locked&&navigator.locks?.request){if(busy)return;return navigator.locks.request('tracker-offline-refresh',()=>refresh(explicit,true));}
    if(busy)return;busy=true;const token=++operation;let preparedPack=null;busyControls();
    $('[data-progress]').removeAttribute('value');$('[data-progress-label]').textContent='Checking catalogue…';
    try{
      let saved=await state();
      if(token!==operation)return;
      if(explicit&&!saved.enabled)saved=await change(old=>token===operation?{...old,enabled:true}:old);
      if(token!==operation)return;
      if(!saved.enabled)return;
      if(navigator.onLine===false){message('Offline: your saved catalogue remains available.');return;}
      await change(old=>token===operation?{...old,lastAttempt:new Date().toISOString()}:old);
      if(token!==operation)return;
      message('Checking for updates…');const manifest=await network('offline-data.php?manifest=1');
      if(token!==operation)return;
      if(saved.snapshot?.revision===manifest.revision){
        let pack=saved.pack;
        if(!pack||pack.version!==VERSION||!await verifyPack(pack))preparedPack=pack=await saveAppPack(saved.snapshot);
        if(token!==operation)return;
        savedState=await change(old=>old.enabled?{...old,pack,lastChecked:new Date().toISOString()}:old);
        invalidate();await removePacks(pack.name);
        message('Your offline catalogue is up to date.');renderStatus();return;
      }
      message('Downloading '+manifest.record_count.toLocaleString()+' records (about '+mb(manifest.approximate_bytes)+')…');
      const snapshot=await network('offline-data.php',true),records=snapshot.records;
      if(!Array.isArray(records)||records.length!==snapshot.record_count||new Set(records.map(r=>r?.id)).size!==records.length||records.some(r=>!r||!Number.isSafeInteger(r.id)||r.id<1||typeof r.title!=='string')||!Number.isFinite(Date.parse(snapshot.generated_at_utc)))throw new Error('Incomplete catalogue response; previous copy kept.');
      if(token!==operation)return;
      const pack=preparedPack=await saveAppPack(snapshot);
      if(token!==operation){await caches.delete(pack.name);return;}
      const verified=await network('offline-data.php?manifest=1');
      if(verified.revision!==snapshot.revision){await caches.delete(pack.name);throw new Error('The catalogue changed during download. Previous complete copy kept; try updating again.');}
      message('Saving complete catalogue…');
      savedState=await change(old=>{
        if(!old.enabled||(old.snapshot&&old.snapshot.generated_at_utc>snapshot.generated_at_utc))return old;
        return {...old,snapshot,pack,lastChecked:new Date().toISOString(),updateSummary:{...changesBetween(old.snapshot?.records,records),at:new Date().toISOString()}};
      });
      invalidate();await removePacks(savedState.pack?.name);current=savedState.snapshot;populate();render();message('Catalogue and app saved and ready offline.');
      window.TrackerPwaMetrics?.record?.('offline_catalogue_ready','publicationTrackerPwa-offline-catalogue-ready');
      // Firefox may await a browser permission prompt. Never block a completed
      // catalogue update or repeat that prompt on every manual refresh.
      if(explicit&&!savedState.persistenceRequested&&navigator.storage?.persist){
        savedState=await change(old=>({...old,persistenceRequested:true}));
        navigator.storage.persist().then(storageStatus).catch(()=>{});
      }
    }catch(error){if(token===operation)message(error.name==='AbortError'?'Update interrupted; previous complete copy kept.':error.message);}
    finally{if(preparedPack){try{if((await state()).pack?.name!==preparedPack.name)await caches.delete(preparedPack.name);}catch(_){}}busy=false;busyControls();window.dispatchEvent(new Event("tracker:offline-idle"));}
  }
  async function cancel(){
    if(!active)return;
    ++operation;active.abort();message('Download cancelled. Your previous complete copy has been kept.');
  }
  async function check(force=false){
    if(busy||navigator.onLine===false||document.visibilityState==='hidden')return;
    try{
      const saved=await state();
      const inspect=force||Date.now()-lastIntegrityCheck>900000;if(inspect)lastIntegrityCheck=Date.now();
      const damaged=saved.enabled&&inspect&&!await verifyPack(saved.pack);
      if(saved.enabled&&(damaged||force||Date.now()-Date.parse(saved.lastAttempt||saved.lastChecked||'1970-01-01')>900000||!saved.pack||saved.pack.version!==VERSION||!await caches.has(saved.pack.name))){
        if(navigator.locks?.request)await navigator.locks.request('tracker-offline-refresh',{ifAvailable:true},lock=>lock?refresh(false,true):undefined);else await refresh();
      }
    }catch(_){}
  }
  function filters(){
    return {q:$('[data-query]').value,source:$('[data-source]').value,status:$('[data-filter-status]').value,substance:$('[data-substance]').value,topic:$('[data-topic]').value,from:$('[data-year-from]').value,to:$('[data-year-to]').value,sort:$('[data-sort]').value,reading:$('[data-reading]').checked};
  }
  function validYears(){
    const f=filters();return $('[data-year-from]').validity.valid&&$('[data-year-to]').validity.valid&&(!f.from||!f.to||Number(f.from)<=Number(f.to));
  }
  const matching=()=>validYears()?selectRecords(current?.records||[],filters(),workspace().ids):[];
  function populate(){
    for(const [selector,key,multiple] of [['[data-source]','source_name',false],['[data-filter-status]','publication_status',false],['[data-substance]','substance_tags',true],['[data-topic]','topic_tags',true]]){
      const select=$(selector),value=select.value;while(select.options.length>1)select.remove(1);
      const values=[...new Set((current?.records||[]).flatMap(r=>multiple?tags(r[key]):[r[key]]).filter(Boolean))].sort((a,b)=>a.localeCompare(b));
      for(const text of values){const option=el('option',text);option.value=text;select.append(option);}
      if(value&&!values.includes(value)){const option=el('option',value+' (not in this snapshot)');option.value=value;select.append(option);}
      select.value=value;
    }
  }
  async function storageStatus(){
    try{
      const estimate=navigator.storage?.estimate?await navigator.storage.estimate():null;
      const persistent=navigator.storage?.persisted?await navigator.storage.persisted():null;
      $('[data-storage]').textContent=(current?'Catalogue metadata: about '+mb(current.approximate_bytes)+'. '+(savedState.pack?'App, maps and tools: '+mb(savedState.pack.bytes)+'. ':''):'')+(estimate?'All database storage: '+mb(estimate.usage)+(estimate.quota?' of '+mb(estimate.quota)+' allowed. ':''):'')+(persistent===true?'Persistent storage granted.':persistent===false?'Browser-managed storage; it may be cleared when space runs low.':'Storage retention is controlled by your browser.');
    }catch(_){$('[data-storage]').textContent='Storage size is unavailable. Your browser controls storage retention.';}
  }
  function renderStatus(){
    const complete=!!(current&&savedState.pack);
    $('[data-ready]').textContent=complete?'Ready offline':current?'Catalogue saved':'Not downloaded';
    $('[data-browser]').hidden=!current;
    $('[data-use-app]').hidden=!complete;
    $('[data-remove]').hidden=!current&&!savedState.enabled;
    $('[data-sync]').hidden=!savedState.lastChecked;
    busyControls();
    $('[data-ready]').dataset.ready=complete?'true':'false';
    $('[data-snapshot]').textContent=current?current.record_count.toLocaleString()+' publications · catalogue dated '+new Date(current.generated_at_utc).toLocaleString():'Save a copy on this device to research without a connection.';
    $('[data-sync]').textContent=savedState.lastChecked?'Last successful sync check: '+new Date(savedState.lastChecked).toLocaleString():'No successful sync check yet.';
    const summary=savedState.updateSummary;
    $('[data-update-summary]').hidden=!summary;
    $('[data-update-summary]').textContent=summary?(summary.initial?'First download: '+summary.added.toLocaleString()+' records saved.':'Last catalogue update: '+summary.added.toLocaleString()+' added · '+summary.corrected.toLocaleString()+' corrected · '+summary.removed.toLocaleString()+' removed.'):'Updates will show counts of added, corrected and removed records.';
    storageStatus();
  }
  function externalLink(text,value){
    try{const u=new URL(value);if(!['https:','http:'].includes(u.protocol))return el('span',text);const link=el('a',text+' (connection required)');link.href=u.href;link.target='_blank';link.rel='noopener noreferrer';return link;}catch(_){return el('span',text);}
  }
  function toggleSaved(id){
    const data=workspace(),exists=data.ids.includes(id);
    if(!exists&&data.ids.length>=50){message('Your reading list contains 50 records. Remove some before adding more.');return;}
    data.ids=exists?data.ids.filter(n=>n!==id):[...data.ids,id];
    if(saveWorkspace(data))message(exists?'Removed from your reading list.':'Saved to your reading list on this device.');
  }
  function saveButton(record){
    const saved=workspace().ids.includes(record.id),button=el('button',saved?'Remove from reading list':'Save to reading list');
    button.type='button';button.dataset.offlineSave=record.id;button.setAttribute('aria-pressed',String(saved));button.setAttribute('aria-label',(saved?'Remove from reading list: ':'Save to reading list: ')+record.title);
    button.addEventListener('click',()=>toggleSaved(record.id));return button;
  }
  function renderDetails(focus=false){
    const host=$('[data-record-view]');host.replaceChildren();host.hidden=selectedId===null;
    if(selectedId===null)return;
    const record=current?.records.find(r=>r.id===selectedId),close=el('button','Close record details');close.type='button';close.addEventListener('click',()=>{const id=selectedId;selectedId=null;renderDetails();(panel.querySelector('[data-offline-details="'+id+'"]')||$('[data-query]')).focus();});
    if(!record){host.append(el('p','Record '+selectedId+' is not in this downloaded snapshot. Connect and update the catalogue to check its availability.'),close);return;}
    const heading=el('h3',record.title);heading.tabIndex=-1;host.append(heading,el('p','Downloaded bibliographic metadata · record '+record.id));
    const dl=el('dl');
    for(const [label,key] of [['Authors','authors'],['Journal / venue','journal'],['Publication date','publication_date'],['Publication year','publication_year'],['Source','source_name'],['Publication status','publication_status'],['Study type','study_type'],['DOI','doi'],['PubMed ID','pubmed_id'],['OpenAlex ID','openalex_id'],['Substances','substance_tags'],['Topics','topic_tags'],['Source record identifier','source_record_id']]){
      if(record[key]!==null&&record[key]!==undefined&&record[key]!==''){dl.append(el('dt',label),el('dd',record[key]));}
    }
    host.append(dl,el('p',record.publication_status==='preprint'?'Preprint: not peer reviewed.':'Source classifications and bibliographic metadata are not independently verified clinical findings.'),el('p','Source abstracts and full texts are not stored in the offline catalogue.'));
    const actions=el('div');actions.className='offline-library-actions';actions.append(saveButton(record),close);host.append(actions);
    if(record.doi){const p=el('p');p.append(externalLink('DOI source','https://doi.org/'+record.doi));host.append(p);}
    if(record.source_url){const p=el('p');p.append(externalLink('Original source',record.source_url));host.append(p);}
    if(focus){host.scrollIntoView({block:'nearest'});heading.focus({preventScroll:true});}
  }
  function renderSaved(){
    const data=workspace(),present=new Set((current?.records||[]).map(r=>r.id)),missing=current?data.ids.filter(id=>!present.has(id)):[];
    $('[data-reading-summary]').textContent=data.ids.length+' saved records on this device'+(missing.length?' · '+missing.length+' unavailable in this snapshot':'')+'.';
    const missingHost=$('[data-unavailable]');missingHost.replaceChildren();missingHost.hidden=!missing.length;
    if(missing.length){
      missingHost.append(el('summary','Saved records unavailable in this snapshot ('+missing.length+')'),el('p','These IDs have no metadata in your downloaded copy. Connect and update to check availability; they are excluded from exports.'));
      for(const id of missing){const row=el('p','Record '+id+' '),button=el('button','Remove saved ID '+id);button.type='button';button.addEventListener('click',()=>toggleSaved(id));row.append(button);missingHost.append(row);}
    }
    const host=$('[data-saved-searches]');host.replaceChildren();
    data.searches.forEach(s=>{
      const f=readSearch(s.url,location.origin);if(!f)return;
      const row=el('div'),apply=el('button',s.title),remove=el('button','Remove');row.className='offline-library-actions';apply.type=remove.type='button';remove.setAttribute('aria-label','Remove offline search: '+s.title);
      apply.addEventListener('click',()=>applyFilters(f));remove.addEventListener('click',()=>{const next=workspace();next.searches=next.searches.filter(v=>v.url!==s.url);saveWorkspace(next);});row.append(apply,remove);host.append(row);
    });
    if(!host.children.length)host.append(el('p','No offline searches saved yet.'));
  }
  function render(){
    renderStatus();renderSaved();renderDetails();
    const rows=matching(),pages=Math.max(1,Math.ceil(rows.length/25));page=Math.max(1,Math.min(page,pages));
    $('[data-count]').textContent=validYears()?rows.length.toLocaleString()+' matching records · page '+page+' of '+pages:'Enter valid years from 1 to 9999, with From no later than To. Undated records are excluded when a year range is used.';
    const list=$('[data-results]');list.replaceChildren();
    for(const record of rows.slice((page-1)*25,page*25)){
      const item=el('li');item.append(el('h3',record.title),el('p',[record.authors,record.journal,record.publication_date].filter(Boolean).join(' · ')),el('p',[record.source_name,record.publication_status==='preprint'?'PREPRINT (not peer reviewed)':record.publication_status,record.study_type].filter(Boolean).join(' · ')));
      if(record.doi)item.append(el('p','DOI: '+record.doi));
      const actions=el('div'),details=el('button','View record details');actions.className='offline-library-actions';details.type='button';details.dataset.offlineDetails=record.id;details.setAttribute('aria-controls','offline-record-view');details.setAttribute('aria-label','View downloaded details: '+record.title);details.addEventListener('click',()=>{selectedId=record.id;renderDetails(true);});
      actions.append(details,saveButton(record));item.append(actions);list.append(item);
    }
    $('[data-empty]').hidden=rows.length>0;
    $('[data-empty]').textContent=current?'No records match these filters. Try clearing filters or showing the complete catalogue.':'Connect and choose Download to keep the catalogue on this device.';
    $('[data-pagination]').hidden=pages<=1;
    $('[data-results]').hidden=!rows.length;
    $('[data-prev]').disabled=page<=1;$('[data-next]').disabled=page>=pages;$('[data-export]').disabled=!current||!rows.length||!validYears();
  }
  function applyFilters(f){
    if(current)$('[data-browser]').open=true;
    for(const [key,selector] of [['q','[data-query]'],['source','[data-source]'],['status','[data-filter-status]'],['substance','[data-substance]'],['topic','[data-topic]'],['from','[data-year-from]'],['to','[data-year-to]'],['sort','[data-sort]']]){
      const control=$(selector),value=f[key]|| (key==='sort'?'newest':'');
      if(control.tagName==='SELECT'&&value&&![...control.options].some(o=>o.value===value)){const option=el('option',value+' (not in this snapshot)');option.value=value;control.append(option);}
      control.value=value;
    }
    $('[data-reading]').checked=!!f.reading;page=1;render();
  }
  async function load(){try{savedState=await state();current=savedState.snapshot;populate();render();}catch(error){message(error.message);}}
  async function open(){if(dialog&&!dialog.open)dialog.showModal();await load();check();}
  async function offer(){
    try{if(!dialog||localStorage.getItem('publicationTrackerOfflineOffered')==='1')return;localStorage.setItem('publicationTrackerOfflineOffered','1');await open();}catch(_){}
  }
  function setup(){
    const css=el('link');css.rel='stylesheet';css.href=url('assets/offline-library.css?v='+VERSION);document.head.append(css);
    panel=document.querySelector('[data-offline-library]');
    if(!panel){dialog=el('dialog');dialog.className='offline-library-dialog';dialog.setAttribute('aria-labelledby','offline-library-title');panel=el('section');dialog.append(panel);document.body.append(dialog);dialog.addEventListener('click',e=>{if(e.target===dialog)dialog.close();});}
    panel.className='offline-library';
    panel.innerHTML=`<div class="offline-library-heading"><h2 id="offline-library-title">Offline research library</h2>${dialog?'<button type="button" data-close aria-label="Close offline library">×</button>':''}</div>
      <p class="offline-library-intro">Keep the catalogue, maps and research tools on this device and use the app without a connection.</p>
      <div class="offline-library-download">
        <strong data-ready>Not downloaded</strong><p data-snapshot>Save a copy on this device to research without a connection.</p>
        <p data-sync class="offline-library-note" hidden></p>
        <p class="offline-library-note">Allow about 40 MB of storage; the download size varies. Papers, videos and alerts require the internet. Videos and preview clips are not downloaded.</p>
        <div class="offline-library-actions"><button type="button" data-download>Download offline app</button><button type="button" data-cancel hidden>Cancel download</button></div>
        <p class="offline-library-note"><a href="/r-analysis/offline/" data-offline-r-link>Add offline R analysis</a> · Optional runtime and verified dataset download.</p><p data-status role="status" aria-live="polite"></p>
        <div data-progress-wrap hidden><label data-progress-label for="offline-download-progress">Checking catalogue…</label><progress id="offline-download-progress" data-progress max="100" aria-label="Catalogue download progress"></progress></div>
      </div>
      <p data-use-app hidden>Ready to work offline: use the usual app search, reading list, maps and exports. <a href="/">Open the catalogue</a>.</p>
      <details class="offline-library-storage"><summary>Storage and automatic updates</summary><p data-storage></p><p data-update-summary hidden></p><p class="offline-library-note">Once downloaded, your copy checks for updates when you open the app, reconnect or return to it. Keep the app open to finish an update. Your browser may clear the copy if storage runs low.</p><p class="offline-library-note">Removing the offline copy turns off automatic updates and keeps your reading list and saved searches.</p><button type="button" data-remove hidden>Remove offline copy</button></details>
      <details data-browser hidden class="offline-library-browser" ${dialog?'':'open'}><summary>Search and export saved metadata</summary><p class="offline-library-note">Search the catalogue saved on this device. Abstracts and full papers are not included.</p>
      <div class="offline-library-filters"><label>Search metadata<input type="search" data-query placeholder="Title, author, DOI or topic" autocomplete="off"></label><label>Sort by<select data-sort><option value="newest">Newest first</option><option value="oldest">Oldest first</option><option value="title">Title A–Z</option><option value="author">Author A–Z</option></select></label></div>
      <details class="offline-library-advanced"><summary>Filter by year, source, status, substance or topic</summary><div class="offline-library-filters">
      <label>From year<input type="number" data-year-from min="1" max="9999" step="1" inputmode="numeric" placeholder="Any year"></label><label>To year<input type="number" data-year-to min="1" max="9999" step="1" inputmode="numeric" placeholder="Any year"></label>
      <label>Source<select data-source><option value="">All sources</option></select></label><label>Publication status<select data-filter-status><option value="">All statuses</option></select></label><label>Substance<select data-substance><option value="">All substances</option></select></label><label>Topic<select data-topic><option value="">All topics</option></select></label></div><p class="offline-library-note">Undated records are excluded when a year range is used. Topic and substance labels are screening aids.</p></details>
      <label class="offline-library-reading"><input type="checkbox" data-reading> Show my reading list only</label><p data-reading-summary></p><details data-unavailable hidden></details>
      <div class="offline-library-actions"><button type="button" data-clear-filters>Clear filters</button></div>
      <details class="offline-library-searches"><summary>Save or open an offline search</summary><label>Search name<input data-search-name maxlength="150" placeholder="Name this search (optional)"></label><button type="button" data-save-search>Save current search</button><div data-saved-searches></div></details>
      <div class="offline-library-actions offline-library-export"><label>Export format<select data-export-format><option value="json">JSON</option><option value="bibtex">BibTeX</option><option value="ris">RIS</option><option value="csv">CSV</option></select></label><button type="button" data-export>Export matching records</button></div>
      <section id="offline-record-view" data-record-view hidden aria-label="Downloaded record details"></section>
      <p data-count role="status" aria-live="polite" tabindex="-1"></p><p data-empty hidden></p><ol data-results></ol><div class="offline-library-actions" data-pagination><button type="button" data-prev>Previous</button><button type="button" data-next>Next</button></div></details>`;
    $('[data-close]')?.addEventListener('click',()=>dialog.close());

    $('[data-download]').addEventListener('click',()=>refresh(true));$('[data-cancel]').addEventListener('click',cancel);
    $('[data-remove]').addEventListener('click',async()=>{
      ++operation;active?.abort();
      try{savedState=await change(()=>({enabled:false,snapshot:null}));current=null;invalidate();await removePacks();populate();render();message('Offline copy removed. Automatic updates are off. Your reading list and searches are kept.');}catch(error){message(error.message);}
    });
    $('[data-export]').addEventListener('click',()=>{
      if(!current||!validYears())return;
      try{
        const output=exportRecords($('[data-export-format]').value,matching(),current,filters()),blob=new Blob([output.text],{type:output.mime}),objectURL=URL.createObjectURL(blob),a=el('a');a.href=objectURL;a.download='psilocybin-research-offline.'+output.extension;a.click();setTimeout(()=>URL.revokeObjectURL(objectURL),1000);
      }catch(error){message(error.message);}
    });
    for(const control of panel.querySelectorAll('.offline-library-filters input,.offline-library-filters select,[data-reading]'))control.addEventListener('input',()=>{
      clearTimeout(renderTimer);page=1;if(control.matches('[data-query]'))renderTimer=setTimeout(render,120);else render();
    });
    $('[data-clear-filters]').addEventListener('click',()=>applyFilters({}));
    $('[data-save-search]').addEventListener('click',()=>{
      if(!validYears()){message('Correct the year range before saving this search.');return;}
      const data=workspace(),view=searchUrl(filters());
      if(data.searches.length>=30&&!data.searches.some(s=>s.url===view)){message('Your workspace contains 30 saved searches or views. Remove some before saving another.');return;}
      const title=$('[data-search-name]').value.trim()||filters().q.trim()||($('[data-reading]').checked?'My offline reading list':'Offline catalogue search');
      data.searches=data.searches.filter(s=>s.url!==view);data.searches.unshift({title:title.slice(0,150),url:view});if(saveWorkspace(data))message('Search saved on this device.');
    });
    const changePage=delta=>{page+=delta;render();$('[data-count]').scrollIntoView({block:'start'});$('[data-count]').focus({preventScroll:true});};
    $('[data-prev]').addEventListener('click',()=>changePage(-1));$('[data-next]').addEventListener('click',()=>changePage(1));
    document.querySelectorAll('[data-open-offline]').forEach(button=>button.addEventListener('click',open));
    load().then(()=>{
      const initial=readSearch(location.href,location.origin);
      if(initial){applyFilters(initial);if(dialog)dialog.showModal();}
      if(!dialog&&/research-workspace(?:\.php)?\/?$/.test(location.pathname))applyFilters({reading:true});
      if(!dialog&&location.pathname==='/publication.php'){
        const id=Number(new URL(location.href).searchParams.get('id'));if(Number.isSafeInteger(id)&&id>0){selectedId=id;$('[data-browser]').open=true;renderDetails();}
      }
      check();
    });
    window.addEventListener('research-workspace-change',render);
    window.addEventListener('storage',event=>{if(event.key===workspaceKey||event.key===null)render();});
    window.addEventListener('hashchange',()=>{const f=readSearch(location.href,location.origin);if(f)open().then(()=>applyFilters(f));});
  }
  // Offline fallback launches also queue aggregate signals; no identity leaves this device.
  async function offlineLaunchMetrics() {
    if (!document.querySelector('[data-offline-library]') || !(matchMedia('(display-mode: standalone)').matches || navigator.standalone===true)) return;
    try {
      const key='publicationTrackerPwaMetricQueue',today=new Date().toISOString().slice(0,10),cutoff=new Date(Date.now()-7*86400000).toISOString().slice(0,10);
      let queue=JSON.parse(localStorage.getItem(key)||'[]').filter(e=>e.date>=cutoff);
      for (const [metric,storageKey] of [['standalone_launch','publicationTrackerStandaloneLaunch-'+today],['standalone_first_launch','publicationTrackerStandaloneFirstRecorded']]) {
        if(localStorage.getItem(storageKey)!=='1'&&!queue.some(e=>e.storageKey===storageKey))queue.push({metric,storageKey,date:today});
      }
      localStorage.setItem(key,JSON.stringify(queue.slice(-32)));
      if(navigator.onLine===false)return;
      for(const entry of queue){
        if(localStorage.getItem(entry.storageKey)==='1')continue;
        const response=await fetch(url('engagement.php'),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({metric:entry.metric,date:entry.date}),keepalive:true});
        if(!response.ok||!(await response.json()).ok)break;
        localStorage.setItem(entry.storageKey,'1');
      }
      localStorage.setItem(key,JSON.stringify(JSON.parse(localStorage.getItem(key)||'[]').filter(e=>localStorage.getItem(e.storageKey)!=='1').slice(-32)));
    }catch(_){}
  }
  window.addEventListener('online',offlineLaunchMetrics);
  document.addEventListener('submit',event=>{
    if(document.querySelector('#papers')||navigator.onLine!==false||!current?.records||!event.target.matches('.hero-search,.advanced-filter-form,.result-quick-controls'))return;
    event.preventDefault();event.stopImmediatePropagation();
    const query=new FormData(event.target).get('search')||new FormData(event.target).get('q')||'';
    open().then(()=>{applyFilters({q:String(query)});message('Searching downloaded metadata. Server analytics and other live-only features require a connection.');});
  },true);
  window.TrackerOffline={open,offer,refresh,state,cancel,get busy(){return busy;}};
  const offerR=()=>import('/assets/account-analysis-offline-offer.js?v=8').then(m=>m.start()).catch(()=>{});
  window.addEventListener('tracker:installed',offerR);
  if(matchMedia('(display-mode: standalone)').matches||navigator.standalone===true)offerR();
  window.addEventListener('tracker:installed',offer);window.addEventListener('online',()=>{busyControls();check(true);});window.addEventListener('offline',()=>{busyControls();message(current?'You are offline. Your saved catalogue is available.':'Connect to the internet to download the offline app.');});
  document.addEventListener('visibilitychange',()=>check());setInterval(()=>check(),900000);
  window.addEventListener('pageshow',()=>load().then(()=>check()));
  setup();offlineLaunchMetrics();if(matchMedia('(display-mode: standalone)').matches||navigator.standalone===true)setTimeout(async()=>{const saved=await state().catch(()=>null);if(!saved?.snapshot)window.TrackerPwaMetrics?.record?.('offline_setup_shown','publicationTrackerPwa-offline-setup-shown');offer();},1500);
})();
