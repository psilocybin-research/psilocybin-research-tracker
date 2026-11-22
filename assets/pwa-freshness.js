// One root service-worker registration serves browser tabs and installed PWAs.
(() => {
 if(!('serviceWorker' in navigator)||window.trackerFreshnessStarted)return;
 window.trackerFreshnessStarted=true;
 let controller=navigator.serviceWorker.controller,pending=false,reloading=false,requested=false,checking=false,lastCheck=0,lastInteraction=0,snoozeUntil=0,notice,transition=false;
 const dirtyForms=new Set();
 const hardBusy=()=>!!(window.TrackerOffline?.busy||window.TrackerRWorkspace?.busy||window.TrackerOfflineRDownload?.busy||window.TrackerShinyOfflineDownload?.busy||window.TrackerAccountSignIn?.busy);
 const unsaved=()=>!!(window.TrackerRWorkspace?.dirty||dirtyForms.size);
 const blocked=()=>hardBusy()||unsaved()||document.querySelector('dialog[open]')||document.activeElement?.matches('input,textarea,select,[contenteditable=true]')||Date.now()-lastInteraction<15000;
 const css=document.createElement('link');css.rel='stylesheet';css.href='/assets/pwa-updates.css?v=20261115-bottom-toasts';document.head.append(css);
 const message=()=>hardBusy()?'Your current task will finish before the app updates.':window.TrackerRWorkspace?.dirty?'Your R draft will be kept on this device when you update.':dirtyForms.size?'Finish or submit your form before updating.':'The latest improvements are ready.';
 const showNotice=()=>{
  if(Date.now()<snoozeUntil&&!requested)return;
  if(!notice){notice=document.createElement('aside');notice.id='app-update-notice';notice.className='app-update-notice';notice.setAttribute('role','status');notice.setAttribute('aria-live','polite');notice.innerHTML='<div class="app-update-copy"><strong>Update ready</strong><span data-update-message></span></div><div class="app-update-actions"><button type="button" data-update-now>Update now</button><button type="button" data-update-later>Later</button></div>';
   notice.querySelector('[data-update-now]').addEventListener('click',()=>{requested=true;snoozeUntil=0;refresh();});
   notice.querySelector('[data-update-later]').addEventListener('click',()=>{requested=false;snoozeUntil=Date.now()+300000;notice.hidden=true;});
  }
  notice.hidden=false;notice.querySelector('[data-update-message]').textContent=message();notice.querySelector('[data-update-now]').textContent=requested&&hardBusy()?'Update when ready':window.TrackerRWorkspace?.dirty?'Update & keep draft':'Update now';
  const host=document.querySelector('dialog[open]')||document.body;if(notice.parentElement!==host)host.append(notice);
  if(typeof notice.showPopover==='function'){notice.popover='manual';if(!notice.matches(':popover-open'))try{notice.showPopover();}catch{}}
 };
 const confirmForms=()=>new Promise(resolve=>{
  const dialog=document.createElement('dialog');dialog.className='app-update-confirm';dialog.setAttribute('aria-labelledby','app-update-confirm-title');dialog.innerHTML='<h2 id="app-update-confirm-title">Update this page?</h2><p>Text you haven’t submitted in a form will be lost. Save or submit it first if you need to keep it.</p><div><button type="button" data-update-cancel autofocus>Keep working</button><button type="button" data-update-confirm>Update anyway</button></div>';
  const opener=document.activeElement;dialog.querySelector('[data-update-cancel]').onclick=()=>dialog.close('cancel');dialog.querySelector('[data-update-confirm]').onclick=()=>dialog.close('update');dialog.addEventListener('close',()=>{const accepted=dialog.returnValue==='update';dialog.remove();opener?.focus?.();resolve(accepted);},{once:true});document.body.append(dialog);dialog.showModal();
 });
 const reload=async explicit=>{
  if(reloading||transition)return;transition=true;
  try{
   if(explicit&&dirtyForms.size&&!await confirmForms()){requested=false;return;}
   if(hardBusy()){requested=explicit;showNotice();return;}
   if(window.TrackerRWorkspace?.dirty){
    if(!explicit)return;
    if(!await window.TrackerRWorkspace.prepareUpdate?.()){requested=false;showNotice();notice.querySelector('[data-update-message]').textContent='Save your analysis to your account or download your R code before updating.';return;}
   }
   reloading=true;try{sessionStorage.setItem('publicationTrackerUpdated',JSON.stringify({href:location.href,x:scrollX,y:scrollY,at:Date.now()}));}catch{}
   window.TrackerRWorkspace?.commitUpdate?.();location.reload();
  }finally{transition=false;}
 };
 const refresh=()=>{
  if(!pending||reloading||document.visibilityState==='hidden'||navigator.onLine===false||Date.now()<snoozeUntil)return;
  if(requested){if(hardBusy()){showNotice();return;}reload(true);return;}
  if(blocked()){showNotice();return;}reload(false);
 };
 const changed=()=>{const next=navigator.serviceWorker.controller;if(controller&&next&&next!==controller)pending=true;controller=next;refresh();};
 navigator.serviceWorker.addEventListener('controllerchange',changed);
 const track=e=>{lastInteraction=Date.now();const form=e.target.closest?.('form');if(form&&!e.target.matches('[type=search]')&&!form.matches('[role=search],.hero-search,.advanced-filter-form,.result-quick-controls,#filters-form,#search-form'))dirtyForms.add(form);};
 document.addEventListener('input',track,true);document.addEventListener('change',track,true);
 for(const event of ['pointerdown','keydown'])document.addEventListener(event,()=>{lastInteraction=Date.now();},{capture:true,passive:true});
 document.addEventListener('reset',e=>{dirtyForms.delete(e.target);setTimeout(refresh,0);},true);
 for(const event of ['close','focusin','focusout'])document.addEventListener(event,()=>setTimeout(refresh,0),true);
 window.addEventListener('tracker:offline-idle',refresh);
 // Repairs only an already opted-in R package; never enlarges offline downloads.
 let checkingR=false,lastRCheck=0;
 const repairR=async()=>{if(checkingR||navigator.onLine===false||document.visibilityState==='hidden'||Date.now()-lastRCheck<900000||hardBusy())return;checkingR=true;lastRCheck=Date.now();try{if('caches' in window&&await caches.has('psilo-webr-offline-index')){const index=await caches.open('psilo-webr-offline-index');if(await index.match(new URL('/__webr_offline_active',location.origin))){const module=await import('/assets/account-analysis-offline.js?v=6');await module.repair();}}}catch(error){window.dispatchEvent(new CustomEvent('tracker:offline-r-check',{detail:{ready:false,message:error.message}}));}finally{checkingR=false;}};
 window.addEventListener('online',()=>{lastRCheck=0;repairR();});window.addEventListener('pageshow',repairR);document.addEventListener('visibilitychange',repairR);setInterval(repairR,900000);repairR();
 navigator.serviceWorker.register('/sw.js',{scope:'/',updateViaCache: "none"}).then(registration=>{
  const activate=worker=>{if(!worker)return;const ready=()=>{if(worker.state==='installed')worker.postMessage({type:'SKIP_WAITING'});};worker.addEventListener('statechange',ready);ready();};activate(registration.waiting);activate(registration.installing);registration.addEventListener('updatefound',()=>activate(registration.installing));
  const check=async(force=false)=>{changed();if(checking||navigator.onLine===false||document.visibilityState==='hidden'||(!force&&Date.now()-lastCheck<30000))return;checking=true;lastCheck=Date.now();try{await registration.update();}catch{}finally{checking=false;}};
  window.addEventListener('online',()=>check(true));window.addEventListener('pageshow',()=>check());window.addEventListener('focus',()=>check());document.addEventListener('visibilitychange',()=>check());setInterval(()=>{check();refresh();},30000);check(true);
 }).catch(()=>{});
 // A successful update keeps the current URL and reading position.
 try{const raw=sessionStorage.getItem('publicationTrackerUpdated');if(raw){sessionStorage.removeItem('publicationTrackerUpdated');let saved;try{saved=JSON.parse(raw);}catch{}if(saved?.href===location.href&&Date.now()-saved.at<120000){const restore=()=>window.scrollTo(saved.x,saved.y);requestAnimationFrame(()=>requestAnimationFrame(restore));setTimeout(restore,500);}const done=document.createElement('div');done.className='app-update-complete';done.setAttribute('role','status');done.textContent='You’re up to date';const close=document.createElement('button');close.type='button';close.className='toast-close';close.setAttribute('aria-label','Dismiss notification');close.textContent='×';done.append(close);done.addEventListener('click',()=>done.remove());document.body.append(done);setTimeout(()=>done.remove(),4000);}}catch{}
})();
