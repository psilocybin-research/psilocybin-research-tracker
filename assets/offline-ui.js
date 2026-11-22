(() => {
 const offline=()=>navigator.onLine===false||!!window.trackerOfflinePage;
 const compactKey='publicationTrackerOfflineNoticeCompact';
 let compact=false;try{compact=localStorage.getItem(compactKey)==='1';}catch(_){}
 const applyCompact=node=>{node.classList.toggle('is-compact',compact);const detail=node.querySelector('#offline-status-description'),toggle=node.querySelector('[data-offline-notice-toggle]'),title=node.querySelector('strong');if(detail)detail.hidden=compact;if(title)title.textContent=compact?'Offline':'Offline mode';if(toggle){toggle.textContent=compact?'Details':'Minimise';toggle.setAttribute('aria-expanded',String(!compact));toggle.setAttribute('aria-label',compact?'Show offline details':'Minimise offline notice');}};
 let wasOffline = false;
 let reconnectTimer = 0;
 const note=()=>{
  let node=document.getElementById('offline-status-note');
  if(!node){
   node=document.createElement('aside');node.id='offline-status-note';node.className='offline-status-note';
   node.setAttribute('role','status');node.setAttribute('aria-live','polite');node.setAttribute('aria-atomic','true');
   document.body.prepend(node);
   if ('ResizeObserver' in window) new ResizeObserver(()=>{
    document.documentElement.style.setProperty('--offline-notice-height',node.hidden?'0px':`${node.getBoundingClientRect().height}px`);
   }).observe(node);
  }
  const active=offline();
  if(active){
   clearTimeout(reconnectTimer);
   if(!wasOffline || node.hidden){
    node.replaceChildren();
    const icon=document.createElement('span');icon.className='offline-status-icon';icon.setAttribute('aria-hidden','true');icon.textContent='↯';
    const copy=document.createElement('span');const title=document.createElement('strong');title.textContent='Offline mode';
    const detail=document.createElement('span');detail.id='offline-status-description';detail.textContent='Viewing saved pages and data. Live updates and external links need a connection.';
    copy.append(title,detail);node.append(icon,copy);
    const toggle=document.createElement('button');toggle.type='button';toggle.className='offline-notice-toggle';toggle.dataset.offlineNoticeToggle='';toggle.setAttribute('aria-controls','offline-status-description');toggle.onclick=()=>{compact=!compact;try{localStorage.setItem(compactKey,compact?'1':'0');}catch(_){}applyCompact(node);};node.append(toggle);
   }
   node.hidden=false;node.classList.remove('is-reconnected');applyCompact(node);
   const snapshot=window.trackerOfflinePage?.snapshot;
   node.title=snapshot?'Saved catalogue: '+new Date(snapshot).toLocaleString():'';
  }else if(wasOffline){
   node.classList.remove('is-compact');node.textContent='Connection restored. Live services are available again.';
   node.hidden=false;node.classList.add('is-reconnected');
   clearTimeout(reconnectTimer);reconnectTimer=setTimeout(()=>{node.hidden=true;},5000);
  }else if(!node.classList.contains('is-reconnected'))node.hidden=true;
  wasOffline=active;
 };
 const notify=()=>{if(typeof showAppToast==='function')showAppToast('Connect to the internet to use this feature.');};
 document.addEventListener('submit',event=>{if(offline()&&((event.target.method||'get').toLowerCase()==='post'||event.target.id==='push-preferences-form')){event.preventDefault();event.stopImmediatePropagation();notify();}},true);
 document.addEventListener('click',async event=>{
  if(!offline()||event.defaultPrevented||event.button!==0||event.metaKey||event.ctrlKey||event.shiftKey||event.altKey)return;
  if(event.target.closest('#push-app,[data-push-unsubscribe]')){event.preventDefault();event.stopImmediatePropagation();notify();return;}
  const a=event.target.closest('a[href]');if(!a)return;
  const u=new URL(a.href,location.href);
  if(/\.(mp4|webm|mov|m4v)$/i.test(u.pathname)){event.preventDefault();if(typeof showAppToast==='function')showAppToast('Videos are not included in the offline download. Reconnect to watch or download.');return;}
  if(u.origin!==location.origin){event.preventDefault();notify();return;}
  if(/^\/account(?:-[a-z-]+)?\.php$/.test(u.pathname)){event.preventDefault();notify();return;}
  if(u.pathname==='/export.php'){
   event.preventDefault();try{
    for(let i=0;!window.TrackerOffline&&i<125;i++)await new Promise(r=>setTimeout(r,40));if(!window.TrackerOffline)throw new Error();
    const state=await TrackerOffline.state();if(!state.snapshot){notify();return;}
    const p=new URLSearchParams(location.search);for(const [k,v] of u.searchParams)p.set(k,v);
    // Preserve all selected IDs; URLSearchParams.set would otherwise keep just the last.
    p.delete('ids[]');u.searchParams.getAll('ids[]').forEach(id=>p.append('ids[]',id));
    const response=await fetch('/export.php?'+p);if(!response.ok)throw new Error();const blob=await response.blob(),href=URL.createObjectURL(blob),link=document.createElement('a');link.href=href;link.download=/filename="?([^";]+)/i.exec(response.headers.get('Content-Disposition')||'')?.[1]||'psilocybin-research-offline.'+(p.get('format')||'bib');link.click();setTimeout(()=>URL.revokeObjectURL(href),1000);
   }catch(_){if(typeof showAppToast==='function')showAppToast('The export is unavailable. Keep your downloaded catalogue and try again.');}
  }
 },true);
 window.addEventListener('storage',event=>{if(event.key===compactKey){compact=event.newValue==='1';const node=document.getElementById('offline-status-note');if(node&&offline())applyCompact(node);}});
 window.addEventListener('online',()=>{window.trackerOfflinePage=null;note();});window.addEventListener('offline',note);window.addEventListener('pageshow',note);window.addEventListener('tracker:connection-status',note);note();
})();
