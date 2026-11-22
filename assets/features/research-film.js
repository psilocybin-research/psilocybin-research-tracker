// Playback retains the original soundtrack, frames, date and accessibility behavior.
export function init({ ICONS, showAppToast, updateModalOpenState }) {
function initResearchFilm() {
  const portrait = window.matchMedia('(max-width: 1023px)');
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
  document.querySelectorAll('[data-research-film]').forEach(figure => {
    const video = figure.querySelector('video');
    if (!video) return; // Offline downloads deliberately omit film playback.
    const toggle = figure.querySelector('[data-film-toggle]');
    const sound = figure.querySelector('[data-film-sound]');
    const status = figure.querySelector('.research-film-status');
    const surface = video.parentElement;
    const download = figure.querySelector('[data-film-download]');
    const downloadModal = figure.querySelector('[data-film-download-modal]');
    if (download && downloadModal && typeof downloadModal.showModal === 'function') {
      const confirmDownload = downloadModal.querySelector('[data-film-download-confirm]');
      const cancelDownload = downloadModal.querySelector('[data-film-download-cancel]');
      const updateDownloadDetails = () => {
        const mobileFile = download.href === new URL(video.dataset.mobile, location.href).href;
        confirmDownload.href = download.href;
        const dimensions = mobileFile ? '1080 × 1440' : '1920 × 1080';
        const bytes = Number(mobileFile ? downloadModal.dataset.mobileBytes : downloadModal.dataset.desktopBytes);
        const size = Number.isFinite(bytes) && bytes > 0 ? ` · ${(bytes / 1000000).toLocaleString('en', { minimumFractionDigits: 1, maximumFractionDigits: 1 })} MB` : '';
        downloadModal.querySelector('[data-film-download-description]').textContent = `${mobileFile ? 'Portrait' : 'Landscape'} · ${dimensions} · 46 seconds · MP4${size}`;
      };
      download.addEventListener('click', event => {
        event.preventDefault();
        updateDownloadDetails();
        downloadModal.showModal();
        updateModalOpenState();
      });
      cancelDownload.addEventListener('click', () => downloadModal.close());
      confirmDownload.addEventListener('click', () => downloadModal.close());
      downloadModal.addEventListener('close', () => { updateModalOpenState(); download.focus({ preventScroll: true }); });
      downloadModal.addEventListener('click', event => {
        if (event.target !== downloadModal) return;
        const r = downloadModal.getBoundingClientRect();
        if (event.clientX < r.left || event.clientX > r.right || event.clientY < r.top || event.clientY > r.bottom) downloadModal.close();
      });
    }
    const share = figure.querySelector('[data-film-share]');
    if (share) {
      share.hidden = false;
      share.setAttribute('aria-label', 'Share page link');
      share.addEventListener('click', async () => {
        share.disabled = true;
        share.setAttribute('aria-busy', 'true');
        const url = new URL('/research-in-motion/', window.location.origin).href;
        const copyLink = async () => {
          try {
            await navigator.clipboard.writeText(url);
            showAppToast('Page link copied.');
          } catch {
            window.prompt('Copy this page link to share:', url);
            status.textContent = '';
          }
        };
        try {
          if (typeof navigator.share === 'function' && window.isSecureContext) {
            try {
              await navigator.share({ title: 'Psilocybin Research in motion', text: 'Explore psilocybin research sources, topics and trial registrations.', url });
              status.textContent = '';
            } catch (error) { if (error.name !== 'AbortError') await copyLink(); }
          } else await copyLink();
        } finally { share.disabled = false; share.removeAttribute('aria-busy'); }
      });
    }
    const fullscreen = figure.querySelector('[data-film-fullscreen]');
    const chapterMenu = figure.querySelector('.research-film-chapter-menu');
    const exploration = figure.querySelector('[data-film-exploration]');
    const openExplorationFragment = () => {
      if (exploration && ['#topic-counts', '#venue-directory'].includes(location.hash)) exploration.open = true;
    };
    openExplorationFragment();
    window.addEventListener('hashchange', openExplorationFragment);
    document.addEventListener('pointerdown', event => { if (chapterMenu?.open && !chapterMenu.contains(event.target)) chapterMenu.open = false; });
    figure.addEventListener('keydown', event => { if (event.key === 'Escape' && chapterMenu?.open) { chapterMenu.open = false; chapterMenu.querySelector('summary').focus(); } });
    const meter = figure.querySelector('[data-film-audio-meter]');
    const AudioAPI = window.AudioContext || window.webkitAudioContext;
    let transportContext = null, meterAudioContext = null, cueGeneration = 0, lastCue = -Infinity;
    if (meter && window.requestAnimationFrame) {
      const canvas=meter.querySelector('canvas'),pen=canvas.getContext('2d');
      if(pen){
        let spectrum=null,frame=null,lastDraw=0,meterVisible=true,width=0,height=12,dpr=1;
        const levels=new Float32Array(80);
        const controls=figure.querySelector('.research-film-controls');
        const theme=getComputedStyle(figure);
        const forest=theme.getPropertyValue('--green').trim()||'#123c31';
        const brass='#9b7b4d';
        const audible=()=>!video.paused&&!video.muted&&video.volume>.001&&!reduced.matches;
        const cancel=()=>{if(frame!==null)cancelAnimationFrame(frame);frame=null;};
        const draw=(time=0)=>{
          frame=null;
          if(!width||!spectrum||document.hidden||!meterVisible)return;
          if(time-lastDraw>=1000/30||time===0){
            const delta=lastDraw?Math.min(.1,Math.max(.001,(time-lastDraw)/1000)):1/30;lastDraw=time;
            const active=audible();
            const position=Math.min(spectrum.frames-1,Math.max(0,video.currentTime*spectrum.fps));
            const first=Math.floor(position),second=Math.min(spectrum.frames-1,first+1),mix=position-first;
            pen.setTransform(dpr,0,0,dpr,0,0);pen.clearRect(0,0,width,height);
            pen.lineCap='round';pen.lineWidth=1.2;
            const padding=5,step=(width-padding*2)/80;
            for(let band=0;band<80;band++){
              const value=(spectrum.values[first*80+band]*(1-mix)+spectrum.values[second*80+band]*mix)/255;
              const target=active?value*video.volume:0;
              // Faster attack, longer release: responsive needles with a quiet decay.
              const tau=target>levels[band]?.035:.16;
              levels[band]+=(target-levels[band])*(1-Math.exp(-delta/tau));
              if(reduced.matches)levels[band]=0;
              const needle=.7+Math.min(1,levels[band])*(height-3);
              const x=padding+(band+.5)*step;
              pen.strokeStyle=band>=70?brass:forest;
              pen.globalAlpha=.22+levels[band]*.68;
              pen.beginPath();pen.moveTo(x,(height-needle)/2);pen.lineTo(x,(height+needle)/2);pen.stroke();
            }
            pen.globalAlpha=1;
          }
          if(!reduced.matches&&(audible()||levels.some(level=>level>.003)))frame=requestAnimationFrame(draw);
        };
        const pump=()=>{if(frame===null&&spectrum&&!document.hidden&&meterVisible)frame=requestAnimationFrame(draw);};
        const reset=()=>{cancel();levels.fill(0);lastDraw=0;draw();};
        const resize=()=>{
          const nextWidth=controls?.offsetWidth||344;
          meter.style.width=`${nextWidth}px`;
          width=meter.getBoundingClientRect().width||nextWidth;
          height=canvas.getBoundingClientRect().height||12;dpr=Math.min(2,window.devicePixelRatio||1);
          canvas.width=Math.max(1,Math.round(width*dpr));canvas.height=Math.max(1,Math.round(height*dpr));
          cancel();lastDraw=0;draw();
        };
        fetch('/assets/media/research-spectrum-2026-11-01/spectrum-80.json',{cache:'force-cache'})
          .then(response=>{if(!response.ok)throw Error();return response.json();})
          .then(data=>{
            if(data.version!==1||data.bands!==80||data.fps!==24||data.frames!==1105||data.values.length>130000)throw Error();
            const values=Uint8Array.from(atob(data.values),value=>value.charCodeAt(0));
            if(values.length!==data.frames*80)throw Error();
            spectrum={frames:data.frames,fps:data.fps,values};meter.hidden=false;figure.classList.add('has-audio-meter');resize();pump();
          }).catch(()=>{meter.hidden=true;});
        video.addEventListener('playing',pump);
        video.addEventListener('pause',pump);video.addEventListener('ended',pump);
        video.addEventListener('volumechange',pump);video.addEventListener('seeked',reset);
        video.addEventListener('loadedmetadata',resize);
        reduced.addEventListener('change',reset);
        document.addEventListener('visibilitychange',()=>{if(document.hidden){cancel();levels.fill(0);}else{lastDraw=0;pump();}});
        if('ResizeObserver' in window)new ResizeObserver(resize).observe(controls);else window.addEventListener('resize',resize);
        document.addEventListener('fullscreenchange',()=>requestAnimationFrame(resize));
        if('IntersectionObserver' in window)new IntersectionObserver(entries=>{meterVisible=entries[0].isIntersecting;if(meterVisible){lastDraw=0;pump();}else{cancel();levels.fill(0);}},{threshold:0}).observe(meter);
        window.addEventListener('pagehide',cancel);window.addEventListener('pageshow',pump);
        resize();
      }
    }
    let backdrop = null;
    const syncBackdrop = () => {
      if (!backdrop) return;
      if (backdrop.src !== video.currentSrc) { backdrop.src = video.currentSrc; backdrop.load(); }
      if (Number.isFinite(video.currentTime) && Math.abs(backdrop.currentTime - video.currentTime) > .15) backdrop.currentTime = video.currentTime;
      if (video.paused) backdrop.pause(); else backdrop.play().catch(() => {});
    };
    if (fullscreen && document.fullscreenEnabled && figure.requestFullscreen) {
      fullscreen.hidden = false;
      fullscreen.addEventListener('click', async () => {
        try { if (document.fullscreenElement === figure) await document.exitFullscreen(); else await figure.requestFullscreen(); } catch {}
      });
      document.addEventListener('fullscreenchange', () => {
        const active = document.fullscreenElement === figure;
        fullscreen.innerHTML = `<span aria-hidden="true">${ICONS[active ? 'minimize' : 'maximize']}</span>`;
        fullscreen.setAttribute('aria-label', active ? 'Exit fullscreen' : 'Enter fullscreen');
        fullscreen.title = active ? 'Exit fullscreen' : 'Enter fullscreen';
        if (active) {
          backdrop = document.createElement('video');
          backdrop.className = 'research-film-blur'; backdrop.muted = true; backdrop.defaultMuted = true;
          backdrop.playsInline = true; backdrop.loop = video.loop; backdrop.tabIndex = -1;
          backdrop.setAttribute('aria-hidden', 'true'); surface.prepend(backdrop); syncBackdrop();
        } else if (backdrop) { backdrop.pause(); backdrop.removeAttribute('src'); backdrop.load(); backdrop.remove(); backdrop = null; }
      });
      ['play','pause','seeked','timeupdate'].forEach(event => video.addEventListener(event, syncBackdrop));
    }

    let visible = false;
    // Play once by default, including for visitors with retired loop preferences.
    video.loop = false;
    const progressIndicator = figure.querySelector('[data-film-progress-indicator]');
    if (progressIndicator) {
      const ring = progressIndicator.querySelector('[data-film-progress]');
      const updateProgress = () => {
        const percent = Number.isFinite(video.duration) && video.duration > 0
          ? Math.min(100, Math.max(0, video.currentTime / video.duration * 100)) : 0;
        ring.style.strokeDashoffset = String(100 - percent);
        progressIndicator.setAttribute('aria-valuenow', String(Math.round(percent)));
      };
      ['timeupdate', 'loadedmetadata', 'seeked', 'emptied'].forEach(event => video.addEventListener(event, updateProgress));
      progressIndicator.hidden = false;
      updateProgress();
    }
    let intentToPlay = false; // Playback begins only after a video-control interaction.
    let loaded = false;
    let generation = 0;
    let pendingTime = 0;
    let stopTime = null;
    let slideGeneration = 0, slideFadePending = false;
    let frameRequest = null;
    let explicitlyMuted = false;
    const mutePreference = 'psilocybin-research-film-muted';
    try { explicitlyMuted = localStorage.getItem(mutePreference) === '1'; } catch {}
    video.muted = true;
    toggle.hidden = false;
    sound.hidden = false;
    let audioAutoplayAttempted = false;
    let waitingForSoundInteraction = false;
    let audioFade = null, scrollActive = false;
    let firstAudibleFade = true, userAudioActivated = false;
    // A real audible autoplay attempt must start at audible volume. Starting at
    // zero can pass autoplay checks and then be paused when a fade raises it.
    video.volume = 1;
    const resumeFilmAudio = () => {
      for (const context of new Set([meterAudioContext, transportContext])) {
        if (context && ['suspended', 'interrupted'].includes(context.state)) context.resume().catch(() => {});
      }
    };
    const fadeVolume = (value, duration = 1.0, done) => {
      audioFade?.kill();
      if (window.gsap) audioFade = gsap.to(video, { volume: value, duration, ease: 'sine.inOut', onComplete: done });
      else {
        const from = video.volume, start = performance.now();
        let frame = null, cancelled = false;
        audioFade = { kill() { cancelled = true; if (frame !== null) cancelAnimationFrame(frame); } };
        const step = now => {
          if (cancelled) return;
          const progress = Math.min(1, (now - start) / (duration * 1000));
          video.volume = from + (value - from) * (.5 - .5 * Math.cos(Math.PI * progress));
          if (progress < 1) frame = requestAnimationFrame(step); else done?.();
        };
        frame = requestAnimationFrame(step);
      }
    };
    const softPause = done => fadeVolume(0, .85, () => { video.pause(); done?.(); });
    const fadeSoundIn = () => {
      if (video.paused || video.muted || explicitlyMuted) return;
      fadeVolume(1, userAudioActivated ? .35 : firstAudibleFade ? 3.2 : 1.25, () => { firstAudibleFade = false; });
    };
    const transportCue = async playing => {
      const request = ++cueGeneration;
      if (!AudioAPI || explicitlyMuted || video.muted) return;
      try {
        if (!transportContext || transportContext.state === 'closed') transportContext = new AudioAPI();
        const context = transportContext;
        if (context.state === 'suspended') await context.resume();
        if (context.state !== 'running' || request !== cueGeneration || explicitlyMuted || video.muted) return;
        if (context.currentTime - lastCue < .22) return;
        lastCue = context.currentTime;
        const start = context.currentTime + .005, duration = .16;
        const tone = context.createOscillator(), envelope = context.createGain();
        const filter = context.createBiquadFilter(), delay = context.createDelay(.1), wet = context.createGain();
        // A3/D4 fit the original score; a quiet 1/32-beat echo adds a little space.
        tone.type = 'sine';
        tone.frequency.setValueAtTime(playing ? 220 : 293.6648, start);
        tone.frequency.exponentialRampToValueAtTime(playing ? 293.6648 : 220, start + .11);
        envelope.gain.setValueAtTime(.00001, start);
        envelope.gain.exponentialRampToValueAtTime(.026, start + .014);
        envelope.gain.exponentialRampToValueAtTime(.00001, start + duration);
        filter.type = 'lowpass'; filter.frequency.value = 1200; filter.Q.value = .5;
        delay.delayTime.value = .0625; wet.gain.value = .1;
        tone.connect(envelope); envelope.connect(filter); filter.connect(context.destination);
        filter.connect(delay); delay.connect(wet); wet.connect(context.destination);
        tone.onended = () => setTimeout(() => {
          [tone, envelope, filter, delay, wet].forEach(node => node.disconnect());
        }, 100);
        tone.start(start); tone.stop(start + duration + .005);
      } catch { /* Optional feedback must never interrupt playback. */ }
    };
    const updateSound = () => {
      sound.innerHTML = `<span aria-hidden="true">${ICONS[video.muted ? 'volume-x' : 'volume-2']}</span>`;
      const label = video.muted ? 'Enable original soundtrack' : 'Mute original soundtrack';
      sound.setAttribute('aria-label', label);
      sound.title = label;
      sound.setAttribute('aria-pressed', String(!video.muted));
      video.setAttribute('aria-label', explicitlyMuted ? 'Play research animation' : 'Play research animation with sound');
      video.removeAttribute('title');
    };
    sound.addEventListener('click', () => {
      audioAutoplayAttempted = true;
      const enable = explicitlyMuted || video.muted;
      explicitlyMuted = !enable;
      try { if (enable) localStorage.removeItem(mutePreference); else localStorage.setItem(mutePreference, '1'); } catch {}
      if (!enable) { audioFade?.kill(); video.muted = true; video.volume = 0; updateSound(); }
      else { intentToPlay = true; enableSound(true); if (!loaded) chooseSource(pendingTime, true); play(); }
    });
    const updateButton = () => {
      toggle.innerHTML = `<span aria-hidden="true">${ICONS[video.paused ? 'play' : 'pause']}</span>`;
      const label = video.paused ? 'Play research animation' : 'Pause research animation';
      toggle.setAttribute('aria-label', label);
      toggle.title = label;
    };
    const play = () => {
      if (document.hidden || !intentToPlay || waitingForSoundInteraction) return;
      if (explicitlyMuted) video.muted = true;
      const attempt = !audioAutoplayAttempted && !explicitlyMuted;
      const currentGeneration = generation;
      if (attempt) { audioAutoplayAttempted = true; video.muted = false; updateSound(); }
      if (video.paused) { audioFade?.kill(); if (!explicitlyMuted) video.volume = 1; }
      if (navigator.userActivation?.isActive) resumeFilmAudio();
      video.play().catch(error => {
        if (currentGeneration !== generation) return;
        if (error.name === 'NotAllowedError') {
          waitingForSoundInteraction = true;
          intentToPlay = false;
          audioFade?.kill();
          video.pause();
          status.textContent = 'Tap Play to start the video with sound.';
          updateSound();
        }
        updateButton();
      });
    };
    updateSound();
    const chooseSource = (time = 0, load = false) => {
      video.pause();
      const thisGeneration = ++generation;
      pendingTime = time;
      if (download) download.href = portrait.matches ? video.dataset.mobile : video.dataset.desktop;
      video.poster = portrait.matches ? video.dataset.mobilePoster : video.dataset.desktopPoster;
      video.width = portrait.matches ? 1080 : 1920;
      video.height = portrait.matches ? 1440 : 1080;
      if (!load) return;
      loaded = true;
      video.preload = intentToPlay ? 'auto' : 'metadata';
      video.src = portrait.matches ? video.dataset.mobile : video.dataset.desktop;
      video.addEventListener('loadedmetadata', () => {
        if (thisGeneration !== generation) return;
        video.currentTime = Math.min(pendingTime, Math.max(0, video.duration - 0.05));
        pendingTime = 0;
        play();
      }, { once: true });
      video.load();
    };
    const seek = (time, shouldPlay = false) => {
      if (shouldPlay) intentToPlay = true;
      if (!loaded || video.readyState < 1) chooseSource(time, true);
      else { video.currentTime = Math.min(time, video.duration - 0.05); if (shouldPlay) play(); }
    };
    const revealSlide = (time, hold) => {
      const selection = ++slideGeneration;
      stopTime = null;
      const reveal = () => {
        if (selection !== slideGeneration) return;
        stopTime = hold;
        slideFadePending = true;
        seek(time, true);
      };
      if (!video.paused && !video.muted && video.volume > .001) {
        fadeVolume(0, .14);
        setTimeout(reveal, 140);
      } else reveal();
    };
    const enableSound = (explicit = false) => {
      if (explicitlyMuted && !explicit) return;
      if (explicit) {
        waitingForSoundInteraction = false;
        explicitlyMuted = false;
        userAudioActivated = true;
        try { localStorage.removeItem(mutePreference); } catch {}
      }
      // Resume synchronously within the gesture, before metadata/play promises.
      resumeFilmAudio();
      audioAutoplayAttempted = true;
      video.muted = false;
      if (video.paused) video.volume = 1;
      updateSound();
      fadeSoundIn();
    };
    const invert = figure.querySelector('[data-film-invert]');
    if (invert) {
      invert.hidden = false;
      invert.addEventListener('click', () => {
        const inverted = surface.classList.toggle('is-inverted');
        invert.setAttribute('aria-pressed', String(inverted));
        invert.title = inverted ? 'Restore original colours' : 'Invert video colours';
      });
    }
    const playWithSound = () => {
      slideGeneration++; slideFadePending = false;
      stopTime = null;
      intentToPlay = true;
      waitingForSoundInteraction = false;
      enableSound();
      if (!loaded) chooseSource(pendingTime, true);
      play();
    };
    video.tabIndex = 0;
    video.setAttribute('role', 'button');
    updateSound();
    video.addEventListener('click', playWithSound);
    video.addEventListener('keydown', event => {
      if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); playWithSound(); }
    });
    const seekFragment = () => {
      const match = /^#t=(\d+(?:\.\d+)?)$/.exec(window.location.hash);
      if (match) {
        const time = Math.max(0, Number(match[1]));
        intentToPlay = false;
        seek(time); // Shared timestamps select a frame without starting playback.
      }
    };
    chooseSource();
    seekFragment();
    window.addEventListener('hashchange', seekFragment);
    toggle.addEventListener('click', () => {
      slideGeneration++; slideFadePending = false;
      stopTime = null;
      if (!video.paused) { intentToPlay = false; transportCue(false); softPause(); }
      else { intentToPlay = true; waitingForSoundInteraction = false; enableSound(); if (!loaded) chooseSource(pendingTime, true); play(); transportCue(true); }
      updateButton();
    });
    document.querySelectorAll('[data-film-time]').forEach(link => link.addEventListener('click', event => {
      event.preventDefault();
      link.closest('details')?.removeAttribute('open');
      figure.querySelector('.research-film-chapter-menu summary')?.focus({ preventScroll: true });
      history.replaceState(null, '', link.getAttribute('href'));
      figure.scrollIntoView({ behavior: reduced.matches ? 'instant' : 'smooth', block: 'start' });
      if (explicitlyMuted) {
        // Slide navigation preserves the sound choice, including an unfinished mute fade.
        waitingForSoundInteraction = false;
        userAudioActivated = true;
        audioFade?.kill();
        video.muted = true;
        video.volume = 0;
        updateSound();
      } else enableSound();
      revealSlide(Number(link.dataset.filmTime), Number(link.dataset.filmStop));
      play();
    }));
    portrait.addEventListener('change', () => chooseSource(pendingTime || video.currentTime, loaded));
    reduced.addEventListener('change', () => {
      if (reduced.matches) { intentToPlay = false; video.pause(); }
    });
    document.addEventListener('visibilitychange', () => { if (document.hidden) softPause(); else play(); });
    if ('IntersectionObserver' in window) new IntersectionObserver(entries => {
      visible = entries[0].isIntersecting;
      if (visible && intentToPlay) { if (!loaded) chooseSource(pendingTime, true); else play(); }
    }, { threshold: 0.15 }).observe(video);
    else { visible = true; if (intentToPlay) chooseSource(pendingTime, true); }
    const stopAtFullView = () => {
      if (stopTime === null || video.seeking || video.currentTime < Math.max(0, stopTime - .85)) return;
      const hold = stopTime;
      stopTime = null;
      intentToPlay = false;
      fadeVolume(0, Math.min(.85, Math.max(.01, hold - video.currentTime)), () => { video.pause(); video.currentTime = hold; });
    };
    const checkFrame = () => {
      frameRequest = null;
      stopAtFullView();
      if (!video.paused) frameRequest = video.requestVideoFrameCallback(checkFrame);
    };
    video.addEventListener('play', () => {
      updateButton();
      if ('requestVideoFrameCallback' in video && frameRequest === null) frameRequest = video.requestVideoFrameCallback(checkFrame);
    });
    video.addEventListener('pause', () => {
      if (frameRequest !== null) { video.cancelVideoFrameCallback(frameRequest); frameRequest = null; }
    });
    video.addEventListener('timeupdate', stopAtFullView);
    video.addEventListener('seeked', () => {
      if (intentToPlay && !scrollActive) {
        play();
        if (slideFadePending) { slideFadePending = false; fadeSoundIn(); }
      }
    });
    video.addEventListener('canplay', () => { if (intentToPlay) play(); });
    video.addEventListener('ended', () => { if (!video.loop) intentToPlay = false; });
    video.addEventListener('pause', updateButton);
    video.addEventListener('error', () => {
      intentToPlay = false;
      status.textContent = 'Read the exact data, or use the MP4 download links.';
      updateButton();
    });
    video.addEventListener('playing', () => { status.textContent = ''; if (intentToPlay) fadeSoundIn(); });
    updateButton();
  });
  const search = document.querySelector('[data-film-venue-search]');
  if (search) {
    const rows = [...document.querySelectorAll('#film-venue-list li')];
    const labels = rows.map(row => row.textContent.toLocaleLowerCase());
    const count = document.querySelector('[data-film-venue-count]');
    search.addEventListener('input', () => {
      const query = search.value.trim().toLocaleLowerCase(); let matches = 0;
      rows.forEach((row, i) => { row.hidden = !labels[i].includes(query); if (!row.hidden) matches++; });
      count.textContent = matches ? `${matches.toLocaleString()} matching venue ${matches === 1 ? 'label' : 'labels'}` : 'No matching venues. Try a shorter journal name.';
    });
  }
}



// Native details and checkboxes preserve keyboard and touch behavior.

  initResearchFilm();
  document.querySelectorAll('.research-film').forEach(figure => {
    const video = figure.querySelector('video:not(.research-film-blur)');
    const links = [...figure.querySelectorAll('.research-film-chapter-menu [data-film-time]')];
    if (!video || !links.length) return;
    const updateCurrentSlide = () => {
      const time = video.currentTime;
      links.forEach(link => {
        const current = time >= Number(link.dataset.filmTime) && time < Number(link.dataset.filmEnd);
        if (current) link.setAttribute('aria-current', 'true');
        else link.removeAttribute('aria-current');
      });
    };
    ['timeupdate', 'seeking', 'seeked', 'loadedmetadata', 'emptied'].forEach(event => video.addEventListener(event, updateCurrentSlide));
    updateCurrentSlide();
  });
  return {};
}
