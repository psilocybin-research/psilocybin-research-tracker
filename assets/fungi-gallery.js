(() => {
  'use strict';
  const gallery = document.getElementById('gallery');
  const {slides} = JSON.parse(document.getElementById('gallery-data').textContent);
  const layers = [...gallery.querySelectorAll('.photograph')];
  const byId = id => document.getElementById(id);
  const reduced = matchMedia('(prefers-reduced-motion: reduce)');
  const thumbnails = byId('thumbnails'), browse = byId('browse'), play = byId('play');
  const controls = gallery.querySelector('.gallery-controls');
  const blendedControls = controls.cloneNode(true);
  blendedControls.classList.add('gallery-controls-blended');
  blendedControls.setAttribute('aria-hidden', 'true'); blendedControls.inert = true;
  blendedControls.removeAttribute('role'); blendedControls.removeAttribute('aria-label');
  function mirrorControls() {
    blendedControls.innerHTML = controls.innerHTML;
    blendedControls.querySelectorAll('[id]').forEach(node => node.removeAttribute('id'));
    blendedControls.querySelectorAll('button,a,[tabindex]').forEach(node => node.setAttribute('tabindex', '-1'));
  }
  mirrorControls(); controls.after(blendedControls);
  new MutationObserver(mirrorControls).observe(controls, {subtree: true, attributes: true, childList: true, characterData: true});

  let index = 0, active = 0, playing = !reduced.matches, busy = false, queued = null, timer, quietTimer, touchStart;
  const prepared = new Map();
  const pausePath = 'M8 5v14M16 5v14', playPath = 'm9 5 11 7-11 7Z';
  const variant = slide => {
    const target = Math.min(2560, Math.max(innerWidth, innerHeight) * Math.min(devicePixelRatio || 1, 1.5));
    return slide.variants.find(v => v.width >= target) || slide.variants.at(-1);
  };
  function prepare(position) {
    const slide = slides[position], url = variant(slide).url;
    if (!prepared.has(url)) {
      const promise = new Promise((resolve, reject) => {
        const image = new Image(); image.onload = () => resolve(url); image.onerror = reject; image.src = url;
      }).catch(error => { prepared.delete(url); throw error; });
      prepared.set(url, promise);
    }
    return prepared.get(url);
  }
  function schedule() {
    clearTimeout(timer);
    if (playing && !document.hidden && thumbnails.hidden && !busy) timer = setTimeout(() => show(index + 1, false), 8000);
  }
  function syncPlay() {
    play.setAttribute('aria-label', playing ? 'Pause slideshow' : 'Play slideshow');
    play.title = playing ? 'Pause slideshow' : 'Play slideshow';
    byId('play-path').setAttribute('d', playing ? pausePath : playPath);
    byId('play-path').style.fill = playing ? 'none' : 'currentColor';
    schedule();
  }
  function wake() {
    gallery.classList.remove('quiet'); clearTimeout(quietTimer);
    if (playing && thumbnails.hidden && !reduced.matches) quietTimer = setTimeout(() => {
      if (!gallery.matches(':focus-within') && thumbnails.hidden) gallery.classList.add('quiet');
    }, 5000);
  }
  function update(manual) {
    const slide = slides[index];
    byId('source-link').href = slide.source;
    thumbnails.querySelectorAll('button').forEach((button, i) => button.setAttribute('aria-current', String(i === index)));
    gallery.querySelectorAll('[data-slide-index]').forEach((button, i) => button.setAttribute('aria-current', String(i === index)));
    if (manual) byId('announcement').textContent = 'Photograph ' + (index + 1) + ' of ' + slides.length + ': ' + slide.alt;
    gallery.dataset.slide = String(index);
    prepare((index + 1) % slides.length).catch(() => {});
  }
  async function show(position, manual = true) {
    const next = (position + slides.length) % slides.length;
    if (busy) { queued = {position: next, manual}; return; }
    if (next === index) { schedule(); return; }
    busy = true; clearTimeout(timer); byId('load-error').hidden = true;
    try {
      const url = await prepare(next), incoming = 1 - active, image = layers[incoming].querySelector('.picture');
      layers[incoming].querySelector('.backdrop').src = url;
      image.style.objectPosition = slides[next].position || '50% 50%';
      image.src = url; image.removeAttribute('srcset');
      await image.decode().catch(() => {});
      await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
      layers[incoming].classList.add('active'); layers[active].classList.remove('active');
      active = incoming; index = next; update(manual);
      await new Promise(resolve => setTimeout(resolve, reduced.matches ? 0 : 1300));
    } catch {
      byId('load-error').hidden = false; playing = false; syncPlay(); wake();
    } finally {
      busy = false;
      if (queued) { const request = queued; queued = null; show(request.position, request.manual); }
      else schedule();
    }
  }
  const togglePlay = () => { playing = !playing; syncPlay(); wake(); };
  gallery.querySelector('.gallery-dots').onclick = event => {
    const button = event.target.closest('[data-slide-index]');
    if (button) { wake(); show(Number(button.dataset.slideIndex)); }
  };
  byId('next').onclick = () => { wake(); show(index + 1); };
  byId('previous').onclick = () => { wake(); show(index - 1); };
  play.onclick = togglePlay;
  function setBrowse(open) {
    const restoreFocus = !open && thumbnails.contains(document.activeElement);
    thumbnails.hidden = !open;
    browse.setAttribute('aria-expanded', String(open));
    const label = open ? 'Close photographs' : 'Browse photographs';
    browse.setAttribute('aria-label', label); browse.title = label;
    browse.querySelector('path').setAttribute('d', open ? 'm6 6 12 12M18 6 6 18' : 'M3 3h7v7H3zm11 0h7v7h-7zM3 14h7v7H3zm11 0h7v7h-7z');
    if (open) thumbnails.querySelector('[aria-current=true]')?.focus({preventScroll: true});
    else if (restoreFocus) browse.focus({preventScroll: true});
    schedule(); wake();
  }
  browse.onclick = () => setBrowse(thumbnails.hidden);
  thumbnails.onclick = event => {
    const button = event.target.closest('button[data-index]'); if (!button) return;
    show(Number(button.dataset.index)); setBrowse(false); browse.focus();
  };
  const requestName = ['requestFullscreen', 'webkitRequestFullscreen'].find(name => typeof gallery[name] === 'function');
  const exitName = ['exitFullscreen', 'webkitExitFullscreen'].find(name => typeof document[name] === 'function');
  const full = byId('fullscreen');
  const fullscreenEnabled = document.fullscreenEnabled ?? document.webkitFullscreenEnabled;
  if (requestName && exitName && fullscreenEnabled !== false) {
    full.hidden = false;
    full.onclick = async () => {
      try {
        if (document.fullscreenElement || document.webkitFullscreenElement) await document[exitName]();
        else await gallery[requestName]();
        if (!full.matches(':focus-visible')) full.blur();
      } catch { byId('announcement').textContent = 'Full screen is unavailable in this browser.'; }
      wake();
    };
    const updateFull = () => {
      const isFull = Boolean(document.fullscreenElement || document.webkitFullscreenElement);
      const label = isFull ? 'Exit full screen' : 'Enter full screen';
      full.setAttribute('aria-label', label); full.title = label;
      full.setAttribute('aria-pressed', String(isFull));
      full.querySelector('path').setAttribute('d', isFull ? 'M3 8h5V3m8 0v5h5m0 8h-5v5M8 21v-5H3' : 'M8 3H3v5m13-5h5v5m0 8v5h-5M3 16v5h5');
      wake();
    };
    updateFull();
    document.addEventListener('fullscreenchange', updateFull); document.addEventListener('webkitfullscreenchange', updateFull);
  }
  document.addEventListener('keydown', event => {
    if (event.altKey || event.ctrlKey || event.metaKey || event.target.matches('input,textarea,select')) return;
    if (['ArrowRight', 'ArrowLeft'].includes(event.key) && !event.target.closest('.thumbnails')) {
      event.preventDefault(); show(index + (event.key === 'ArrowRight' ? 1 : -1));
    } else if (event.code === 'Space' && !event.target.closest('button,a')) {
      event.preventDefault(); togglePlay();
    } else if (event.key.toLowerCase() === 'f' && !full.hidden) full.click();
    else if (event.key === 'Escape' && !thumbnails.hidden) { setBrowse(false); browse.focus(); }
    wake();
  });
  gallery.addEventListener('pointermove', wake, {passive: true});
  gallery.addEventListener('focusin', wake);
  gallery.addEventListener('pointerdown', event => {
    wake();
    if (!event.isPrimary || event.button !== 0 || event.target.closest('button,a,.thumbnails,.gallery-controls')) return;
    if (!thumbnails.hidden) { setBrowse(false); touchStart = null; return; }
    touchStart = {id: event.pointerId, x: event.clientX, y: event.clientY, type: event.pointerType, time: performance.now()};
  }, {passive: true});
  gallery.addEventListener('pointerup', event => {
    if (!touchStart || touchStart.id !== event.pointerId) return;
    const start = touchStart; touchStart = null;
    if (event.target.closest('button,a,.thumbnails,.gallery-controls')) return;
    const dx = event.clientX - start.x, dy = event.clientY - start.y;
    if (start.type !== 'mouse' && Math.abs(dx) > 55 && Math.abs(dx) > Math.abs(dy) * 1.5) {
      show(index + (dx < 0 ? 1 : -1));
    } else if (Math.hypot(dx, dy) < 12 && performance.now() - start.time < 600) show(index + 1);
  }, {passive: true});
  gallery.addEventListener('pointercancel', () => { touchStart = null; }, {passive: true});
  document.addEventListener('visibilitychange', schedule);
  reduced.addEventListener('change', () => { if (reduced.matches) { playing = false; syncPlay(); wake(); } });
  update(false); syncPlay(); wake();
})();
