// Native document snapshots animate only the homepage-to-film journey.
(() => {
  const reduced = matchMedia('(prefers-reduced-motion: reduce)');
  const home = url => ['/', '/index.php'].includes(new URL(url, location.href).pathname);
  const film = url => ['/research-in-motion/', '/research-in-motion.php'].includes(new URL(url, location.href).pathname);
  const prepare = () => {
    document.documentElement.style.viewTransitionName = 'none';
    const main = document.querySelector('main');
    if (main) main.style.viewTransitionName = 'research-entry';
  };
  addEventListener('pageswap', event => {
    if (!reduced.matches && event.viewTransition && event.activation && home(location.href) && film(event.activation.entry.url)) {
      prepare();
      event.viewTransition.finished.finally(() => {
        document.documentElement.style.removeProperty('view-transition-name');
        document.querySelector('main')?.style.removeProperty('view-transition-name');
      });
    } else event.viewTransition?.skipTransition();
  });
  addEventListener('pagereveal', event => {
    const from = window.navigation?.activation?.from;
    if (!reduced.matches && event.viewTransition && from && home(from.url) && film(location.href)) {
      prepare();
      event.viewTransition.finished.finally(() => {
        document.documentElement.style.removeProperty('view-transition-name');
        document.querySelector('main')?.style.removeProperty('view-transition-name');
      });
    } else event.viewTransition?.skipTransition();
  });
})();

// Keep the slide menu aligned with the frame currently displayed.
addEventListener('DOMContentLoaded', () => {
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
});
