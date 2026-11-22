document.documentElement.classList.add("js");

// Installed Android PWAs paint their own navigation safe area in edge-to-edge mode.
function initAndroidInstalledViewport() {
  if (!/Android/i.test(navigator.userAgent)) return;
  const installed = window.matchMedia("(display-mode: standalone)");
  const update = () => {
    if (!installed.matches) return;
    const viewport = document.querySelector('meta[name="viewport"]');
    if (!viewport) return;
    const settings = viewport.content.split(',').map(item => item.trim()).filter(item => !/^viewport-fit\s*=/i.test(item));
    viewport.content = [...settings, 'viewport-fit=cover'].join(', ');
    document.documentElement.classList.add('android-installed-app');
    // Non edge-to-edge Android windows keep a light native bar outside CSS.
    // Match its theme so Chrome chooses dark, readable system buttons.
    const inset = document.createElement('span');
    inset.style.cssText = 'position:fixed;visibility:hidden;height:env(safe-area-inset-bottom,0px)';
    document.body.append(inset);
    const edgeToEdge = inset.getBoundingClientRect().height > 0;
    inset.remove();
    if (!edgeToEdge) document.querySelector('meta[name="theme-color"]')?.setAttribute('content', '#fafbf7');
  };
  update();
  installed.addEventListener?.('change', update);
}
initAndroidInstalledViewport();


function initScrollbarHoldState() {
  const root = document.documentElement;
  const release = () => root.classList.remove("scrollbar-is-held");
  window.addEventListener("pointerdown", (event) => {
    const scrollbarWidth = Math.max(0, window.innerWidth - root.clientWidth);
    const scrollbarStart = window.innerWidth - Math.max(scrollbarWidth, 18);
    if (root.scrollHeight > root.clientHeight && event.clientX >= scrollbarStart) {
      root.classList.add("scrollbar-is-held");
    }
  }, { passive: true });
  window.addEventListener("pointerup", release, { passive: true });
  window.addEventListener("pointercancel", release, { passive: true });
  window.addEventListener("blur", release);
}

initScrollbarHoldState();

const DAY_MS = 86400000;
const deferredOptionCatalog = new Map();
const heroUpdatedText = document.querySelector("#hero-updated-text");
let collapsePrimaryNavSidebarOnCompact = () => {};

let preloaderHideTimer = null;
function hidePreloader() {
  window.clearTimeout(preloaderHideTimer);
  const preloader = document.querySelector("#app-preloader");
  document.documentElement.classList.remove("is-loading");
  if (!preloader || preloader.hidden) return;
  preloader.classList.add("is-hidden");
  preloaderHideTimer = window.setTimeout(() => {
    if (preloader.classList.contains("is-hidden")) preloader.hidden = true;
  }, 260);
}

function matchingSearchKey(params) {
  const values = new URLSearchParams(params);
  const query = (values.get("search") || values.get("q") || "").trim();
  values.delete("search"); values.delete("q");
  if (query) values.set("q", query);
  ["page", "per_page", "sort"].forEach((key) => values.delete(key));
  if (!values.get("range") || values.get("range") === "all") values.delete("range");
  if (params.has("range") && params.get("range") !== "custom") { values.delete("from"); values.delete("to"); }
  if (!values.has("substances[]") && !values.has("substances")) {
    values.append("substances[]", "psilocybin"); values.append("substances[]", "psilocin");
  }
  return JSON.stringify([...values.entries()].filter(([,value]) => value !== "").sort((a,b) => a[0].localeCompare(b[0]) || a[1].localeCompare(b[1])));
}

function setPreloaderContext(title, description = "") {
  const preloader = document.querySelector("#app-preloader");
  if (!preloader) return null;
  preloader.querySelector(".preloader-title")?.replaceChildren(document.createTextNode(title));
  const detail = preloader.querySelector(".preloader-detail");
  if (detail) { detail.hidden = !description; detail.replaceChildren(document.createTextNode(description)); }
  preloader.setAttribute("aria-label", [title, description].filter(Boolean).join(" "));
  return preloader;
}

function showSearchPreloader(url) {
  const params = url.searchParams;
  window.clearTimeout(preloaderHideTimer);
  const requested = params.get("per_page") || "20";
  const all = requested === "all";
  const size = Math.min(200, Math.max(5, Number.parseInt(requested, 10) || 5));
  const format = (value) => new Intl.NumberFormat("en-US").format(value);
  const results = document.querySelector("#papers");
  const sameSelection = results && matchingSearchKey(params) === matchingSearchKey(new URLSearchParams(results.dataset.searchQuery || ""));
  const total = sameSelection ? Number(results.dataset.resultTotal) : null;
  const count = total !== null ? Math.min(size, total) : size;
  const label = all ? "Loading all matching records…" : `Loading up to ${format(count)} matching records…`;
  const descriptions = [all ? "Selection: all matching records" : `Selection: ${format(size)} records per page`, `Requested page: ${all ? 1 : Math.max(1, Number.parseInt(params.get("page") || "1", 10) || 1)}`];
  descriptions.push(total !== null ? `Last confirmed matching total: ${format(total)}` : "Calculating matching total");
  const fields = { search: "Query", q: "Query", author: "Author", journal: "Journal", topic: "Topic", study_type: "Study type", cited_doi: "Cites DOI", year: "Year", from: "From", to: "To", added_from: "Added from", added_to: "Added to", range: "Range", "substances[]": "Substances", "sources[]": "Sources", "publication_statuses[]": "Evidence" };
  for (const [key, name] of Object.entries(fields)) {
    const values = [...params.entries()].filter(([k,v])=>(k===key||k.startsWith(key+'['))&&v).flatMap(([,v])=>{try{const a=JSON.parse(v);return Array.isArray(a)?a:[v];}catch{return [v];}});
    if ((key === "from" || key === "to") && params.has("range") && params.get("range") !== "custom") continue;
    if (values.length && !(key === "q" && params.get("search"))) descriptions.push(`${name}: ${values.join(", ")}`);
  }
  const sort = {newest:"Newest first",oldest:"Oldest first",newly_added:"Recently added first"};
  descriptions.push(sort[params.get("sort")] || "Recently added first");
  const preloader = setPreloaderContext(label, descriptions.join(" · "));
  if (!preloader) return null;
  preloader.hidden = false; preloader.classList.remove("is-hidden");
  document.documentElement.classList.add("is-loading");
  return preloader;
}

window.addEventListener("load", () => { if (!document.documentElement.classList.contains("is-searching")) hidePreloader(); });
window.setTimeout(() => { if (!document.documentElement.classList.contains("is-searching")) hidePreloader(); }, 2800);

function initImmediatePageTransitions() {
  document.addEventListener("click", (event) => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const link = event.target.closest?.("a[href]");
    if (!link || link.hasAttribute("download") || link.target === "_blank") return;
    let destination;
    try {
      destination = new URL(link.href, window.location.href);
    } catch (error) {
      return;
    }
    if (destination.origin !== window.location.origin || !destination.pathname.endsWith("/citation-network.php")) return;
    if (destination.pathname === window.location.pathname && destination.search === window.location.search) return;
    if (destination.href === window.location.href) return;

    event.preventDefault();
    pendingSearchController?.abort();
    pendingSearchController = null;
    window.clearTimeout(preloaderHideTimer);
    let preloader = document.querySelector("#app-preloader");
    if (!preloader) {
      preloader = document.createElement("div");
      preloader.id = "app-preloader";
      preloader.className = "app-preloader navigation-preloader";
      preloader.setAttribute("role", "status");
      preloader.setAttribute("aria-live", "polite");
      preloader.setAttribute("aria-label", "Loading the interactive citation network");
      preloader.innerHTML = '<div class="preloader-shell"><strong class="preloader-title">Loading citation network…</strong><span class="preloader-meter" aria-hidden="true"><span></span></span></div>';
      document.body.append(preloader);
    } else {
      const title = preloader.querySelector(".preloader-title");
      if (title) title.textContent = "Loading citation network…";
      const detail = preloader.querySelector(".preloader-detail");
      if (detail) { detail.hidden = true; detail.textContent = ""; }
      preloader.setAttribute("aria-label", "Loading the interactive citation network");
    }
    window.clearTimeout(preloaderHideTimer);
    preloader.hidden = false;
    preloader.classList.remove("is-hidden");
    document.documentElement.classList.add("is-loading");
    try {
      window.sessionStorage.setItem("citationNetworkTransitionPreloaded", "1");
    } catch (error) {}
    window.requestAnimationFrame(() => window.requestAnimationFrame(() => window.location.assign(destination.href)));
  });

  window.addEventListener("pageshow", (event) => {
    if (event.persisted) hidePreloader();
  });
}

function scheduleIdleTask(callback, timeout = 1600) {
  if ("requestIdleCallback" in window) {
    window.requestIdleCallback(callback, { timeout });
    return;
  }
  window.setTimeout(callback, Math.min(timeout, 900));
}

const ICONS = {
  "user-round": '<svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/></svg>',
  "fingerprint": '<svg viewBox="0 0 24 24"><path d="M5 11a7 7 0 0 1 14 0v3M8 15v-4a4 4 0 0 1 8 0v3c0 3-1 5-2 7M11 21c1-3 1-5 1-10M5 15c0 2-.5 4-1 5M8 18l-1 4M19 18l-1 3"/></svg>',
  "more-horizontal": '<svg viewBox="0 0 24 24"><circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/></svg>',
  "globe": '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><ellipse cx="12" cy="12" rx="4" ry="9"/><path d="M3 12h18"/></svg>',
  "calendar-clock": '<svg viewBox="0 0 24 24"><rect x="3" y="5" width="14" height="15" rx="2"/><path d="M7 3v4m6-4v4M3 10h14"/><circle cx="17" cy="17" r="5"/><path d="M17 14v3l2 1"/></svg>',
  "wine": '<svg viewBox="0 0 24 24"><path d="M8 3h8l1 6a5 5 0 0 1-10 0zM12 14v7m-4 0h8M7 8h10"/></svg>',
  "heart-pulse": '<svg viewBox="0 0 24 24"><path d="M20 5a5 5 0 0 0-8 1 5 5 0 0 0-8-1c-4 5 3 11 8 15 5-4 12-10 8-15Z"/><path d="M3 12h5l2-4 3 8 2-4h6"/></svg>',
  "cigarette-off": '<svg viewBox="0 0 24 24"><path d="M4 14h12v4H4zm9 0v4m6-4v4m3-4v4M17 4c-2 3 3 3 1 6M21 3c-2 3 3 4 1 7M3 3l18 18"/></svg>',
  "coins": '<svg viewBox="0 0 24 24"><ellipse cx="9" cy="6" rx="6" ry="3"/><path d="M3 6v6c0 4 12 4 12 0V6M3 10c0 4 12 4 12 0"/><path d="M15 10c4 0 6 1 6 3v5c0 4-12 4-12 0v-3m6-1c3 1 6 0 6-1"/></svg>',
  "folders": '<svg viewBox="0 0 24 24"><path d="M3 7V4h6l2 3h9v3M6 10h6l2 3h8v8H6zM3 7v11"/></svg>',
  "chart-line": '<svg viewBox="0 0 24 24"><path d="M4 3v17h17M7 15l4-5 4 2 5-7"/><circle cx="7" cy="15" r="1"/><circle cx="11" cy="10" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="20" cy="5" r="1"/></svg>',
  "compass": '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m16 8-3 5-5 3 3-5z"/></svg>',
  "evidence-scale": '<svg viewBox="0 0 24 24"><path d="M12 3v18m-5 0h10M4 7h16M6 7l-4 7h8zm12 0-4 7h8zM2 14c0 4 8 4 8 0m4 0c0 4 8 4 8 0"/></svg>',
  "quote-document": '<svg viewBox="0 0 24 24"><path d="M5 3h10l4 4v14H5zM14 3v5h5M8 12h3v3H8v-3m0 3v2m5-5h3v3h-3v-3m0 3v2"/></svg>',
  "badge-info": '<svg viewBox="0 0 24 24"><path d="m12 2 3 2 4 1 1 4 2 3-2 3-1 4-4 1-3 2-3-2-4-1-1-4-2-3 2-3 1-4 4-1zM12 11v6m0-10v.01"/></svg>',
  "scroll-text": '<svg viewBox="0 0 24 24"><path d="M6 4h14v13a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3v-2h11v2a3 3 0 0 0 3 3M6 15V4a2 2 0 0 0-4 0v3h4M9 8h8m-8 3h6"/></svg>',
  "lock": '<svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/></svg>',
  "chart-bars": '<svg viewBox="0 0 24 24"><path d="M3 3v18h18M7 17v-5h3v5zm5 0V7h3v10zm5 0V4h3v13z"/></svg>',
  "tools": '<svg viewBox="0 0 24 24"><path d="m14.5 6.5 3 3L21 6a5 5 0 0 1-6.5 6.5L6 21a2.1 2.1 0 0 1-3-3l8.5-8.5A5 5 0 0 1 18 3z"></path><path d="m5 3 4 4-2 2-4-4z"></path><path d="m8 8 3 3m4 4 5.5 5.5a1.4 1.4 0 0 1-2 2L13 17"></path></svg>',
  "search": '<svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-4.2-4.2"></path></svg>',
  "microscope": '<svg viewBox="0 0 24 24"><path d="M6 18h8"></path><path d="M3 22h18"></path><path d="M14 22a7 7 0 0 0 0-14h-1"></path><path d="M9 14h2"></path><path d="M8 6h4"></path><path d="M7 10h6"></path><path d="M10 6V3"></path><path d="M9 3h2"></path><path d="M6 10v4a2 2 0 0 0 2 2h4"></path></svg>',
  "settings": '<svg viewBox="0 0 24 24"><path d="M4 7h10"></path><path d="M18 7h2"></path><circle cx="16" cy="7" r="2"></circle><path d="M4 17h2"></path><path d="M10 17h10"></path><circle cx="8" cy="17" r="2"></circle></svg>',
  "book-marked": '<svg viewBox="0 0 24 24"><path d="M6 3h11a1 1 0 0 1 1 1v17l-6-3-6 3z"></path><path d="M9 7h6"></path><path d="M9 11h4"></path></svg>',
  "book-open": '<svg viewBox="0 0 24 24"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H11v18H6.5A2.5 2.5 0 0 0 4 18.5z"></path><path d="M20 5.5A2.5 2.5 0 0 0 17.5 3H13v18h4.5a2.5 2.5 0 0 1 2.5-2.5z"></path></svg>',
  "users": '<svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"></circle><path d="M3 20a6 6 0 0 1 12 0"></path><circle cx="17" cy="9" r="2.5"></circle><path d="M15 15.5A5 5 0 0 1 21 20"></path></svg>',
  "clipboard-list": '<svg viewBox="0 0 24 24"><path d="M9 4h6l1 2h3v15H5V6h3z"></path><path d="M9 11h6"></path><path d="M9 15h6"></path><path d="M8 4h8"></path></svg>',
  "wrench": '<svg viewBox="0 0 24 24"><path d="M14.7 6.3a5 5 0 0 0-6.4 6.4L3 18a2.1 2.1 0 0 0 3 3l5.3-5.3a5 5 0 0 0 6.4-6.4l-3 3-3-3z"></path></svg>',
  "grid-3x3": '<svg viewBox="0 0 24 24"><path d="M4 4h16v16H4z"></path><path d="M4 9.3h16"></path><path d="M4 14.7h16"></path><path d="M9.3 4v16"></path><path d="M14.7 4v16"></path></svg>',
  "library": '<svg viewBox="0 0 24 24"><path d="M4 19V5"></path><path d="M8 19V5"></path><path d="M12 19V5"></path><path d="M16 19V5"></path><path d="M20 19V5"></path></svg>',
  "table": '<svg viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="14" rx="1.5"></rect><path d="M4 10h16"></path><path d="M10 5v14"></path></svg>',
  "database": '<svg viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="7" ry="3"></ellipse><path d="M5 5v6c0 1.7 3.1 3 7 3s7-1.3 7-3V5"></path><path d="M5 11v6c0 1.7 3.1 3 7 3s7-1.3 7-3v-6"></path></svg>',
  "zenodo": '<svg viewBox="0 0 24 24"><path d="M7 4h10a3 3 0 0 1 3 3v10a3 3 0 0 1-3 3H7a3 3 0 0 1-3-3V7a3 3 0 0 1 3-3z"></path><path d="M8 8h8l-8 8h8"></path><path d="M16 8v3"></path><path d="M8 13v3"></path></svg>',
  "braces": '<svg viewBox="0 0 24 24"><path d="M8 4c-2 0-2 2-2 3v2c0 1-1 2-2 2 1 0 2 1 2 2v2c0 1 0 3 2 3"></path><path d="M16 4c2 0 2 2 2 3v2c0 1 1 2 2 2-1 0-2 1-2 2v2c0 1 0 3-2 3"></path></svg>',
  "github": '<svg viewBox="0 0 24 24"><path d="M12 2.5a9.5 9.5 0 0 0-3 18.5c.5.1.7-.2.7-.5v-1.8c-2.9.6-3.5-1.2-3.5-1.2-.5-1.1-1.1-1.4-1.1-1.4-.9-.6.1-.6.1-.6 1 .1 1.5 1 1.5 1 .9 1.5 2.3 1.1 2.9.8.1-.6.3-1.1.6-1.3-2.3-.3-4.7-1.2-4.7-5.1 0-1.1.4-2 1-2.8-.1-.3-.4-1.3.1-2.8 0 0 .8-.3 2.9 1.1a10 10 0 0 1 5.2 0c2-1.4 2.9-1.1 2.9-1.1.5 1.5.2 2.5.1 2.8.6.7 1 1.7 1 2.8 0 4-2.4 4.8-4.7 5.1.4.3.7 1 .7 2v2.9c0 .3.2.6.7.5A9.5 9.5 0 0 0 12 2.5z"></path></svg>',
  "file-type": '<svg viewBox="0 0 24 24"><path d="M14 3v5a1 1 0 0 0 1 1h5"></path><path d="M6 3h8l6 6v12H6z"></path><path d="M9 16h6"></path><path d="M10 13h4"></path></svg>',
  "r-script": '<svg viewBox="0 0 24 24"><path d="M14 3v5a1 1 0 0 0 1 1h5"></path><path d="M6 3h8l6 6v12H6z"></path><path d="M9 18v-6h4a2 2 0 0 1 0 4H9"></path><path d="m12.5 16 3 3"></path></svg>',
  "list-filter": '<svg viewBox="0 0 24 24"><path d="M4 7h16"></path><path d="M7 12h10"></path><path d="M10 17h4"></path></svg>',
  "menu": '<svg viewBox="0 0 24 24"><path d="M4 7h16"></path><path d="M4 12h16"></path><path d="M4 17h16"></path></svg>',
  "chevron-left": '<svg viewBox="0 0 24 24"><path d="m15 18-6-6 6-6"></path></svg>',
  "chevron-right": '<svg viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"></path></svg>',
  "chevron-down": '<svg viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"></path></svg>',
  "arrow-up": '<svg viewBox="0 0 24 24"><path d="m12 19V5"></path><path d="m5 12 7-7 7 7"></path></svg>',
  "refresh-cw": '<svg viewBox="0 0 24 24"><path d="M20 7v5h-5"></path><path d="M4 17v-5h5"></path><path d="M18 12a6 6 0 0 0-10.2-4.2L4 11"></path><path d="M6 12a6 6 0 0 0 10.2 4.2L20 13"></path></svg>',
  "link": '<svg viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7 0l2-2a5 5 0 0 0-7-7l-1 1"></path><path d="M14 11a5 5 0 0 0-7 0l-2 2a5 5 0 0 0 7 7l1-1"></path></svg>',
  "external-link": '<svg viewBox="0 0 24 24"><path d="M14 5h5v5"></path><path d="m19 5-8 8"></path><path d="M19 14v4a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h4"></path></svg>',
  "network": '<svg viewBox="0 0 24 24"><circle cx="6" cy="12" r="2.5"></circle><circle cx="17" cy="6" r="2.5"></circle><circle cx="18" cy="18" r="2.5"></circle><path d="m8.2 10.8 6.6-3.6"></path><path d="m8.4 13.2 7.2 3.6"></path><path d="m17.4 8.5.4 7"></path></svg>',
  "map-pin": '<svg viewBox="0 0 24 24"><path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0z"></path><circle cx="12" cy="10" r="2.5"></circle></svg>',
  "brain": '<svg viewBox="0 0 24 24"><path d="M9.5 4.5A3.5 3.5 0 0 0 6 8v.4A3.5 3.5 0 0 0 4.5 15a3.5 3.5 0 0 0 5 3.2V5.5a2 2 0 0 1 2-2"></path><path d="M14.5 4.5A3.5 3.5 0 0 1 18 8v.4a3.5 3.5 0 0 1 1.5 6.6 3.5 3.5 0 0 1-5 3.2V5.5a2 2 0 0 0-2-2"></path><path d="M6 8.5h2"></path><path d="M16 8.5h2"></path><path d="M6.5 15.5H9"></path><path d="M15 15.5h2.5"></path></svg>',
  "target": '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"></circle><circle cx="12" cy="12" r="4"></circle><circle cx="12" cy="12" r="1"></circle><path d="M12 2v3"></path><path d="M22 12h-3"></path><path d="M12 22v-3"></path><path d="M2 12h3"></path></svg>',
  "molecule": '<svg viewBox="0 0 24 24"><circle cx="6" cy="12" r="2.5"></circle><circle cx="16.5" cy="6" r="2.5"></circle><circle cx="17.5" cy="17.5" r="2.5"></circle><path d="m8.2 10.7 6.1-3.5"></path><path d="m8.4 13.1 6.9 3.3"></path><path d="m16.7 8.5.6 6.5"></path></svg>',
  "sparkles": '<svg viewBox="0 0 24 24"><path d="m12 3 1.2 3.8L17 8l-3.8 1.2L12 13l-1.2-3.8L7 8l3.8-1.2z"></path><path d="m18.5 14 .7 2.3 2.3.7-2.3.7-.7 2.3-.7-2.3-2.3-.7 2.3-.7z"></path><path d="m5.5 13 .6 1.9 1.9.6-1.9.6-.6 1.9-.6-1.9-1.9-.6 1.9-.6z"></path></svg>',
  "copy": '<svg viewBox="0 0 24 24"><rect x="8" y="8" width="11" height="11" rx="2"></rect><path d="M5 15V7a2 2 0 0 1 2-2h8"></path></svg>',
  "check": '<svg viewBox="0 0 24 24"><path d="m5 12 5 5L20 7"></path></svg>',
  "circle-alert": '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v6"></path><path d="M12 17h.01"></path></svg>',
  "info": '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="M12 11v6"></path><path d="M12 7h.01"></path></svg>',
  "mail": '<svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"></rect><path d="m4 7 8 6 8-6"></path></svg>',
  "shield": '<svg viewBox="0 0 24 24"><path d="M12 3 19 6v5c0 5-3 8-7 10-4-2-7-5-7-10V6z"></path><path d="M12 8v6"></path><path d="M9 11h6"></path></svg>',
  "bell-plus": '<svg viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4"></path><path d="M19 2v6"></path><path d="M16 5h6"></path></svg>',
  "volume-2": '<svg viewBox="0 0 24 24"><path d="m11 5-6 4H2v6h3l6 4z"></path><path d="M15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13"></path></svg>',
  "volume-x": '<svg viewBox="0 0 24 24"><path d="m11 5-6 4H2v6h3l6 4z"></path><path d="m16 9 5 6m0-6-5 6"></path></svg>',
  "play": '<svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"></path></svg>',
  "pause": '<svg viewBox="0 0 24 24"><path d="M8 5v14"></path><path d="M16 5v14"></path></svg>',
  "download": '<svg viewBox="0 0 24 24"><path d="M12 3v12"></path><path d="m7 10 5 5 5-5"></path><path d="M5 21h14"></path></svg>',
  "printer": '<svg viewBox="0 0 24 24"><path d="M7 8V4h10v4"></path><path d="M7 17H5a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2h-2"></path><path d="M7 14h10v6H7z"></path><path d="M17 12h.01"></path></svg>',
  "maximize": '<svg viewBox="0 0 24 24"><path d="M8 3H5a2 2 0 0 0-2 2v3"></path><path d="M16 3h3a2 2 0 0 1 2 2v3"></path><path d="M8 21H5a2 2 0 0 1-2-2v-3"></path><path d="M16 21h3a2 2 0 0 0 2-2v-3"></path></svg>',
  "move": '<svg viewBox="0 0 24 24"><path d="M12 3v18"></path><path d="m8 7 4-4 4 4"></path><path d="m8 17 4 4 4-4"></path><path d="M3 12h18"></path><path d="m7 8-4 4 4 4"></path><path d="m17 8 4 4-4 4"></path></svg>',
  "minimize": '<svg viewBox="0 0 24 24"><path d="M8 3v3a2 2 0 0 1-2 2H3"></path><path d="M16 3v3a2 2 0 0 0 2 2h3"></path><path d="M8 21v-3a2 2 0 0 0-2-2H3"></path><path d="M16 21v-3a2 2 0 0 1 2-2h3"></path></svg>',
  "share-2": '<svg viewBox="0 0 24 24"><circle cx="18" cy="5" r="3"></circle><circle cx="6" cy="12" r="3"></circle><circle cx="18" cy="19" r="3"></circle><path d="m8.6 10.8 6.8-4.6"></path><path d="m8.6 13.2 6.8 4.6"></path></svg>',
  "x": '<svg viewBox="0 0 24 24"><path d="M18 6 6 18"></path><path d="m6 6 12 12"></path></svg>',
};

function refreshIcons() {
  for (const icon of document.querySelectorAll("i[data-icon]")) {
    const name = icon.getAttribute("data-icon") || "";
    if (!ICONS[name]) continue;
    if (icon.dataset.renderedIcon === name && icon.querySelector("svg")) continue;
    icon.innerHTML = ICONS[name];
    icon.classList.add("svg-icon");
    icon.dataset.renderedIcon = name;
  }
}

function updateModalOpenState() {
  const apply = () => {
    const hasOpenDialog = Boolean(document.querySelector("dialog[open]"));
    document.documentElement.classList.toggle("modal-open", hasOpenDialog);
    document.body?.classList.toggle("modal-open", hasOpenDialog);
  };
  apply();
  if (typeof window.requestAnimationFrame === "function") {
    window.requestAnimationFrame(apply);
    window.setTimeout(apply, 0);
    return;
  }
  window.setTimeout(apply, 0);
}

function closeOpenDialogsForDomSwap() {
  document.querySelectorAll("dialog[open]").forEach((dialog) => {
    dialog.classList.remove("is-open");
    dialog.closest(".filters")?.classList.remove("is-modal-open");
    const filtersBody = dialog.closest(".filters-body");
    if (filtersBody && dialog.dataset.restoreFiltersBodyHidden === "1") {
      filtersBody.hidden = true;
      delete dialog.dataset.restoreFiltersBodyHidden;
    }
    try {
      if (typeof dialog.close === "function") dialog.close();
      else dialog.removeAttribute("open");
    } catch (error) {
      dialog.removeAttribute("open");
    }
  });
  document.documentElement.classList.remove("modal-open");
  document.body?.classList.remove("modal-open");
}

function subtleHaptic(pattern = 8) {
  if (!("vibrate" in navigator) || window.trackerTouchHaptics === false) return;
  if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;
  try {
    navigator.vibrate(pattern);
  } catch (error) {}
}

function escapeHtml(value) {
  return String(value || "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

async function refreshHeroUpdatedTime() {
  if (!heroUpdatedText) return;
  try {
    const response = await fetch("status.php", {
      headers: { Accept: "application/json" },
      cache: "no-store",
    });
    if (!response.ok) throw new Error("Status request failed");
    const data = await response.json();
    if (!data.ok) throw new Error(data.message || "Status unavailable");
    if (heroUpdatedText && data.last_updated_display) {
      heroUpdatedText.textContent = data.last_updated_display;
    }
  } catch (error) {}
}

window.addEventListener("load", refreshIcons);

function initMobileSearchViewport(dialog) {
  if (dialog.dataset.viewportBound === "1") return;
  dialog.dataset.viewportBound = "1";
  const sheet = dialog.querySelector(".advanced-filter-sheet");
  const query = dialog.querySelector("[data-advanced-search-query]");
  let frame = 0;
  const update = () => {
    frame = 0;
    const mobile = dialog.open && window.matchMedia("(max-width: 760px)").matches;
    dialog.classList.toggle("mobile-search-viewport", mobile);
    if (!mobile) return;
    const viewport = window.visualViewport;
    dialog.style.setProperty("--search-viewport-top", `${viewport?.offsetTop || 0}px`);
    dialog.style.setProperty("--search-viewport-height", `${viewport?.height || window.innerHeight}px`);
    // Scroll the sheet itself; never pan the page behind the modal.
    if (document.activeElement === query && sheet) {
      const field = query.getBoundingClientRect();
      const bounds = sheet.getBoundingClientRect();
      const head = dialog.querySelector(".advanced-filter-head");
      const top = Math.max(bounds.top + 8, head?.getBoundingClientRect().bottom || bounds.top);
      const bottom = bounds.bottom - 12;
      if (field.top < top) sheet.scrollTop -= top - field.top;
      else if (field.bottom > bottom) sheet.scrollTop += field.bottom - bottom;
    }
  };
  const schedule = () => {
    if (!frame) frame = window.requestAnimationFrame(update);
  };
  window.visualViewport?.addEventListener("resize", schedule, { passive: true });
  window.visualViewport?.addEventListener("scroll", schedule, { passive: true });
  window.addEventListener("resize", schedule, { passive: true });
  dialog.addEventListener("focusin", update);
  dialog.addEventListener("close", () => {
    dialog.classList.remove("mobile-search-viewport");
    dialog.style.removeProperty("--search-viewport-top");
    dialog.style.removeProperty("--search-viewport-height");
  });
}

function initAppSheets() {
  const bindings = [
    { open: "[data-open-advanced]", close: "[data-close-advanced]", dialog: "#advanced-filters" },
    { open: "[data-open-alerts]", close: "[data-close-alerts]", dialog: "#alert-enrollment" },
    { open: "[data-open-analytics]", close: "[data-close-analytics]", dialog: "#analytics-modal" },
    { open: "[data-open-app-info]", close: "[data-close-app-info]", dialog: "#app-info-modal" },
  ];
  const prepareDialogHost = (dialog, binding) => {
    if (binding.dialog !== "#advanced-filters") return;
    const filters = dialog.closest(".filters");
    const filtersBody = dialog.closest(".filters-body");
    filters?.classList.add("is-modal-open");
    if (filtersBody?.hidden) {
      filtersBody.hidden = false;
      dialog.dataset.restoreFiltersBodyHidden = "1";
    }
  };
  const releaseDialogHost = (dialog, binding) => {
    if (binding.dialog !== "#advanced-filters") return;
    dialog.closest(".filters")?.classList.remove("is-modal-open");
    const filtersBody = dialog.closest(".filters-body");
    if (filtersBody && dialog.dataset.restoreFiltersBodyHidden === "1") {
      filtersBody.hidden = true;
      delete dialog.dataset.restoreFiltersBodyHidden;
    }
  };
  const closeDialog = (dialog, binding) => {
    if (typeof dialog.close === "function" && dialog.open) dialog.close();
    dialog.classList.remove("is-open");
    releaseDialogHost(dialog, binding);
    updateModalOpenState();
  };
  const openDialog = (dialog, binding) => {
    if (dialog.open) {
      dialog.classList.add("is-open");
      updateModalOpenState();
    } else {
      document.querySelectorAll("dialog[open]").forEach((openDialogElement) => {
        if (openDialogElement !== dialog && typeof openDialogElement.close === "function") {
          openDialogElement.close();
        }
      });
      prepareDialogHost(dialog, binding);
      try {
        if (typeof dialog.showModal === "function") dialog.showModal();
        else dialog.setAttribute("open", "");
      } catch (error) {
        dialog.setAttribute("open", "");
      }
      dialog.classList.add("is-open");
      updateModalOpenState();
    }
    if (binding.dialog === "#alert-enrollment") setActiveNavLink("#alerts");
    if (binding.dialog === "#analytics-modal") {
      setActiveNavLink("#analytics");
      const summary = dialog.querySelector('#timeline-summary');
      if (summary) summary.textContent = 'Preparing analytics…';
      ensureAnalyticsFeature().then(feature => { if (dialog.open) return feature.open(); })
        .catch(() => {
          if (summary) summary.textContent = 'Analytics could not load. Close and reopen this dialog to retry.';
        });
    }
    if (binding.dialog === "#advanced-filters") initJournalPicker();
    initAlertMultiselects(dialog);
    refreshIcons();
    const focusTarget = dialog.querySelector("[autofocus], .advanced-filter-close, summary, input, select, button");
    focusTarget?.focus({ preventScroll: true });
  };
  if (document.documentElement.dataset.sheetOpenDelegated !== "1") {
    document.documentElement.dataset.sheetOpenDelegated = "1";
    document.addEventListener("click", (event) => {
      const binding = bindings.find((item) => event.target.closest(item.open));
      if (!binding) return;
      const dialog = document.querySelector(binding.dialog);
      if (!dialog) return;
      event.preventDefault();
      if (binding.dialog === "#advanced-filters") {
        collapsePrimaryNavSidebarOnCompact();
        const options = dialog.querySelector(".publication-search-options");
        if (options) options.open = !event.target.closest("[data-full-search]");
        const count = Number(document.querySelector("[data-smart-result-bar]")?.dataset.activeFilterCount || 0);
        const badge = dialog.querySelector("[data-search-filter-count]");
        if (badge) badge.textContent = count ? `${count} active` : "";
      }
      if (binding.dialog === "#advanced-filters") initMobileSearchViewport(dialog);
      openDialog(dialog, binding);
      if (binding.dialog === "#advanced-filters" && event.target.closest("[data-full-search]")) {
        const sheet = dialog.querySelector(".advanced-filter-sheet");
        if (sheet) sheet.scrollTop = 0;
        // Keep focus within the tap gesture so iOS can open its keyboard.
        dialog.querySelector("[data-advanced-search-query]")?.focus({ preventScroll: true });
      }
    });
    const openSheetFromHash = () => {
      const hashBinding = window.location.hash === "#analytics"
        ? bindings.find((item) => item.dialog === "#analytics-modal")
        : window.location.hash === "#alerts"
          ? bindings.find((item) => item.dialog === "#alert-enrollment")
          : null;
      if (!hashBinding) return;
      const dialog = document.querySelector(hashBinding.dialog);
      if (dialog) openDialog(dialog, hashBinding);
    };
    window.addEventListener("hashchange", openSheetFromHash);
    window.requestAnimationFrame(openSheetFromHash);
  }
  for (const binding of bindings) {
    const dialog = document.querySelector(binding.dialog);
    if (!dialog) continue;
    dialog.querySelectorAll(binding.close).forEach((button) => {
      if (button.dataset.sheetCloseBound === "1") return;
      button.dataset.sheetCloseBound = "1";
      button.addEventListener("click", () => closeDialog(dialog, binding));
    });
    if (dialog.dataset.sheetDialogBound === "1") continue;
    dialog.dataset.sheetDialogBound = "1";
    dialog.addEventListener("keydown", event => {
      if (event.key === "Escape") { event.preventDefault(); event.stopPropagation(); closeDialog(dialog, binding); }
    }, true);
    dialog.addEventListener("click", (event) => {
      if (event.target === dialog) closeDialog(dialog, binding);
    });
    dialog.addEventListener("cancel", () => {
      dialog.classList.remove("is-open");
      releaseDialogHost(dialog, binding);
      updateModalOpenState();
    });
    dialog.addEventListener("close", () => {
      dialog.classList.remove("is-open");
      releaseDialogHost(dialog, binding);
      updateModalOpenState();
    });
  }
}

function initAdvancedFilterSearchSync() {
  if (document.documentElement.dataset.publicationQuerySyncBound === "1") return;
  document.documentElement.dataset.publicationQuerySyncBound = "1";
  document.addEventListener("input", event => {
    if (event.target.matches("#hero-q")) {
      const other = document.querySelector("[data-advanced-search-query]");
      if (other) other.value = event.target.value;
    } else if (event.target.matches("[data-advanced-search-query]")) {
      const other = document.querySelector("#hero-q");
      if (other) other.value = event.target.value;
    }
  });
}

function initJournalPicker() {
  const root = document.querySelector("[data-journal-picker]");
  const select = root?.querySelector("select");
  if (!root || !select || root.dataset.ready === "1") return;
  root.dataset.ready = "1";
  const normalize = (value) => value.normalize("NFKD").replace(/[\u0300-\u036f]/g, "").toLocaleLowerCase().replace(/[^\p{L}\p{N}]+/gu, " ").trim();
  const journals = [...select.options].filter((o) => o.value && !o.hasAttribute("data-unlisted")).map((o) => {
    const key = normalize(o.value), words = key.split(" ");
    return { name: o.value, key, words, count: Number(o.dataset.count || 0), fullAcronym: words.map((w) => w[0]).join(""), acronym: words.filter((w) => !["of", "the", "and", "in", "a"].includes(w)).map((w) => w[0]).join("") };
  });
  const byKey = new Map(journals.map((j) => [j.key, j]));
  const originalValue = select.value;
  const input = document.createElement("input");
  input.type = "search"; input.name = "journal"; input.id = "journal-search"; input.value = select.value;
  input.placeholder = "Type a journal name or abbreviation"; input.autocomplete = "off";
  input.setAttribute("role", "combobox"); input.setAttribute("aria-autocomplete", "list");
  input.setAttribute("aria-expanded", "false"); input.setAttribute("aria-controls", "journal-suggestions");
  input.setAttribute("aria-describedby", "journal-picker-status");
  const list = document.createElement("ul"); list.id = "journal-suggestions"; list.className = "journal-suggestions";
  list.setAttribute("role", "listbox"); list.setAttribute("aria-label", "Matching journals"); list.hidden = true;
  const status = root.querySelector(".journal-picker-help"); status.id = "journal-picker-status"; status.setAttribute("role", "status");
  const browse = document.createElement("button"); browse.type = "button"; browse.className = "journal-browse";
  browse.textContent = "Browse all journals"; browse.setAttribute("aria-controls", select.id); browse.setAttribute("aria-expanded", "false");
  root.querySelector("label").htmlFor = input.id;
  select.removeAttribute("name"); select.setAttribute("aria-label", "All indexed journals"); select.hidden = true;
  select.before(input, list); status.before(browse);
  const collator = new Intl.Collator(undefined, { sensitivity: "base", numeric: true });
  let matches = [], active = -1;
  const close = () => { list.hidden = true; input.setAttribute("aria-expanded", "false"); input.removeAttribute("aria-activedescendant"); active = -1; };
  const validate = () => input.setCustomValidity(!input.value.trim() || byKey.has(normalize(input.value)) || input.value === originalValue ? "" : "Choose a journal from the suggestions or the full list.");
  const choose = (item) => { input.value = item.name; select.value = item.name; validate(); close(); input.focus(); status.textContent = `Selected: ${item.name}.`; };
  const render = () => {
    validate();
    const query = normalize(input.value), terms = query.split(" ").filter(Boolean);
    // Keep only the best twelve while scanning the local index once. No
    // full-list sort or thousands of suggestion nodes on each keystroke.
    const best = []; let total = 0;
    const compare = (a, b) => a.score - b.score || (!query ? b.count - a.count : 0) || collator.compare(a.name, b.name);
    for (const j of journals) {
      const score = !query ? 6 : j.key === query ? 0 : j.key.startsWith(query) ? 1 : terms.every((t) => j.words.some((w) => w.startsWith(t))) ? 2 : j.key.includes(query) ? 3 : terms.every((t) => j.key.includes(t)) ? 4 : (j.acronym === query.replace(/ /g, "") || j.fullAcronym === query.replace(/ /g, "")) ? 5 : 99;
      if (score === 99) continue;
      total++;
      const candidate = { ...j, score };
      if (best.length === 12 && compare(candidate, best[11]) >= 0) continue;
      const index = best.findIndex((existing) => compare(candidate, existing) < 0);
      best.splice(index < 0 ? best.length : index, 0, candidate);
      if (best.length > 12) best.pop();
    }
    matches = best; active = -1; list.replaceChildren(); input.removeAttribute("aria-activedescendant");
    matches.forEach((item, i) => {
      const option = document.createElement("li"); option.id = `journal-option-${i}`; option.setAttribute("role", "option"); option.setAttribute("aria-selected", "false");
      const name = document.createElement("span"); name.textContent = item.name; option.append(name);
      if (item.score === 0) { option.classList.add("is-exact"); const badge = document.createElement("strong"); badge.textContent = "Exact match"; option.append(badge); }
      const count = document.createElement("small"); count.textContent = `${item.count.toLocaleString()} ${item.count === 1 ? "record" : "records"}`; option.append(count);
      option.addEventListener("pointerdown", (event) => event.preventDefault()); option.addEventListener("click", () => choose(item)); list.append(option);
    });
    status.textContent = total ? `${total.toLocaleString()} matching journals${total > 12 ? "; showing 12 — type more to narrow" : ""}.` : "No matching indexed journal. Try fewer words or browse the full list.";
    list.hidden = !matches.length; input.setAttribute("aria-expanded", matches.length ? "true" : "false");
  };
  input.addEventListener("input", render); input.addEventListener("focus", render);
  input.addEventListener("keydown", (event) => {
    if (["ArrowDown", "ArrowUp"].includes(event.key)) {
      event.preventDefault(); if (list.hidden) render(); if (!matches.length) return;
      active = active < 0 ? (event.key === "ArrowDown" ? 0 : matches.length - 1) : (active + (event.key === "ArrowDown" ? 1 : -1) + matches.length) % matches.length;
      [...list.children].forEach((o, i) => o.setAttribute("aria-selected", i === active ? "true" : "false"));
      input.setAttribute("aria-activedescendant", list.children[active].id); list.children[active].scrollIntoView({block:"nearest"});
    } else if (event.key === "Enter" && !list.hidden && matches.length) { event.preventDefault(); choose(matches[Math.max(0, active)]); }
    else if (event.key === "Escape" && !list.hidden) { event.preventDefault(); event.stopPropagation(); close(); }
    else if (event.key === "Tab") close();
  });
  browse.addEventListener("click", () => { close(); select.hidden = !select.hidden; browse.setAttribute("aria-expanded", select.hidden ? "false" : "true"); browse.textContent = select.hidden ? "Browse all journals" : "Hide full journal list"; if (!select.hidden) select.focus(); });
  select.addEventListener("change", () => { input.value = select.value; validate(); close(); status.textContent = input.value ? `Selected: ${input.value}.` : `${journals.length.toLocaleString()} indexed journals available.`; });
  root.addEventListener("focusout", () => window.setTimeout(() => { if (!root.contains(document.activeElement)) close(); }, 0));
  root.closest("form")?.addEventListener("submit", () => { const exact = byKey.get(normalize(input.value)); if (exact) input.value = exact.name; });
  document.querySelector("#advanced-filters")?.addEventListener("close", close);
}

function setActiveNavLink(hash) {
  const normalized = hash && hash.startsWith("#") ? hash : `#${hash || "papers"}`;
  document.querySelectorAll(".topbar nav a[href^='#'], .top-actions a[href^='#']").forEach((link) => {
    const active = link.getAttribute("href") === normalized;
    link.classList.toggle("is-active", active);
    if (active) link.setAttribute("aria-current", "location");
    else link.removeAttribute("aria-current");
  });
}

function scrollToSection(hash, { updateHash = true } = {}) {
  const target = document.querySelector(hash);
  if (!target) return false;
  const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  const targetY = Math.max(0, window.scrollY + target.getBoundingClientRect().top - currentScrollOffset());
  window.scrollTo({ top: targetY, behavior: reduceMotion ? "auto" : "smooth" });
  if (updateHash && window.history.pushState) {
    window.history.pushState({ publicationTrackerSection: hash }, "", hash);
  }
  setActiveNavLink(hash);
  return true;
}

function initSectionNavigation() {
  const links = document.querySelectorAll(".topbar nav a, .top-actions a");
  if (!links.length) return;
  links.forEach((link) => {
    if (link.dataset.sectionNavBound === "1") return;
    link.dataset.sectionNavBound = "1";
    link.addEventListener("click", (event) => {
      if (link.matches("[data-open-alerts], [data-open-analytics]")) {
        setActiveNavLink(link.matches("[data-open-alerts]") ? "#alerts" : "#analytics");
        collapsePrimaryNavSidebarOnCompact();
        return;
      }
      const hash = link.getAttribute("href") || "";
      if (!hash.startsWith("#") || hash.length < 2) {
        collapsePrimaryNavSidebarOnCompact();
        return;
      }
      if (!document.querySelector(hash)) {
        collapsePrimaryNavSidebarOnCompact();
        return;
      }
      event.preventDefault();
      scrollToSection(hash);
      collapsePrimaryNavSidebarOnCompact();
    });
  });

  const sections = ["#papers", "#analytics", "#alerts", "#admin"]
    .map((selector) => document.querySelector(selector))
    .filter(Boolean);
  if ("IntersectionObserver" in window && sections.length) {
    const observer = new IntersectionObserver((entries) => {
      const visible = entries
        .filter((entry) => entry.isIntersecting)
        .sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0];
      if (visible?.target?.id) setActiveNavLink(`#${visible.target.id}`);
    }, {
      root: null,
      rootMargin: `-${Math.min(160, currentScrollOffset() + 24)}px 0px -58% 0px`,
      threshold: [0.12, 0.28, 0.5],
    });
    sections.forEach((section) => observer.observe(section));
  }

  const initialHash = window.location.hash && document.getElementById(window.location.hash.slice(1)) ? window.location.hash : "#papers";
  setActiveNavLink(initialHash);
}

function initPublicationSearchFocus() {
  const focusSearch = () => {
    const input = document.querySelector("#hero-q");
    if (!input) return;
    collapsePrimaryNavSidebarOnCompact();
    input.focus({ preventScroll: true });
    input.scrollIntoView({ block: "center", behavior: window.matchMedia("(prefers-reduced-motion: reduce)").matches ? "auto" : "smooth" });
  };
  document.addEventListener("click", (event) => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const link = event.target.closest?.("a[href]");
    if (!link || link.target === "_blank" || link.hasAttribute("download")) return;
    const url = new URL(link.href, window.location.href);
    if (url.origin !== window.location.origin || url.hash !== "#hero-q" || url.pathname !== window.location.pathname || !document.querySelector("#hero-q")) return;
    event.preventDefault();
    const current = new URL(window.location.href);
    current.hash = "hero-q";
    window.history.pushState({}, "", current);
    focusSearch();
  });
  window.addEventListener("hashchange", () => { if (window.location.hash === "#hero-q") focusSearch(); });
  const openFullSearch = () => {
    if (window.location.hash === "#publication-search") document.querySelector("[data-full-search]")?.click();
  };
  window.addEventListener("hashchange", openFullSearch);
  if (window.location.hash === "#publication-search") {
    if (document.readyState === "complete") openFullSearch();
    else window.addEventListener("load", openFullSearch, { once: true });
  }
  if (window.location.hash === "#hero-q") {
    if (document.readyState === "complete") focusSearch();
    else window.addEventListener("load", () => window.setTimeout(focusSearch, 280), { once: true });
  }
}

function initSidebarControls() {
  const sidebar = document.querySelector(".filters");
  const toggle = document.querySelector("#sidebar-toggle");
  const body = document.querySelector("#publication-filters-body");
  if (sidebar && toggle && body) {
    const setCollapsed = (collapsed) => {
      document.documentElement.classList.toggle("sidebar-collapsed", collapsed);
      sidebar.classList.toggle("is-collapsed", collapsed);
      body.hidden = collapsed;
      toggle.setAttribute("aria-expanded", collapsed ? "false" : "true");
      toggle.title = collapsed ? "Expand filters" : "Collapse filters";
      localStorage.setItem("publicationTrackerSidebarCollapsed", collapsed ? "1" : "0");
      refreshIcons();
    };
    setCollapsed(localStorage.getItem("publicationTrackerSidebarCollapsed") !== "0");
    toggle.addEventListener("click", () => {
      setCollapsed(!sidebar.classList.contains("is-collapsed"));
    });
  }

  if (!sidebar) return;
  sidebar.addEventListener("change", (event) => {
    const field = event.target;
    if (!(field instanceof HTMLInputElement || field instanceof HTMLSelectElement)) return;
    if (field.closest("dialog")) return;
    if (field.matches('input[type="search"], input[type="text"], input[type="date"], input[type="email"], input[type="password"], input[type="number"]')) return;
    if (field.name === "page") return;
    sidebar.requestSubmit();
  });
}

let smartResultBarFrame = 0;

function updateSmartResultBar() {
  smartResultBarFrame = 0;
  const bar = document.querySelector("[data-smart-result-bar]");
  const sentinel = document.querySelector("[data-result-bar-sentinel]");
  const panel = bar?.closest("#publication-results");
  if (!bar || !sentinel || !panel) return;
  const mobile = window.matchMedia("(max-width: 760px)").matches;
  const compact = window.matchMedia("(max-width: 1180px)").matches;
  const sentinelRect = sentinel.getBoundingClientRect();
  const panelRect = panel.getBoundingClientRect();
  const viewportHeight = window.visualViewport?.height || window.innerHeight;
  const pager = panel.querySelector(".results-bottom-pager")?.getBoundingClientRect();
  const focused = document.activeElement;
  const typing = focused?.matches('input:not([type="checkbox"]):not([type="radio"]), textarea, [contenteditable="true"]');
  const keyboard = typing || viewportHeight < window.innerHeight - 120;
  const pagerVisible = pager && pager.top < viewportHeight - 24 && pager.bottom > 0;
  const visible = mobile && panelRect.top < viewportHeight - 100 && panelRect.bottom > 100 && !pagerVisible && !keyboard;
  const floating = !mobile && sentinelRect.top <= (compact ? 70 : 10) && panelRect.bottom > (compact ? 70 : 10) + bar.offsetHeight + 16;
  bar.classList.toggle("is-smart-floating", floating);
  // Keep selected-record actions below the sticky search controls, never behind them.
  const selectionTop = bar.getClientRects().length ? (parseFloat(getComputedStyle(bar).top) || 0) + bar.offsetHeight + 8 : (compact ? 78 : 12);
  panel.style.setProperty('--selection-toolbar-top', `${selectionTop}px`);
  const dock = document.querySelector(".mobile-result-dock");
  if (dock) dock.hidden = !visible;
}

function initDesktopResultControls() {
  const panel = document.querySelector("#publication-results");
  const bar = panel?.querySelector("[data-smart-result-bar]");
  if (!bar || bar.dataset.desktopControlsBound === "1") return;
  bar.dataset.desktopControlsBound = "1";
  bar.classList.add("has-desktop-result-controls");
  const heading = bar.querySelector(".result-title-block");
  if (heading) {
    heading.classList.add("publication-results-heading");
    panel.insertBefore(heading, panel.querySelector("[data-result-bar-sentinel]"));
  }
  let search = bar.querySelector('.desktop-result-search');
  if (!search) {
    search = document.createElement('button');
    search.type = 'button'; search.className = 'desktop-result-search secondary iconed';
    search.dataset.openAdvanced = ''; search.dataset.fullSearch = '';
    search.setAttribute('aria-haspopup', 'dialog'); search.setAttribute('aria-controls', 'advanced-filters');
    search.innerHTML = '<i data-icon="search" aria-hidden="true"></i><span>Search</span>';
    bar.prepend(search);
  }
  const filters = bar.querySelector(".result-filter-trigger");
  if (filters) {
    search.after(filters);
    const count = Number(bar.dataset.activeFilterCount || 0);
    if (count) {
      let badge = filters.querySelector('.desktop-filter-count');
      if (!badge) { badge = document.createElement('b'); badge.className = 'desktop-filter-count'; filters.append(badge); }
      badge.textContent = String(count); filters.querySelector('span').textContent = 'Filters';
      filters.setAttribute("aria-label", `Filters, ${count} active`);
    }
  }
  const menu = bar.querySelector(".result-settings-menu");
  const summary = menu?.querySelector("summary");
  if (summary) {
    summary.setAttribute("aria-label", "Open research tools");
    summary.innerHTML = '<i data-icon="tools" aria-hidden="true"></i><span>Tools</span>';
  }
}

let mobileResultControlsCleanup = null;
function initMobileResultControls() {
  const panel = document.querySelector("#publication-results");
  const bar = panel?.querySelector("[data-smart-result-bar]");
  const form = bar?.querySelector(".result-quick-controls");
  if (!form || panel.dataset.mobileControlsBound === "1") return;
  mobileResultControlsCleanup?.();
  panel.dataset.mobileControlsBound = "1";
  const mobile = window.matchMedia("(max-width: 760px)");
  const labels = [...form.querySelectorAll("label")];
  const exports = bar.querySelector(".publication-exports");
  const exportHost = exports?.parentElement;
  const pageTools = document.querySelector(".page-tools");
  if (pageTools && pageTools.dataset.mobileSheetBound !== "1") {
    pageTools.dataset.mobileSheetBound = "1";
    pageTools.addEventListener("click", event => {
      if (event.target.closest("button")) pageTools.closest("dialog")?.close();
    }, true);
  }
  const accountDesktop = window.matchMedia("(min-width: 1181px)");
  const accountHeader = document.querySelector(".primary-sidebar-header");
  const pageToolsHost = pageTools?.parentElement;
  const pageToolsNext = pageTools?.nextSibling;
  const restorePageTools = () => {
    if (!pageTools) return;
    const host = pageToolsHost?.isConnected ? pageToolsHost : document.body;
    host.insertBefore(pageTools, pageToolsNext?.parentNode === host ? pageToolsNext : null);
  };
  form.id = "publication-quick-controls";
  const dock = document.createElement("nav");
  dock.className = "mobile-result-dock"; dock.hidden = true;
  dock.setAttribute("aria-label", "Publication result controls");
  const button = (label, icon) => {
    const node = document.createElement("button"); node.type = "button";
    node.innerHTML = `<i data-icon="${icon}" aria-hidden="true"></i><span>${label}</span>`;
    node.setAttribute("aria-haspopup", "dialog"); return node;
  };
  const filters = button("Filters", "list-filter"); filters.dataset.openAdvanced = "";
  filters.setAttribute("aria-controls", "advanced-filters");
  const count = Number(bar.dataset.activeFilterCount || 0);
  if (count) { const badge = document.createElement("b"); badge.className = "mobile-filter-count"; badge.textContent = String(count); filters.append(badge); filters.setAttribute("aria-label", `Filters, ${count} active`); }
  const sort = document.createElement('div');
  sort.className = 'mobile-sort-control';
  sort.innerHTML = '<i data-icon="calendar-clock" aria-hidden="true"></i><span aria-hidden="true">Sort</span>';
  const sortSelect = labels[0].querySelector('select');
  sortSelect.setAttribute('aria-label', 'Sort publications');
  const share = button('Share', 'share-2');
  share.removeAttribute('aria-haspopup'); share.className = 'mobile-share-results';
  share.setAttribute('aria-label', 'Share current research results');
  share.addEventListener('click', shareCurrentResults);
  const more = button("More", "more-horizontal");
  dock.append(filters, sort, share, more);
  const makeSheet = (id, title, description, opener) => {
    const dialog = document.createElement("dialog"); dialog.id = id; dialog.className = "mobile-result-sheet";
    dialog.setAttribute("aria-labelledby", id + "-title");
    const header = document.createElement("div"); header.className = "mobile-result-sheet-head";
    const heading = document.createElement("h2"); heading.id = id + "-title"; heading.textContent = title;
    const close = button("Close", "x"); close.removeAttribute("aria-haspopup"); close.className = "mobile-result-sheet-close";
    close.setAttribute("aria-label", "Close " + title.toLowerCase());
    header.append(heading, close);
    const note = document.createElement("p"); note.className = "mobile-result-sheet-note"; note.textContent = description;
    const body = document.createElement("div"); body.className = "mobile-result-sheet-body";
    dialog.append(header, note, body); panel.append(dialog);
    opener.setAttribute("aria-controls", id);
    const dismiss = () => { dialog.close(); updateModalOpenState(); scheduleSmartResultBarUpdate(); };
    close.addEventListener("click", dismiss);
    dialog.addEventListener("click", event => { if (event.target === dialog) { const box = dialog.getBoundingClientRect(); if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) dismiss(); } });
    dialog.addEventListener("close", () => { updateModalOpenState(); scheduleSmartResultBarUpdate(); });
    opener.addEventListener("click", () => { dialog.showModal(); updateModalOpenState(); });
    return { dialog, body };
  };
  const moreSheet = makeSheet("mobile-result-more", "Results and exports", "Choose how many records to show, or export your current selection.", more);
  document.body.append(dock);
  labels.forEach(label => { const select = label.querySelector("select"); select.setAttribute("form", form.id); select.dataset.mobileResultSelect = ""; });
  const placeControls = () => {
    if (!panel.isConnected) { mobile.removeEventListener("change", placeControls); return; }
    const account = document.querySelector("[data-account-navigation]");
    const fullscreen = document.querySelector('#sidebar-fullscreen-toggle');
    const install = document.querySelector('[data-mobile-install-app]');
    for (const id of ['sidebar-share-results','sidebar-print-results']) { const tool=document.getElementById(id);if(tool)(accountDesktop.matches&&pageTools?pageTools:accountHeader)?.append(tool); }
    if (account && pageTools && accountDesktop.matches) pageTools.prepend(account);
    else if (account && accountHeader) accountHeader.append(account);
    if (fullscreen && pageTools && accountDesktop.matches) pageTools.prepend(fullscreen);
    else if (fullscreen && accountHeader) accountHeader.append(fullscreen);
    if (install && pageTools && accountDesktop.matches) pageTools.insertBefore(install, account?.nextSibling || null);
    else if (install && accountHeader) accountHeader.append(install);
    panel.classList.toggle("has-mobile-result-controls", mobile.matches);
    if (mobile.matches) {
      sort.append(sortSelect); labels[0].remove(); moreSheet.body.append(labels[1]);
      if (exports) moreSheet.body.append(exports);
      if (pageTools) moreSheet.body.append(pageTools);
      // Export links retain their original targets, filter parameters and event handlers.
      const exportHeading = document.createElement("h3"); exportHeading.textContent = "Export and data tools";
      if (!moreSheet.body.querySelector("h3") && exports) moreSheet.body.insertBefore(exportHeading, exports);
    } else {
      if (moreSheet.dialog.open) moreSheet.dialog.close();
      labels[0].append(sortSelect);
      labels.forEach(label => form.insertBefore(label, form.querySelector(".sr-only")));
      if (exports && exportHost) exportHost.append(exports);
      if (pageTools && accountDesktop.matches) document.body.append(pageTools);
      else if (pageTools && exportHost) exportHost.append(pageTools);
    }
    scheduleSmartResultBarUpdate();
  };
  accountDesktop.addEventListener("change", placeControls);
  mobile.addEventListener("change", placeControls);
  mobileResultControlsCleanup = () => { accountDesktop.removeEventListener("change", placeControls); mobile.removeEventListener("change", placeControls); restorePageTools(); dock.remove(); };
  placeControls(); refreshIcons();
}

function scheduleSmartResultBarUpdate() {
  if (smartResultBarFrame) return;
  smartResultBarFrame = window.requestAnimationFrame(updateSmartResultBar);
}

function initSmartResultBar() {
  initDesktopResultControls();
  initMobileResultControls();
  if (document.documentElement.dataset.smartResultBarBound !== "1") {
    document.documentElement.dataset.smartResultBarBound = "1";
    window.addEventListener("scroll", scheduleSmartResultBarUpdate, { passive: true });
    window.addEventListener("resize", scheduleSmartResultBarUpdate, { passive: true });
    window.addEventListener("nav-sidebar-statechange", scheduleSmartResultBarUpdate);
    window.visualViewport?.addEventListener("resize", scheduleSmartResultBarUpdate);
    document.addEventListener("focusin", scheduleSmartResultBarUpdate);
    document.addEventListener("focusout", () => setTimeout(scheduleSmartResultBarUpdate, 0));
  }
  scheduleSmartResultBarUpdate();
}

function initResultQuickControls() {
  if (document.documentElement.dataset.resultQuickControlsBound === "1") return;
  document.documentElement.dataset.resultQuickControlsBound = "1";
  document.addEventListener("change", (event) => {
    const select = event.target.closest?.(".result-quick-controls select, [data-mobile-result-select]");
    if (!(select instanceof HTMLSelectElement)) return;
    const form = select.form;
    if (!(form instanceof HTMLFormElement)) return;
    form.querySelector('input[name="page"]')?.setAttribute("value", "1");
    form.requestSubmit();
  });
}

function initSettingsMenus() {
  if (document.documentElement.dataset.settingsMenusBound === "1") return;
  document.documentElement.dataset.settingsMenusBound = "1";
  document.addEventListener("click", (event) => {
    const openMenu = event.target.closest(".command-menu[open]");
    document.querySelectorAll(".command-menu[open]").forEach((menu) => {
      if (menu !== openMenu) menu.removeAttribute("open");
    });
    if (event.target.closest(".command-menu-item, .compact-export-menu a")) {
      window.setTimeout(() => openMenu?.removeAttribute("open"), 0);
    }
  });
  document.addEventListener("keydown", (event) => {
    if (event.key !== "Escape") return;
    document.querySelectorAll(".command-menu[open]").forEach((menu) => {
      menu.removeAttribute("open"); menu.querySelector("summary")?.focus({ preventScroll: true });
    });
  });
}

function initNavSidebarCollapse() {
  const toggle = document.querySelector("#nav-sidebar-toggle");
  const content = document.querySelector("#primary-sidebar-content");
  if (!toggle || !content || toggle.dataset.navSidebarBound === "1") return;
  toggle.dataset.navSidebarBound = "1";
  const compactQuery = window.matchMedia?.("(max-width: 1180px)");
  const isCompact = () => Boolean(compactQuery?.matches);
  const storageKey = () => isCompact() ? "publicationTrackerNavSidebarCollapsedMobile" : "publicationTrackerNavSidebarCollapsedDesktop";
  const taskGroups = [...content.querySelectorAll(".research-task-nav > details")];
  const details = [...content.querySelectorAll('.research-task-nav details, .sidebar-preferences')];
  const detailKey = (node) => {
    const label = (element) => element.querySelector(':scope > summary .research-nav-label, :scope > summary > span')?.textContent.trim() || '';
    const parent=node.parentElement.closest('.research-nav-section');
    return (parent?label(parent)+'/':'')+label(node);
  };
  const disclosureKey='publicationTrackerSidebarDisclosuresV1';
  let restoring=true;
  try {const saved=JSON.parse(sessionStorage.getItem(disclosureKey)||'null');if(saved&&typeof saved==='object')details.forEach(node=>{const key=detailKey(node);if(typeof saved[key]==='boolean')node.open=saved[key];});} catch {}
  const persistDisclosures=()=>{if(restoring)return;try{sessionStorage.setItem(disclosureKey,JSON.stringify(Object.fromEntries(details.map(node=>[detailKey(node),node.open]))));}catch{}};
  details.forEach(node=>node.addEventListener('toggle',persistDisclosures));
  window.addEventListener('pagehide',persistDisclosures);
  requestAnimationFrame(()=>requestAnimationFrame(()=>{restoring=false;}));

  content.querySelectorAll(".research-nav-subgroup").forEach((group) => {
    group.addEventListener("toggle", () => {
      if (!group.open) return;
      [...group.parentElement.children].forEach((other) => {
        if (other !== group && other.matches("details")) other.open = false;
      });
    });
  });
  const sidebar = content.closest('.topbar') || content;
  const drawer = content.querySelector('.primary-sidebar-body') || content;
  const reducedNavigation = matchMedia('(prefers-reduced-motion: reduce)');
  const scrim = document.createElement('div');
  scrim.className = 'nav-menu-scrim';
  scrim.hidden = true;
  scrim.setAttribute('aria-hidden', 'true');
  document.body.append(scrim);
  const originalSemantics = new Map(['role', 'aria-modal', 'aria-label'].map(name => [name, sidebar.getAttribute(name)]));
  const inerted = new Map();
  let navAnimation = null, scrimAnimation = null, navigationTarget = null;
  const syncOverlay = visible => {
    document.documentElement.classList.toggle('nav-menu-open', visible);
    scrim.hidden = !visible;
    if (visible) {
      sidebar.setAttribute('role', 'dialog');
      sidebar.setAttribute('aria-modal', 'true');
      sidebar.setAttribute('aria-label', 'Navigation menu');
      for (const node of document.body.children) {
        if (node === sidebar || node === scrim || node.contains(sidebar) || ['SCRIPT', 'STYLE', 'LINK', 'DIALOG'].includes(node.tagName)) continue;
        if (!inerted.has(node)) inerted.set(node, node.inert);
        node.inert = true;
      }
      if (!sidebar.contains(document.activeElement)) toggle.focus({ preventScroll: true });
    } else {
      for (const [node, inert] of inerted) node.inert = inert;
      inerted.clear();
      for (const [name, value] of originalSemantics) {
        if (value === null) sidebar.removeAttribute(name); else sidebar.setAttribute(name, value);
      }
    }
  };
  let railWasCollapsed = false;
  const closeRailPanels = () => taskGroups.forEach((group) => { group.open = false; });
  taskGroups.forEach((group) => group.addEventListener("toggle", () => {
    if (group.open) {
      taskGroups.forEach((other) => { if (other !== group) other.open = false; });
    }
  }));
  document.addEventListener("click", (event) => {
    if (!isCompact() && document.documentElement.classList.contains("nav-sidebar-collapsed") && !event.target.closest(".research-task-nav")) closeRailPanels();
  });
  content.addEventListener("keydown", (event) => {
    if (event.key !== "Escape" || isCompact() || !document.documentElement.classList.contains("nav-sidebar-collapsed")) return;
    const openGroup = taskGroups.find((group) => group.open);
    if (openGroup) { closeRailPanels(); openGroup.querySelector("summary").focus(); event.preventDefault(); }
  });
  const syncToggle = collapsed => {
    toggle.setAttribute("aria-expanded", collapsed ? "false" : "true");
    const label = isCompact()
      ? (collapsed ? "Open menu" : "Close menu")
      : (collapsed ? "Expand sidebar" : "Collapse sidebar");
    toggle.setAttribute("title", label);
    toggle.querySelector(".sr-only").textContent = label;
    const icon = toggle.querySelector("i[data-icon]");
    if (icon && !isCompact()) icon.setAttribute("data-icon", collapsed ? "chevron-right" : "chevron-left");
  };
  const setCollapsed = (collapsed, { persist = true } = {}) => {
    navAnimation?.cancel(); scrimAnimation?.cancel();
    navAnimation = scrimAnimation = null; navigationTarget = null;
    const railCollapsed = collapsed && !isCompact();
    if (railCollapsed && !railWasCollapsed) {
      taskGroups.forEach((group) => { group.dataset.expandedOpen = group.open ? "1" : "0"; });
      closeRailPanels();
    } else if (!railCollapsed && railWasCollapsed) {
      taskGroups.forEach((group) => { group.open = group.dataset.expandedOpen === "1"; });
    }
    railWasCollapsed = railCollapsed;
    document.documentElement.classList.toggle("nav-sidebar-collapsed", collapsed);
    syncToggle(collapsed);
    const compact = isCompact();
    if (persist) { try { localStorage.setItem(storageKey(), collapsed ? "1" : "0"); } catch (_) {} }
    refreshIcons();
    syncOverlay(compact && !collapsed);
    window.dispatchEvent(new CustomEvent("nav-sidebar-statechange"));
  };
  const transitionCollapsed = collapsed => {
    if (!isCompact() || reducedNavigation.matches || !drawer.animate) {
      setCollapsed(collapsed);
      if (collapsed) toggle.focus({ preventScroll: true });
      return;
    }
    try { localStorage.setItem(storageKey(), collapsed ? '1' : '0'); } catch (_) {}
    const interrupted = navAnimation !== null;
    const startTransform = interrupted ? getComputedStyle(drawer).transform : null;
    const startOpacity = interrupted ? getComputedStyle(drawer).opacity : null;
    const scrimOpacity = getComputedStyle(scrim).opacity;
    navAnimation?.cancel(); scrimAnimation?.cancel();
    if (!collapsed) setCollapsed(false);
    navigationTarget = collapsed;
    syncToggle(collapsed);
    // The shared header stays stationary; only the menu body moves.
    // Only transforms and opacity animate; drawer layout is committed once.
    navAnimation = drawer.animate([
      { transform: startTransform || (collapsed ? 'none' : 'translate3d(-105%,0,0)'), opacity: startOpacity || (collapsed ? 1 : .8) },
      { transform: collapsed ? 'translate3d(-105%,0,0)' : 'none', opacity: collapsed ? .55 : 1 }
    ], { duration: collapsed ? 230 : 310, easing: 'cubic-bezier(.22,1,.36,1)', fill: 'both' });
    scrimAnimation = scrim.animate([{ opacity: interrupted ? scrimOpacity : (collapsed ? 1 : 0) }, { opacity: collapsed ? 0 : 1 }], { duration: collapsed ? 200 : 280, fill: 'both' });
    const current = navAnimation;
    current.finished.then(() => {
      if (navAnimation !== current) return;
      navAnimation = null; navigationTarget = null;
      current.cancel(); scrimAnimation?.cancel(); scrimAnimation = null;
      if (collapsed) { setCollapsed(true); toggle.focus({ preventScroll: true }); }
    }).catch(() => {});
  };
  window.addEventListener('pagehide', () => { if (navigationTarget !== null) setCollapsed(navigationTarget, { persist: false }); });
  scrim.addEventListener('click', () => transitionCollapsed(true));
  document.addEventListener('keydown', event => {
    if (!isCompact() || document.documentElement.classList.contains('nav-sidebar-collapsed') || document.querySelector('dialog[open]')) return;
    if (event.key === 'Escape') {
      event.preventDefault(); transitionCollapsed(true);
    } else if (event.key === 'Tab') {
      const items = [...sidebar.querySelectorAll('a[href], button, input, select, textarea, summary, [tabindex="0"]')]
        .filter(node => {
          if (node.disabled || node.tabIndex < 0 || !node.getClientRects().length || node.closest('[inert]') || getComputedStyle(node).visibility !== 'visible') return false;
          // Closed details can report layout boxes even though their links cannot receive focus.
          for (let parent = node.parentElement; parent && parent !== sidebar; parent = parent.parentElement) {
            if (parent.matches('details:not([open])') && !parent.querySelector(':scope > summary')?.contains(node)) return false;
          }
          return true;
        });
      const first = items[0], last = items[items.length - 1];
      if (event.shiftKey && (document.activeElement === first || !sidebar.contains(document.activeElement))) { event.preventDefault(); last?.focus(); }
      else if (!event.shiftKey && (document.activeElement === last || !sidebar.contains(document.activeElement))) { event.preventDefault(); first?.focus(); }
    }
  });
  // Native dialogs take over focus and dismissal when launched from the drawer.
  new MutationObserver(() => {
    if (isCompact() && !document.documentElement.classList.contains('nav-sidebar-collapsed') && document.querySelector('dialog[open]')) setCollapsed(true);
  }).observe(document.body, { subtree: true, attributes: true, attributeFilter: ['open'] });
  reducedNavigation.addEventListener('change', () => {
    if (reducedNavigation.matches && navigationTarget !== null) setCollapsed(navigationTarget);
  });
  collapsePrimaryNavSidebarOnCompact = () => {
    if (isCompact()) setCollapsed(true);
  };
  const applySavedPreference = () => {
    const compact = isCompact();
    let storedPreference = null;
    try { storedPreference = localStorage.getItem(storageKey()); } catch (_) {}
    setCollapsed(document.body.dataset.sidebarInitial === "collapsed" && !toggle.dataset.initialSidebarApplied ? true : (storedPreference === null ? compact : storedPreference === "1"), { persist: false });
    toggle.dataset.initialSidebarApplied = "1";
  };
  applySavedPreference();
  compactQuery?.addEventListener?.("change", applySavedPreference);
  toggle.addEventListener("click", () => {
    transitionCollapsed(navigationTarget === null ? !document.documentElement.classList.contains("nav-sidebar-collapsed") : !navigationTarget);
  });
  sidebar.addEventListener('click', event => {
    if (!isCompact() || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    const link = event.target.closest('a[href]');
    if (!link || !sidebar.contains(link) || link.hasAttribute('download')) return;
    const href = link.getAttribute('href')?.trim();
    if (!href || href === '#' || /^javascript:/i.test(href)) return;
    // Persist before navigation so the destination also starts with its menu closed.
    setCollapsed(true);
  }, true);
  let swipe = null, suppressClickUntil = 0;
  sidebar.addEventListener('touchstart', event => {
    swipe = null;
    if (!isCompact() || document.documentElement.classList.contains('nav-sidebar-collapsed') || event.touches.length !== 1) return;
    if (event.target.closest('input, textarea, select, [contenteditable="true"]')) return;
    const touch = event.touches[0];
    swipe = { id: touch.identifier, x: touch.clientX, y: touch.clientY, started: performance.now(), horizontal: false };
  }, { passive: true });
  sidebar.addEventListener('touchmove', event => {
    if (!swipe || event.touches.length !== 1) { swipe = null; return; }
    const touch = [...event.touches].find(item => item.identifier === swipe.id);
    if (!touch) { swipe = null; return; }
    const dx = touch.clientX - swipe.x, dy = touch.clientY - swipe.y;
    if (!swipe.horizontal && Math.abs(dy) > 12 && Math.abs(dy) >= Math.abs(dx)) { swipe = null; return; }
    if (dx < -12 && Math.abs(dx) > Math.abs(dy) * 1.5) swipe.horizontal = true;
    if (swipe.horizontal && event.cancelable) event.preventDefault();
  }, { passive: false });
  sidebar.addEventListener('touchend', event => {
    const gesture = swipe;
    swipe = null;
    if (!gesture || !isCompact()) return;
    const touch = [...event.changedTouches].find(item => item.identifier === gesture.id);
    if (!touch) return;
    const dx = touch.clientX - gesture.x, dy = touch.clientY - gesture.y;
    if (dx > -64 || Math.abs(dx) <= Math.abs(dy) * 1.5 || performance.now() - gesture.started > 1800) return;
    suppressClickUntil = performance.now() + 500;
    transitionCollapsed(true);
    toggle.focus({ preventScroll: true });
  }, { passive: true });
  sidebar.addEventListener('touchcancel', () => { swipe = null; }, { passive: true });
  document.addEventListener('click', event => {
    if (performance.now() < suppressClickUntil && event.detail !== 0) { event.preventDefault(); event.stopImmediatePropagation(); }
  }, true);

}

/** Browser-local destination search; publication queries use the main search. */
function initToolFinder() {
  const root = document.querySelector('[data-tool-finder]');
  if (!root || root.dataset.ready === '1') return;
  const input = root.querySelector('input');
  const panel = root.querySelector('.tool-finder-panel');
  const matches = root.querySelector('.tool-finder-matches');
  const results = root.querySelector('ul');
  const status = root.querySelector('[role="status"]');
  const launch = root.querySelector('.tool-finder-launch');
  let catalogue;
  try { catalogue = JSON.parse(root.querySelector('[data-tool-catalog]').textContent); }
  catch { return; }
  root.dataset.ready = '1';
  root.hidden = false;
  const normalise = (text) => text.normalize('NFKD').replace(/\p{M}/gu, '').toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').trim();
  const words = (text) => normalise(text).split(' ').filter(Boolean);
  const ignored = new Set(['a','an','the','and','or','for','to','of','in','on','with','where','how','can','i','my','find','psilocybin','psilocin','research']);
  const oneEdit = (a,b) => {
    if (a.length < 5 || Math.abs(a.length-b.length)>1) return false;
    let i=0, j=0, edits=0;
    while (i<a.length && j<b.length) {
      if (a[i]===b[j]) { i++;j++;continue; }
      if (++edits>1) return false;
      if (a.length===b.length && a[i]===b[j+1] && a[i+1]===b[j]) { i+=2;j+=2; }
      else if (a.length>b.length) i++;
      else if (a.length<b.length) j++;
      else { i++;j++; }
    }
    return edits+(a.length-i)+(b.length-j)<=1;
  };
  const index = catalogue.map((item,position) => ({...item,position,labelText:normalise(item.label),labelWords:words(item.label),aliases:words(item.keywords),context:words(`${item.group} ${item.section} ${item.description}`)}));
  const score = (item, tokens, phrase) => {
    let total = item.labelText===phrase ? 100 : 0;
    for (const token of tokens) {
      let weight = item.labelWords.includes(token) ? 12
        : item.labelWords.some(word=>word.startsWith(token)) ? 9
        : item.aliases.includes(token) ? 8
        : item.aliases.some(word=>word.startsWith(token)) ? 5
        : item.context.some(word=>word===token || word.startsWith(token)) ? 2
        : [...item.labelWords,...item.aliases].some(word=>oneEdit(token,word)) ? 1 : 0;
      if (!weight) return 0;
      total += weight;
    }
    return total;
  };
  const close = ({clear=false}={}) => {
    matches.hidden = true;
    root.classList.remove('is-open');
    launch.setAttribute('aria-expanded','false');
    if (clear) { input.value=''; results.replaceChildren(); status.textContent=''; }
  };
  const render = () => {
    const phrase=normalise(input.value);
    if (phrase.length<2) { matches.hidden=true; results.replaceChildren();status.textContent='';return; }
    const tokens=words(phrase).filter(token=>!ignored.has(token));
    const ranked=tokens.length ? index.map(item=>({item,score:score(item,tokens,phrase)})).filter(hit=>hit.score>0).sort((a,b)=>b.score-a.score || a.item.position-b.item.position) : [];
    results.replaceChildren();
    ranked.slice(0,5).forEach(({item})=>{
      const li=document.createElement('li');
      const link=document.createElement('a'); link.href=item.href;
      const label=document.createElement('strong'); label.textContent=item.label;
      const context=document.createElement('span');context.textContent=[item.group,item.section].filter(Boolean).join(' · ');
      link.append(label,context);
      // Keep homepage modal behaviour when Analytics is chosen from suggestions.
      if (item.key==='analytics' && document.querySelector('#analytics-modal')) {
        link.addEventListener('click',(event)=>{event.preventDefault();document.querySelector('.research-task-nav [data-open-analytics]')?.click();});
      }
      if (item.key==='email-alerts' && document.querySelector('#alert-enrollment')) {
        link.addEventListener('click',(event)=>{event.preventDefault();document.querySelector('.top-actions [data-open-alerts]')?.click();});
      }
      li.append(link);results.append(li);
    });
    status.textContent=ranked.length ? `${Math.min(ranked.length,5)} of ${ranked.length} matching tools and pages` : tokens.length ? 'No matching tool. Try “trials”, “citations” or “downloads”.' : 'Add a task or topic, such as “trials” or “downloads”.';
    matches.hidden=false;
  };
  const open = () => {
    root.classList.add('is-open'); launch.setAttribute('aria-expanded','true');
    input.focus(); render();
  };
  launch.addEventListener('click',()=>root.classList.contains('is-open')?close():open());
  input.addEventListener('input',render);
  input.addEventListener('focus',render);
  root.addEventListener('keydown',(event)=>{
    const links=[...results.querySelectorAll('a')];
    if (event.key==='Escape') {
      event.preventDefault();event.stopPropagation();close({clear:true});
      if (getComputedStyle(launch).display!=='none') launch.focus(); else input.focus();
    } else if ((event.key==='ArrowDown' || event.key==='ArrowUp') && !matches.hidden && links.length) {
      event.preventDefault();
      const current=links.indexOf(document.activeElement);
      const next=event.key==='ArrowDown'?(current+1)%links.length:current<=0?links.length-1:current-1;
      links[next].focus();
    } else if (event.key==='Enter' && event.target===input && !matches.hidden && links.length) {
      event.preventDefault();links[0].click();
    }
  });
  results.addEventListener('click',()=>{
    close();
    collapsePrimaryNavSidebarOnCompact?.();
  });
  root.addEventListener('focusout',()=>window.setTimeout(()=>{
    if (!root.contains(document.activeElement)) close();
  },0));
  document.addEventListener('click',(event)=>{if(!root.contains(event.target))close();});
  document.addEventListener('keydown',(event)=>{
    if ((event.ctrlKey||event.metaKey) && event.key.toLowerCase()==='k' && !event.altKey) {
      event.preventDefault();
      const toggle=document.querySelector('#nav-sidebar-toggle');
      if (window.matchMedia('(max-width:1180px)').matches && document.documentElement.classList.contains('nav-sidebar-collapsed')) toggle?.click();
      open();
    }
  });
  window.addEventListener('nav-sidebar-statechange',()=>close({clear:true}));
}

function initNavGroups() {
  const nav = document.querySelector(".topbar nav");
  const content = document.querySelector("#primary-sidebar-content");
  if (!nav || !content || nav.dataset.navGroupReady === "1" || nav.querySelector(".research-task-nav")) return;
  nav.dataset.navGroupReady = "1";
  const compactQuery = window.matchMedia?.("(max-width: 1180px)");
  const isCompact = () => Boolean(compactQuery?.matches);
  const shouldGroup = () => isCompact() || !document.documentElement.classList.contains("nav-sidebar-collapsed");
  const originalItems = [...nav.children];
  const groups = [
    { name: "Literature", labels: new Set(["Publications", "Research guide", "Alcohol evidence", "Depression evidence", "Evidence", "Dataset", "Research trends", "Trials"]) },
    { name: "Visualize", labels: new Set(["Citation Network", "Trials Map"]) },
    { name: "Tools", labels: new Set(["Open Datasets", "Authors", "Analytics", "R script", "Export data", "API"]) },
    { name: "Project", labels: new Set(["GitHub", "Zenodo DOI", "About", "Data protection"]) },
    { name: "In development", labels: new Set(["Brain Model", "Receptor Binding", "Chemical Structure"]) },
  ];
  const labelFor = (item) => item.querySelector("span")?.textContent?.trim() || item.textContent.trim();
  const groupFor = (item) => groups.find((group) => group.labels.has(labelFor(item))) || groups.find((group) => group.name === "Project");
  const isActiveItem = (item) => item.matches?.("[aria-current='location'], .is-active") || Boolean(item.querySelector?.("[aria-current='location'], .is-active"));
  const buildGroup = (group, items, activeGroupName) => {
    const details = document.createElement("details");
    details.className = "nav-group";
    details.dataset.navGroup = group.name;
    const summary = document.createElement("summary");
    summary.innerHTML = `<span>${group.name}</span><i data-icon="chevron-down" aria-hidden="true"></i>`;
    const body = document.createElement("div");
    body.className = "nav-group-body";
    items.forEach((item) => body.append(item));
    details.append(summary, body);
    if (group.name === (activeGroupName || "Literature")) details.open = true;
    details.addEventListener("toggle", () => {
      if (!details.open || !nav.contains(details)) return;
      nav.querySelectorAll(".nav-group[open]").forEach((other) => {
        if (other !== details) other.open = false;
      });
    });
    return details;
  };
  const apply = () => {
    if (!shouldGroup()) {
      nav.classList.remove("is-nav-grouped");
      nav.replaceChildren(...originalItems);
      refreshIcons();
      return;
    }
    const byGroup = new Map(groups.map((group) => [group.name, []]));
    originalItems.forEach((item) => byGroup.get(groupFor(item).name).push(item));
    const activeItem = originalItems.find(isActiveItem);
    const activeGroupName = activeItem ? groupFor(activeItem).name : "";
    const fragment = document.createDocumentFragment();
    groups.forEach((group) => {
      const items = byGroup.get(group.name) || [];
      if (items.length) fragment.append(buildGroup(group, items, activeGroupName));
    });
    nav.classList.add("is-nav-grouped");
    nav.replaceChildren(fragment);
    refreshIcons();
  };
  apply();
  compactQuery?.addEventListener?.("change", apply);
  window.addEventListener("nav-sidebar-statechange", apply);
}

function initFloatingExportHoverMenu() {
  if (document.documentElement.dataset.floatingExportHoverBound === "1") return;
  document.documentElement.dataset.floatingExportHoverBound = "1";
  let closeTimer = 0;
  document.addEventListener("pointerover", (event) => {
    if (event.pointerType === "touch") return;
    const bar = event.target.closest?.("[data-smart-result-bar].is-smart-floating");
    if (!bar || bar.dataset.desktopControlsBound === "1") return;
    const exportMenu = event.target.closest?.(".result-settings-menu");
    if (exportMenu) {
      window.clearTimeout(closeTimer);
      exportMenu.open = true;
    }
  });
  document.addEventListener("pointerout", (event) => {
    if (event.pointerType === "touch") return;
    const exportMenu = event.target.closest?.(".result-settings-menu");
    if (!exportMenu || exportMenu.closest("[data-desktop-controls-bound]") || exportMenu.contains(event.relatedTarget)) return;
    window.clearTimeout(closeTimer);
    closeTimer = window.setTimeout(() => {
      if (!exportMenu.matches(":hover, :focus-within")) exportMenu.open = false;
    }, 180);
  });
  document.addEventListener("click", (event) => {
    const closeButton = event.target.closest?.("[data-close-export-menu]");
    if (!closeButton) return;
    const exportMenu = closeButton.closest(".result-settings-menu");
    if (!(exportMenu instanceof HTMLDetailsElement)) return;
    exportMenu.open = false;
    exportMenu.querySelector("summary")?.focus();
  });
  document.addEventListener("keydown", (event) => {
    if (event.key !== "Escape") return;
    const exportMenu = document.querySelector(".result-settings-menu[open]");
    if (!(exportMenu instanceof HTMLDetailsElement)) return;
    exportMenu.open = false;
    exportMenu.querySelector("summary")?.focus();
  });
}

function initEntryChoices() {
  const choices = document.querySelectorAll("[data-entry-action]");
  if (!choices.length) return;
  const setActive = (activeChoice) => {
    choices.forEach((choice) => {
      const active = choice === activeChoice;
      choice.classList.toggle("is-active", active);
      choice.setAttribute("aria-pressed", active ? "true" : "false");
    });
  };
  choices.forEach((choice) => {
    if (choice.dataset.entryChoiceBound === "1") return;
    choice.dataset.entryChoiceBound = "1";
    choice.addEventListener("click", () => {
      setActive(choice);
      const action = choice.dataset.entryAction;
      if (action === "latest") {
        const resultsPanel = document.querySelector("#publication-results") || document.querySelector("#papers");
        if (resultsPanel) {
          const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
          const targetY = Math.max(0, window.scrollY + resultsPanel.getBoundingClientRect().top - currentScrollOffset());
          window.scrollTo({ top: targetY, behavior: reduceMotion ? "auto" : "smooth" });
          resultsPanel.focus({ preventScroll: true });
        }
        showAppToast("Showing the latest indexed publications.");
        return;
      }
      if (action === "search") {
        const searchInput = document.querySelector("#hero-q");
        document.querySelector(".hero-search")?.scrollIntoView({ behavior: "smooth", block: "center" });
        window.setTimeout(() => searchInput?.focus(), 260);
        showAppToast("Search is ready. Enter a keyword, author, DOI, or topic.");
        return;
      }
      if (action === "alert") {
        document.querySelector("[data-open-alerts]")?.click();
      }
    });
  });
}

function initScrollTop() {
  document.querySelector('#scroll-progress')?.remove();
  let button=document.querySelector('#scroll-top');
  if(!button){
    button=document.createElement('button');button.id='scroll-top';button.className='scroll-top';button.type='button';
    button.innerHTML=`<i aria-hidden="true">${ICONS['arrow-up']}</i><span>Top</span>`;document.body.append(button);
  }
  if(button.dataset.scrollReady==='1')return;
  button.dataset.scrollReady='1';button.setAttribute('aria-label','Scroll to top');
  button.setAttribute('aria-description','The surrounding ring shows page scroll progress.');
  button.insertAdjacentHTML('afterbegin','<svg class="scroll-top-ring" viewBox="0 0 56 56" aria-hidden="true" focusable="false"><circle class="scroll-top-track" cx="28" cy="28" r="23.5"/><circle class="scroll-top-progress" cx="28" cy="28" r="23.5" pathLength="100"/></svg>');
  const ring=button.querySelector('.scroll-top-progress');let frame=0,last=-1;
  const update=()=>{
    frame=0;const doc=document.documentElement,max=Math.max(0,doc.scrollHeight-doc.clientHeight);
    const ratio=max?Math.max(0,Math.min(1,scrollY/max)):0;
    ring.style.strokeDashoffset=String(100-ratio*100);
    button.classList.toggle('is-visible',max>0&&scrollY>Math.min(400,doc.clientHeight*.6,max/3));
    const percent=Math.round(ratio*100);
    if(percent!==last){button.title=`Scroll to top · ${percent}% of page`;last=percent;}
  };
  const schedule=()=>{if(!frame)frame=requestAnimationFrame(update);};
  button.addEventListener('click',()=>{
    window.scrollTo({top:0,behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'instant':'smooth'});
    const heading=document.querySelector('h1');
    if(heading){const hadTabIndex=heading.hasAttribute('tabindex');if(!hadTabIndex)heading.setAttribute('tabindex','-1');heading.focus({preventScroll:true});if(!hadTabIndex)heading.addEventListener('blur',()=>heading.removeAttribute('tabindex'),{once:true});}
  });
  window.addEventListener('scroll',schedule,{passive:true});window.addEventListener('resize',schedule);
  window.addEventListener('pageshow',schedule);window.addEventListener('tracker:results-loaded',schedule);
  if('ResizeObserver' in window)new ResizeObserver(schedule).observe(document.body);
  update();
}

function browserFromUserAgent(userAgent) {
  const ua = userAgent || "";
  const rules = [
    ["Firefox", /Firefox\/([\d.]+)/],
    ["Edge", /Edg\/([\d.]+)/],
    ["Chrome", /Chrome\/([\d.]+)/],
    ["Safari", /Version\/([\d.]+).*Safari/],
  ];
  for (const [name, pattern] of rules) {
    const match = ua.match(pattern);
    if (match) return `${name} ${match[1]}`;
  }
  return "Browser unavailable";
}

function osFromUserAgent(userAgent) {
  const ua = userAgent || "";
  const rules = [
    ["Windows", /Windows NT ([\d.]+)/],
    ["macOS", /Mac OS X ([\d_]+)/],
    ["iOS", /(?:iPhone|iPad).*OS ([\d_]+)/],
    ["Android", /Android ([\d.]+)/],
    ["Linux", /Linux/],
  ];
  for (const [name, pattern] of rules) {
    const match = ua.match(pattern);
    if (!match) continue;
    return match[1] ? `${name} ${match[1].replaceAll("_", ".")}` : name;
  }
  return "Operating system unavailable";
}

function initClientEnvironment() {
  const target = document.querySelector("[data-client-environment]");
  if (!target) return;
  const uaData = navigator.userAgentData;
  const platform = uaData?.platform || osFromUserAgent(navigator.userAgent);
  const brand = uaData?.brands?.find((item) => !/Not/i.test(item.brand)) || uaData?.brands?.[0];
  const browser = brand ? `${brand.brand} ${brand.version}` : browserFromUserAgent(navigator.userAgent);
  target.textContent = `Client: ${browser}; ${platform}.`;
}

function initCountUpStats() {
  const counters = document.querySelectorAll("[data-count-up]");
  if (!counters.length) return;
  const reducedMotion = window.matchMedia?.("(prefers-reduced-motion: reduce)").matches;
  counters.forEach((counter) => {
    const locale = counter.getAttribute("data-count-locale") || undefined;
    const formatter = new Intl.NumberFormat(locale, { maximumFractionDigits: 0 });
    const target = Number.parseInt(counter.getAttribute("data-count-up") || "0", 10);
    if (!Number.isFinite(target) || target < 1 || reducedMotion) {
      counter.textContent = formatter.format(Math.max(target, 0));
      return;
    }
    const duration = 950;
    const start = performance.now();
    counter.classList.add("is-counting");
    const tick = (now) => {
      const progress = Math.min((now - start) / duration, 1);
      const eased = 1 - Math.pow(1 - progress, 3);
      counter.textContent = formatter.format(Math.round(target * eased));
      if (progress < 1) {
        window.requestAnimationFrame(tick);
        return;
      }
      counter.textContent = formatter.format(target);
      counter.classList.remove("is-counting");
    };
    counter.textContent = "0";
    window.requestAnimationFrame(tick);
  });
}

function initGsapEnhancements() {
  if (!window.gsap || window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;
  const gsap = window.gsap;
  gsap.set([".hero-card", ".panel"], { willChange: "transform, opacity" });
  gsap.fromTo(".hero-card", { opacity: 0, y: 14 }, { opacity: 1, y: 0, duration: 0.55, ease: "power2.out", clearProps: "transform,opacity,willChange" });
  gsap.fromTo(".filters, .results > .panel, .right-rail > .panel", { opacity: 0, y: 10 }, { opacity: 1, y: 0, duration: 0.36, stagger: 0.025, delay: 0.12, ease: "power2.out", clearProps: "transform,opacity,willChange" });
}

function currentScrollOffset() {
  const topbar = document.querySelector(".topbar");
  if (window.matchMedia("(min-width: 1181px)").matches) return 12;
  return (topbar?.getBoundingClientRect().height || 0) + 12;
}

function focusResultsPanel() {
  const results = document.querySelector("#search-context")
    || document.querySelector("#publication-results")
    || document.querySelector("#matching-results-body")
    || document.querySelector("#papers");
  if (!results) return;
  const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  const targetY = Math.max(0, window.scrollY + results.getBoundingClientRect().top - currentScrollOffset());
  window.scrollTo({ top: targetY, behavior: reduceMotion ? "auto" : "smooth" });
  results.focus({ preventScroll: true });
  results.classList.add("is-search-arrival");
  window.setTimeout(() => results.classList.remove("is-search-arrival"), 1800);
}

function syncSearchInputs(params) {
  const q = params.get("search") || params.get("q") || "";
  const author = params.get("author") || "";
  for (const input of document.querySelectorAll('input[name="search"], input[name="q"]')) input.value = q;
  for (const input of document.querySelectorAll('input[name="author"]')) input.value = author;
}

function syncSearchInputsFromLocation() {
  syncSearchInputs(new URLSearchParams(window.location.search));
}

function replaceSection(selector, nextDocument) {
  const current = document.querySelector(selector);
  const next = nextDocument.querySelector(selector);
  if (!current || !next) return;
  current.replaceWith(next);
}

let pendingSearchController = null;

let renderedSearchLocation = window.location.pathname + window.location.search;

async function loadSearchUrl(url, { push = true } = {}) {
  pendingSearchController?.abort();
  const controller = new AbortController();
  pendingSearchController = controller;
  hidePreloader();
  const target = new URL(url, window.location.href);
  target.hash = "";
  closeOpenDialogsForDomSwap();
  document.documentElement.classList.add("is-searching");
  let completedSearch = false;
  let searchPreloader = null;
  const countChanged = (target.searchParams.get("per_page") || "20") !== (new URLSearchParams(window.location.search).get("per_page") || "20");
  if (countChanged) searchPreloader = showSearchPreloader(target);
  const searchPreloaderTimer = window.setTimeout(() => {
    if (!controller.signal.aborted && pendingSearchController === controller) searchPreloader = showSearchPreloader(target);
  }, countChanged ? 0 : 450);
  try {
    const response = await fetch(target.href, {
      headers: { Accept: "text/html" },
      cache: "no-store",
      signal: controller.signal,
    });
    if (!response.ok) throw new Error("Search request failed");
    window.trackerOfflinePage = response.headers.get("X-Tracker-Offline") === "1" ? { snapshot: "", kind: "home" } : null;
    window.dispatchEvent(new Event("tracker:connection-status"));
    const html = await response.text();
    if (controller.signal.aborted) return;
    const nextDocument = new DOMParser().parseFromString(html, "text/html");
    window.clearTimeout(searchPreloaderTimer);
    const confirmedResults = nextDocument.querySelector("#papers");
    if (searchPreloader && confirmedResults) setPreloaderContext(confirmedResults.dataset.loadingTitle, confirmedResults.dataset.loadingDetail);
    const robots = nextDocument.querySelector('meta[name="robots"]');
    if (robots) document.querySelector('meta[name="robots"]')?.setAttribute("content", robots.content);
    closeOpenDialogsForDomSwap();
    replaceSection(".hero-action-summary", nextDocument);
    replaceSection(".advanced-filter-form", nextDocument);
    replaceSection("#alert-option-catalog", nextDocument);
    replaceSection(".results", nextDocument);
    if (push) {
      window.history.pushState({ publicationTrackerSearch: true }, "", `${target.pathname}${target.search}#papers`);
    }
    renderedSearchLocation = target.pathname + target.search;
    syncSearchInputsFromLocation();
    window.dispatchEvent(new Event("tracker:results-loaded"));
    refreshIcons();
    bindCitationButtons(document.querySelector("#papers") || document);
    initAppSheets();
    initAdvancedFilterSearchSync();
    initAlertMultiselects();
    initSidebarControls();
    initSmartResultBar();
    initResultQuickControls();
    initCollectionExport();
    syncSidebarResultActions();
    focusResultsPanel();
    showAppToast("Search results updated.");
    completedSearch = true;
  } catch (error) {
    if (controller.signal.aborted) return;
    window.location.href = `${target.pathname}${target.search}#papers`;
  } finally {
    window.clearTimeout(searchPreloaderTimer);
    if (pendingSearchController === controller) {
      if (searchPreloader) hidePreloader();
      document.documentElement.classList.remove("is-searching");
      pendingSearchController = null;
      if (completedSearch && !controller.signal.aborted) window.dispatchEvent(new Event("tracker:search-complete"));
    }
  }
  return completedSearch;
}

function urlFromForm(form) {
  const url = new URL(form.getAttribute("action") || window.location.pathname, window.location.href);
  const data = new FormData(form);
  const params = new URLSearchParams();
  for (const [key, value] of data.entries()) {
    if (typeof value !== "string") continue;
    if (value === "" && key !== "page") continue;
    params.append(key, value);
  }
  if (form.matches(".advanced-filter-form")) {
    const statuses = [...form.querySelectorAll('input[name="publication_statuses[]"]')];
    if (statuses.length && statuses.every(input => input.checked)) params.delete("publication_statuses[]");
  }
  if (!params.has("page")) params.set("page", "1");
  url.search = params.toString();
  url.hash = "papers";
  return url;
}

function initAjaxSearch() {
  syncSearchInputsFromLocation();
  window.addEventListener("pageshow", syncSearchInputsFromLocation);

  document.addEventListener("submit", (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    if ((form.getAttribute("method") || "get").toLowerCase() !== "get") return;
    if (!form.matches(".hero-search, .advanced-filter-form, .result-quick-controls")) return;
    event.preventDefault();
    const completed = loadSearchUrl(urlFromForm(form));
    if (event.agentInvoked && typeof event.respondWith === 'function') {
      event.respondWith(completed.then(done => {
        if (!done) throw new Error('Search did not complete; retry against the current page.');
        return {
        url: currentResultsShareUrl(),
        total: Number(document.querySelector('#papers')?.dataset.resultTotal || 0),
        records: [...document.querySelectorAll('#matching-results-body .paper')].map(paper => ({
          title: paper.querySelector('.paper-main h3 a')?.textContent?.trim() || '',
          url: paper.querySelector('.paper-main h3 a')?.href || '',
          metadata: paper.querySelector('.paper-meta')?.textContent?.trim() || ''
        }))
      }; }));
    }
  });

  document.addEventListener("click", (event) => {
    const link = event.target.closest(".pager a:not(.disabled), .results-bottom-pager a:not(.disabled), .search-context-reset, .advanced-filter-reset, .rank-filter-link, .active-filter-chip, .filter-reset, .search-recovery-link");
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    if (!(link instanceof HTMLAnchorElement)) return;
    const url = new URL(link.href, window.location.href);
    if (url.origin !== window.location.origin || url.pathname !== window.location.pathname) return;
    event.preventDefault();
    loadSearchUrl(url);
  });

  window.addEventListener("popstate", () => {
    // Fragment-only navigation (including analytics) is not a new search.
    if (window.location.pathname + window.location.search === renderedSearchLocation) return;
    loadSearchUrl(window.location.href, { push: false });
  });
}

function initSearchResultFocus() {
  const results = document.querySelector("#papers");
  if (!results) return;
  const params = new URLSearchParams(window.location.search);
  const hasQuery = ["search", "q", "author", "journal", "topic", "study_type", "year", "from", "to"].some((key) => [...params.keys()].some(k=>(k===key||k.startsWith(key+"["))&&(params.get(k)||"").trim()));
  const hash = window.location.hash;
  const shouldFocusResults = hash === "#papers" || (hasQuery && (!hash || hash === "#papers"));
  if (!shouldFocusResults) return;
  const focusResults = () => {
    focusResultsPanel();
  };
  window.setTimeout(focusResults, 180);
}

function syncSidebarResultActions() {
  const printButton = document.querySelector("#sidebar-print-results");
  if (!printButton) return;
  if (!document.querySelector('#publication-results')) { printButton.disabled=false;printButton.removeAttribute('aria-disabled');printButton.title='Print this page';return; }
  const hasPrintableResults = document.querySelectorAll("#matching-results-body .paper").length > 0;
  printButton.disabled = !hasPrintableResults;
  printButton.setAttribute("aria-disabled", hasPrintableResults ? "false" : "true");
  printButton.setAttribute("title", hasPrintableResults ? "Print visible results" : "No visible results to print");
}

function publicResultParams(params = new URLSearchParams(window.location.search)) {
  const allowed = new Set(['search', 'q', 'author', 'journal', 'topic', 'study_type', 'cited_doi', 'year', 'from', 'to', 'added_from', 'added_to', 'substances', 'sources', 'publication_statuses', 'range', 'sort', 'per_page', 'page']);
  const clean = new URLSearchParams();
  for (const [key, value] of params) {
    const match = key.match(/^([a-z_]+)(?:\[\d*\])?$/);
    if (!match || !allowed.has(match[1]) || !value.trim()) continue;
    if ((key === 'range' && value === 'all') || (key === 'page' && value === '1') || (key === 'per_page' && value === '20') || (key === 'sort' && value === 'newly_added')) continue;
    clean.append(key, value);
  }
  return clean;
}

function hasShareableResultFilters(params = new URLSearchParams(window.location.search)) {
  return publicResultParams(params).toString().length > 0;
}

function canonicalShareBaseUrl() {
  const canonical = document.querySelector("link[rel='canonical']")?.href || "https://psilocybin-research.com/";
  return new URL(canonical, window.location.href);
}

function currentResultsShareUrl() {
  if (!document.querySelector('#publication-results')) return canonicalShareBaseUrl().href;
  const params = publicResultParams();
  const url = canonicalShareBaseUrl();
  if (params.toString()) {
    url.search = params.toString();
    url.hash = "papers";
  } else {
    url.search = "";
    url.hash = "";
  }
  return url.href;
}

function shareTextForCurrentResults() {
  const count = document.querySelector(".result-title-block p")?.textContent?.trim() || "";
  const context = document.querySelector(".search-context strong")?.textContent?.trim() || "";
  if (hasShareableResultFilters() && context && !/No search or filter specified/i.test(context)) {
    return `${count ? `${count}. ` : ""}Search context: ${context}.`;
  }
  return "Search and analyze psilocybin and psilocin publications, preprints, protocols, reviews, and clinical trials.";
}

async function shareCurrentResults() {
  const results=!!document.querySelector('#publication-results');
  const payload = { title: results?'Psilocybin Research Database':document.title, text: results?shareTextForCurrentResults():'', url: currentResultsShareUrl() };
  if (navigator.share) {
    try { await navigator.share(payload); return; }
    catch (error) { if (error?.name === 'AbortError') return; }
  }
  try {
    await navigator.clipboard.writeText(payload.url);
    showAppToast('Link copied.');
  } catch (_) { showAppToast(`Copy this link: ${payload.url}`, 12000); }
}

function initNativeShare() {
  const button = document.querySelector('#sidebar-share-results');
  if (!button || button.dataset.shareReady === '1') return;
  button.dataset.shareReady = '1'; button.hidden = false;
  button.setAttribute('title', document.querySelector('#publication-results')?'Share current results':'Share this page');
  button.addEventListener('click', shareCurrentResults);
}

function initFullscreenToggle() {
  const button = document.querySelector("#sidebar-fullscreen-toggle");
  if (!button) return;
  const fullscreenEnabled = Boolean(document.fullscreenEnabled || document.webkitFullscreenEnabled);
  const fullscreenElement = () => document.fullscreenElement || document.webkitFullscreenElement || null;
  const requestFullscreen = (element) => {
    if (element.requestFullscreen) return element.requestFullscreen();
    if (element.webkitRequestFullscreen) return element.webkitRequestFullscreen();
    return Promise.reject(new Error("Fullscreen unavailable"));
  };
  const exitFullscreen = () => {
    if (document.exitFullscreen) return document.exitFullscreen();
    if (document.webkitExitFullscreen) return document.webkitExitFullscreen();
    return Promise.reject(new Error("Fullscreen exit unavailable"));
  };
  if (!fullscreenEnabled) {
    button.hidden = true;
    return;
  }
  const sync = () => {
    const active = Boolean(fullscreenElement());
    button.hidden = false;
    button.setAttribute("aria-pressed", active ? "true" : "false");
    button.setAttribute("title", active ? "Exit fullscreen" : "Open fullscreen");
    button.innerHTML = `<i data-icon="${active ? "minimize" : "maximize"}" aria-hidden="true"></i><span>${active ? "Exit fullscreen" : "Full screen"}</span>`;
    refreshIcons();
  };
  if (button.dataset.fullscreenBound !== "1") {
    button.dataset.fullscreenBound = "1";
    button.addEventListener("click", async () => {
      try {
        if (fullscreenElement()) {
          await exitFullscreen();
          showAppToast("Exited fullscreen.");
        } else {
          await requestFullscreen(document.documentElement);
          showAppToast("Fullscreen mode enabled.");
        }
      } catch (error) {
        showAppToast("Fullscreen is not available in this browser window.");
      }
    });
    document.addEventListener("fullscreenchange", sync);
    document.addEventListener("webkitfullscreenchange", sync);
  }
  sync();
}

function paperDataForPrint() {
  return Array.from(document.querySelectorAll("#matching-results-body .paper")).map((paper) => {
    const titleLink = paper.querySelector(".paper-main h3 a");
    const meta = Array.from(paper.querySelectorAll(".paper-meta span")).map((item) => item.textContent?.trim() || "");
    const doiLink = Array.from(paper.querySelectorAll(".links a[href]")).find((link) => (link.textContent || "").includes("DOI"));
    const pubmedLink = Array.from(paper.querySelectorAll(".links a[href]")).find((link) => (link.textContent || "").includes("PubMed"));
    return {
      title: titleLink?.textContent?.trim() || "Untitled publication",
      journal: paper.querySelector(".paper-meta strong")?.textContent?.trim() || "",
      date: meta[0] || "",
      authors: meta[1] || "",
      source: paper.querySelector(".source-badge")?.textContent?.trim() || "",
      doi: doiLink?.textContent?.replace(/^DOI\s*/i, "").trim() || "",
      pubmedId: pubmedLink?.textContent?.replace(/^PubMed\s*/i, "").trim() || "",
    };
  });
}

function parsePrintDate(value) {
  const match = String(value || "").match(/\d{4}-\d{2}-\d{2}/);
  return match ? match[0] : "";
}

function printPaperDate(paper) {
  return paper.date || paper.publication_date || "";
}

function printPaperSource(paper) {
  return paper.source || paper.source_name || "";
}

function printPaperPubMedId(paper) {
  return paper.pubmedId || paper.pubmed_id || "";
}

function printDateRangeLabel(papers) {
  const dates = papers
    .map((paper) => parsePrintDate(printPaperDate(paper)))
    .filter(Boolean)
    .sort();
  if (!dates.length) return "No publication dates available";
  const first = dates[0];
  const last = dates[dates.length - 1];
  return first === last ? first : `${first} to ${last}`;
}

function confirmPrintResults(papers) {
  const count = papers.length;
  const dateRange = printDateRangeLabel(papers);
  return window.confirm(`Print ${count} ${count === 1 ? "record" : "records"}?\n\nDate range: ${dateRange}`);
}

function printSourcesLabel(papers) {
  const sources = Array.from(new Set(papers.map((paper) => printPaperSource(paper)).filter(Boolean))).sort();
  return sources.length ? sources.join(", ") : "Not specified";
}

function printReferenceText(paper) {
  const year = parsePrintDate(printPaperDate(paper)).slice(0, 4);
  const parts = [];
  if (paper.authors) parts.push(paper.authors);
  if (year) parts.push(`(${year}).`);
  parts.push(paper.title.endsWith(".") ? paper.title : `${paper.title}.`);
  if (paper.journal) parts.push(paper.journal.endsWith(".") ? paper.journal : `${paper.journal}.`);
  if (paper.doi) parts.push(`doi:${paper.doi}`);
  const pubmedId = printPaperPubMedId(paper);
  if (pubmedId) parts.push(`PMID:${pubmedId}`);
  return parts.join(" ").replace(/\s+/g, " ").trim();
}

function printResultsHtml(papers) {
  const count = papers.length;
  const dateRange = printDateRangeLabel(papers);
  const sources = printSourcesLabel(papers);
  const generated = new Date().toLocaleString(undefined, { dateStyle: "medium", timeStyle: "short" });
  return `<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Scientific references | Psilocybin Research</title>
  <style>
    @page{margin:16mm 14mm}
    body{margin:0;color:#111;font-family:Georgia,"Times New Roman",serif;font-size:11pt;line-height:1.42}
    header{border-bottom:1px solid #111;margin-bottom:14px;padding-bottom:8px;font-family:Arial,Helvetica,sans-serif;font-size:9pt;color:#222}
    header dl{display:grid;grid-template-columns:30mm 1fr;gap:3px 8px;margin:0}
    header dt{font-weight:700}
    header dd{margin:0}
    ol{margin:0;padding-left:20px}
    li{break-inside:avoid;margin:0 0 8px;padding-left:3px}
    footer{border-top:1px solid #bbb;margin-top:14px;padding-top:7px;font-family:Arial,Helvetica,sans-serif;font-size:9pt;color:#333}
    footer a{color:#111;text-decoration:none}
    @media screen{body{background:#ffffff;padding:28px}main{max-width:850px;margin:auto;background:white;border:1px solid #d8d2c4;border-left:4px solid #123c31;border-radius:8px;padding:28px;box-shadow:0 1px 2px rgba(36,37,31,.08)}footer a{color:#1a6b54;font-weight:800}}
  </style>
</head>
<body>
  <main>
    <header>
      <dl>
        <dt>Generated</dt><dd>${escapeHtml(generated)}</dd>
        <dt>Entries</dt><dd>${escapeHtml(String(count))}</dd>
        <dt>Date range</dt><dd>${escapeHtml(dateRange)}</dd>
        <dt>Sources</dt><dd>${escapeHtml(sources)}</dd>
      </dl>
    </header>
    <ol>
      ${papers.map((paper) => `<li>${escapeHtml(printReferenceText(paper))}</li>`).join("")}
    </ol>
    <footer><a href="https://psilocybin-research.com/">psilocybin-research.com</a></footer>
  </main>
</body>
</html>`;
}

function initPrintResults() {
  const button = document.querySelector("#sidebar-print-results");
  if (!button) return;
  syncSidebarResultActions();
  if (button.dataset.printBound === "1") return;
  button.dataset.printBound = "1";
  button.addEventListener("click", () => {
    if (!document.querySelector('#publication-results')) { window.print();return; }
    const papers = paperDataForPrint();
    if (!papers.length) {
      syncSidebarResultActions();
      showAppToast("No visible results are available to print.");
      return;
    }
    if (!confirmPrintResults(papers)) return;
    const frame = document.createElement("iframe");
    frame.title = "Printable publication results";
    frame.setAttribute("aria-hidden", "true");
    frame.style.position = "fixed";
    frame.style.right = "0";
    frame.style.bottom = "0";
    frame.style.width = "0";
    frame.style.height = "0";
    frame.style.border = "0";
    document.body.append(frame);
    const printDocument = frame.contentDocument || frame.contentWindow?.document;
    if (!printDocument || !frame.contentWindow) {
      frame.remove();
      showAppToast("Could not prepare the print view.");
      return;
    }
    printDocument.open();
    printDocument.write(printResultsHtml(papers));
    printDocument.close();
    frame.onload = () => {
      frame.contentWindow?.focus();
      frame.contentWindow?.print();
      window.setTimeout(() => frame.remove(), 1000);
    };
    window.setTimeout(() => {
      if (!frame.isConnected) return;
      frame.contentWindow?.focus();
      frame.contentWindow?.print();
      window.setTimeout(() => frame.remove(), 1000);
    }, 350);
  });
}

for (const input of document.querySelectorAll('input[name="range"]')) {
  input.addEventListener("change", () => {
    if (input.value !== "custom") {
      document.querySelector('input[name="from"]').value = "";
      document.querySelector('input[name="to"]').value = "";
    }
  });
}

for (const form of document.querySelectorAll("form")) {
  form.addEventListener("submit", () => {
    if ((form.getAttribute("method") || "get").toLowerCase() === "get") return;
    const button = form.querySelector('button[type="submit"]');
    if (!button) return;
    button.dataset.originalText = button.textContent?.trim() || "";
    button.innerHTML = '<span class="spinner" aria-hidden="true"></span><span>Loading...</span>';
    button.setAttribute("aria-busy", "true");
  });
}

// Delegation also covers the card after an AJAX results refresh.
document.addEventListener("click", async (event) => {
  const button = event.target.closest?.("[data-copy-mcp-url]");
  if (!button || button.disabled) return;
  const address = button.closest(".mcp-connect-card")?.querySelector("code");
  if (!address) return;
  button.disabled = true;
  try {
    await navigator.clipboard.writeText(address.textContent.trim());
    button.textContent = "Copied";
    showAppToast("MCP server address copied.");
  } catch (error) {
    address.focus({ preventScroll: true });
    const range = document.createRange();
    range.selectNodeContents(address);
    const selection = window.getSelection();
    selection?.removeAllRanges();
    selection?.addRange(range);
    showAppToast("Select the highlighted server address and copy it.");
  } finally {
    window.setTimeout(() => { button.textContent = "Copy"; button.disabled = false; }, 1600);
  }
});

function bindCitationButtons(scope = document) {
for (const button of scope.querySelectorAll(".copy-citation")) {
  button.addEventListener("click", async () => {
    const citation = button.getAttribute("data-citation") || "";
    try {
      await navigator.clipboard.writeText(citation);
      button.innerHTML = '<i data-icon="check" aria-hidden="true"></i><span>Copied</span>';
      refreshIcons();
      showAppToast("Citation copied to clipboard.");
      setTimeout(() => { button.innerHTML = '<i data-icon="copy" aria-hidden="true"></i><span>Copy citation</span>'; refreshIcons(); }, 1400);
    } catch (error) {
      button.innerHTML = '<i data-icon="circle-alert" aria-hidden="true"></i><span>Copy failed</span>';
      refreshIcons();
      showAppToast("Could not copy the citation.");
      setTimeout(() => { button.innerHTML = '<i data-icon="copy" aria-hidden="true"></i><span>Copy citation</span>'; refreshIcons(); }, 1800);
    }
  });
}
for (const button of scope.querySelectorAll(".copy-bibtex")) {
  button.addEventListener("click", async () => {
    const bibtex = button.getAttribute("data-bibtex") || "";
    try {
      await navigator.clipboard.writeText(bibtex);
      button.innerHTML = '<i data-icon="check" aria-hidden="true"></i><span>Copied BibTeX</span>';
      refreshIcons();
      showAppToast("BibTeX citation copied to clipboard.");
      setTimeout(() => { button.innerHTML = '<i data-icon="copy" aria-hidden="true"></i><span>Copy BibTeX</span>'; refreshIcons(); }, 1400);
    } catch (error) {
      button.innerHTML = '<i data-icon="circle-alert" aria-hidden="true"></i><span>Copy failed</span>';
      refreshIcons();
      showAppToast("Could not copy the BibTeX citation.");
      setTimeout(() => { button.innerHTML = '<i data-icon="copy" aria-hidden="true"></i><span>Copy BibTeX</span>'; refreshIcons(); }, 1800);
    }
  });
}
}
bindCitationButtons();

function recencyForPublicationDate(value) {
  if (!/^\d{4}-\d{2}-\d{2}/.test(String(value || ""))) return null;
  const [year, month, day] = String(value).slice(0, 10).split("-").map(Number);
  const published = new Date(year, month - 1, day);
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  published.setHours(0, 0, 0, 0);
  const days = Math.round((today.getTime() - published.getTime()) / DAY_MS);
  if (days < 0 || days > 31) return null;
  return days <= 7
    ? { label: "New this week", className: "recency-week" }
    : { label: "New this month", className: "recency-month" };
}

function syncRecencyBadges(root = document) {
  root.querySelectorAll(".recency-badge[data-publication-date]").forEach((badge) => {
    const recency = recencyForPublicationDate(badge.dataset.publicationDate);
    if (!recency) {
      badge.remove();
      return;
    }
    badge.textContent = recency.label;
    badge.classList.toggle("recency-week", recency.className === "recency-week");
    badge.classList.toggle("recency-month", recency.className === "recency-month");
  });
}

function initAlertScope() {
  const form = document.querySelector("#alert-enrollment .alert-form");
  if (!form) return;
  const targeting = form.querySelector(".alert-targeting");
  const scopedInputs = () => targeting ? Array.from(targeting.querySelectorAll("input, select")) : [];
  const radios = Array.from(form.querySelectorAll('input[name="alert_scope"]'));
  const targetedRadio = form.querySelector('input[name="alert_scope"][value="targeted"]');
  const allRadio = form.querySelector('input[name="alert_scope"][value="all"]');

  const sync = () => {
    const targeted = targetedRadio?.checked || false;
    if (targeting && targeted) targeting.open = true;
    scopedInputs().forEach((input) => {
      input.disabled = !targeted;
    });
  };

  radios.forEach((radio) => radio.addEventListener("change", sync));
  if (targeting) {
    targeting.addEventListener("toggle", () => {
      if (targeting.open && targetedRadio) {
        targetedRadio.checked = true;
        sync();
      } else if (!targeting.open && allRadio && !scopedInputs().some((input) => input.value)) {
        allRadio.checked = true;
        sync();
      }
    });
  }
  const selectTargeted = () => { if (targetedRadio) targetedRadio.checked = true; sync(); };
  targeting?.addEventListener('input', selectTargeted);
  targeting?.addEventListener('change', selectTargeted);
  sync();
}

function initSourceStatsModal() {
  const trigger = document.querySelector("#footer-stats-trigger");
  const modal = document.querySelector("#source-stats-modal");
  if (!trigger || !modal) return;
  const close = () => {
    if (typeof modal.close === "function" && modal.open) {
      modal.close();
    }
    modal.classList.remove("is-open");
    updateModalOpenState();
  };
  const open = () => {
    if (typeof modal.showModal === "function") {
      modal.showModal();
    } else {
      modal.setAttribute("open", "");
    }
    modal.classList.add("is-open");
    updateModalOpenState();
    refreshIcons();
  };

  trigger.addEventListener("click", open);
  modal.addEventListener("click", (event) => {
    if (event.target.closest?.("[data-close-source-stats]")) {
      close();
      return;
    }
    if (event.target === modal) close();
  });
  modal.addEventListener("submit", (event) => {
    if (event.target.closest?.("form[method='dialog']")) {
      close();
    }
  });
  modal.addEventListener("close", () => {
    modal.classList.remove("is-open");
    updateModalOpenState();
  });
}

function initHaptics() {
  const selectors = [
    "button",
    ".publication-exports a",
    ".export-links a",
    ".timeline-open button",
    ".timeline-presets button",
    ".topbar nav a",
    ".copy-citation",
    ".copy-bibtex",
    ".footer-stats-trigger",
  ].join(",");
  document.addEventListener("click", (event) => {
    const target = event.target.closest?.(selectors);
    if (!event.isTrusted || !["touch", "pen"].includes(event.pointerType) || !target || target.matches("[disabled], .disabled, [aria-disabled='true']")) return;
    subtleHaptic(target.matches(".primary, .push-app, .install-app, form button[type='submit']") ? [8, 24, 8] : 7);
  }, { passive: true });
}

function augmentDownloadUrl(url) {
  const next = new URL(url.href);
  if (!next.searchParams.has("download_at")) {
    next.searchParams.set("download_at", new Date().toISOString().slice(0, 19).replace(/[-:]/g, "").replace("T", "T"));
  }
  if (!next.searchParams.has("download_id")) {
    const random = window.crypto?.getRandomValues ? Array.from(window.crypto.getRandomValues(new Uint8Array(4)), (byte) => byte.toString(16).padStart(2, "0")).join("") : Math.random().toString(16).slice(2, 10);
    next.searchParams.set("download_id", random);
  }
  return next;
}

function isDownloadEndpoint(url) {
  return url.origin === window.location.origin && /\/(?:export|database)\.php$/.test(url.pathname);
}

function isApiEndpoint(url) {
  return url.origin === window.location.origin && url.pathname.endsWith("/api.php");
}

function repeatedDownloadParams(params, baseName) {
  const values = [];
  for (const [key, value] of params.entries()) {
    if (key === baseName || key === `${baseName}[]` || new RegExp(`^${baseName}\\[\\d+\\]$`).test(key)) {
      if (value.trim() !== "") values.push(value);
    }
  }
  return Array.from(new Set(values));
}

function selectedCollectionPaperIds() {
  return [...document.querySelectorAll("[data-collection-paper]")]
    .filter((box) => box.checked)
    .map((box) => String(box.value || "").trim())
    .filter(Boolean);
}

function exportUrlForSelectedPapers(format = "json") {
  const ids = selectedCollectionPaperIds();
  if (!ids.length) return null;
  const url = new URL("export.php", window.location.href);
  url.searchParams.set("format", format);
  ids.forEach((id) => url.searchParams.append("ids[]", id));
  return url;
}

function initSidebarSelectedExport() {
  const link = document.querySelector("[data-sidebar-export]");
  if (!(link instanceof HTMLAnchorElement)) return;
  link.addEventListener("click", (event) => {
    const currentUrl = new URL(link.href, window.location.href);
    const selectedUrl = exportUrlForSelectedPapers(currentUrl.searchParams.get("format") || "json");
    if (!selectedUrl) return;
    event.preventDefault();
    link.href = selectedUrl.href;
  });
}

function downloadDateRange(url) {
  if (url.pathname.endsWith("/database.php")) return "All indexed years";
  const params = url.searchParams;
  const year = (params.get("year") || "").trim();
  if (/^\d{4}$/.test(year)) return `${year}-01-01 to ${year}-12-31`;
  const from = (params.get("from") || "").trim();
  const to = (params.get("to") || "").trim();
  if (from || to) return `${from || "earliest indexed date"} to ${to || "latest indexed date"}`;
  const range = (params.get("range") || document.querySelector('input[name="range"]:checked')?.value || "5y").trim();
  const labels = {
    month: "Last month",
    year: "Last year",
    "5y": "Last 5 years",
    all: "All indexed years",
    custom: "Custom date range",
  };
  return labels[range] || "Current date range";
}

function downloadIncludedSources(url) {
  if (url.pathname.endsWith("/database.php")) return "All public indexed sources";
  const sources = repeatedDownloadParams(url.searchParams, "sources");
  if (sources.length) return sources.join(", ");
  const selected = Array.from(document.querySelectorAll('select[name="sources[]"] option:checked, input[name="sources[]"]:checked'))
    .map((option) => (option.value || option.textContent)?.replace(/\s*\\(.*?\\)\s*$/, "").trim() || "")
    .filter(Boolean);
  return selected.length ? selected.join(", ") : "All included sources";
}

function fallbackDownloadCount(url) {
  if (url.pathname.endsWith("/database.php")) {
    return document.querySelector(".sidebar-status strong")?.textContent?.trim() || "all";
  }
  const resultText = document.querySelector(".result-title-block p")?.textContent?.trim() || "";
  const match = resultText.match(/[\d,.\s]+/);
  return match ? match[0].replace(/\s+/g, "").trim() : "current";
}

function apiResourceLabel(url) {
  const resource = (url.searchParams.get("resource") || "papers").trim();
  const labels = {
    papers: "Filtered publication search",
    latest: "Latest publications",
    analytics: "Analytics summary",
    authors: "Author index",
    topics: "Topic index",
    study_types: "Study-type index",
    sources: "Source database summary",
    publication_statuses: "Publication status summary",
    journals: "Journal index",
    paper: "Single publication record",
    citation: "Citation text",
  };
  return labels[resource] || resource;
}

function apiResultWindow(url) {
  const params = url.searchParams;
  const limit = params.get("limit") || params.get("per_page") || "20";
  if (limit.toLowerCase() === "all") {
    return "All matching records";
  }
  const offset = params.get("offset");
  const page = params.get("page") || (offset && /^\d+$/.test(offset) && /^\d+$/.test(limit) ? String(Math.floor(Number(offset) / Math.max(Number(limit), 1)) + 1) : "1");
  return `Page ${page}; up to ${limit} records`;
}

function initApiConfirmations() {
  document.addEventListener("click", (event) => {
    const link = event.target.closest?.("a[href]");
    if (!(link instanceof HTMLAnchorElement)) return;
    const url = new URL(link.href, window.location.href);
    if (!isApiEndpoint(url)) return;

    const confirmed = window.confirm(`Open JSON API response?\n\nResource: ${apiResourceLabel(url)}\nRecords: ${apiResultWindow(url)}\nDate range: ${downloadDateRange(url)}\nIncluded sources: ${downloadIncludedSources(url)}\n\nThis opens structured JSON in a new browser tab for scripts, notebooks, and integrations. Use Export JSON when you want a downloadable file.`);
    if (!confirmed) {
      event.preventDefault();
    }
  });
}

function initDownloadConfirmations() {
  const approved = new WeakSet();
  const nativeClick = HTMLAnchorElement.prototype.click;
  // Generated exports often click detached anchors; bring them through the same gate.
  HTMLAnchorElement.prototype.click = function () {
    const detached = !this.isConnected;
    if (detached) this.ownerDocument.body.append(this);
    try { return nativeClick.call(this); } finally { if (detached) this.remove(); }
  };
  let queue = Promise.resolve();
  const ask = (name, size, context) => new Promise(resolve => {
    const readable = Number.isFinite(size) ? `${new Intl.NumberFormat('en', {maximumFractionDigits: 1}).format(size / (size >= 1048576 ? 1048576 : size >= 1024 ? 1024 : 1))} ${size >= 1048576 ? 'MB' : size >= 1024 ? 'KB' : 'bytes'}` : 'Not available';
    if (!window.HTMLDialogElement) { resolve(window.confirm(`Download file?\n\nFilename: ${name}\nSize: ${readable}`)); return; }
    const dialog=document.createElement('dialog');dialog.className='file-download-confirm';dialog.setAttribute('aria-labelledby','file-download-title');
    dialog.innerHTML='<form method="dialog"><div class="file-download-icon" aria-hidden="true">'+ICONS.download+'</div><h2 id="file-download-title">Download file?</h2><p>Save this file to your device.</p><dl><div><dt>Filename</dt><dd data-download-name></dd></div><div><dt>Size</dt><dd data-download-size></dd></div></dl><p data-download-context></p><div class="file-download-actions"><button class="secondary" value="cancel" autofocus>Cancel</button><button class="primary" value="download">Download</button></div></form>';
    dialog.querySelector('[data-download-context]').textContent=context||'';dialog.querySelector('[data-download-name]').textContent=name;dialog.querySelector('[data-download-size]').textContent=readable;
    const previous=document.activeElement;document.body.append(dialog);
    dialog.addEventListener('close',()=>{const accepted=dialog.returnValue==='download';dialog.remove();if(previous?.isConnected)previous.focus({preventScroll:true});resolve(accepted);},{once:true});dialog.showModal();
  });
  const handleDownload = event => {
    const link=event.target.closest?.('a[href]');if(link?.tagName!=='A')return;
    if(approved.has(link)){approved.delete(link);return;}
    let original=new URL(link.href,location.href);
    if(link.matches('[data-sidebar-export]'))original=exportUrlForSelectedPapers(original.searchParams.get('format')||'json')||original;
    const downloadable=link.hasAttribute('download')||isDownloadEndpoint(original)||/\.(?:pdf|zip|gz|xz|csv|tsv|json|txt|bib|ris|rds|rdata|xlsx?|docx?|pptx?|png|jpe?g|webp|svg|mp[34]|wav|r|py|ipynb)$/i.test(original.pathname)||(/\/(?:account-analysis-api|catalogue)\.php$/.test(original.pathname)&&original.searchParams.get('action')!=='status');
    if(!downloadable)return;
    event.preventDefault();event.stopImmediatePropagation();
    const href=isDownloadEndpoint(original)?augmentDownloadUrl(original).href:original.href;
    const requestedName=link.download;
    // Read local bytes immediately so the producer can safely revoke its short-lived URL.
    const local=/^(?:blob|data):/.test(original.protocol)?fetch(href).then(r=>r.blob()).catch(()=>null):null;
    queue=queue.catch(()=>{}).then(async()=>{
      let name=requestedName||decodeURIComponent(original.pathname.split('/').pop()||'download'),size=NaN,pinned=null;
      if(local){const blob=await local;if(!blob){showAppToast('The download expired. Please try again.');return;}size=blob.size;pinned=URL.createObjectURL(blob);}
      else {const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),3000);try{const response=await fetch(href,{method:'HEAD',cache:'no-store',credentials:original.origin===location.origin?'same-origin':'omit',signal:controller.signal});if(response.ok){const disposition=response.headers.get('Content-Disposition')||'';const encoded=disposition.match(/filename\*=UTF-8''([^;]+)/i),plain=disposition.match(/filename="([^"]+)"|filename=([^;]+)/i);if(encoded){try{name=decodeURIComponent(encoded[1]);}catch{}}else if(plain)name=(plain[1]||plain[2]).trim();const length=response.headers.get('Content-Length');if(length&&!response.headers.get('Content-Encoding'))size=Number(length);}}catch{}finally{clearTimeout(timer);}}
      const context=isDownloadEndpoint(original)?`Entries: ${fallbackDownloadCount(new URL(href))} · Date range: ${downloadDateRange(new URL(href))} · Included sources: ${downloadIncludedSources(new URL(href))}`:'';
      if(await ask(name,size,context)){const save=document.createElement('a');save.href=pinned||href;save.download=requestedName||name;save.rel='noopener';approved.add(save);document.body.append(save);nativeClick.call(save);save.remove();if(pinned)setTimeout(()=>URL.revokeObjectURL(pinned),60000);}else if(pinned)URL.revokeObjectURL(pinned);
    });
  };
  const attached=new WeakSet();
  const attach=win=>{try{if(attached.has(win.document))return;attached.add(win.document);win.addEventListener('click',handleDownload,true);win.HTMLAnchorElement.prototype.click=HTMLAnchorElement.prototype.click;win.document.addEventListener('load',e=>{if(e.target.tagName==='IFRAME')attach(e.target.contentWindow);},true);win.document.querySelectorAll('iframe').forEach(frame=>attach(frame.contentWindow));}catch{ /* External frames own their download policy. */ }};
  attach(window);
}


function initAlertVanta() {
  const element = document.querySelector("#alert-vanta-bg");
  if (!element || !window.VANTA?.NET || !window.THREE) return;
  if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;
  const effect = window.VANTA.NET({
    el: element,
    THREE: window.THREE,
    mouseControls: true,
    touchControls: true,
    gyroControls: false,
    minHeight: 200,
    minWidth: 200,
    scale: 1,
    scaleMobile: 1,
    color: 0x2c8066,
    backgroundColor: 0xf7f6f1,
    points: 9,
    maxDistance: 21,
    spacing: 18,
    showDots: true,
  });
  window.addEventListener("pagehide", () => effect?.destroy?.(), { once: true });
}

// Installation is browser-controlled. Positive evidence suppresses promotion;
// an empty related-apps response alone is never evidence of uninstallation.
// Daily counters only: random receipt IDs identify one delivery, never a browser.
function createPwaMetricRecorder() {
  const queueKey = 'publicationTrackerPwaMetricQueue';
  const platform = /Android/.test(navigator.userAgent) ? 'android'
    : /iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1) ? 'ios'
    : /Windows|Macintosh|Linux|CrOS/.test(navigator.userAgent) ? 'desktop' : 'other';
  const base = { strategy: 'contextual-v2', platform, surface: 'unknown' };
  let sending = false, retryAt = 0, failures = 0;
  const load = () => {
    const cutoff = new Date(Date.now() - 7 * 86400000).toISOString().slice(0, 10);
    let value;
    try { value = JSON.parse(localStorage.getItem(queueKey) || '[]'); } catch (_) { return []; }
    return (Array.isArray(value) ? value : []).filter(e => e && e.date >= cutoff && typeof e.metric === 'string' && typeof e.storageKey === 'string').slice(-64);
  };
  const flush = async () => {
    if (sending || navigator.onLine === false || Date.now() < retryAt) return;
    sending = true;
    const deliver = async () => {
      try {
        for (const entry of load()) {
          if (localStorage.getItem(entry.storageKey) !== '1') {
            const payload = entry.event_id
              ? { metric: entry.metric, date: entry.date, event_id: entry.event_id, strategy: entry.strategy, platform: entry.platform, surface: entry.surface }
              : { metric: entry.metric, date: entry.date }; // Preserve pre-upgrade queued observations.
            const response = await fetch('/engagement.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload), keepalive: true });
            if (response.status === 400) {
              // A permanently invalid event must not strand valid observations behind it.
              localStorage.setItem(entry.storageKey, '1');
            } else {
              if (!response.ok || !(await response.json()).ok) throw new Error('Metric delivery failed');
              localStorage.setItem(entry.storageKey, '1');
            }
          }
          // Re-read so observations queued during a request are never overwritten.
          localStorage.setItem(queueKey, JSON.stringify(load().filter(e => e.storageKey !== entry.storageKey)));
        }
        failures = 0; retryAt = 0;
      } catch (_) {
        retryAt = Date.now() + Math.min(600000, 15000 * 2 ** Math.min(6, failures++));
      }
    };
    try {
      if (navigator.locks?.request) await navigator.locks.request('tracker-pwa-metric-send', { ifAvailable: true }, lock => lock ? deliver() : undefined);
      else await deliver();
    } catch (_) { retryAt = Date.now() + 60000; }
    finally { sending = false; }
  };
  const record = (metric, storageKey, context = {}) => {
    try {
      if (localStorage.getItem('publicationTrackerMetricsExcluded') === '1') { localStorage.removeItem(queueKey); return; }
      if (localStorage.getItem(storageKey) === '1') return;
      const queue = load();
      if (!queue.some(e => e.storageKey === storageKey)) {
        const bytes = new Uint8Array(16); crypto.getRandomValues(bytes);
        const event_id = Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
        queue.push({ metric, storageKey, date: new Date().toISOString().slice(0, 10), event_id, ...base, ...context });
        localStorage.setItem(queueKey, JSON.stringify(queue.slice(-64)));
      }
      flush();
    } catch (_) {} // Measurement is optional and never blocks installation or research.
  };
  window.addEventListener('online', () => { retryAt = 0; flush(); });
  window.addEventListener('storage', e => { if (e.key === queueKey) flush(); });
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') flush(); });
  setInterval(flush, 30000);
  flush();
  return record;
}

function initPwaInstall({ installButton, recordPwaMetric, updatePushButton }) {
  if (!installButton || window.TrackerInstall) return;
  const mobileInstallButton = document.querySelector('[data-mobile-install-app]');
  const installedKey = 'publicationTrackerInstalled';
  const preferenceKey = 'publicationTrackerInstallPreferenceV1';
  const offlineChoiceKey = 'psiloPwaOfflineChoiceV1';
  const sessionKey = 'publicationTrackerInstallOfferSession';
  const mode = matchMedia('(display-mode: standalone), (display-mode: minimal-ui), (display-mode: window-controls-overlay)');
  const day = 86400000;
  const strategy = 'contextual-v2';
  const attributionKey = 'publicationTrackerInstallAttributionV1';
  let installSurface = 'unknown', attempt = 0;
  const track = (metric, key, surface = installSurface) => recordPwaMetric(metric, key, { strategy, surface });
  const eventKey = metric => `publicationTrackerPwa-${strategy}-${metric}-${Date.now()}-${++attempt}`;
  const attribution = () => { try { const a = JSON.parse(localStorage.getItem(attributionKey) || '{}'); return a.strategy === strategy && Date.now() - a.at < 7 * day ? a : null; } catch (_) { return null; } };

  const startedAt = Date.now();
  const read = (key, session = false) => { try { return (session ? sessionStorage : localStorage).getItem(key); } catch (_) { return null; } };
  const write = (key, value, session = false) => { try { (session ? sessionStorage : localStorage).setItem(key, value); } catch (_) {} };
  const preferences = () => { try { const value = JSON.parse(read(preferenceKey) || '{}'); return value && typeof value === 'object' ? value : {}; } catch (_) { return {}; } };
  const savePreferences = patch => write(preferenceKey, JSON.stringify({ ...preferences(), ...patch }));
  const saveOfflineChoice = (components, source = 'install') => {
    if (!components || !('includeWebR' in components) || !('includeLatex' in components)) return;
    write(offlineChoiceKey, JSON.stringify({
      chosen: true,
      includeWebR: components.includeWebR === true,
      includeLatex: components.includeLatex === true,
      source,
      at: Date.now()
    }));
  };
  const saveOfflineChoiceFrom = (container, source) => {
    const webR = container?.querySelector?.('[data-pwa-offline-webr]');
    const latex = container?.querySelector?.('[data-pwa-offline-latex]');
    if (!webR || !latex) return;
    saveOfflineChoice({ includeWebR: webR.checked, includeLatex: latex.checked }, source);
  };
  let deferredPrompt = null, installing = false, relatedInstalled = false, checkingRelated = false;
  let rememberedInstalled = read(installedKey) === '1';
  let engaged = false, engagedAt = 0, engagementContext = 'catalogue', cardShownHere = false, dismissedHere = false, pendingOfflineOffer = false;
  let card, help, helpOpener, entry;
  let relatedCheckStarted = 0;
  const dirtyForms = new Set();
  const appMode = () => mode.matches || navigator.standalone === true || document.referrer.startsWith('android-app://com.psilocybinresearch.tracker');
  const knownInstalled = () => appMode() || rememberedInstalled || relatedInstalled;
  const pendingInstall = () => Number(preferences().pendingUntil || 0) > Date.now();
  const isiOS = () => /iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const researchPage = !/^\/(?:account[^/]*|admin[^/]*|internal-traffic|webr)(?:[/.?]|$)/.test(location.pathname);
  const manualSupported = () => isiOS() || (/Macintosh/.test(navigator.userAgent) && /Safari/.test(navigator.userAgent) && !/Chrome|Chromium|Edg/.test(navigator.userAgent));
  const restoreFocus = preferred => {
    const target = [preferred, entry?.querySelector('button'), installButton, document.querySelector('.header-publication-search')].find(el => el && !el.hidden && el.getClientRects().length);
    target?.focus({ preventScroll: true });
  };
  const hideCard = () => {
    if (!card || card.hidden) return;
    const hadFocus = card.contains(document.activeElement);
    card.hidden = true;
    if (hadFocus && !installing) restoreFocus();
  };
  const blocked = () => {
    for (const form of dirtyForms) if (!form.isConnected) dirtyForms.delete(form);
    return document.visibilityState !== 'visible' || navigator.onLine === false || window.TrackerOffline?.busy || dirtyForms.size > 0
      || !!document.querySelector('dialog[open], .is-searching, .is-preloading, .app-toast.is-visible, .explorer-toast.is-visible')
      || document.activeElement?.matches('input,textarea,select,[contenteditable="true"]')
      || (window.visualViewport && window.visualViewport.height < 450)
      || (matchMedia('(max-width: 1180px)').matches && !document.documentElement.classList.contains('nav-sidebar-collapsed'));
  };
  const rememberInstalled = () => {
    rememberedInstalled = true;
    if (read(installedKey) !== '1') write(installedKey, '1');
    if (Number(preferences().pendingUntil || 0)) savePreferences({ pendingUntil: 0 });
    deferredPrompt = null;
    hideCard();
    if (help?.open) help.close();
    installButton.hidden = true;
    if (mobileInstallButton) mobileInstallButton.hidden = true;
  };
  const dismiss = (forever = false) => {
    const previous = preferences();
    const dismissals = Math.min(10, Number(previous.dismissals || 0) + 1);
    savePreferences({ never: forever || previous.never === true, dismissals, nextOfferAt: Date.now() + (dismissals > 1 ? 30 : 7) * day });
    dismissedHere = true;
    hideCard();
  };
  const ensureEntry = () => {
    if (entry || !researchPage) return;
    const main = document.querySelector('main');
    if (!main) return;
    entry = document.createElement('aside');
    entry.className = 'pwa-install-entry'; entry.hidden = true;
    entry.setAttribute('aria-label', 'Get the research app');
    entry.innerHTML = `<img src="/assets/mushroom-brand-mark-96.webp" width="40" height="40" alt=""><div><strong>Your research, a tap away</strong><p>Free app · Optional offline downloads</p></div><button type="button" class="primary" data-install-entry>Install free app</button>`;
    main.prepend(entry);
    if ('IntersectionObserver' in window) new IntersectionObserver(rows => {
      if (rows.some(row => row.isIntersecting) && !entry.hidden) track('install_control_visible', `publicationTrackerPwa-${strategy}-control-${new Date().toISOString().slice(0,10)}`, 'manual');
    }, { threshold: 0.5 }).observe(entry);
    entry.querySelector('button').addEventListener('click', requestInstall);
  };
  const showCard = () => {
    if (!card) {
      card = document.createElement('aside');
      card.className = 'pwa-install-invitation'; card.hidden = true;
      card.setAttribute('aria-labelledby', 'pwa-offer-title');
      card.innerHTML = `<button type="button" class="pwa-install-close" aria-label="Dismiss app invitation">×</button><div class="pwa-install-invitation-heading"><img src="/assets/mushroom-brand-mark-96.webp" width="44" height="44" alt=""><div><p class="pwa-install-eyebrow">FREE RESEARCH APP</p><h2 id="pwa-offer-title">Keep research close</h2></div></div><p>Open the tracker from your home screen or desktop. Download the catalogue separately when you want to research offline.</p><div class="pwa-install-invitation-actions"><button type="button" class="primary" data-install-now>Install free app</button><button type="button" class="secondary" data-install-later>Not now</button></div><button type="button" class="pwa-install-no-thanks" data-install-never>Don't suggest again</button>`;
      document.body.append(card);
      card.querySelector('[data-install-now]').addEventListener('click', requestInstall);
      const decline = forever => { track(forever ? 'install_invitation_never' : 'install_invitation_later', eventKey('decline'), 'automatic'); dismiss(forever); };
      card.querySelector('[data-install-later]').addEventListener('click', () => decline(false));
      card.querySelector('.pwa-install-close').addEventListener('click', () => decline(false));
      card.querySelector('[data-install-never]').addEventListener('click', () => decline(true));
      card.addEventListener('keydown', e => { if (e.key === 'Escape') { e.preventDefault(); decline(false); } });
    }
    card.querySelector('[data-install-now]').textContent = isiOS() ? 'Add to Home Screen' : 'Install free app';
    card.hidden = false;
    if (!cardShownHere) {
      cardShownHere = true; write(sessionKey, '1', true);
      track('install_invitation_shown', eventKey('offer'), 'automatic');
      track('install_invitation_browser_day', `publicationTrackerPwa-${strategy}-offer-${new Date().toISOString().slice(0,10)}`, 'automatic');
      track('install_invitation_first_seen', `publicationTrackerPwa-${strategy}-offer-first`, 'automatic');
    }
  };
  const showHelp = () => {
    if (knownInstalled() || pendingInstall()) return;
    if (!help) {
      help = document.createElement('dialog');
      help.className = 'pwa-install-help';
      help.setAttribute('aria-labelledby', 'pwa-install-help-title');
      help.innerHTML = `<div class="pwa-install-help-head"><h2 id="pwa-install-help-title">Install the Psilocybin Research app</h2><button type="button" data-install-help-close aria-label="Close installation help">×</button></div><p class="pwa-install-help-intro">Use the research app on your phone or computer. Installation is free; no app-store account is needed.</p><div data-install-steps></div><p class="pwa-install-help-note">The app opens in its own window. The public catalogue and app pages can be downloaded separately in Offline library; videos and audio require a connection.</p><div class="pwa-install-help-actions"><button type="button" class="secondary" data-install-already>I've already added it</button><button type="button" class="primary" data-install-help-close>Done</button></div>`;
      document.body.append(help);
      help.querySelectorAll('[data-install-help-close]').forEach(button => button.addEventListener('click', () => help.close()));
      help.querySelector('[data-install-already]').addEventListener('click', () => { rememberInstalled(); updatePushButton(); });
      help.addEventListener('click', event => { if (event.target === help) help.close(); });
      help.addEventListener('close', () => restoreFocus(helpOpener));
    }
    const ua = navigator.userAgent;
    const steps = help.querySelector('[data-install-steps]');
    if (isiOS()) {
      const safari = !/CriOS|FxiOS|EdgiOS|OPiOS/.test(ua);
      steps.innerHTML = `${safari ? '' : '<p>Open this website in Safari for these steps.</p>'}<ol><li>Tap <strong>Share</strong> in Safari (the square with an upward arrow).</li><li>Choose <strong>Add to Home Screen</strong>. You may need to scroll through the actions.</li><li>If shown, leave <strong>Open as Web App</strong> enabled, then tap <strong>Add</strong>.</li></ol>`;
    } else if (/Android/.test(ua)) {
      steps.innerHTML = '<ol><li>Open your browser menu.</li><li>Choose <strong>Install app</strong> or <strong>Add to Home screen</strong> (wording varies by browser).</li><li>Confirm, then open the database from its new home-screen icon.</li></ol><p>If installation is unavailable in an embedded browser, open this website in Chrome or another browser that supports installation.</p>';
    } else if (/Macintosh/.test(ua) && /Safari/.test(ua) && !/Chrome|Chromium|Edg/.test(ua)) {
      steps.innerHTML = '<ol><li>In a recent Safari version, open <strong>File</strong>.</li><li>Choose <strong>Add to Dock</strong>, then confirm.</li></ol><p>If that option is unavailable, use a browser with web-app installation support, such as Chrome or Edge.</p>';
    } else {
      steps.innerHTML = '<ol><li>In Chrome or Edge, look for the install icon in the address bar, or open the browser menu.</li><li>Choose <strong>Install</strong> or the option to install this page as an app, then confirm.</li></ol><p>Available options depend on your browser. If it has no installation option, you can still bookmark and use the full tracker on the web.</p>';
    }
    helpOpener = document.activeElement;
    hideCard();
    if (!help.open) {
      help.showModal();
      track('install_help_shown', eventKey('help'));
    }
  };
  const requestInstall = async event => {
    installSurface = event?.currentTarget?.hasAttribute('data-install-now') ? 'automatic'
      : 'manual';
    if (navigator.onLine === false || installing || knownInstalled() || pendingInstall()) return;
    track('install_control_clicked', eventKey('control-click'));
    if (installSurface === 'automatic') track('install_invitation_install', eventKey('invitation-install'));
    saveOfflineChoiceFrom(event?.currentTarget?.closest?.('.pwa-install-invitation,.pwa-install-help,.install-hero'), installSurface);
    if (!deferredPrompt) { showHelp(); return; }
    const prompt = deferredPrompt;
    deferredPrompt = null; // Each browser event can be used only once.
    installing = true;
    installButton.disabled = true;
    if (mobileInstallButton) mobileInstallButton.disabled = true;
    if (card) card.querySelector('[data-install-now]').disabled = true;
    hideCard();
    try {
      track('install_prompt_requested', eventKey('prompt-request'));
      write(attributionKey, JSON.stringify({ strategy, surface: installSurface, at: Date.now() }));
      await prompt.prompt(); // Called directly in the click gesture, before any async detection.
      track('install_prompt_shown', eventKey('prompt-shown'));
      const choice = await prompt.userChoice;
      if (choice.outcome === 'accepted') {
        track('install_prompt_accepted_first', `publicationTrackerPwa-${strategy}-accepted-first`);
        savePreferences({ pendingUntil: Date.now() + day });
        installButton.hidden = true;
        if (mobileInstallButton) mobileInstallButton.hidden = true;
        dismissedHere = true;
        if (typeof showAppToast === 'function') showAppToast('Installation requested. Your browser will finish adding the app.');
      } else {
        dismiss();
        restoreFocus();
      }
      track(choice.outcome === 'accepted' ? 'install_prompt_accepted' : 'install_prompt_dismissed', eventKey('prompt-choice'));
      if (choice.outcome !== 'accepted') { try { localStorage.removeItem(attributionKey); } catch (_) {} }
    } catch (_) {
      track('install_prompt_error', eventKey('prompt-error'));
      try { localStorage.removeItem(attributionKey); } catch (_) {}
      dismissedHere = true;
      savePreferences({ nextOfferAt: Date.now() + 7 * day });
      showHelp();
    } finally {
      installing = false;
      if (card?.hidden && card.contains(document.activeElement)) restoreFocus();
      installButton.disabled = false;
      if (mobileInstallButton) mobileInstallButton.disabled = false;
      if (card) card.querySelector('[data-install-now]').disabled = false;
      reconcile();
    }
  };
  let lastNativeInstallAvailability=null;
  const reconcile = () => {
    if (appMode()) rememberInstalled();
    // Keep a compact header shortcut alongside the labelled research-page entry;
    // keep the full sidebar action for manual-install browsers such as iOS Safari.
    const useMobileHeaderInstall = !!mobileInstallButton && matchMedia('(max-width: 1180px)').matches && !!deferredPrompt;
    installButton.hidden = navigator.onLine === false || knownInstalled() || pendingInstall() || useMobileHeaderInstall;
    if (mobileInstallButton) {
      mobileInstallButton.hidden = navigator.onLine === false || knownInstalled() || pendingInstall() || !deferredPrompt;
      mobileInstallButton.disabled = installing;
    }
    const nativeAvailable=!!deferredPrompt&&!knownInstalled()&&!pendingInstall()&&!installing&&navigator.onLine!==false;
    if(nativeAvailable!==lastNativeInstallAvailability){lastNativeInstallAvailability=nativeAvailable;window.dispatchEvent(new CustomEvent('tracker:install-availability',{detail:{available:nativeAvailable}}));}
    if (knownInstalled()) {
      hideCard();
      if (entry) entry.hidden = true;
      if (help?.open) help.close();
      if (pendingOfflineOffer && !blocked()) {
        pendingOfflineOffer = false;
        window.dispatchEvent(new Event('tracker:installed'));
        if (!window.TrackerOffline) document.querySelector('script[data-offline-library-loader]')?.addEventListener('load', () => window.TrackerOffline?.offer(), { once: true });
      }
      return;
    }
    ensureEntry();
    const eligible = !!deferredPrompt || manualSupported();
    if (entry) {
      entry.hidden = !eligible || navigator.onLine === false || pendingInstall();
      const button = entry.querySelector('button');
      button.textContent = isiOS() ? 'Add to Home Screen' : 'Install free app';
      button.disabled = installing;

    }
    const pref = preferences();
    if (!researchPage || !eligible || installing || pendingInstall() || blocked() || pref.never === true || Number(pref.nextOfferAt || 0) > Date.now() || dismissedHere) { hideCard(); return; }
    if (engaged && Date.now() - startedAt >= 15000 && Date.now() - engagedAt >= 4000 && (!read(sessionKey, true) || cardShownHere)) showCard();
  };
  const checkRelated = () => {
    if (!navigator.getInstalledRelatedApps || checkingRelated || relatedInstalled || Date.now() - relatedCheckStarted < 30000) return;
    relatedCheckStarted = Date.now();
    checkingRelated = true;
    const timeout = setTimeout(() => { checkingRelated = false; reconcile(); }, 2500);
    Promise.resolve().then(() => navigator.getInstalledRelatedApps()).then(apps => {
      relatedInstalled = apps.some(app => {
        if (app.platform === 'play') return app.id === 'com.psilocybinresearch.tracker';
        if (app.platform !== 'webapp') return false;
        try { const url = new URL(app.url || app.id, location.origin); return url.origin === location.origin && url.pathname === '/manifest.webmanifest'; } catch (_) { return false; }
      });
      if (relatedInstalled) rememberInstalled();
    }).catch(() => {}).finally(() => { clearTimeout(timeout); checkingRelated = false; reconcile(); });
  };
  const meaningfulUse = (context = 'catalogue') => { if (!engaged) engagedAt = Date.now(); engaged = true; engagementContext = context; reconcile(); };
  installButton.addEventListener('click', requestInstall);
  mobileInstallButton?.addEventListener('click', requestInstall);
  window.addEventListener('beforeinstallprompt', event => {
    // Offer the native dialog only after an explicit click on our install controls.
    event.preventDefault();
    if (appMode() || relatedInstalled) return;
    // A fresh installable event is stronger than an old local "installed" flag.
    rememberedInstalled = false;
    write(installedKey, '0');
    if (Number(preferences().pendingUntil || 0)) savePreferences({ pendingUntil: 0 });
    deferredPrompt = event;
    track('install_prompt_available', `publicationTrackerPwa-${strategy}-available-${new Date().toISOString().slice(0, 10)}`, 'unknown');
    installButton.title = 'Install the Psilocybin Research app';
    reconcile();
    // A native choice can consume the event without our manual button being used.
    Promise.resolve(event.userChoice).then(choice => {
      if (deferredPrompt !== event || !choice?.outcome) return;
      deferredPrompt = null;
      if (choice.outcome === 'accepted') {
        savePreferences({ pendingUntil: Date.now() + day });
        write(attributionKey, JSON.stringify({ strategy, surface: 'unknown', at: Date.now() }));
        track('install_prompt_accepted_first', `publicationTrackerPwa-${strategy}-accepted-first`, 'unknown');
      }
      track(choice.outcome === 'accepted' ? 'install_prompt_accepted' : 'install_prompt_dismissed', eventKey('native-choice'), 'unknown');
      reconcile();
    }).catch(() => { if (deferredPrompt === event) { deferredPrompt = null; reconcile(); } });
  });
  window.addEventListener('appinstalled', () => {
    rememberInstalled();
    pendingOfflineOffer = true;
    const context = attribution();
    track('pwa_install', context ? `publicationTrackerPwa-${strategy}-installed-${context.at}` : `publicationTrackerPwa-${strategy}-installed-${new Date().toISOString().slice(0, 10)}`, context?.surface || 'unknown');
    track('pwa_install_first', `publicationTrackerPwa-${strategy}-installed-first`, context?.surface || 'unknown');
    updatePushButton();
    reconcile();
  });
  window.addEventListener('tracker:search-complete', () => meaningfulUse('search'));
  window.addEventListener('tracker:export-requested', () => meaningfulUse('export'));
  window.addEventListener('tracker:reading-list-saved', () => meaningfulUse('reading'));
  window.addEventListener('research-analysis-complete', () => meaningfulUse('analysis'));
  document.addEventListener('click', event => {
    if (!event.isTrusted) return;
    if (event.target.closest?.('[data-save-paper]')) window.dispatchEvent(new Event('tracker:reading-list-saved'));
    if (event.target.closest?.('.publication-exports a,.export-links a,[data-export-selected],[data-sidebar-export]')) window.dispatchEvent(new Event('tracker:export-requested'));
  }, true);
  window.addEventListener('storage', event => {
    if (event.key === installedKey || event.key === null) rememberedInstalled = read(installedKey) === '1';
    if (event.key === preferenceKey) { dismissedHere = true; hideCard(); }
    reconcile();
  });
  mode.addEventListener?.('change', () => { reconcile(); updatePushButton(); });
  document.addEventListener('input', event => {
    const form = event.target.closest?.('form');
    if (form && !form.matches('[role="search"],.hero-search,.advanced-filter-form,.result-quick-controls,#filters-form,#search-form')) dirtyForms.add(form);
    reconcile();
  }, true);
  document.addEventListener('reset', event => { dirtyForms.delete(event.target); setTimeout(reconcile, 0); }, true);
  document.addEventListener('focusin', reconcile);
  document.addEventListener('visibilitychange', () => { reconcile(); if (document.visibilityState === 'visible') checkRelated(); });
  window.addEventListener('pageshow', () => { reconcile(); checkRelated(); });
  window.addEventListener('online', reconcile);
  window.addEventListener('offline', reconcile);
  window.addEventListener('nav-sidebar-statechange', reconcile);
  window.addEventListener('tracker:offline-idle', reconcile);
  window.visualViewport?.addEventListener('resize', reconcile);
  setInterval(reconcile, 2000);
  window.TrackerInstall = { open: requestInstall, selectOfflineComponents: saveOfflineChoice, get installed() { return knownInstalled(); }, get available() { return !!deferredPrompt&&!knownInstalled()&&!pendingInstall()&&!installing&&navigator.onLine!==false; } };
  if (appMode()) {
    const context = attribution();
    if (context) track('install_followup_launch', `publicationTrackerPwa-${strategy}-followup-${context.at}`, context.surface);
  }
  reconcile();
  checkRelated();
}

async function initPwa() {
  const installButton=document.querySelector('#install-app'),pushButton=document.querySelector('#push-app');
  const standalone=matchMedia('(display-mode: standalone)').matches||navigator.standalone===true;
  const recordPwaMetric=createPwaMetricRecorder();
  window.TrackerPwaMetrics={record:recordPwaMetric};
  if(standalone){recordPwaMetric('standalone_first_launch','publicationTrackerStandaloneFirstRecorded');recordPwaMetric('standalone_launch',`publicationTrackerStandaloneLaunch-${new Date().toISOString().slice(0,10)}`);}
  for(const [attribute,src] of [['offline-library-loader','/assets/offline-library.min.js?'+APP_ENTRY_URL.searchParams.toString()],['offline-interface','/assets/offline-ui.min.js?'+APP_ENTRY_URL.searchParams.toString()],['pwa-freshness','/assets/pwa-freshness.min.js?'+APP_ENTRY_URL.searchParams.toString()]]){
    if(!document.querySelector(`script[data-${attribute}]`)){const script=document.createElement('script');script.src=src;script.setAttribute('data-'+attribute,'1');document.head.append(script);}
  }
  const canPush='serviceWorker' in navigator&&'PushManager' in window&&'Notification' in window;
  const installed=()=>{try{return standalone||localStorage.getItem('publicationTrackerInstalled')==='1';}catch(_){return standalone;}};
  let subscription=null,serverActive=null,busy=false,savedLoaded=true,notificationState=null;
  const registration=async()=>navigator.serviceWorker?.getRegistration('/');
  const bounded=(promise,ms=6000)=>Promise.race([promise,new Promise((_,reject)=>setTimeout(()=>reject(new Error('Status check timed out. Check your connection and reopen settings.')),ms))]);
  const updatePushButton=async()=>{
    try{const reg=canPush?await bounded(registration()):null;subscription=reg?.pushManager?await bounded(reg.pushManager.getSubscription()):null;}catch(error){subscription=null;throw error;}
    pushButton?.classList.toggle('is-enabled',serverActive!==false&&!!subscription&&Notification.permission==='granted');
    if(pushButton){pushButton.hidden=false;pushButton.title='Manage research notifications and check this device';}
  };
  initPwaInstall({installButton,recordPwaMetric,updatePushButton});
  try{
    if(!window.TrackerNotifications)await new Promise((resolve,reject)=>{const script=document.createElement('script');script.src='/assets/notification-state.js?v=20261108-received-history-v1';script.onload=resolve;script.onerror=reject;document.head.append(script);});
    notificationState=await TrackerNotifications.badge();
    window.trackerTouchHaptics=notificationState.preferences.haptics;
  }catch(_){}
  const showUnread=async()=>{
    try{notificationState=await TrackerNotifications.read();}catch(_){}
  };
  const confirmViewed=async()=>{
    if(document.visibilityState!=='visible')return;
    const marker=document.querySelector('[data-notification-batch]'),id=marker?.dataset.notificationBatch;
    if(!/^[a-f0-9]{64}$/.test(id||''))return;
    try{
      await TrackerNotifications.viewed(id);
      const reg=await registration();for(const notice of await reg?.getNotifications()||[])if(notice.data?.batch===id)notice.close();
      await showUnread();
    }catch(_){}
  };
  await confirmViewed();await showUnread();
  navigator.serviceWorker?.addEventListener('message',event=>{if(event.data?.type==='research-notification')showUnread();});
  document.addEventListener('visibilitychange',()=>{if(!document.hidden){confirmViewed();showUnread();}});
  window.addEventListener('pageshow',confirmViewed);
  window.addEventListener('tracker:results-loaded',confirmViewed);
  const createPushPreferences = async () => {
  let dialog=document.querySelector('#push-preferences');
  if(!dialog){
    try{const response=await fetch('/push.php?action=form',{cache:'no-store'});if(!response.ok)throw Error();document.body.insertAdjacentHTML('beforeend',await response.text());dialog=document.querySelector('#push-preferences');}catch(_){throw new Error('Connect to open notification settings, then try again.');}
  }
  const form=dialog.querySelector('form'),submit=form.querySelector('[type="submit"]'),stop=form.querySelector('[data-push-unsubscribe]'),test=form.querySelector('[data-push-test]');
  const feedback=(text,error=false)=>{const node=form.querySelector('[data-push-feedback]');node.textContent=text;node.dataset.error=String(error);};
  const choice=name=>form.querySelector(`[name="${name}"]`);
  const aliases=value=>{try{const parsed=JSON.parse(value);return Array.isArray(parsed)?parsed:[value];}catch(_){return [value];}};
  const sync=()=>{
    const targeted=form.querySelector('[name="push_scope"]:checked')?.value==='targeted';
    form.querySelectorAll('.alert-targeting input, .alert-targeting select, .alert-targeting button').forEach(input=>input.disabled=!targeted||busy);
    choice('push_vibration').disabled=!choice('push_sound').checked||busy;
    const data=new FormData(form),parts=[];
    if(targeted){
      for(const [key,label] of [['keywords','Keywords'],['author','Author'],['cited_doi','Cites DOI']]){const value=String(data.get('push_'+key)||'').trim();if(value)parts.push(label+': '+value);}
      for(const [key,label] of [['journal','Journals'],['topic','Topics']]){const values=[...form.querySelectorAll(`[name="push_${key}[]"]:checked`)].map(input=>input.closest('label').querySelector('span').textContent);if(values.length)parts.push(label+': '+values.join(', '));}
    }
    form.querySelector('[data-push-review]').textContent=`${data.get('push_frequency')||'daily'} digest · ${data.getAll('push_substances[]').join(', ')||'Choose at least one substance'}. ${parts.length?parts.join(' · ')+'. Any selection within a group; all filter groups must match.':'All new publications in this coverage.'} ${choice('push_sound').checked?'Phone sound settings apply.':'Quiet delivery: no sound or vibration.'}`;
    submit.disabled=busy||!savedLoaded||!canPush||!installed()||(canPush&&Notification.permission==='denied')||!navigator.onLine;
    test.disabled=busy||!serverActive||!subscription||!navigator.onLine;
    stop.disabled=busy||!navigator.onLine;
  };
  const status=async()=>{
    try { await updatePushButton(); } catch (_) { savedLoaded=false; }
    const pairs=[['Notifications',canPush?Notification.permission:'Not supported'],['App installation',installed()?'Installed':'Install the app to enable push'],['Subscription',serverActive?'Enabled for this device':subscription?'Not enabled on the server':'Not enabled'],['Connection',navigator.onLine?'Online':'Offline'],['Unread badge',notificationState?`${TrackerNotifications.count(notificationState)} unseen research updates`:'Device storage unavailable']];
    const summary=form.querySelector('[data-push-summary]');
    const enabled=canPush && Notification.permission==='granted' && !!subscription && serverActive===true;
    summary.textContent=!savedLoaded?'Status could not be verified. Reopen settings to try again.':enabled?'Push is enabled on this device.':!canPush?'Push notifications are not supported on this device.':Notification.permission==='denied'?'Push is blocked in your device or browser settings.':subscription && !serverActive?'Push is not active on the server. Save your preferences to enable it.':'Push is not enabled on this device.';
    summary.dataset.active=String(enabled);
    const host=form.querySelector('[data-push-device-status]');host.replaceChildren();
    for(const [label,value] of pairs){const dt=document.createElement('dt'),dd=document.createElement('dd');dt.textContent=label;dd.textContent=value;host.append(dt,dd);}
    sync();
  };
  const refreshPickers=preferences=>{
    for(const key of ['journal','topic']){
      const name=`push_${key}[]`,old=form.querySelector(`[name="${name}"]`)?.closest('[data-alert-multiselect]');if(!old)continue;
      const root=old.cloneNode(true);root.removeAttribute('data-picker-ready');old.replaceWith(root);
      const values=aliases(preferences[key]||'').filter(Boolean),represented=new Set([...root.querySelectorAll('[type="checkbox"]')].flatMap(box=>aliases(box.value)));
      for(const value of values)if(!represented.has(value)){
        const label=document.createElement('label'),box=document.createElement('input'),span=document.createElement('span');box.type='checkbox';box.name=name;box.value=value;span.textContent=value;label.append(box,span);root.querySelector('.alert-selection-options').append(label);
      }
      root.querySelectorAll('[type="checkbox"]').forEach(box=>box.checked=aliases(box.value).some(value=>values.includes(value)));
      root.querySelector('[data-selection-search]').value='';root.open=false;
    }
    initAlertMultiselects();
  };
  const close=()=>{dialog.close();dialog.classList.remove('is-open');updateModalOpenState();};
  let opening=0;
  const open=async()=>{
    if(dialog.open)return;
    const generation=++opening;
    dialog.showModal();dialog.classList.add('is-open');updateModalOpenState();refreshIcons();
    form.reset();form.querySelectorAll('details').forEach(node=>node.open=false);feedback('Checking this device…');savedLoaded=false;sync();
    try{
      await updatePushButton();
      let preferences={};serverActive=false;
      if(subscription){const response=await bounded(fetch('/push.php?action=preferences',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(subscription.toJSON()),cache:'no-store'}));const data=await response.json();if(!response.ok)throw Error(data.message||'Saved preferences are unavailable.');preferences=data.preferences;serverActive=data.active;}
      if(generation!==opening||!dialog.open)return;
      form.querySelectorAll('[name="push_frequency"]').forEach(input=>input.checked=input.value===(preferences.frequency||'daily'));
      form.querySelectorAll('[name="push_substances[]"]').forEach(input=>input.checked=(preferences.substances||'psilocybin,psilocin').split(',').includes(input.value));
      for(const key of ['keywords','author','cited_doi'])choice('push_'+key).value=preferences[key]||'';
      refreshPickers(preferences);
      const targeted=['keywords','author','journal','topic','cited_doi'].some(key=>preferences[key]);
      form.querySelector(`[name="push_scope"][value="${targeted?'targeted':'all'}"]`).checked=true;
      form.querySelector('.alert-targeting').open=targeted;
      if(notificationState)for(const [key,name] of [['sound','push_sound'],['vibration','push_vibration'],['badge','push_badge'],['haptics','push_haptics']])choice(name).checked=notificationState.preferences[key];
      stop.hidden=!subscription;savedLoaded=true;
      feedback(!canPush?'This browser does not support push notifications.':!installed()?'Install the app, then open it from your home screen to enable push.':Notification.permission==='denied'?'Notifications are blocked. Allow them in your browser or phone settings.':'');
    }catch(error){feedback(error.message||'Could not load saved preferences. Reopen settings to try again.',true);}
    await status();form.querySelector('[data-close-push-preferences]').focus({preventScroll:true});
  };
  dialog.querySelectorAll('[data-close-push-preferences]').forEach(button=>button.addEventListener('click',close));
  dialog.addEventListener('close',()=>{opening++;dialog.classList.remove('is-open');updateModalOpenState();});
  dialog.addEventListener('click',event=>{if(event.target===dialog)close();});
  form.addEventListener('input',sync);form.addEventListener('change',event=>{if(event.target.name==='push_scope'&&event.target.value==='targeted')form.querySelector('.alert-targeting').open=true;sync();});
  form.querySelector('.alert-targeting').addEventListener('toggle',()=>{if(form.querySelector('.alert-targeting').open){form.querySelector('[name="push_scope"][value="targeted"]').checked=true;sync();}});
  form.addEventListener('submit',async event=>{
    event.preventDefault();if(submit.disabled)return;
    if(!form.querySelector('[name="push_substances[]"]:checked')){feedback('Choose at least one substance.',true);return;}
    const data=new FormData(form),targeted=data.get('push_scope')==='targeted';
    const preferences={frequency:data.get('push_frequency'),substances:data.getAll('push_substances[]')};
    for(const key of ['keywords','author','cited_doi'])preferences[key]=targeted?data.get('push_'+key):'';
    for(const key of ['journal','topic'])preferences[key]=targeted?data.getAll(`push_${key}[]`).flatMap(aliases):[];
    busy=true;sync();feedback('Saving preferences…');
    try{
      // Keep the permission prompt directly inside the user's submit gesture.
      const permission=Notification.permission==='granted'?'granted':await Notification.requestPermission();
      if(permission!=='granted')throw Error('Notifications are not allowed. You can change this in browser or phone settings.');
      const reg=await Promise.race([navigator.serviceWorker.ready,new Promise((_,reject)=>setTimeout(()=>reject(Error('The app is still preparing notifications. Please try again.')),8000))]);
      subscription=await reg.pushManager.getSubscription();
      if(!subscription){const response=await fetch('/push.php?action=public-key',{cache:'no-store'});if(!response.ok)throw Error('Could not prepare notifications.');const key=await response.json();subscription=await reg.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:base64UrlToUint8Array(key.publicKey)});}
      const response=await fetch('/push.php?action=subscribe',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({...subscription.toJSON(),preferences})});const result=await response.json();if(!response.ok||!result.ok)throw Error(result.message||'Could not save preferences.');
      serverActive=true;
      try{notificationState=await TrackerNotifications.preferences({sound:choice('push_sound').checked,vibration:choice('push_vibration').checked,badge:choice('push_badge').checked,haptics:choice('push_haptics').checked});window.trackerTouchHaptics=notificationState.preferences.haptics;feedback('');showAppToast('Push preferences saved for this device.');}catch(_){feedback('Research preferences saved. Device delivery choices could not be stored; browser defaults apply.',true);}
      stop.hidden=false;await showUnread();
    }catch(error){feedback(error.message||'Could not save preferences. Please try again.',true);}
    finally{busy=false;await status();}
  });
  stop.addEventListener('click',async()=>{
    if(busy||!subscription)return;busy=true;sync();
    try{const response=await fetch('/push.php?action=unsubscribe',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(subscription.toJSON())});if(!response.ok)throw Error('Could not stop delivery. Try again or revoke notification permission in browser settings.');serverActive=false;await subscription.unsubscribe();subscription=null;stop.hidden=true;feedback('Push delivery stopped for this device.');}
    catch(error){feedback(error.message,true);}finally{busy=false;await status();}
  });
  test.addEventListener('click',async()=>{
    if(test.disabled)return;busy=true;sync();feedback('Sending a test to this device…');
    try{const response=await fetch('/push.php?action=test',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(subscription.toJSON())});const result=await response.json();if(!response.ok||!result.ok)throw Error(result.message||'The test could not be delivered.');feedback(result.message);}
    catch(error){feedback(error.message,true);}finally{busy=false;await status();}
  });
  const historyDetails=form.querySelector('[data-push-history]');
  historyDetails.addEventListener('toggle',async()=>{
    if(!historyDetails.open)return;
    const list=historyDetails.querySelector('[data-push-history-list]');list.replaceChildren();
    try {
      const state=await TrackerNotifications.read(),history=Array.isArray(state.history)?state.history:[];
      if(!history.length){list.textContent='No received notifications recorded yet.';return;}
      const ul=document.createElement('ul');
      for(const item of history){const li=document.createElement('li'),time=document.createElement('time');time.dateTime=new Date(item.time).toISOString();time.textContent=new Date(item.time).toLocaleString();li.textContent=(item.title||'Research notification')+' · ';li.append(time);ul.append(li);}list.append(ul);
    } catch (_) {list.textContent='Notification history is unavailable on this device.';}
  });
  form.querySelector('[data-push-read]').addEventListener('click',async()=>{
    try{notificationState=await TrackerNotifications.viewed();const reg=await registration();for(const notice of await reg?.getNotifications()||[])if(notice.data?.kind!=='test')notice.close();await showUnread();await status();feedback('Research updates marked as viewed.');}catch(_){feedback('Device storage is unavailable.',true);}
  });
  window.addEventListener('online',status);window.addEventListener('offline',status);
  return open;
  };
  let preferencesRequest = null;
  const openPushPreferences = async () => {
    collapsePrimaryNavSidebarOnCompact();
    pushButton?.setAttribute('aria-busy', 'true');
    try {
      if (!preferencesRequest) preferencesRequest = createPushPreferences().catch(error => { preferencesRequest = null; throw error; });
      const open = await preferencesRequest;
      await open();
    } catch (error) { showAppToast(error.message || 'Notification settings could not load. Try again.'); }
    finally { pushButton?.removeAttribute('aria-busy'); }
  };
  pushButton?.addEventListener('click', openPushPreferences);
  const hashSettings = () => { if (location.hash === '#notifications') openPushPreferences(); };
  window.addEventListener('hashchange', hashSettings);
  await updatePushButton().catch(()=>{}); hashSettings();
}

function initVisitorActivity() {
  const online = document.querySelector('#visitor-online-count');
  if (!online) return;
  const active = document.querySelector('#visitor-active-count');
  const views = document.querySelector('#visitor-pageview-count');
  const spinner = document.querySelector('#visitor-poll-spinner');
  const refresh = async () => {
    if (document.hidden) return;
    spinner?.classList.add('is-polling');
    try {
      const response = await fetch('visitor-activity.php', { headers: { Accept: 'application/json' }, cache: 'no-store' });
      if (!response.ok) return;
      const data = await response.json();
      if (Number.isInteger(data.online)) online.textContent = data.online.toLocaleString();
      if (active && Number.isInteger(data.active_sessions)) active.textContent = data.active_sessions.toLocaleString();
      if (views && Number.isInteger(data.page_views)) views.textContent = data.page_views.toLocaleString();
    } catch (error) {} finally { spinner?.classList.remove('is-polling'); }
  };
  window.setInterval(refresh, 30000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
}

function initGlobalInteractionFeedback() {
  if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;
  let keyboardNavigation = false;
  const ripple = (x, y) => {
    const element = document.createElement("span");
    element.className = "tracker-interaction-ripple";
    element.setAttribute("aria-hidden", "true");
    element.style.left = `${Math.round(x)}px`;
    element.style.top = `${Math.round(y)}px`;
    document.body.append(element);
    window.requestAnimationFrame(() => element.classList.add("is-visible"));
    window.setTimeout(() => element.remove(), 680);
  };
  document.addEventListener("pointerdown", (event) => {
    keyboardNavigation = false;
    if (!event.isPrimary || event.button !== 0) return;
    ripple(event.clientX, event.clientY);
  }, { capture: true, passive: true });
  document.addEventListener("keydown", (event) => { keyboardNavigation = event.key === "Tab"; }, { capture: true });
  document.addEventListener("focusin", (event) => {
    if (!keyboardNavigation || !(event.target instanceof HTMLElement)) return;
    const rect = event.target.getBoundingClientRect();
    if (rect.width && rect.height) ripple(rect.left + rect.width / 2, rect.top + rect.height / 2);
  }, { capture: true });
}

function renderToastContent(node, message) {
  if(!node.dataset.dismissOnClick){node.dataset.dismissOnClick='true';node.addEventListener('click',()=>{if(node.popover&&node.matches(':popover-open'))node.hidePopover();node.classList.remove('is-visible');node.hidden=true;});}
  const icon = /could not|unavailable|not available|failed|error|unable|before adding|before exporting/i.test(message) ? 'info'
    : /copied|copy this|copy it/i.test(message) ? 'copy'
    : /fullscreen/i.test(message) ? 'maximize'
    : /search|results updated|showing.*publications/i.test(message) ? 'search'
    : /saved|reading list/i.test(message) ? 'book-marked'
    : /install|download/i.test(message) ? 'download' : 'check';
  const graphic=document.createElement('span');graphic.className='toast-icon';graphic.setAttribute('aria-hidden','true');graphic.innerHTML=ICONS[icon]||ICONS.info;
  const text=document.createElement('span');text.className='toast-message';text.textContent=message;
  const close=document.createElement('button');close.type='button';close.className='toast-close';close.setAttribute('aria-label','Dismiss notification');close.title='Dismiss notification';close.textContent='×';
  node.replaceChildren(graphic,text,close);
}
window.TrackerToast={render:renderToastContent};

function showAppToast(message, timeout = 5200) {
  let toast = document.querySelector("#app-toast");
  if (!toast) {
    toast = document.createElement("div");
    toast.id = "app-toast";
    toast.className = "app-toast";
    toast.setAttribute("role", "status");
    toast.setAttribute("aria-live", "polite");
    toast.hidden = true;
    document.body.append(toast);
  }
  // Cancel both stages so a previous message cannot hide a newer confirmation.
  window.clearTimeout(showAppToast.timer);
  window.clearTimeout(showAppToast.hideTimer);
  renderToastContent(toast, message);
  toast.hidden = false;
  // Keep the live region inside the active modal's accessible subtree.
  const host = document.querySelector("dialog[open]") || document.body;
  if (toast.parentElement !== host) host.append(toast);
  if (typeof toast.showPopover === "function") {
    toast.popover = "manual";
    if (toast.matches(":popover-open")) toast.hidePopover();
    toast.showPopover(); // The top layer keeps feedback visible above native dialogs.
  }
  requestAnimationFrame(() => toast.classList.add("is-visible"));
  showAppToast.timer = window.setTimeout(() => {
    toast.classList.remove("is-visible");
    showAppToast.hideTimer = window.setTimeout(() => {
      if (toast.popover && toast.matches(":popover-open")) toast.hidePopover();
      toast.hidden = true;
    }, 220);
  }, timeout);
}

function initCollectionExport() {
  const host = document.querySelector('#publication-results');
  const list = host?.querySelector('.paper-list');
  if (!list || host.dataset.selectionBound || !host.querySelector('[data-collection-paper]')) return;
  host.dataset.selectionBound = 'true';
  const bar = document.createElement('div');
  bar.className = 'collection-selection-bar';
  bar.hidden = true;
  bar.setAttribute('aria-label', 'Selected records on this page');
  bar.setAttribute('role', 'region');
  bar.innerHTML = '<span data-selection-count role="status" aria-live="polite" aria-atomic="true"></span><label>Format <select data-selection-format aria-label="Selected records export format"><option value="bibtex">BibTeX</option><option value="ris">RIS</option><option value="csv">CSV</option><option value="json">JSON</option><option value="latex">LaTeX</option></select></label><button type="button" class="primary" data-export-selected><span>Export</span></button><button type="button" class="secondary" data-selection-clear>Clear</button>';
  list.before(bar);
  const boxes = () => [...host.querySelectorAll('input[type="checkbox"][data-collection-paper]')];
  const selectedIds = () => [...new Set(boxes().filter(box => box.checked).map(box => box.value).filter(id => /^[1-9]\d*$/.test(id)))];
  const setText = (node, value) => { if (node && node.textContent !== value) node.textContent = value; };
  const sync = () => {
    const count = selectedIds().length;
    bar.hidden = count === 0;
    setText(bar.querySelector('[data-selection-count]'), `${count} selected`);
    host.querySelectorAll('[data-export-selected]').forEach(trigger => {
      trigger.disabled = count === 0;
      if (!bar.contains(trigger)) {
        let label = trigger.querySelector('span');
        if (!label) { label = document.createElement('span'); trigger.append(label); }
        setText(label, count ? `Export selected (${count})` : 'Export selected');
      }
    });
    scheduleSmartResultBarUpdate();
  };
  host.addEventListener('change', event => {
    if (event.target.matches?.('input[data-collection-paper]')) {
      sync();
      const menu = event.target.closest('.paper-citation-menu');
      if (menu) { menu.open = false; menu.querySelector('summary')?.focus({ preventScroll: true }); }
      showAppToast(`${selectedIds().length} selected for export. Use the export toolbar above the papers.`);
    }
  });
  host.addEventListener('click', event => {
    const clear = event.target.closest?.('[data-selection-clear]');
    if (clear && bar.contains(clear)) {
      boxes().forEach(box => { box.checked = false; });
      host.focus({ preventScroll: true });
      sync();
      showAppToast('Selection cleared.');
      return;
    }
    const trigger = event.target.closest?.('[data-export-selected]');
    if (!trigger || !host.contains(trigger)) return;
    event.preventDefault();
    const ids = selectedIds();
    if (!ids.length) { sync(); return; }
    const url = new URL('export.php', window.location.href);
    url.searchParams.set('format', bar.querySelector('[data-selection-format]').value);
    ids.forEach(id => url.searchParams.append('ids[]', id));
    const download=document.createElement('a');download.href=url.href;download.click();
  });
  sync();
}

function initEvidenceMapSort() {
  const table = document.querySelector("[data-evidence-map]");
  if (!table) return;
  const buttons = [...table.querySelectorAll("[data-evidence-sort]")];
  const rows = [...table.querySelectorAll(".evidence-map-row")];
  const numericKeys = new Set(["year", "count"]);
  const sortRows = (key, direction) => {
    const multiplier = direction === "ascending" ? 1 : -1;
    rows.sort((a, b) => {
      const av = a.dataset[key] || "";
      const bv = b.dataset[key] || "";
      if (numericKeys.has(key)) {
        return (Number(av) - Number(bv)) * multiplier;
      }
      return av.localeCompare(bv, undefined, { sensitivity: "base", numeric: true }) * multiplier;
    });
    for (const row of rows) table.append(row);
    buttons.forEach((button) => {
      const active = button.dataset.evidenceSort === key;
      button.setAttribute("aria-sort", active ? direction : "none");
      button.classList.toggle("is-sorted", active);
      button.classList.toggle("is-ascending", active && direction === "ascending");
    });
  };
  buttons.forEach((button) => {
    button.addEventListener("click", () => {
      const key = button.dataset.evidenceSort;
      const current = button.getAttribute("aria-sort");
      const nextDirection = current === "ascending" ? "descending" : "ascending";
      sortRows(key, nextDirection);
    });
  });
}


function base64UrlToUint8Array(value) {
  const padding = "=".repeat((4 - value.length % 4) % 4);
  const base64 = (value + padding).replaceAll("-", "+").replaceAll("_", "/");
  const raw = window.atob(base64);
  const output = new Uint8Array(raw.length);
  for (let i = 0; i < raw.length; i++) output[i] = raw.charCodeAt(i);
  return output;
}

// Feature requests are shared, retryable, and keyed to this entry script's release.
const APP_ENTRY_URL = new URL(document.currentScript?.src || [...document.scripts].find(script => /\/assets\/app(?:\.min)?\.js/.test(script.src))?.src || location.href);
const APP_ASSET_VERSION = (APP_ENTRY_URL.searchParams.get('v') || '20261114') + (APP_ENTRY_URL.searchParams.get('r') ? '-'+APP_ENTRY_URL.searchParams.get('r') : '');
const featureRequests = new Map();
const featureAttempts = new Map();
function loadAppFeature(name, dependencies) {
  if (featureRequests.has(name)) return featureRequests.get(name);
  const attempt = (featureAttempts.get(name) || 0) + 1;
  featureAttempts.set(name, attempt);
  const url = `/assets/features/${name}.min.js?v=${encodeURIComponent(APP_ASSET_VERSION)}${attempt > 1 ? `&retry=${attempt}` : ''}`;
  const request = import(url).then(module => module.init(dependencies)).catch(error => { featureRequests.delete(name); throw error; });
  featureRequests.set(name, request);
  return request;
}
function ensureAnalyticsFeature() {
  return loadAppFeature('analytics', { escapeHtml, printDateRangeLabel, printReferenceText, printSourcesLabel, refreshIcons });
}
function initPageFeatures() {
  const growth = document.querySelector('#publication-growth-chart');
  if (growth) {
    const showGrowth = () => ensureAnalyticsFeature().then(feature => feature.renderGrowth()).catch(() => {});
    if ('IntersectionObserver' in window) {
      const observer = new IntersectionObserver(entries => {
        if (entries.some(entry => entry.isIntersecting)) { observer.disconnect(); showGrowth(); }
      }, { rootMargin: '160px' });
      observer.observe(growth);
    } else scheduleIdleTask(showGrowth);
  }
  if (document.querySelector('[data-research-film]')) {
    loadAppFeature('research-film', { ICONS, showAppToast, updateModalOpenState }).catch(() => {
      // Native playback and downloads remain available if enhancement cannot load.
      document.querySelectorAll('[data-research-film] video:not(.research-film-blur)').forEach(video => { video.controls = true; try { video.muted = localStorage.getItem('psilocybin-research-film-muted') === '1'; } catch { video.muted = true; } });
    });
  }
  syncRecencyBadges();
}
window.addEventListener('pagehide', () => pendingSearchController?.abort());
window.addEventListener('pageshow', event => {
  if (!event.persisted) return;
  pendingSearchController = null;
  document.documentElement.classList.remove('is-searching');
  hidePreloader();
});

function initPageTransitions() {
  const reduced = matchMedia('(prefers-reduced-motion: reduce)');
  const uiDocument = href => {
    const url = new URL(href, location.href);
    return url.origin === location.origin && !/\.(?:mp4|webm|pdf|zip|csv|json|sqlite|bib|ris|r|py)$/i.test(url.pathname)
      && !/\/(?:api(?:-v1)?|export|database|linked-data|status|health|sitemap(?:-[a-z]+)?|widget)\.php$/i.test(url.pathname);
  };
  addEventListener('pageswap', event => {
    if (!event.viewTransition) return;
    if (reduced.matches || !event.activation || !uiDocument(event.activation.entry.url) || document.querySelector('dialog[open]')) event.viewTransition.skipTransition();
  });
  addEventListener('pagereveal', event => {
    if (!event.viewTransition) return;
    if (reduced.matches || !uiDocument(location.href)) { event.viewTransition.skipTransition(); return; }
    document.documentElement.classList.add('native-page-transition');
    const clearTransition = () => document.documentElement.classList.remove('native-page-transition');
    event.viewTransition.finished.then(clearTransition, clearTransition);
  });
}

function initToolDirectory() {
  const input = document.querySelector('[data-tool-directory-search]');
  if (!input || input.dataset.ready === '1') return;
  input.dataset.ready = '1';
  const directory = input.closest('.tool-directory');
  const rows = [...directory.querySelectorAll('[data-directory-key]')];
  const normalize = value => value.normalize('NFKD').replace(/\p{M}/gu, '').toLocaleLowerCase();
  const index = rows.map(row => ({ row, text: normalize(row.textContent + ' ' + row.dataset.directoryKeywords) }));
  const update = () => {
    const terms = normalize(input.value).trim().split(/\s+/).filter(Boolean);
    let count = 0;
    index.forEach(({ row, text }) => { row.hidden = !terms.every(term => text.includes(term)); if (!row.hidden) count++; });
    directory.querySelectorAll(':scope > section').forEach(section => { section.hidden = !section.querySelector('li:not([hidden])'); });
    directory.querySelector('[data-tool-directory-count]').textContent = count ? `${count} ${count === 1 ? 'tool' : 'tools'}${terms.length ? ' matching your search' : ' available'}` : 'No matching tools. Try a broader term such as trials, data or evidence.';
  };
  input.addEventListener('input', update); update();
}

async function initPublicationTracker() {
  if (document.documentElement.dataset.publicationTrackerInitialized === "1") return;
  document.documentElement.dataset.publicationTrackerInitialized = "1";
  initPageTransitions();
  initImmediatePageTransitions();
  initGlobalInteractionFeedback();
  initVisitorActivity();
  refreshHeroUpdatedTime();
  initAppSheets();
  initAdvancedFilterSearchSync();
  initPublicationSearchFocus();
  initSettingsMenus();
  initSidebarControls();
  initResultQuickControls();
  initFloatingExportHoverMenu();
  initNavSidebarCollapse();
  initNavGroups();
  initToolFinder();
  initToolDirectory();
  initFullscreenToggle();
  initPrintResults();
  initNativeShare();
  initEntryChoices();
  initAjaxSearch();
  // Paint usable server-rendered content before measuring secondary controls.
  if (!document.documentElement.classList.contains('is-searching')) hidePreloader();
  await new Promise(resolve => requestAnimationFrame(() => window.setTimeout(resolve, 0)));
  initSectionNavigation();
  initSmartResultBar();
  initScrollTop();
  initCountUpStats();
  initClientEnvironment();
  initGsapEnhancements();
  initSearchResultFocus();
  initAlertScope();
  initSourceStatsModal();
  initHaptics();
  initSidebarSelectedExport();
  initCollectionExport();
  initEvidenceMapSort();
  initAlertVanta();
  initDownloadConfirmations();
  initApiConfirmations();
  initPageFeatures();
  // Usable server-rendered content does not wait for images, analytics or PWA storage.
  requestAnimationFrame(() => { if (!document.documentElement.classList.contains('is-searching')) hidePreloader(); });
  initPwa().catch(() => showAppToast("Notification settings are unavailable. Please reconnect and try again."));
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", initPublicationTracker, { once: true });
} else {
  initPublicationTracker();
}

// Reading-list access uses existing device-local storage; nothing is sent to the server.
(() => {
 const refresh = () => {
  let ids=[], searches=[];
  try {const value=JSON.parse(localStorage.getItem('psilocybinResearchWorkspaceV1')||'{}');ids=Array.isArray(value.ids)?[...new Set(value.ids.filter(id=>Number.isSafeInteger(id)&&id>0))].slice(0,50):[];searches=Array.isArray(value.searches)?value.searches.slice(0,30):[];} catch {}
  document.querySelectorAll('[data-reading-list-shortcut]').forEach(link=>{link.hidden=!(ids.length||searches.length);const count=link.querySelector('[data-reading-list-count]');if(count)count.textContent=ids.length?'('+ids.length+')':'';});
  document.querySelectorAll('[data-sidebar-reading-count]').forEach(badge=>{badge.hidden=!ids.length;badge.textContent=String(ids.length);badge.setAttribute('aria-label',`${ids.length} saved ${ids.length===1?'publication':'publications'}`);});
 };
 window.addEventListener('storage',refresh);window.addEventListener('research-workspace-change',refresh);
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',refresh,{once:true});else refresh();
})();

// A shared metadata-only catalogue keeps thousands of inactive controls out of the DOM.
function hydrateDeferredOptions(root, all = false) {
  const key = root.dataset.optionsKey;
  if (!key || root.dataset.optionsHydrated === '1') return;
  const script = document.querySelector('#alert-option-catalog');
  if (script && script.dataset.catalogRead !== '1') {
    try { for (const [id, groups] of Object.entries(JSON.parse(script.textContent))) if (Array.isArray(groups) && groups.length <= 10000) deferredOptionCatalog.set(id, groups); script.dataset.catalogRead = '1'; } catch { return; }
  }
  const activeKeys = new Set([...document.querySelectorAll('[data-options-key]')].map(node => node.dataset.optionsKey));
  for (const id of deferredOptionCatalog.keys()) if (!activeKeys.has(id)) deferredOptionCatalog.delete(id);
  const groups = deferredOptionCatalog.get(key);
  if (!groups) return;
  let selected;
  try { selected = root.dataset.optionsSynced === '1' ? [...root.querySelectorAll('input:checked')].map(box => box.value) : JSON.parse(root.dataset.optionsSelected || '[]'); } catch { selected = []; }
  selected = new Set(Array.isArray(selected) ? selected.filter(value => typeof value === 'string').slice(0, 100) : []);
  const options = root.querySelector('.alert-selection-options');
  const fragment = document.createDocumentFragment();
  const targeted = root.closest('.alert-targeting');
  const disabled = targeted && root.closest('form')?.querySelector('[name="alert_scope"]:checked')?.value !== 'targeted';
  for (const item of groups) {
    const group = typeof item === 'string' ? { label: item, aliases: [item] } : item;
    if (!group || typeof group.label !== 'string' || !Array.isArray(group.aliases) || !group.aliases.length || group.aliases.some(alias => typeof alias !== 'string')) continue;
    const value = group.aliases.length === 1 ? group.aliases[0] : JSON.stringify(group.aliases);
    const checked = selected.has(value) || group.aliases.some(alias => selected.has(alias));
    if (!all && !checked) continue;
    const label = document.createElement('label'), box = document.createElement('input'), text = document.createElement('span');
    box.type = 'checkbox'; box.name = root.dataset.optionsName + '[]'; box.value = value; box.checked = checked; box.disabled = Boolean(disabled);
    text.textContent = group.label; label.classList.toggle('is-selected', checked); label.append(box, text); fragment.append(label);
  }
  options.replaceChildren(fragment);
  root.dataset.optionsSynced = '1';
  if (all) root.dataset.optionsHydrated = '1';
}

function initAlertMultiselects(scope = document) {
  scope.querySelectorAll('[data-alert-multiselect]').forEach(root => {
    const dialog = root.closest('dialog');
    if (dialog && !dialog.open) return;
    if(root.dataset.pickerReady==='1')return;
    root.dataset.pickerReady='1';
    hydrateDeferredOptions(root);
    let boxes = Array.from(root.querySelectorAll('input[type="checkbox"]'));
    const search = root.querySelector('[data-selection-search]');
    const summary = root.querySelector('.alert-selection-summary');
    const count = root.querySelector('.alert-selection-count');
    const clear = root.querySelector('.alert-selection-clear');
    const options = root.querySelector('.alert-selection-options');
    const results = root.querySelector('[data-selection-result]');
    const filter = () => {
      const query = search.value.trim().toLocaleLowerCase();
      boxes.forEach(box => { box.closest('label').hidden = !box.closest('label').textContent.toLocaleLowerCase().includes(query); });
      const visible = boxes.filter(box => !box.closest('label').hidden).length;
      results.textContent = `${visible} ${query ? 'matching' : 'available'}`;
      root.querySelector('[data-selection-empty]').hidden = visible > 0;
    };
    const sync = () => {
      const selected = boxes.filter(box => box.checked).map(box => box.closest('label').querySelector('span')?.textContent || box.value);
      summary.textContent = selected.length ? selected.join(', ') : summary.dataset.emptyLabel;
      summary.title = selected.join(', ');
      count.textContent = selected.length ? `${selected.length} selected` : 'All';
      root.classList.toggle('has-selection', selected.length > 0);
      clear.disabled = selected.length === 0;
      boxes.forEach(box => box.closest('label').classList.toggle('is-selected', box.checked));
    };
    options.addEventListener('change', sync);
    clear.addEventListener('click', () => {
      boxes.forEach(box => { box.checked = false; });
      sync();
      boxes[0]?.dispatchEvent(new Event('change', {bubbles: true}));
    });
    search.addEventListener('input', filter);
    root.addEventListener('toggle', () => {
      if (!root.open) return;
      hydrateDeferredOptions(root, true);
      boxes = Array.from(root.querySelectorAll('input[type="checkbox"]'));
      sync();
      // Keep one choice list open per form, without resetting either selection.
      root.closest('form')?.querySelectorAll('[data-alert-multiselect]').forEach(other => {
        if (other !== root) other.open = false;
      });
      // Reopening brings chosen options to the top; rows stay still while selecting.
      [...boxes].sort((a, b) => Number(b.checked) - Number(a.checked)).forEach(box => options.append(box.closest('label')));
      filter();
    });
    sync();
    filter();
  });
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => initAlertMultiselects());
else initAlertMultiselects();


// Search-to-alert is delegated so it also works after an AJAX result replacement.
function initSearchAlerts() {
  const form = document.querySelector('#alert-enrollment .alert-form');
  if (!form) return;
  const readAliases = value => { try { const v=JSON.parse(value); return Array.isArray(v)?v:[value]; } catch { return [value]; } };
  const refresh = () => {
    const data = new FormData(form), targeted = data.get('alert_scope') === 'targeted';
    const parts = [];
    if (targeted) {
      for (const [key,label] of [['alert_keywords','Keywords'],['alert_author','Author'],['alert_cited_doi','Cites DOI']]) {
        const value=String(data.get(key)||'').trim(); if(value) parts.push(`${label}: ${value}`);
      }
      for (const [key,label] of [['alert_journal','Journals'],['alert_topic','Topics']]) {
        const names=[...form.querySelectorAll(`input[name="${key}[]"]:checked`)].map(e=>e.closest('label').querySelector('span').textContent);
        if(names.length) parts.push(`${label}: ${names.join(', ')}`);
      }
    }
    const substances=data.getAll('alert_substances[]').join(', ') || 'No substances selected';
    const summary=form.querySelector('[data-alert-review]');
    summary.textContent=`${data.get('frequency') || 'daily'} digest · ${substances}. ${parts.length?parts.join(' · ')+'.':'All new publications in this coverage.'}${targeted&&parts.length?' Any selection within journals or topics; all filter groups must match.':''} Confirmation by email is required.`;
  };
  form.addEventListener('input', refresh); form.addEventListener('change', refresh);
  document.addEventListener('click', event => {
    if (!event.target.closest('[data-alert-from-search]')) return;
    form.querySelectorAll('[data-options-key]').forEach(root => hydrateDeferredOptions(root, true));
    const p = new URLSearchParams(location.search);
    const set=(name,value)=>{const input=form.elements.namedItem(name); if(input)input.value=value||'';};
    set('alert_keywords',p.get('search')||p.get('q'));set('alert_author',p.get('author'));set('alert_cited_doi',p.get('cited_doi'));
    for (const [field,key] of [['alert_journal','journal'],['alert_topic','topic']]) {
      const values=[...p.entries()].filter(([k])=>k===key||k.startsWith(key+'[')).flatMap(([,v])=>readAliases(v));
      const boxes=[...form.querySelectorAll(`input[name="${field}[]"]`)];
      boxes.forEach(box=>{box.checked=readAliases(box.value).some(v=>values.includes(v));});
      boxes[0]?.dispatchEvent(new Event('change',{bubbles:true}));
    }
    const values=[...p.entries()].filter(([k])=>/^substances(?:\[.*\])?$/.test(k)).map(([,v])=>v);
    form.querySelectorAll('[name="alert_substances[]"]').forEach(box=>box.checked=!values.length||values.includes(box.value));
    const targeted=['search','q','author','journal','topic','cited_doi'].some(key=>[...p.keys()].some(k=>(k===key||k.startsWith(key+'['))&&p.get(k)));
    const radio=form.querySelector(`[name="alert_scope"][value="${targeted?'targeted':'all'}"]`);radio.checked=true;radio.dispatchEvent(new Event('change',{bubbles:true}));
    const ignored=[];
    for(const [key,label] of [['year','year'],['from','date range'],['to','date range'],['added_from','date added'],['added_to','date added'],['study_type','study type']]) if(p.get(key))ignored.push(label);
    if(p.get('range')&&!['all','custom'].includes(p.get('range')))ignored.push('date range');
    for(const [key,label] of [['sources','source database'],['publication_statuses','evidence status']]) if([...p.keys()].some(k=>k===key||k.startsWith(key+'[')))ignored.push(label);
    const note=form.querySelector('[data-alert-search-note]');note.hidden=false;
    note.textContent=ignored.length?`Copied supported search criteria. Alerts do not apply these search filters: ${[...new Set(ignored)].join(', ')}. Review your alert coverage below.`:'Search criteria copied. Review your alert coverage below.';
    refresh();
  });
  refresh();
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initSearchAlerts);
else initSearchAlerts();

document.addEventListener('toggle', event => {
  const menu = event.target;
  if (!(menu instanceof HTMLDetailsElement) || !menu.matches('.paper-citation-menu')) return;
  menu.querySelector('summary')?.setAttribute('aria-expanded', String(menu.open));
  if (!menu.open) return;
  document.querySelectorAll('.paper-citation-menu[open]').forEach(other => { if (other !== menu) other.open = false; });
  menu.classList.remove('opens-above');
  const panel = menu.querySelector(':scope > div'), anchor = menu.querySelector('summary').getBoundingClientRect();
  const below = innerHeight - anchor.bottom - 16, above = anchor.top - 16;
  const opensAbove = panel.scrollHeight > below && above > below;
  menu.classList.toggle('opens-above', opensAbove);
  panel.style.maxHeight = Math.max(100, Math.min(360, opensAbove ? above : below)) + 'px';
}, true);
document.addEventListener('focusin', event => {
  document.querySelectorAll('.paper-citation-menu[open]').forEach(menu => { if (!menu.contains(event.target)) menu.open = false; });
});
document.addEventListener('click', event => {
  document.querySelectorAll('.paper-citation-menu[open]').forEach(menu => { if(!menu.contains(event.target)) menu.open=false; });
});
document.addEventListener('keydown', event => {
  if(event.key!=='Escape')return;
  document.querySelectorAll('.paper-citation-menu[open]').forEach(menu=>{menu.open=false;menu.querySelector('summary')?.focus();});
});

// Account sync is loaded only for a browser that has signed in previously.
(() => { try { if(localStorage.getItem('psilocybinResearchAccountV1')&&!document.querySelector('script[data-account-sync]')) { const s=document.createElement('script');s.src='/assets/account-sync.min.js?v=20261108-accounts-v1';s.dataset.accountSync='';document.head.append(s); } } catch (_) {} })();
