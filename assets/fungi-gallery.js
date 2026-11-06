(() => {
  'use strict';
  const gallery = document.getElementById('gallery');
  const {slides} = JSON.parse(document.getElementById('gallery-data').textContent);
  const layers = [...gallery.querySelectorAll('.photograph')];
  const byId = id => document.getElementById(id);
  const reduced = matchMedia('(prefers-reduced-motion: reduce)');
  const thumbnails = byId('thumbnails'), browse = byId('browse'), play = byId('play');
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
    byId('description').textContent = slide.alt;
    byId('source-link').href = slide.source;
    byId('counter').innerHTML = String(index + 1).padStart(2, '0') + ' <span aria-hidden="true">/</span> ' + String(slides.length).padStart(2, '0');
    byId('counter').setAttribute('aria-label', 'Photograph ' + (index + 1) + ' of ' + slides.length);
    thumbnails.querySelectorAll('button').forEach((button, i) => button.setAttribute('aria-current', String(i === index)));
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
  byId('next').onclick = () => { wake(); show(index + 1); };
  byId('previous').onclick = () => { wake(); show(index - 1); };
  play.onclick = togglePlay;
  function setBrowse(open) {
    thumbnails.hidden = !open; browse.setAttribute('aria-expanded', String(open)); schedule(); wake();
  }
  browse.onclick = () => setBrowse(thumbnails.hidden);
  thumbnails.onclick = event => {
    const button = event.target.closest('button[data-index]'); if (!button) return;
    show(Number(button.dataset.index)); setBrowse(false); browse.focus();
  };
  byId('fit').onclick = () => {
    const fill = gallery.classList.toggle('fill');
    byId('fit').setAttribute('aria-pressed', String(fill));
    const label = fill ? 'Show the whole photograph' : 'Fill screen with photograph';
    byId('fit').setAttribute('aria-label', label); byId('fit').title = label; wake();
  };
  const requestName = ['requestFullscreen', 'webkitRequestFullscreen'].find(name => typeof gallery[name] === 'function');
  const exitName = ['exitFullscreen', 'webkitExitFullscreen'].find(name => typeof document[name] === 'function');
  const full = byId('fullscreen');
  if (requestName && exitName) {
    full.hidden = false;
    full.onclick = async () => {
      try {
        if (document.fullscreenElement || document.webkitFullscreenElement) await document[exitName]();
        else await gallery[requestName]();
      } catch { byId('announcement').textContent = 'Full screen is unavailable in this browser.'; }
      wake();
    };
    const updateFull = () => full.setAttribute('aria-label', document.fullscreenElement || document.webkitFullscreenElement ? 'Exit full screen' : 'Enter full screen');
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
    wake(); if (event.target.closest('button,a,.thumbnails')) return;
    touchStart = {x: event.clientX, y: event.clientY, type: event.pointerType};
  }, {passive: true});
  gallery.addEventListener('pointerup', event => {
    if (!touchStart || touchStart.type !== 'touch') return;
    const dx = event.clientX - touchStart.x, dy = event.clientY - touchStart.y; touchStart = null;
    if (Math.abs(dx) > 55 && Math.abs(dx) > Math.abs(dy) * 1.5) show(index + (dx < 0 ? 1 : -1));
  }, {passive: true});
  document.addEventListener('visibilitychange', schedule);
  reduced.addEventListener('change', () => { if (reduced.matches) { playing = false; syncPlay(); wake(); } });
  update(false); syncPlay(); wake();
})();
