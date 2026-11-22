importScripts("./assets/notification-state.js?v=20261108-received-history-v1");
importScripts("./assets/offline-runtime.min.js?v=20261108-offline-components-v1");
const CACHE_VERSION = "publication-tracker-pwa-v414-20261110-provenance";
const ASSET_VERSION = "20261110-install-visibility-v2";
const NOTIFICATION_BADGE = "./assets/pwa/notification-badge-96.png?v=20261101-liberty-cap";
const STATIC_CACHE = `${CACHE_VERSION}-static`;
const RUNTIME_CACHE = `${CACHE_VERSION}-runtime`;

const STATIC_ASSETS = [
  "./offline.html",
  `./assets/offline-ui.min.js?v=${ASSET_VERSION}`,
  "./assets/offline-runtime.min.js?v=20261108-offline-components-v1",
  `./assets/pwa-freshness.min.js?v=${ASSET_VERSION}`,
  `./assets/offline-library.min.js?v=${ASSET_VERSION}`,
  "./assets/offline-library.css?v=20261101-agentic-performance-v307",
  "./favicon.ico",
  `./manifest.webmanifest?v=20261101-nature-launcher`,
  `./assets/styles.min.css?v=${ASSET_VERSION}`,
  `./assets/home.min.css?v=${ASSET_VERSION}`,
  `./assets/app.min.js?v=${ASSET_VERSION}`,
  `./assets/visitor-statistics.min.js?v=20261012-android-safe-area-v255`,
  `./assets/vendor/chart.umd.min.js?v=20261012-android-safe-area-v255`,
  `./assets/research-explorer.min.js?v=20261108-accounts-v1`,
  `./assets/trials-map.min.js?v=20261017-pwa-funnel-v272`,
  `./assets/world-110m.geojson?v=20261012-android-safe-area-v255`,
  `./assets/logo.png?v=20261012-android-safe-area-v255`,
  "./assets/mockup-brand.png",
  "./assets/mushroom-brand-mark-96.webp",
  "./assets/mushroom-brand-mark-144.webp",
  "./assets/mushroom-brand-mark-192.webp",
  "./assets/preloader-mushroom-desktop.webp",
  "./assets/preloader-mushroom-mobile.webp",
  "./assets/funding/cognovo-logo.png",
  "./assets/funding/marie-curie.svg",
  "./assets/funding/eu-marie-curie-actions.jpg",
  "./assets/fonts/roboto-latin.woff2",
  "./assets/fonts/roboto-latin-ext.woff2",
  `./assets/pwa/icon-192.png?v=20261012-android-safe-area-v255`,
  `./assets/pwa/icon-512.png?v=20261012-android-safe-area-v255`,
  "./assets/notification-state.js?v=20261108-received-history-v1",
  NOTIFICATION_BADGE,
  "./assets/pwa/shortcut-papers-96.png?v=20261101-nature-launcher",
  "./assets/pwa/shortcut-papers-192.png?v=20261101-nature-launcher",
  "./assets/pwa/shortcut-analytics-96.png?v=20261101-nature-launcher",
  "./assets/pwa/shortcut-analytics-192.png?v=20261101-nature-launcher",
  "./assets/pwa/shortcut-alerts-96.png?v=20261101-nature-launcher",
  "./assets/pwa/shortcut-alerts-192.png?v=20261101-nature-launcher",
  `./assets/pwa/maskable-512.png?v=20261012-android-safe-area-v255`,
  `./assets/pwa/apple-touch-icon.png?v=20261012-android-safe-area-v255`
];

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches.open(STATIC_CACHE)
      .then((cache) => cache.addAll(STATIC_ASSETS.map((url) => new Request(url, { cache: "reload" }))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((key) => key.startsWith("publication-tracker-pwa-") && !key.startsWith(CACHE_VERSION)).map((key) => caches.delete(key))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener("message", (event) => {
  if (event.data?.type === "SKIP_WAITING") {
    self.skipWaiting();
  }
  if (event.data?.type === "GET_VERSION") {
    event.source?.postMessage({ type: "APP_VERSION", version: CACHE_VERSION });
  }
});

function isSameOrigin(request) {
  return new URL(request.url).origin === self.location.origin;
}

function isDynamicEndpoint(url) {
  return /^\/(api|status|export|health|alert|push)\.php$/.test(url.pathname);
}

async function networkFirst(request, fallbackUrl = null) {
  const cache = await caches.open(RUNTIME_CACHE);
  try {
    const response = await fetch(request);
    if (response && response.ok && request.method === "GET" && request.mode !== "navigate") {
      cache.put(request, response.clone());
    }
    return response;
  } catch (error) {
    const cached = await cache.match(request);
    if (cached) return cached;
    if (fallbackUrl) {
      const fallback = await caches.match(new URL(fallbackUrl, self.location.href).href);
      if (fallback) return new Response(await fallback.blob(), { status: 200, headers: { "Content-Type": "text/html; charset=utf-8", "X-Robots-Tag": "noindex, follow" } });
    }
    throw error;
  }
}

async function cacheFirst(request, event) {
  // Storage can be denied or unavailable even while the network works.
  // An optional cache failure must not prevent an image/font/script loading.
  let cached;
  try { cached = await caches.match(request); } catch (_) {}
  if (cached && request.cache !== "reload") return cached;
  // A new worker must not refill its cache from an older immutable HTTP asset.
  const isCodeAsset = /\.(?:css|js)$/i.test(new URL(request.url).pathname);
  const response = await fetch(request, isCodeAsset ? { cache: "reload" } : undefined);
  if (response && response.ok && request.method === "GET") {
    const copy = response.clone();
    const store = (async () => {
      try {
        const cache = await caches.open(STATIC_CACHE);
        await cache.put(request, copy);
      } catch (_) {}
    })();
    event.waitUntil(store);
  }
  return response;
}

async function offlineState() {
  const db = await new Promise((resolve, reject) => {
    const request = indexedDB.open("publication-tracker-offline", 1);
    request.onupgradeneeded = () => request.result.createObjectStore("library");
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
  try { return await new Promise((resolve,reject) => {
    const request=db.transaction("library").objectStore("library").get("active");
    request.onsuccess=()=>resolve(request.result);request.onerror=()=>reject(request.error);
  }); } finally { db.close(); }
}
const offlineHeaders={"X-Tracker-Offline":"1","X-Robots-Tag":"noindex, follow","Cache-Control":"no-store"};
async function offlineResponse(request) {
  if(new URL(request.url).searchParams.has('notification'))return new Response('<!doctype html><html lang="en"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Research notification</title><main><h1>Reconnect to open these research updates</h1><p>Your notification remains unseen until its research page loads.</p><button onclick="location.reload()">Try again</button> <a href="/">Open downloaded research</a></main></html>',{headers:{'Content-Type':'text/html; charset=utf-8','X-Robots-Tag':'noindex','X-Tracker-Offline':'1'}});

  const state=await offlineState();
  if(!state?.enabled || !state.snapshot || !state.pack || !await caches.has(state.pack.name))return null;
  const url=new URL(request.url),runtime=self.TrackerOfflineRuntime,snapshot=state.snapshot,pack=await caches.open(state.pack.name);
  const json=value=>new Response(JSON.stringify(value),{headers:{...offlineHeaders,"Content-Type":"application/json; charset=utf-8"}});
  if(url.pathname==="/export.php") {
    try {const output=runtime.offlineExport(snapshot,url);return new Response(output.text,{headers:{...offlineHeaders,"Content-Type":output.mime,"Content-Disposition":'attachment; filename="psilocybin-research-offline.'+output.extension+'"'}});}catch(_){return new Response("Export unavailable",{status:400,headers:offlineHeaders});}
  }
  if(url.pathname==="/status.php"){const saved=await pack.match(new URL('/status.php',self.location.origin));return saved?json(await saved.json()):json({ok:true,message:'Saved catalogue',last_updated:snapshot.generated_at_utc});}
  if(url.pathname==="/api.php"){
    const resource=url.searchParams.get("resource")||"papers";
    if(resource==="research_comparison"){
      const ids=runtime.values(url.searchParams,"ids").map(Number),papers=snapshot.records.filter(r=>ids.includes(r.id));
      const saved=await pack.match(new URL('/api.php?resource=research_trials',self.location.origin));const data=saved?await saved.json():[];
      const trials=(Array.isArray(data)?data:data.trials||[]).filter(t=>ids.includes(Number(t.id||t.publication_id||t.paper_id))||papers.some(p=>p.source_record_id===t.nct_id));
      return json({papers,trials});
    }
    if(resource==="paper")return json({paper:snapshot.records.find(r=>r.id===Number(url.searchParams.get("id")))||null});
    if(resource==="latest"){const limit=Math.max(1,Math.min(200,Number(url.searchParams.get("limit"))||25)),offset=Math.max(0,Number(url.searchParams.get("offset"))||0);return json({papers:runtime.queryRecords(snapshot.records,new URLSearchParams({sort:"newly_added",per_page:"all"})).all.slice(offset,offset+limit),limit,offset});}
    if(resource==="papers"){const result=runtime.queryRecords(snapshot.records,url.searchParams);delete result.all;return json({...result,resource:"papers"});}
    if(resource==="research_network"){const response=await pack.match(new URL('/api.php?resource=research_network&full=1',self.location.origin));if(response)return json(runtime.researcherGraph(await response.json(),snapshot,url.searchParams));}
    if(resource==="research_trials"){const response=await pack.match(new URL('/api.php?resource=research_trials',self.location.origin));if(response){const full=await response.json();return json({...full,trials:runtime.filteredTrials(full,url.searchParams)});}}
    const saved=await pack.match(request)||await pack.match(new URL('/api.php?resource='+resource,self.location.origin));
    if(saved)return new Response(await saved.arrayBuffer(),{headers:{...offlineHeaders,"Content-Type":"application/json; charset=utf-8"}});
    return new Response('{"error":"Connection required for this resource"}',{status:503,headers:{...offlineHeaders,"Content-Type":"application/json"}});
  }
  let path=url.pathname,kind="page";
  if(path==="/"||path==="/index.php"){path="/";kind="home";}
  if(path==="/publication.php"){path="/__offline_record_template";kind="record";}
  if(["/research-dataset/","/research-trends/"].includes(path))path=path.replace(/\/$/,".php");
  
  let saved=await pack.match(new URL(path,self.location.origin));
  if(saved&&(path==="/database.php"||path.startsWith("/tools/")))return new Response(await saved.arrayBuffer(),{headers:{...Object.fromEntries(saved.headers),...offlineHeaders}});
  if(!saved){
    saved=await pack.match(new URL('/__offline_record_template',self.location.origin));if(!saved)return null;
    let html=(await saved.text()).replace(/<main\b[^>]*>[\s\S]*?<\/main>/i,'<main class="detail-shell"><section class="panel detail-hero"><h1>This page needs a connection</h1><p>This page was not included in your download. Your saved catalogue, reading list and research tools remain available.</p><p><a class="primary" href="/">Search downloaded publications</a> · <a href="/webr">Open R workspace</a></p></section></main>').replace(/<title>[\s\S]*?<\/title>/i,'<title>Connection needed | Psilocybin Research</title>');
    return new Response(runtime.offlineHTML(html,snapshot,url),{headers:{...offlineHeaders,"Content-Type":"text/html; charset=utf-8"}});
  }
  let html=runtime.offlineHTML(await saved.text(),snapshot,url,kind);
  html=await runtime.toolHTML(html,snapshot,url,async key=>{
    const saved=await pack.match(new URL(key,self.location.origin));return saved?await saved.json():null;
  });
  return new Response(html,{headers:{...offlineHeaders,"Content-Type":"text/html; charset=utf-8"}});
}
async function onlineOrOffline(request,isPage=false){
  if(self.navigator?.onLine===false){const saved=await offlineResponse(request).catch(()=>null);if(saved)return saved;if(isPage){const fallback=await caches.match(new URL('./offline.html',self.location.href));if(fallback)return new Response(await fallback.blob(),{headers:{...offlineHeaders,'Content-Type':'text/html; charset=utf-8'}});}}
  try {const response=await fetch(request);if(response.ok||response.status<500)return response;throw new Error("Server unavailable");}
  catch(error){const response=await offlineResponse(request).catch(()=>null);if(response)return response;
    if(isPage){const fallback=await caches.match(new URL("./offline.html",self.location.href));if(fallback)return new Response(await fallback.blob(),{headers:{...offlineHeaders,"Content-Type":"text/html; charset=utf-8"}});}
    throw error;
  }
}

self.addEventListener("fetch", event => {
  const request=event.request;if(!isSameOrigin(request))return;
  const url=new URL(request.url);
  const shinyGuest=url.pathname==='/shinyapp-live'||url.pathname==='/assets/account-analysis-shiny-offline.js'||url.pathname==='/assets/account-analysis-shiny-manifest.json'||url.pathname==='/assets/account-analysis-confirm.js'||url.pathname==='/assets/fonts/roboto-latin.woff2'||url.pathname==='/assets/mushroom-brand-mark-192.webp';
  if(request.method==='GET'&&shinyGuest){event.respondWith((async()=>{try{if(self.navigator.onLine===false)throw Error('Offline');const response=await fetch(request);if(response.status<500)return response;}catch(_){}try{const index=await caches.open('psilo-shiny-offline-index'),entry=await index.match(new URL('/__shiny_offline_active',self.location.origin));if(entry){const a=await entry.json();if(await caches.has(a.cache)){const pack=await caches.open(a.cache),hit=await pack.match(new URL(url.pathname,self.location.origin));if(hit)return hit;}}}catch(_){}return fetch(request);})());return;}
  const rPage=/^\/webr\/?$/i.test(url.pathname)||/^\/r-analysis\/workspace\/?$/i.test(url.pathname);
  const rSetup=/^\/r-analysis\/offline\/?$/.test(url.pathname);
  const rAsset=url.pathname.startsWith('/assets/account-latex/')||url.pathname.startsWith('/assets/account-webr/')||url.pathname.startsWith('/assets/account-analysis')||url.pathname.startsWith('/assets/account-report-fonts/');
  if((request.method==='GET'||request.method==='HEAD')&&(rPage||rSetup||rAsset)){
    event.respondWith((async()=>{
      // Explicitly downloaded engine files are pinned to the saved package.
      // Reuse them online too; interface and account responses stay network-first.
      if(url.pathname.startsWith('/assets/account-webr/')||url.pathname.startsWith('/assets/account-latex/')){
        try{const idx=await caches.open('psilo-webr-offline-index'),entry=await idx.match(new URL('/__webr_offline_active',self.location.origin));if(entry){const a=await entry.json();if(a.cache&&await caches.has(a.cache)){const pack=await caches.open(a.cache),saved=await pack.match(new Request(request.url,{method:'GET'}));if(saved){if(request.method==='HEAD'){const headers=new Headers(saved.headers);headers.delete('accept-ranges');headers.delete('content-encoding');headers.set('content-length',String((await saved.arrayBuffer()).byteLength));return new Response(null,{status:200,headers});}return saved;}}}}catch(_){}
      }
      try{const response=await fetch(request, /\.(?:js|css)$/i.test(url.pathname)?{cache:'reload'}:undefined);if(response.status<500)return response;}catch(_){}
      let hit;
      try{const idx=await caches.open('psilo-webr-offline-index'),entry=await idx.match(new URL('/__webr_offline_active',self.location.origin));if(entry){const a=await entry.json(),pack=await caches.open(a.cache);hit=await pack.match(rPage?new URL('/__webr_offline_shell',self.location.origin):rSetup?new URL('/r-analysis/offline/',self.location.origin):new Request(request.url,{method:'GET'}));}}catch(_){}
      if(hit){if(request.method==='HEAD'){const headers=new Headers(hit.headers);headers.delete('accept-ranges');headers.delete('content-encoding');headers.set('content-length',String((await hit.arrayBuffer()).byteLength));return new Response(null,{status:200,headers});}return hit;}
      if(rPage||rSetup)return new Response('<!doctype html><html lang="en"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Offline R download needed</title><main><h1>Prepare offline R when connected</h1><p>The R runtime and dataset have not been downloaded on this device, or browser storage was cleared.</p><p><a href="/">Open downloaded publications</a></p><button onclick="location.reload()">Try again</button></main></html>',{headers:{...offlineHeaders,'Content-Type':'text/html; charset=utf-8'}});
      return Response.error();
    })());return;
  }
  if(request.method!=='GET')return;
  if(/^\/analysis-(?:dataset|report)\.php$/.test(url.pathname))return;
  if(/^\/account(?:-(?:api|analysis(?:-api)?))?\.php$/.test(url.pathname)||url.pathname.startsWith("/assets/account-analysis")||url.pathname.startsWith("/assets/account-webr/"))return;
  if(/\/(offline-data|engagement|internal-traffic)\.php$/.test(url.pathname))return;
  // Offline packs exclude video. Leave streaming, byte ranges and cancelled
  // source changes to the browser rather than proxying them through the worker.
  if(request.destination === "video" || /\.mp4$/i.test(url.pathname))return;
  if(request.mode==="navigate"||request.headers.get("Accept")?.includes("text/html")) {event.respondWith(onlineOrOffline(request,true));return;}
  if(url.pathname==="/api.php"||url.pathname==="/export.php"||url.pathname==="/status.php"){event.respondWith(onlineOrOffline(request));return;}
  if(isDynamicEndpoint(url)){event.respondWith(networkFirst(request));return;}
  if(/\.(?:css|js|json|geojson|png|jpg|jpeg|webp|svg|ico|webmanifest|vtt|woff2?)$/i.test(url.pathname))event.respondWith(cacheFirst(request, event));
});

self.addEventListener("push", (event) => {
  event.waitUntil((async()=>{
    let data={};
    try {data=event.data?event.data.json():{};} catch(_){data={body:event.data?.text()||''};}
    let state;
    try {state=await TrackerNotifications.receive(data);} catch(_){}
    const preferences=state?.preferences||{sound:true,vibration:true};
    const url=safeNotificationUrl(data.url||'./#papers');
    const options={
      body:data.body||'New research has been added to the database.',
      icon:'./assets/pwa/icon-192.png?v=20261012-android-safe-area-v255',
      badge:NOTIFICATION_BADGE,
      tag:data.kind==='test'?'psilocybin-research-test':'psilocybin-research-latest',
      renotify:false,
      timestamp:data.timestamp||Date.now(),
      data:{url,batch:data.batch||null,kind:data.kind||'research'},
      actions:[{action:'view',title:data.kind==='test'?'Open app':'View research'},{action:'settings',title:'Notification settings'}]
    };
    if(preferences.sound===false)options.silent=true;
    else options.vibrate=preferences.vibration===false?[]:[80];
    await self.registration.showNotification(data.title||'New psilocybin research',options);
    try { await TrackerNotifications.recordReceived(data); } catch (_) {}
    for(const client of await clients.matchAll({type:'window'}))client.postMessage({type:'research-notification'});
  })());
});

function safeNotificationUrl(value){
  try {const url=new URL(value,self.location.href);if(url.origin===self.location.origin)return url.href;} catch(_){}
  return new URL('./#papers',self.location.href).href;
}
self.addEventListener('notificationclick',event=>{
  event.notification.close();
  const targetUrl=event.action==='settings'?new URL('./#notifications',self.location.href).href:safeNotificationUrl(event.notification.data?.url||'./#papers');
  // A click alone does not mark research as viewed: the destination confirms it loaded.
  event.waitUntil((async()=>{
    const windows=await clients.matchAll({type:'window',includeUncontrolled:true});
    for(const client of windows){
      if(new URL(client.url).origin===self.location.origin&&'focus' in client){
        try{await client.navigate(targetUrl);await client.focus();return;}catch(_){}
      }
    }
    await clients.openWindow(targetUrl);
  })());
});
