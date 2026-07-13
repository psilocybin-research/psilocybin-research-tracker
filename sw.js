const CACHE_VERSION = "publication-tracker-pwa-v159-20260713-push-footer";
const ASSET_VERSION = "20260713-push-footer-v89";
const STATIC_CACHE = `${CACHE_VERSION}-static`;
const RUNTIME_CACHE = `${CACHE_VERSION}-runtime`;

const STATIC_ASSETS = [
  "./offline.html",
  "./favicon.ico",
  `./manifest.webmanifest?v=${ASSET_VERSION}`,
  `./assets/styles.min.css?v=${ASSET_VERSION}`,
  `./assets/app.min.js?v=${ASSET_VERSION}`,
  `./assets/logo.png?v=${ASSET_VERSION}`,
  "./assets/mockup-brand.png",
  "./assets/mushroom-brand-mark.webp",
  "./assets/preloader-mushroom-desktop.webp",
  "./assets/preloader-mushroom-mobile.webp",
  "./assets/funding/cognovo-logo.png",
  "./assets/funding/marie-curie.svg",
  "./assets/funding/eu-marie-curie-actions.jpg",
  "./assets/fonts/roboto-latin.woff2",
  "./assets/fonts/roboto-latin-ext.woff2",
  `./assets/pwa/icon-192.png?v=${ASSET_VERSION}`,
  `./assets/pwa/icon-512.png?v=${ASSET_VERSION}`,
  `./assets/pwa/maskable-512.png?v=${ASSET_VERSION}`,
  `./assets/pwa/apple-touch-icon.png?v=${ASSET_VERSION}`
];

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches.open(STATIC_CACHE)
      .then((cache) => cache.addAll(STATIC_ASSETS))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((key) => !key.startsWith(CACHE_VERSION)).map((key) => caches.delete(key))))
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
    if (fallbackUrl) return caches.match(new URL(fallbackUrl, self.location.href).href);
    throw error;
  }
}

async function cacheFirst(request) {
  const cached = await caches.match(request);
  if (cached) return cached;
  const response = await fetch(request);
  if (response && response.ok && request.method === "GET") {
    const cache = await caches.open(STATIC_CACHE);
    cache.put(request, response.clone());
  }
  return response;
}

self.addEventListener("fetch", (event) => {
  const request = event.request;
  if (request.method !== "GET" || !isSameOrigin(request)) return;

  const url = new URL(request.url);
  if (isDynamicEndpoint(url)) {
    event.respondWith(networkFirst(request));
    return;
  }

  if (request.mode === "navigate") {
    event.respondWith(networkFirst(request, "./offline.html"));
    return;
  }

  if (/\.(?:css|js|png|jpg|jpeg|webp|svg|ico|webmanifest|woff2?)$/i.test(url.pathname)) {
    event.respondWith(cacheFirst(request));
  }
});

self.addEventListener("push", (event) => {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch (error) {
    data = { title: "New psilocybin research", body: event.data ? event.data.text() : "" };
  }
  const title = data.title || "New psilocybin research";
  const options = {
    body: data.body || "A new psilocybin or psilocin paper was added to the tracker.",
    icon: `./assets/pwa/icon-192.png?v=${ASSET_VERSION}`,
    badge: `./assets/pwa/icon-192.png?v=${ASSET_VERSION}`,
    tag: data.tag || "psilocybin-research-latest",
    renotify: true,
    timestamp: data.timestamp || Date.now(),
    data: {
      url: data.url || "./#papers"
    }
  };
  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  const targetUrl = new URL(event.notification.data?.url || "./#papers", self.location.href).href;
  event.waitUntil(
    clients.matchAll({ type: "window", includeUncontrolled: true }).then((clientList) => {
      for (const client of clientList) {
        if (client.url.startsWith(self.registration.scope) && "focus" in client) {
          client.navigate(targetUrl);
          return client.focus();
        }
      }
      return clients.openWindow(targetUrl);
    })
  );
});
