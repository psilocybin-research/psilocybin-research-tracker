// Reuse the site's installer, first-party install metrics and offline-library controller.
(() => {
  const button = document.querySelector('#install-page-button');
  const status = document.querySelector('[data-install-page-status]');
  const offline = document.querySelector('[data-install-offline]');
  const mode = matchMedia('(display-mode: standalone), (display-mode: minimal-ui), (display-mode: window-controls-overlay)');
  const ua = navigator.userAgent;
  const ios = /iPhone|iPad|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const macSafari = /Macintosh/.test(ua) && /Safari/.test(ua) && !/Chrome|Chromium|Edg/.test(ua);
  const device = ios ? 'ios' : /Android/.test(ua) ? 'android' : macSafari ? 'mac' : 'desktop';
  document.querySelectorAll('[data-install-device]').forEach(section => { section.open = section.dataset.installDevice === device; });
  const ready = () => {
    if (window.TrackerInstall) button.hidden = false;
    if (window.TrackerOffline?.open) offline.hidden = false;
    let pending = false;
    try { pending = Number(JSON.parse(localStorage.getItem('publicationTrackerInstallPreferenceV1') || '{}').pendingUntil || 0) > Date.now(); } catch (_) {}
    if (pending && !window.TrackerInstall?.installed) {
      button.hidden = true;
      status.textContent = 'Installation requested. Your browser will finish adding the app.';
      return;
    }
    if (mode.matches || navigator.standalone === true) {
      button.hidden = true;
      status.textContent = 'You’re using the installed app. Prepare your offline catalogue below.';
    } else if (window.TrackerInstall?.installed) {
      button.hidden = true;
      status.textContent = 'Already added on this browser. Open it from your home screen or app launcher.';
    }
  };
  button.addEventListener('click', event => {
    const webR = document.querySelector('[data-pwa-offline-webr]');
    const latex = document.querySelector('[data-pwa-offline-latex]');
    if (webR && latex) window.TrackerInstall?.selectOfflineComponents({ includeWebR: webR.checked, includeLatex: latex.checked }, 'install-page');
    window.TrackerInstall?.open(event);
  });
  offline.addEventListener('click', () => window.TrackerOffline?.open());
  window.addEventListener('appinstalled', () => {
    button.hidden = true;
    status.textContent = 'App installed. Open it from your home screen or app launcher, then download the catalogue in Offline library.';
  });
  const nativeButton = document.querySelector('#install-app');
  if (nativeButton) new MutationObserver(ready).observe(nativeButton, { attributes: true, attributeFilter: ['hidden'] });
  mode.addEventListener('change', ready);
  window.addEventListener('pageshow', ready);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) ready(); });
  ready();
  let attempts = 0;
  const timer = setInterval(() => { ready(); if (++attempts > 60 || (window.TrackerInstall && window.TrackerOffline)) clearInterval(timer); }, 250);
})();
