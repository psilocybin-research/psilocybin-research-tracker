document.documentElement.classList.add("js");

const updateCheck = document.querySelector("#update-check");
const lastUpdatedText = document.querySelector("#last-updated-text");
const heroUpdatedText = document.querySelector("#hero-updated-text");
let collapsePrimaryNavSidebarOnCompact = () => {};

function hidePreloader() {
  const preloader = document.querySelector("#app-preloader");
  document.documentElement.classList.remove("is-loading");
  if (!preloader || preloader.hidden) return;
  preloader.classList.add("is-hidden");
  window.setTimeout(() => {
    preloader.hidden = true;
  }, 260);
}

window.addEventListener("load", hidePreloader);
window.setTimeout(hidePreloader, 2800);

function scheduleIdleTask(callback, timeout = 1600) {
  if ("requestIdleCallback" in window) {
    window.requestIdleCallback(callback, { timeout });
    return;
  }
  window.setTimeout(callback, Math.min(timeout, 900));
}

const ICONS = {
  "search": '<svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-4.2-4.2"></path></svg>',
  "microscope": '<svg viewBox="0 0 24 24"><path d="M6 18h8"></path><path d="M3 22h18"></path><path d="M14 22a7 7 0 0 0 0-14h-1"></path><path d="M9 14h2"></path><path d="M8 6h4"></path><path d="M7 10h6"></path><path d="M10 6V3"></path><path d="M9 3h2"></path><path d="M6 10v4a2 2 0 0 0 2 2h4"></path></svg>',
  "settings": '<svg viewBox="0 0 24 24"><path d="M4 7h10"></path><path d="M18 7h2"></path><circle cx="16" cy="7" r="2"></circle><path d="M4 17h2"></path><path d="M10 17h10"></path><circle cx="8" cy="17" r="2"></circle></svg>',
  "book-marked": '<svg viewBox="0 0 24 24"><path d="M6 3h11a1 1 0 0 1 1 1v17l-6-3-6 3z"></path><path d="M9 7h6"></path><path d="M9 11h4"></path></svg>',
  "book-open": '<svg viewBox="0 0 24 24"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H11v18H6.5A2.5 2.5 0 0 0 4 18.5z"></path><path d="M20 5.5A2.5 2.5 0 0 0 17.5 3H13v18h4.5a2.5 2.5 0 0 1 2.5-2.5z"></path></svg>',
  "users": '<svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"></circle><path d="M3 20a6 6 0 0 1 12 0"></path><circle cx="17" cy="9" r="2.5"></circle><path d="M15 15.5A5 5 0 0 1 21 20"></path></svg>',
  "clipboard-list": '<svg viewBox="0 0 24 24"><path d="M9 4h6l1 2h3v15H5V6h3z"></path><path d="M9 11h6"></path><path d="M9 15h6"></path><path d="M8 4h8"></path></svg>',
  "grid-3x3": '<svg viewBox="0 0 24 24"><path d="M4 4h16v16H4z"></path><path d="M4 9.3h16"></path><path d="M4 14.7h16"></path><path d="M9.3 4v16"></path><path d="M14.7 4v16"></path></svg>',
  "library": '<svg viewBox="0 0 24 24"><path d="M4 19V5"></path><path d="M8 19V5"></path><path d="M12 19V5"></path><path d="M16 19V5"></path><path d="M20 19V5"></path></svg>',
  "table": '<svg viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="14" rx="1.5"></rect><path d="M4 10h16"></path><path d="M10 5v14"></path></svg>',
  "database": '<svg viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="7" ry="3"></ellipse><path d="M5 5v6c0 1.7 3.1 3 7 3s7-1.3 7-3V5"></path><path d="M5 11v6c0 1.7 3.1 3 7 3s7-1.3 7-3v-6"></path></svg>',
  "braces": '<svg viewBox="0 0 24 24"><path d="M8 4c-2 0-2 2-2 3v2c0 1-1 2-2 2 1 0 2 1 2 2v2c0 1 0 3 2 3"></path><path d="M16 4c2 0 2 2 2 3v2c0 1 1 2 2 2-1 0-2 1-2 2v2c0 1 0 3-2 3"></path></svg>',
  "github": '<svg viewBox="0 0 24 24"><path d="M12 2.5a9.5 9.5 0 0 0-3 18.5c.5.1.7-.2.7-.5v-1.8c-2.9.6-3.5-1.2-3.5-1.2-.5-1.1-1.1-1.4-1.1-1.4-.9-.6.1-.6.1-.6 1 .1 1.5 1 1.5 1 .9 1.5 2.3 1.1 2.9.8.1-.6.3-1.1.6-1.3-2.3-.3-4.7-1.2-4.7-5.1 0-1.1.4-2 1-2.8-.1-.3-.4-1.3.1-2.8 0 0 .8-.3 2.9 1.1a10 10 0 0 1 5.2 0c2-1.4 2.9-1.1 2.9-1.1.5 1.5.2 2.5.1 2.8.6.7 1 1.7 1 2.8 0 4-2.4 4.8-4.7 5.1.4.3.7 1 .7 2v2.9c0 .3.2.6.7.5A9.5 9.5 0 0 0 12 2.5z"></path></svg>',
  "file-type": '<svg viewBox="0 0 24 24"><path d="M14 3v5a1 1 0 0 0 1 1h5"></path><path d="M6 3h8l6 6v12H6z"></path><path d="M9 16h6"></path><path d="M10 13h4"></path></svg>',
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
  "copy": '<svg viewBox="0 0 24 24"><rect x="8" y="8" width="11" height="11" rx="2"></rect><path d="M5 15V7a2 2 0 0 1 2-2h8"></path></svg>',
  "check": '<svg viewBox="0 0 24 24"><path d="m5 12 5 5L20 7"></path></svg>',
  "circle-alert": '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v6"></path><path d="M12 17h.01"></path></svg>',
  "info": '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="M12 11v6"></path><path d="M12 7h.01"></path></svg>',
  "shield": '<svg viewBox="0 0 24 24"><path d="M12 3 19 6v5c0 5-3 8-7 10-4-2-7-5-7-10V6z"></path><path d="M12 8v6"></path><path d="M9 11h6"></path></svg>',
  "bell-plus": '<svg viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4"></path><path d="M19 2v6"></path><path d="M16 5h6"></path></svg>',
  "play": '<svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"></path></svg>',
  "pause": '<svg viewBox="0 0 24 24"><path d="M8 5v14"></path><path d="M16 5v14"></path></svg>',
  "download": '<svg viewBox="0 0 24 24"><path d="M12 3v12"></path><path d="m7 10 5 5 5-5"></path><path d="M5 21h14"></path></svg>',
  "printer": '<svg viewBox="0 0 24 24"><path d="M7 8V4h10v4"></path><path d="M7 17H5a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2h-2"></path><path d="M7 14h10v6H7z"></path><path d="M17 12h.01"></path></svg>',
  "maximize": '<svg viewBox="0 0 24 24"><path d="M8 3H5a2 2 0 0 0-2 2v3"></path><path d="M16 3h3a2 2 0 0 1 2 2v3"></path><path d="M8 21H5a2 2 0 0 1-2-2v-3"></path><path d="M16 21h3a2 2 0 0 0 2-2v-3"></path></svg>',
  "minimize": '<svg viewBox="0 0 24 24"><path d="M8 3v3a2 2 0 0 1-2 2H3"></path><path d="M16 3v3a2 2 0 0 0 2 2h3"></path><path d="M8 21v-3a2 2 0 0 0-2-2H3"></path><path d="M16 21v-3a2 2 0 0 1 2-2h3"></path></svg>',
  "share-2": '<svg viewBox="0 0 24 24"><circle cx="18" cy="5" r="3"></circle><circle cx="6" cy="12" r="3"></circle><circle cx="18" cy="19" r="3"></circle><path d="m8.6 10.8 6.8-4.6"></path><path d="m8.6 13.2 6.8 4.6"></path></svg>',
  "x": '<svg viewBox="0 0 24 24"><path d="M18 6 6 18"></path><path d="m6 6 12 12"></path></svg>',
};

function refreshIcons() {
  for (const icon of document.querySelectorAll("i[data-icon]")) {
    const name = icon.getAttribute("data-icon") || "";
    if (!ICONS[name]) continue;
    icon.innerHTML = ICONS[name];
    icon.classList.add("svg-icon");
  }
}

function updateModalOpenState() {
  const apply = () => {
    const hasOpenDialog = Boolean(document.querySelector("dialog[open]"));
    document.documentElement.classList.toggle("modal-open", hasOpenDialog);
    document.body?.classList.toggle("modal-open", hasOpenDialog);
  };
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
  if (!("vibrate" in navigator)) return;
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

async function checkUpdateStatus() {
  if (!updateCheck) return;
  updateCheck.classList.remove("is-done", "is-error", "is-pending");
  updateCheck.classList.add("is-loading");
  updateCheck.innerHTML = '<span class="spinner" aria-hidden="true"></span><span>Checking for newly indexed publications...</span>';

  try {
    const response = await fetch("status.php", {
      headers: { Accept: "application/json" },
      cache: "no-store",
    });
    if (!response.ok) throw new Error("Status request failed");
    const data = await response.json();
    if (!data.ok) throw new Error(data.message || "Status unavailable");
    if (lastUpdatedText && data.last_updated_display) {
      lastUpdatedText.textContent = data.last_updated_display;
    }
    if (heroUpdatedText && data.last_updated_display) {
      heroUpdatedText.textContent = data.last_updated_display;
    }
    updateCheck.classList.remove("is-loading");
    updateCheck.classList.add(data.pending ? "is-pending" : "is-done");
    updateCheck.innerHTML = `<span class="status-dot" aria-hidden="true"></span><span>${data.message}</span>`;
  } catch (error) {
    updateCheck.classList.remove("is-loading");
    updateCheck.classList.add("is-error");
    updateCheck.innerHTML = '<span class="status-dot" aria-hidden="true"></span><span>Could not check update status. Showing stored publications.</span>';
  }
}

scheduleIdleTask(checkUpdateStatus, 1800);
window.addEventListener("load", refreshIcons);

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
      window.requestAnimationFrame(() => renderTimeline({ preset: "10y" }));
    }
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
      openDialog(dialog, binding);
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

  const initialHash = window.location.hash && document.querySelector(window.location.hash) ? window.location.hash : "#papers";
  setActiveNavLink(initialHash);
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
    document.querySelectorAll(".command-menu[open]").forEach((menu) => menu.removeAttribute("open"));
  });
}

function initNavSidebarCollapse() {
  const toggle = document.querySelector("#nav-sidebar-toggle");
  const content = document.querySelector("#primary-sidebar-content");
  if (!toggle || !content) return;
  const compactQuery = window.matchMedia?.("(max-width: 1180px)");
  const isCompact = () => Boolean(compactQuery?.matches);
  const storageKey = () => isCompact() ? "publicationTrackerNavSidebarCollapsedMobile" : "publicationTrackerNavSidebarCollapsedDesktop";
  const setCollapsed = (collapsed, { persist = true } = {}) => {
    document.documentElement.classList.toggle("nav-sidebar-collapsed", collapsed);
    toggle.setAttribute("aria-expanded", collapsed ? "false" : "true");
    const compact = isCompact();
    const label = compact
      ? (collapsed ? "Open menu" : "Close menu")
      : (collapsed ? "Expand sidebar" : "Collapse sidebar");
    toggle.setAttribute("title", label);
    toggle.querySelector(".sr-only").textContent = label;
    const icon = toggle.querySelector("i[data-icon]");
    if (icon) icon.setAttribute("data-icon", compact ? (collapsed ? "menu" : "x") : (collapsed ? "chevron-right" : "chevron-left"));
    if (persist) localStorage.setItem(storageKey(), collapsed ? "1" : "0");
    refreshIcons();
  };
  collapsePrimaryNavSidebarOnCompact = () => {
    if (isCompact()) setCollapsed(true);
  };
  const applySavedPreference = () => {
    const compact = isCompact();
    const storedPreference = localStorage.getItem(storageKey());
    setCollapsed(storedPreference === null ? compact : storedPreference === "1", { persist: false });
  };
  applySavedPreference();
  compactQuery?.addEventListener?.("change", applySavedPreference);
  if (toggle.dataset.navSidebarBound === "1") return;
  toggle.dataset.navSidebarBound = "1";
  toggle.addEventListener("click", () => {
    setCollapsed(!document.documentElement.classList.contains("nav-sidebar-collapsed"));
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

function initScrollProgress() {
  const progress = document.querySelector("#scroll-progress span");
  if (!progress) return;
  let ticking = false;
  const update = () => {
    const doc = document.documentElement;
    const max = Math.max(1, doc.scrollHeight - doc.clientHeight);
    const ratio = Math.min(1, Math.max(0, window.scrollY / max));
    progress.style.transform = `scaleX(${ratio})`;
    ticking = false;
  };
  const requestUpdate = () => {
    if (ticking) return;
    ticking = true;
    requestAnimationFrame(update);
  };
  update();
  window.addEventListener("scroll", requestUpdate, { passive: true });
  window.addEventListener("resize", requestUpdate);
}

function initScrollTop() {
  const button = document.querySelector("#scroll-top");
  if (!button) return;
  let ticking = false;
  const sync = () => {
    button.classList.toggle("is-visible", window.scrollY > 640);
    ticking = false;
  };
  const requestSync = () => {
    if (ticking) return;
    ticking = true;
    requestAnimationFrame(sync);
  };
  button.addEventListener("click", () => {
    window.scrollTo({
      top: 0,
      behavior: window.matchMedia("(prefers-reduced-motion: reduce)").matches ? "auto" : "smooth",
    });
  });
  sync();
  window.addEventListener("scroll", requestSync, { passive: true });
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
  const formatter = new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 });
  counters.forEach((counter) => {
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
  const results = document.querySelector("#matching-results-body") || document.querySelector("#papers");
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

async function loadSearchUrl(url, { push = true } = {}) {
  const target = new URL(url, window.location.href);
  target.hash = "";
  closeOpenDialogsForDomSwap();
  document.documentElement.classList.add("is-searching");
  try {
    const response = await fetch(target.href, {
      headers: { Accept: "text/html" },
      cache: "no-store",
    });
    if (!response.ok) throw new Error("Search request failed");
    const html = await response.text();
    const nextDocument = new DOMParser().parseFromString(html, "text/html");
    closeOpenDialogsForDomSwap();
    replaceSection(".filters", nextDocument);
    replaceSection(".results", nextDocument);
    if (push) {
      window.history.pushState({ publicationTrackerSearch: true }, "", `${target.pathname}${target.search}#papers`);
    }
    syncSearchInputsFromLocation();
    refreshIcons();
    bindCitationButtons(document.querySelector("#papers") || document);
    initAppSheets();
    initSidebarControls();
    syncSidebarResultActions();
    focusResultsPanel();
    showAppToast("Search results updated.");
  } catch (error) {
    window.location.href = `${target.pathname}${target.search}#papers`;
  } finally {
    document.documentElement.classList.remove("is-searching");
  }
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
    if (!form.matches(".hero-search, .filters")) return;
    event.preventDefault();
    loadSearchUrl(urlFromForm(form));
  });

  document.addEventListener("click", (event) => {
    const link = event.target.closest(".pager a:not(.disabled), .search-context-reset, .rank-filter-link");
    if (!(link instanceof HTMLAnchorElement)) return;
    const url = new URL(link.href, window.location.href);
    if (url.origin !== window.location.origin || url.pathname !== window.location.pathname) return;
    event.preventDefault();
    loadSearchUrl(url);
  });

  window.addEventListener("popstate", () => {
    loadSearchUrl(window.location.href, { push: false });
  });
}

function initSearchResultFocus() {
  const results = document.querySelector("#papers");
  if (!results) return;
  const params = new URLSearchParams(window.location.search);
  const hasQuery = ["search", "q", "author", "journal", "topic", "study_type", "year", "from", "to"].some((key) => (params.get(key) || "").trim() !== "");
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
  const hasPrintableResults = document.querySelectorAll("#matching-results-body .paper").length > 0;
  printButton.disabled = !hasPrintableResults;
  printButton.setAttribute("aria-disabled", hasPrintableResults ? "false" : "true");
  printButton.setAttribute("title", hasPrintableResults ? "Print visible results" : "No visible results to print");
}

function hasShareableResultFilters(params = new URLSearchParams(window.location.search)) {
  const meaningfulKeys = [
    "search", "q", "author", "journal", "topic", "study_type", "year", "from", "to",
    "substances[]", "sources[]", "publication_statuses[]",
  ];
  if (meaningfulKeys.some((key) => (params.get(key) || "").trim() !== "" || params.getAll(key).some((value) => value.trim() !== ""))) {
    return true;
  }
  const range = (params.get("range") || "").trim();
  return range !== "" && range !== "all";
}

function canonicalShareBaseUrl() {
  const canonical = document.querySelector("link[rel='canonical']")?.href || "https://psilocybin-research.com/";
  return new URL(canonical, window.location.href);
}

function currentResultsShareUrl() {
  const params = new URLSearchParams(window.location.search);
  const url = canonicalShareBaseUrl();
  if (hasShareableResultFilters(params)) {
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

function initNativeShare() {
  const button = document.querySelector("#sidebar-share-results");
  if (!button) return;
  if (!navigator.share) {
    button.hidden = true;
    return;
  }
  button.hidden = false;
  button.setAttribute("title", "Share current results");
  button.addEventListener("click", async () => {
    const payload = {
      title: "Psilocybin Research Publication Tracker",
      text: shareTextForCurrentResults(),
      url: currentResultsShareUrl(),
    };
    try {
      await navigator.share(payload);
      showAppToast("Share sheet opened.");
    } catch (error) {
      if (error && error.name === "AbortError") return;
      showAppToast("Sharing is not available right now.");
    }
  });
}

function initFullscreenToggle() {
  const button = document.querySelector("#sidebar-fullscreen-toggle");
  if (!button) return;
  const fullscreenEnabled = document.fullscreenEnabled || document.webkitFullscreenEnabled;
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

const analyticsDataElement = document.querySelector("#analytics-data");
const timelineChart = document.querySelector("#publication-timeline");
const timelineSummary = document.querySelector("#timeline-summary");
const timelineInsight = document.querySelector("#timeline-insight");
const timelineFrom = document.querySelector("#timeline-from");
const timelineTo = document.querySelector("#timeline-to");
const timelineApply = document.querySelector("#timeline-apply");
const timelineReset = document.querySelector("#timeline-reset");
const timelineOpenJson = document.querySelector("#timeline-open-json");
const timelineOpenHtml = document.querySelector("#timeline-open-html");
const analyticsLens = document.querySelector("#analytics-lens");
const analyticsLensSummary = document.querySelector("#analytics-lens-summary");
const analyticsApply = document.querySelector("#analytics-apply");
const analyticsClear = document.querySelector("#analytics-clear");
const analyticsOpenPapers = document.querySelector("#analytics-open-papers");
const analyticsExportLatex = document.querySelector("#analytics-export-latex");
const analyticsResultsList = document.querySelector("#analytics-results-list");
const analyticsResultsCount = document.querySelector("#analytics-results-count");
const analyticsLensInputs = {
  keyword: document.querySelector("#analytics-keyword"),
  author: document.querySelector("#analytics-author"),
  journal: document.querySelector("#analytics-journal"),
  identifier: document.querySelector("#analytics-identifier"),
  source: document.querySelector("#analytics-source"),
  status: document.querySelector("#analytics-status"),
  topic: document.querySelector("#analytics-topic"),
  studyType: document.querySelector("#analytics-study-type"),
  substance: document.querySelector("#analytics-substance"),
};
const DAY_MS = 86400000;
let currentTimelineView = null;
let currentAnalyticsFilters = {};

const publicationGrowthChart = document.querySelector("#publication-growth-chart");
const publicationGrowthDataElement = document.querySelector("#publication-growth-data");

function analyticsData() {
  if (!analyticsDataElement) return {};
  try {
    return JSON.parse(analyticsDataElement.textContent || "{}");
  } catch (error) {
    return {};
  }
}

function parseIsoDate(value) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(String(value || ""))) return null;
  const date = new Date(`${value}T00:00:00Z`);
  return Number.isNaN(date.getTime()) ? null : date;
}

function isoDate(date) {
  return date.toISOString().slice(0, 10);
}

function addDays(date, days) {
  const next = new Date(date);
  next.setUTCDate(next.getUTCDate() + days);
  return next;
}

function addMonths(date, months) {
  const next = new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth(), 1));
  next.setUTCMonth(next.getUTCMonth() + months);
  return next;
}

function addYears(date, years) {
  return new Date(Date.UTC(date.getUTCFullYear() + years, 0, 1));
}

function monthKey(date) {
  return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, "0")}`;
}

function timelineBounds(rows) {
  const dates = rows.map((row) => parseIsoDate(row.date)).filter(Boolean);
  if (!dates.length) return null;
  return {
    min: new Date(Math.min(...dates.map((date) => date.getTime()))),
    max: new Date(Math.max(...dates.map((date) => date.getTime()))),
  };
}

function analyticsPapers() {
  const data = analyticsData();
  const rows = Array.isArray(data.timeline_papers) ? data.timeline_papers : [];
  return rows.map((paper) => ({
    id: Number(paper.id || 0),
    title: String(paper.title || "Untitled publication"),
    authors: String(paper.authors || ""),
    journal: String(paper.journal || ""),
    publication_date: String(paper.publication_date || ""),
    doi: String(paper.doi || ""),
    pubmed_id: String(paper.pubmed_id || ""),
    source_url: String(paper.source_url || ""),
    source_name: String(paper.source_name || ""),
    publication_status: String(paper.publication_status || "published"),
    keywords: String(paper.keywords || ""),
    substance_tags: String(paper.substance_tags || ""),
    topic_tags: String(paper.topic_tags || ""),
    study_type: String(paper.study_type || ""),
  })).filter((paper) => parseIsoDate(paper.publication_date));
}

function normalizedNeedle(value) {
  return String(value || "").trim().toLowerCase();
}

function getAnalyticsLensFilters() {
  return Object.fromEntries(Object.entries(analyticsLensInputs).map(([key, input]) => [key, normalizedNeedle(input?.value)]));
}

function activeAnalyticsFilterEntries(filters = currentAnalyticsFilters) {
  return Object.entries(filters).filter(([, value]) => value !== "");
}

function analyticsLensSearchText(paper) {
  return [
    paper.title, paper.authors, paper.journal, paper.doi, paper.pubmed_id, paper.source_name,
    paper.publication_status, paper.keywords, paper.substance_tags, paper.topic_tags, paper.study_type,
  ].join(" ").toLowerCase();
}

function analyticsPaperMatches(paper, filters = currentAnalyticsFilters) {
  if (filters.keyword && !analyticsLensSearchText(paper).includes(filters.keyword)) return false;
  if (filters.author && !paper.authors.toLowerCase().includes(filters.author)) return false;
  if (filters.journal && !paper.journal.toLowerCase().includes(filters.journal)) return false;
  if (filters.identifier && !`${paper.doi} ${paper.pubmed_id}`.toLowerCase().includes(filters.identifier)) return false;
  if (filters.source && !paper.source_name.toLowerCase().includes(filters.source)) return false;
  if (filters.status && paper.publication_status.toLowerCase() !== filters.status) return false;
  if (filters.topic && !paper.topic_tags.toLowerCase().includes(filters.topic)) return false;
  if (filters.studyType && !paper.study_type.toLowerCase().includes(filters.studyType)) return false;
  if (filters.substance && !paper.substance_tags.toLowerCase().includes(filters.substance)) return false;
  return true;
}

function filteredAnalyticsPapers(range = null) {
  return analyticsPapers().filter((paper) => {
    if (!analyticsPaperMatches(paper)) return false;
    if (!range) return true;
    return paper.publication_date >= range.from && paper.publication_date <= range.to;
  });
}

function timelineRowsFromPapers(papers) {
  const counts = new Map();
  papers.forEach((paper) => {
    counts.set(paper.publication_date, (counts.get(paper.publication_date) || 0) + 1);
  });
  return Array.from(counts.entries())
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([date, count]) => ({ date, count }));
}

function analyticsUrlFromFilters(format = null) {
  const url = new URL(format ? "export.php" : "./", window.location.href);
  const filters = currentAnalyticsFilters;
  const set = (key, value) => { if (value) url.searchParams.set(key, value); };
  set("search", filters.keyword || filters.identifier || "");
  set("author", filters.author || "");
  set("journal", filters.journal || "");
  set("topic", filters.topic || "");
  set("study_type", filters.studyType || "");
  if (filters.substance) url.searchParams.append("substances[]", filters.substance);
  if (filters.source) url.searchParams.append("sources[]", filters.source);
  if (filters.status) url.searchParams.append("publication_statuses[]", filters.status);
  if (currentTimelineView) {
    url.searchParams.set("range", "custom");
    url.searchParams.set("from", isoDate(currentTimelineView.from));
    url.searchParams.set("to", isoDate(currentTimelineView.to));
  }
  url.searchParams.set("page", "1");
  if (format) {
    url.searchParams.set("format", format);
  } else {
    url.hash = "papers";
  }
  return url.toString();
}

function presetRange(preset, bounds) {
  if (!bounds) return null;
  if (preset === "all") return { from: bounds.min, to: bounds.max };
  const years = Number(String(preset).replace("y", ""));
  if (!Number.isFinite(years)) return { from: bounds.min, to: bounds.max };
  return {
    from: new Date(Date.UTC(bounds.max.getUTCFullYear() - years + 1, bounds.max.getUTCMonth(), bounds.max.getUTCDate())),
    to: bounds.max,
  };
}

function aggregateTimeline(rows, from, to, preferredMode = null) {
  const spanDays = Math.max(1, Math.round((to.getTime() - from.getTime()) / DAY_MS) + 1);
  const autoMode = spanDays <= 120 ? "day" : spanDays <= 1460 ? "month" : "year";
  const mode = ["day", "month", "year"].includes(preferredMode) ? preferredMode : autoMode;
  const counts = new Map();
  rows.forEach((row) => {
    const date = parseIsoDate(row.date);
    if (!date || date < from || date > to) return;
    const key = mode === "day" ? isoDate(date) : mode === "month" ? monthKey(date) : String(date.getUTCFullYear());
    counts.set(key, (counts.get(key) || 0) + Number(row.count || 0));
  });

  const buckets = [];
  if (mode === "day") {
    for (let cursor = new Date(from); cursor <= to; cursor = addDays(cursor, 1)) {
      const key = isoDate(cursor);
      buckets.push({ label: key.slice(5), fullLabel: key, range: { from: key, to: key }, count: counts.get(key) || 0 });
    }
  } else if (mode === "month") {
    const start = new Date(Date.UTC(from.getUTCFullYear(), from.getUTCMonth(), 1));
    const end = new Date(Date.UTC(to.getUTCFullYear(), to.getUTCMonth(), 1));
    for (let cursor = start; cursor <= end; cursor = addMonths(cursor, 1)) {
      const key = monthKey(cursor);
      const monthStart = isoDate(cursor) < isoDate(from) ? isoDate(from) : isoDate(cursor);
      const monthEndDate = addDays(addMonths(cursor, 1), -1);
      const monthEnd = isoDate(monthEndDate) > isoDate(to) ? isoDate(to) : isoDate(monthEndDate);
      buckets.push({ label: key, fullLabel: key, range: { from: monthStart, to: monthEnd }, count: counts.get(key) || 0 });
    }
  } else {
    const start = new Date(Date.UTC(from.getUTCFullYear(), 0, 1));
    const end = new Date(Date.UTC(to.getUTCFullYear(), 0, 1));
    for (let cursor = start; cursor <= end; cursor = addYears(cursor, 1)) {
      const key = String(cursor.getUTCFullYear());
      const yearStart = `${key}-01-01` < isoDate(from) ? isoDate(from) : `${key}-01-01`;
      const yearEnd = `${key}-12-31` > isoDate(to) ? isoDate(to) : `${key}-12-31`;
      buckets.push({ label: key, fullLabel: key, range: { from: yearStart, to: yearEnd }, count: counts.get(key) || 0 });
    }
  }
  return { buckets, mode, total: buckets.reduce((sum, row) => sum + row.count, 0), spanDays };
}

function publicationGrowthData() {
  if (!publicationGrowthDataElement) return null;
  try {
    return JSON.parse(publicationGrowthDataElement.textContent || "{}");
  } catch (error) {
    return null;
  }
}

function renderPublicationGrowthChart() {
  if (!publicationGrowthChart) return;
  const data = publicationGrowthData();
  const rows = Array.isArray(data?.rows) ? data.rows.map((row) => ({
    year: Number(row.year),
    count: Number(row.count || 0),
  })).filter((row) => Number.isFinite(row.year)) : [];
  if (!rows.length) {
    publicationGrowthChart.innerHTML = '<p class="publication-growth-empty">Timeline data will appear after dated records are indexed.</p>';
    return;
  }

  const width = Math.max(320, Math.round(publicationGrowthChart.clientWidth || 360));
  const height = 126;
  const pad = { top: 13, right: 26, bottom: 25, left: 26 };
  const innerW = width - pad.left - pad.right;
  const innerH = 78;
  const total = rows.reduce((sum, row) => sum + row.count, 0);
  const max = Math.max(...rows.map((row) => row.count), 1);
  const slot = innerW / rows.length;
  const barW = Math.max(5, Math.min(18, slot * 0.52));
  let cumulative = 0;
  const bars = rows.map((row, index) => {
    const x = pad.left + index * slot + (slot - barW) / 2;
    const h = row.count > 0 ? Math.max(2, (row.count / max) * innerH) : 0;
    const y = pad.top + innerH - h;
    cumulative += row.count;
    const lineX = pad.left + index * slot + slot / 2;
    const lineY = pad.top + (1 - cumulative / Math.max(1, total)) * innerH;
    return { ...row, x, y, width: barW, height: h, lineX, lineY, cumulative };
  });
  const first = rows[0];
  const last = rows[rows.length - 1];
  const line = bars.map((bar, index) => `${index ? "L" : "M"}${bar.lineX.toFixed(1)} ${bar.lineY.toFixed(1)}`).join(" ");
  publicationGrowthChart.innerHTML = `
    <svg viewBox="0 0 ${width} ${height}" aria-label="Annual publication growth since ${first.year}">
      <line class="chart-grid publication-growth-grid" x1="${pad.left}" y1="${pad.top + innerH / 2}" x2="${width - pad.right}" y2="${pad.top + innerH / 2}"></line>
      <line class="chart-axis publication-growth-axis" x1="${pad.left}" y1="${pad.top + innerH}" x2="${width - pad.right}" y2="${pad.top + innerH}"></line>
      ${bars.map((bar) => `<rect class="chart-bar publication-growth-bar publication-growth-year" tabindex="0" role="link" data-growth-year="${bar.year}" aria-label="Filter publications from ${bar.year}" x="${bar.x.toFixed(1)}" y="${bar.y.toFixed(1)}" width="${bar.width.toFixed(1)}" height="${bar.height.toFixed(1)}" rx="2"><title>${bar.year}: ${bar.count.toLocaleString()} publications. Opens matching records.</title></rect>`).join("")}
      <path class="chart-line publication-growth-line" d="${line}"></path>
      ${bars.map((bar) => `<circle class="chart-point publication-growth-point publication-growth-year" tabindex="0" role="link" data-growth-year="${bar.year}" aria-label="Filter publications from ${bar.year}" cx="${bar.lineX.toFixed(1)}" cy="${bar.lineY.toFixed(1)}" r="3"><title>${bar.year}: ${bar.count.toLocaleString()} publications. Opens matching records.</title></circle>`).join("")}
      <text class="chart-x" x="${pad.left}" y="${height - 8}">${first.year}</text>
      <text class="chart-x" x="${width - pad.right}" y="${height - 8}" text-anchor="end">${last.year}</text>
    </svg>`;
}

function openPublicationGrowthYear(year) {
  const normalizedYear = Number(year);
  if (!Number.isInteger(normalizedYear) || normalizedYear < 1900) return;
  const url = new URL(window.location.href);
  url.searchParams.set("year", String(normalizedYear));
  url.searchParams.set("page", "1");
  url.searchParams.delete("range");
  url.searchParams.delete("from");
  url.searchParams.delete("to");
  url.hash = "papers";
  window.location.assign(url.toString());
}

function bucketRange(bucket, mode, clip = null) {
  const label = String(bucket?.fullLabel || "");
  let range;
  if (mode === "day") {
    range = { from: label, to: label };
  } else if (mode === "month") {
    const [year, month] = label.split("-").map(Number);
    const start = new Date(Date.UTC(year, month - 1, 1));
    const end = new Date(Date.UTC(year, month, 0));
    range = { from: isoDate(start), to: isoDate(end) };
  } else {
    const year = Number(label);
    range = { from: `${year}-01-01`, to: `${year}-12-31` };
  }
  if (!clip) return range;
  return {
    from: range.from < clip.from ? clip.from : range.from,
    to: range.to > clip.to ? clip.to : range.to,
  };
}

function timelinePayload(bucket = null) {
  if (!currentTimelineView) return null;
  const { from, to, aggregate } = currentTimelineView;
  const visibleRange = { from: isoDate(from), to: isoDate(to) };
  const paperRange = bucket ? bucketRange(bucket, aggregate.mode, visibleRange) : visibleRange;
  const papers = timelinePapersForRange(paperRange);
  const base = {
    generated_at: new Date().toISOString(),
    source: "Psilocybin Research Publication Tracker",
    range: { from: isoDate(from), to: isoDate(to) },
    granularity: aggregate.mode,
    total_publications: aggregate.total,
    papers,
  };
  if (bucket) {
    return {
      ...base,
      bucket: {
        label: bucket.fullLabel,
        range: paperRange,
        count: bucket.count,
      },
    };
  }
  return {
    ...base,
    buckets: aggregate.buckets.map((item) => ({
      label: item.fullLabel,
      range: bucketRange(item, aggregate.mode, visibleRange),
      count: item.count,
    })),
  };
}

function timelinePapersForRange(range) {
  return filteredAnalyticsPapers(range);
}

function openBlobWindow(content, type) {
  const blob = new Blob([content], { type });
  const url = URL.createObjectURL(blob);
  const opened = window.open(url, "_blank", "noopener");
  setTimeout(() => URL.revokeObjectURL(url), opened ? 60000 : 1000);
}

function externalPaperUrl(paper) {
  if (paper.doi) return `https://doi.org/${encodeURIComponent(paper.doi).replaceAll("%2F", "/")}`;
  if (paper.pubmed_id) return `https://pubmed.ncbi.nlm.nih.gov/${encodeURIComponent(paper.pubmed_id)}/`;
  return paper.source_url || "";
}

function timelineHtml(payload) {
  const isBucket = !!payload.bucket;
  const rows = isBucket ? [payload.bucket] : payload.buckets;
  const title = isBucket ? `Publication timeline: ${payload.bucket.label}` : "Publication timeline data";
  const papers = Array.isArray(payload.papers) ? payload.papers : [];
  const printedDateRange = printDateRangeLabel(papers) === "No publication dates available"
    ? `${isBucket ? payload.bucket.range.from : payload.range.from} to ${isBucket ? payload.bucket.range.to : payload.range.to}`
    : printDateRangeLabel(papers);
  const printedSources = printSourcesLabel(papers);
  const generatedDisplay = new Date(payload.generated_at).toLocaleString(undefined, { dateStyle: "medium", timeStyle: "short" });
  const printConfirmMessage = `Print ${papers.length} ${papers.length === 1 ? "record" : "records"}?\n\nDate range: ${printedDateRange}`;
  return `<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>${escapeHtml(title)}</title>
  <style>
    body{margin:0;background:#ffffff;color:#24251f;font-family:Arial,Helvetica,sans-serif}
    main{max-width:920px;margin:32px auto;padding:0 18px}
    section{background:#fff;border:1px solid #d8d2c4;border-radius:8px;overflow:hidden}
    header{padding:18px 22px;background:#ffffff;color:#24251f;border-bottom:1px solid #d8d2c4;border-left:4px solid #123c31}
    h1{margin:0;font-size:24px;line-height:1.2}
    h2{margin:22px 22px 8px;font-size:17px;color:#24251f}
    p{margin:8px 0 0;color:#686c61;font-size:13px}
    .timeline-actions{margin-top:14px}
    .timeline-actions button{appearance:none;border:1px solid #123c31;border-radius:6px;background:#123c31;color:#fff;font-weight:800;padding:8px 12px;cursor:pointer}
    .timeline-actions button:hover{background:#0d2f26}
    table{width:100%;border-collapse:collapse}
    th,td{padding:11px 14px;border-bottom:1px solid #d8d2c4;text-align:left;font-size:13px;vertical-align:top}
    th{background:#f7f6f1;color:#686c61;font-size:11px;text-transform:uppercase}
    .summary-table td:last-child,.summary-table th:last-child{text-align:right;font-weight:800}
    .papers-table{border-top:1px solid #d8d2c4}
    .paper-title{font-weight:800;color:#24251f;text-decoration:none}
    .paper-title:hover{text-decoration:underline}
    .paper-meta{margin-top:4px;color:#686c61;font-size:12px;line-height:1.45}
    .paper-links{white-space:nowrap}
    .paper-links a{display:inline-block;margin-right:10px;color:#1a6b54;font-weight:800;text-decoration:none}
    .paper-links a:hover{text-decoration:underline}
    .badge{display:inline-block;margin-top:5px;padding:3px 7px;border:1px solid #d8d2c4;border-radius:999px;background:#f7f6f1;color:#3d4238;font-size:11px;font-weight:800;text-transform:uppercase}
    .meta{padding:14px 22px;color:#686c61;font-size:12px}
    .print-only{display:none}
    @media screen and (max-width: 700px){
      body{background:#f7f6f1}
      main{max-width:none;margin:0;padding:10px}
      section{border-radius:8px}
      header{padding:16px;border-left:0;border-top:4px solid #123c31}
      h1{font-size:21px;line-height:1.16;overflow-wrap:anywhere}
      h2{margin:18px 14px 8px;font-size:16px}
      p{font-size:12px;line-height:1.45}
      .timeline-actions{display:flex;margin-top:12px}
      .timeline-actions button{width:100%;min-height:42px}
      .summary-table,.summary-table thead,.summary-table tbody,.summary-table tr,.summary-table th,.summary-table td{display:block;width:100%}
      .summary-table thead{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
      .summary-table tr{padding:10px 14px;border-bottom:1px solid #d8d2c4}
      .summary-table td{display:flex;justify-content:space-between;gap:12px;padding:4px 0;border:0;white-space:normal}
      .summary-table td:nth-child(1)::before{content:"Bucket";font-weight:800;color:#686c61;text-transform:uppercase;font-size:11px}
      .summary-table td:nth-child(2)::before{content:"From";font-weight:800;color:#686c61;text-transform:uppercase;font-size:11px}
      .summary-table td:nth-child(3)::before{content:"To";font-weight:800;color:#686c61;text-transform:uppercase;font-size:11px}
      .summary-table td:nth-child(4)::before{content:"Publications";font-weight:800;color:#686c61;text-transform:uppercase;font-size:11px}
      .papers-table,.papers-table thead,.papers-table tbody,.papers-table tr,.papers-table th,.papers-table td{display:block;width:100%}
      .papers-table{border-top:0}
      .papers-table thead{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
      .papers-table tr{padding:12px 14px;border-top:1px solid #d8d2c4;background:#fff}
      .papers-table td{padding:0;border:0;font-size:13px}
      .papers-table td + td{margin-top:8px}
      .papers-table td:nth-child(2)::before{content:"Date: ";font-weight:800;color:#686c61}
      .papers-table td:nth-child(3)::before{content:"Source: ";font-weight:800;color:#686c61}
      .paper-meta{font-size:12px;line-height:1.5}
      .paper-links{display:flex;flex-wrap:wrap;gap:8px;white-space:normal}
      .paper-links a{margin:0;padding:6px 8px;border:1px solid #d8d2c4;border-radius:6px;background:#f7f6f1}
      .meta{padding:12px 14px;overflow-wrap:anywhere}
    }
    @media print{
      @page{margin:16mm 14mm}
      body{background:#fff;color:#111;font-family:Georgia,"Times New Roman",serif;font-size:11pt;line-height:1.42}
      main{max-width:none;margin:0;padding:0}
      .screen-section{display:none}
      .print-only{display:block;background:#fff;border:0;border-radius:0;overflow:visible}
      .print-header{border-bottom:1px solid #111;margin-bottom:14px;padding-bottom:8px;font-family:Arial,Helvetica,sans-serif;font-size:9pt;color:#222}
      .print-header dl{display:grid;grid-template-columns:30mm 1fr;gap:3px 8px;margin:0}
      .print-header dt{font-weight:700}
      .print-header dd{margin:0}
      .print-references{margin:0;padding-left:20px}
      .print-references li{break-inside:avoid;margin:0 0 8px;padding-left:3px}
      .print-footer{border-top:1px solid #bbb;margin-top:14px;padding-top:7px;font-family:Arial,Helvetica,sans-serif;font-size:9pt;color:#333}
      .print-footer a{color:#111;text-decoration:none}
    }
  </style>
</head>
<body>
  <main>
    <section class="screen-section">
      <header>
        <h1>${escapeHtml(title)}</h1>
        <p>${escapeHtml(payload.range.from)} to ${escapeHtml(payload.range.to)} · ${payload.total_publications} publications · ${escapeHtml(payload.granularity)}</p>
        <div class="timeline-actions"><button id="timeline-print-references" type="button">Print references</button></div>
      </header>
      <table class="summary-table">
        <thead><tr><th>Bucket</th><th>From</th><th>To</th><th>Publications</th></tr></thead>
        <tbody>
          ${rows.map((row) => `<tr><td>${escapeHtml(row.label)}</td><td>${escapeHtml(row.range.from)}</td><td>${escapeHtml(row.range.to)}</td><td>${row.count}</td></tr>`).join("")}
        </tbody>
      </table>
      <h2>Matching publications (${papers.length})</h2>
      <table class="papers-table">
        <thead><tr><th>Publication</th><th>Date</th><th>Source</th><th>Links</th></tr></thead>
        <tbody>
          ${papers.map((paper) => {
            const url = externalPaperUrl(paper);
            const titleCell = url
              ? `<a class="paper-title" href="${escapeHtml(url)}" target="_blank" rel="noopener">${escapeHtml(paper.title)}</a>`
              : `<span class="paper-title">${escapeHtml(paper.title)}</span>`;
            const doi = paper.doi ? `<a href="https://doi.org/${escapeHtml(paper.doi)}" target="_blank" rel="noopener">DOI</a>` : "";
            const pmid = paper.pubmed_id ? `<a href="https://pubmed.ncbi.nlm.nih.gov/${escapeHtml(paper.pubmed_id)}/" target="_blank" rel="noopener">PubMed</a>` : "";
            const source = paper.source_url ? `<a href="${escapeHtml(paper.source_url)}" target="_blank" rel="noopener">Source</a>` : "";
            return `<tr>
              <td>${titleCell}<div class="paper-meta">${escapeHtml(paper.authors || "Authors not indexed")}${paper.journal ? ` · ${escapeHtml(paper.journal)}` : ""}</div></td>
              <td>${escapeHtml(paper.publication_date)}</td>
              <td>${escapeHtml(paper.source_name || "Unknown")}<br><span class="badge">${escapeHtml(paper.publication_status || "published")}</span></td>
              <td class="paper-links">${doi}${pmid}${source}</td>
            </tr>`;
          }).join("") || '<tr><td colspan="4">No publication records are available for this bucket.</td></tr>'}
        </tbody>
      </table>
      <div class="meta">Generated ${escapeHtml(payload.generated_at)} from ${escapeHtml(payload.source)}.</div>
    </section>
    <section class="print-only" aria-label="Scientific references">
      <div class="print-header">
        <dl>
          <dt>Generated</dt><dd>${escapeHtml(generatedDisplay)}</dd>
          <dt>Entries</dt><dd>${escapeHtml(String(papers.length))}</dd>
          <dt>Date range</dt><dd>${escapeHtml(printedDateRange)}</dd>
          <dt>Sources</dt><dd>${escapeHtml(printedSources)}</dd>
        </dl>
      </div>
      <ol class="print-references">
        ${papers.map((paper) => `<li>${escapeHtml(printReferenceText(paper))}</li>`).join("") || "<li>No publication records are available for this timeline selection.</li>"}
      </ol>
      <div class="print-footer"><a href="https://psilocybin-research.com/">psilocybin-research.com</a></div>
    </section>
  </main>
  <script>
    document.querySelector("#timeline-print-references")?.addEventListener("click", () => {
      if (window.confirm(${JSON.stringify(printConfirmMessage)})) window.print();
    });
  </script>
</body>
</html>`;
}

function openTimelineData(format, bucket = null) {
  const payload = timelinePayload(bucket);
  if (!payload) return;
  if (format === "json") {
    openBlobWindow(JSON.stringify(payload, null, 2), "application/json;charset=utf-8");
    return;
  }
  openBlobWindow(timelineHtml(payload), "text/html;charset=utf-8");
}

function setTimelineSummary(from, to, aggregate) {
  if (!timelineSummary) return;
  const modeLabel = aggregate.mode === "day" ? "daily" : aggregate.mode === "month" ? "monthly" : "yearly";
  timelineSummary.textContent = `${isoDate(from)} to ${isoDate(to)} · ${aggregate.total} publications · ${modeLabel}`;
}

function setTimelineInsight(bucket = null) {
  if (!timelineInsight) return;
  if (!bucket) {
    timelineInsight.textContent = "Hover or focus a bar to inspect the publication bucket. Activate a bar to open the matching publications.";
    return;
  }
  timelineInsight.textContent = `${bucket.fullLabel}: ${Number(bucket.count || 0).toLocaleString()} publications · ${bucket.range.from} to ${bucket.range.to}`;
}

function compactListLabel(values, fallback = "Not indexed") {
  const parts = String(values || "").split(",").map((item) => item.trim()).filter(Boolean);
  return parts.length ? parts.slice(0, 5).join(", ") : fallback;
}

function analyticsMatchReasons(paper) {
  const filters = currentAnalyticsFilters;
  const reasons = [];
  if (filters.keyword && analyticsLensSearchText(paper).includes(filters.keyword)) reasons.push(`keyword "${filters.keyword}"`);
  if (filters.author && paper.authors.toLowerCase().includes(filters.author)) reasons.push(`author "${filters.author}"`);
  if (filters.journal && paper.journal.toLowerCase().includes(filters.journal)) reasons.push(`journal "${filters.journal}"`);
  if (filters.identifier && `${paper.doi} ${paper.pubmed_id}`.toLowerCase().includes(filters.identifier)) reasons.push(`identifier "${filters.identifier}"`);
  if (filters.source && paper.source_name.toLowerCase().includes(filters.source)) reasons.push(`source "${filters.source}"`);
  if (filters.status && paper.publication_status.toLowerCase() === filters.status) reasons.push(`status "${filters.status}"`);
  if (filters.topic && paper.topic_tags.toLowerCase().includes(filters.topic)) reasons.push(`topic "${filters.topic}"`);
  if (filters.studyType && paper.study_type.toLowerCase().includes(filters.studyType)) reasons.push(`study type "${filters.studyType}"`);
  if (filters.substance && paper.substance_tags.toLowerCase().includes(filters.substance)) reasons.push(`substance "${filters.substance}"`);
  return reasons.length ? reasons : ["current timeline range"];
}

function analyticsFacetSummary(papers) {
  const topFor = (key, fallback) => {
    const counts = new Map();
    papers.forEach((paper) => {
      const raw = key === "topic_tags" || key === "substance_tags" ? String(paper[key] || "").split(",") : [paper[key]];
      raw.map((item) => String(item || "").trim()).filter(Boolean).forEach((item) => counts.set(item, (counts.get(item) || 0) + 1));
    });
    const top = Array.from(counts.entries()).sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0])).slice(0, 3);
    return top.length ? top.map(([name, count]) => `${name} (${count})`).join(", ") : fallback;
  };
  const dates = papers.map((paper) => paper.publication_date).filter(Boolean).sort();
  return {
    dateRange: dates.length ? `${dates[0]} to ${dates[dates.length - 1]}` : "No dated publications",
    journals: topFor("journal", "No journal facet"),
    sources: topFor("source_name", "No source facet"),
    statuses: topFor("publication_status", "No status facet"),
    topics: topFor("topic_tags", "No topic facet"),
  };
}

function renderAnalyticsLensResults() {
  if (!analyticsResultsList || !analyticsResultsCount) return;
  const range = currentTimelineView ? { from: isoDate(currentTimelineView.from), to: isoDate(currentTimelineView.to) } : null;
  const papers = filteredAnalyticsPapers(range);
  const activeEntries = activeAnalyticsFilterEntries();
  const rangeLabel = range ? `${range.from} to ${range.to}` : "all dates";
  analyticsResultsCount.textContent = `${papers.length.toLocaleString()} matching ${papers.length === 1 ? "record" : "records"} · ${rangeLabel}`;
  if (analyticsLensSummary) {
    analyticsLensSummary.textContent = activeEntries.length
      ? `${papers.length.toLocaleString()} records match ${activeEntries.map(([key]) => key.replace(/([A-Z])/g, " $1").toLowerCase()).join(", ")}.`
      : "Search within analytics by keyword, author, journal, source, status, topic, or DOI.";
  }
  if (analyticsOpenPapers) analyticsOpenPapers.href = analyticsUrlFromFilters(null);
  if (analyticsExportLatex) analyticsExportLatex.href = analyticsUrlFromFilters("latex");
  if (!papers.length) {
    analyticsResultsList.innerHTML = '<p class="empty-chart">No publications match this research lens and date range.</p>';
    return;
  }
  const summary = analyticsFacetSummary(papers);
  const insightHtml = `<div class="analytics-query-insights" aria-label="Lens result summary">
    <div><span>Date span</span><strong>${escapeHtml(summary.dateRange)}</strong></div>
    <div><span>Top journals</span><strong>${escapeHtml(summary.journals)}</strong></div>
    <div><span>Sources</span><strong>${escapeHtml(summary.sources)}</strong></div>
    <div><span>Status mix</span><strong>${escapeHtml(summary.statuses)}</strong></div>
  </div>`;
  analyticsResultsList.innerHTML = insightHtml + papers.slice(0, 8).map((paper) => {
    const url = externalPaperUrl(paper);
    const title = url
      ? `<a href="${escapeHtml(url)}" target="_blank" rel="noopener">${escapeHtml(paper.title)}</a>`
      : `<span>${escapeHtml(paper.title)}</span>`;
    const tags = [paper.publication_status, paper.source_name, paper.study_type, paper.topic_tags].filter(Boolean).slice(0, 4);
    const reasons = analyticsMatchReasons(paper);
    const doi = paper.doi ? `<a href="https://doi.org/${escapeHtml(paper.doi)}" target="_blank" rel="noopener">${escapeHtml(paper.doi)}</a>` : "Not indexed";
    const pmid = paper.pubmed_id ? `<a href="https://pubmed.ncbi.nlm.nih.gov/${escapeHtml(paper.pubmed_id)}/" target="_blank" rel="noopener">${escapeHtml(paper.pubmed_id)}</a>` : "Not indexed";
    const source = paper.source_url ? `<a href="${escapeHtml(paper.source_url)}" target="_blank" rel="noopener">Open source record</a>` : "No source URL";
    return `<article class="analytics-result-item">
      <div>
        <strong>${title}</strong>
        <p>${escapeHtml(paper.authors || "Authors not indexed")}${paper.journal ? ` · ${escapeHtml(paper.journal)}` : ""}</p>
        <div class="analytics-match-reasons">${reasons.map((reason) => `<span>${escapeHtml(reason)}</span>`).join("")}</div>
        <details class="analytics-paper-detail">
          <summary><span>Inspect record</span><i data-icon="chevron-down" aria-hidden="true"></i></summary>
          <dl>
            <div><dt>DOI</dt><dd>${doi}</dd></div>
            <div><dt>PubMed</dt><dd>${pmid}</dd></div>
            <div><dt>Source</dt><dd>${source}</dd></div>
            <div><dt>Topics</dt><dd>${escapeHtml(compactListLabel(paper.topic_tags))}</dd></div>
            <div><dt>Substances</dt><dd>${escapeHtml(compactListLabel(paper.substance_tags))}</dd></div>
            <div><dt>Keywords</dt><dd>${escapeHtml(compactListLabel(paper.keywords))}</dd></div>
          </dl>
        </details>
      </div>
      <div class="analytics-result-meta">
        <time>${escapeHtml(paper.publication_date)}</time>
        ${tags.map((tag) => `<span>${escapeHtml(tag)}</span>`).join("")}
      </div>
    </article>`;
  }).join("");
  if (papers.length > 8) {
    analyticsResultsList.insertAdjacentHTML("beforeend", `<p class="analytics-result-more">${(papers.length - 8).toLocaleString()} more records are available through matching-publication and LaTeX export actions.</p>`);
  }
  refreshIcons();
}

function renderTimeline(options = { preset: "10y" }) {
  if (!timelineChart) return;
  const data = analyticsData();
  const sourcePapers = filteredAnalyticsPapers();
  const rows = activeAnalyticsFilterEntries().length ? timelineRowsFromPapers(sourcePapers) : (data.timeline || []);
  const bounds = timelineBounds(rows);
  if (!bounds) {
    currentTimelineView = null;
    timelineChart.innerHTML = '<p class="empty-chart">No trend data matches this research lens.</p>';
    renderAnalyticsLensResults();
    return;
  }
  if (timelineFrom && timelineTo) {
    timelineFrom.min = isoDate(bounds.min);
    timelineFrom.max = isoDate(bounds.max);
    timelineTo.min = isoDate(bounds.min);
    timelineTo.max = isoDate(bounds.max);
  }
  let range = options.from && options.to ? { from: options.from, to: options.to } : presetRange(options.preset || "10y", bounds);
  if (!range) range = { from: bounds.min, to: bounds.max };
  if (range.from < bounds.min) range.from = bounds.min;
  if (range.to > bounds.max) range.to = bounds.max;
  if (range.from > range.to) [range.from, range.to] = [range.to, range.from];
  if (timelineFrom) timelineFrom.value = isoDate(range.from);
  if (timelineTo) timelineTo.value = isoDate(range.to);
  const preferredMode = options.preset === "1y" ? "month" : null;
  const aggregate = aggregateTimeline(rows, range.from, range.to, preferredMode);
  currentTimelineView = { from: new Date(range.from), to: new Date(range.to), aggregate };
  const labels = aggregate.buckets.map((row) => row.label);
  const counts = aggregate.buckets.map((row) => Number(row.count || 0));
  setTimelineSummary(range.from, range.to, aggregate);
  setTimelineInsight(null);
  if (!aggregate.buckets.length) {
    timelineChart.innerHTML = '<p class="empty-chart">No publications in this range.</p>';
    renderAnalyticsLensResults();
    return;
  }
  const max = Math.max(...counts, 1);
  const axisMax = Math.max(max, 4);
  const containerWidth = Math.round(timelineChart.clientWidth || Math.min(window.innerWidth - 44, 760) || 360);
  const isCompactTimeline = containerWidth < 560;
  const targetWidth = aggregate.buckets.length * (isCompactTimeline ? 30 : 48) + (isCompactTimeline ? 92 : 128);
  const width = Math.max(isCompactTimeline ? 340 : 760, Math.min(isCompactTimeline ? 560 : 1280, targetWidth, Math.max(containerWidth, 340)));
  const height = isCompactTimeline ? 282 : 360;
  const pad = {
    top: isCompactTimeline ? 28 : 34,
    right: isCompactTimeline ? 18 : 34,
    bottom: labels.length > 14 ? (isCompactTimeline ? 56 : 68) : (isCompactTimeline ? 42 : 48),
    left: isCompactTimeline ? 46 : 68,
  };
  const innerW = width - pad.left - pad.right;
  const innerH = height - pad.top - pad.bottom;
  const slot = innerW / counts.length;
  const barGap = Math.max(4, Math.min(16, slot * 0.28));
  const barW = Math.max(5, Math.min(34, slot - barGap));
  const points = counts.map((count, index) => {
    const x = pad.left + index * (innerW / counts.length) + (innerW / counts.length) / 2;
    const y = pad.top + innerH - (count / axisMax) * innerH;
    return { x, y, count, label: labels[index] };
  });
  const line = points.map((point, index) => `${index ? "L" : "M"}${point.x.toFixed(1)} ${point.y.toFixed(1)}`).join(" ");
  const yTicks = Array.from(new Set([0, Math.ceil(axisMax / 4), Math.ceil(axisMax / 2), Math.ceil(axisMax * 0.75), axisMax]));
  const skip = labels.length > 36 ? (isCompactTimeline ? 8 : 6) : labels.length > 24 ? (isCompactTimeline ? 5 : 4) : labels.length > 14 ? (isCompactTimeline ? 3 : 2) : 1;
  timelineChart.innerHTML = `
    <svg class="timeline-svg" viewBox="0 0 ${width} ${height}" aria-label="Interactive publication timeline chart">
      <rect class="chart-plot-bg" x="${pad.left}" y="${pad.top}" width="${innerW}" height="${innerH}" rx="6"></rect>
      <text class="chart-axis-label" x="${pad.left}" y="20">Publications</text>
      ${yTicks.map((tick) => {
        const y = pad.top + innerH - (tick / axisMax) * innerH;
        return `<line class="chart-grid" x1="${pad.left}" y1="${y}" x2="${width - pad.right}" y2="${y}"></line><text class="chart-y" x="${pad.left - 12}" y="${y + 4}" text-anchor="end">${tick}</text>`;
      }).join("")}
      <line class="chart-axis" x1="${pad.left}" y1="${pad.top + innerH}" x2="${width - pad.right}" y2="${pad.top + innerH}"></line>
      ${counts.map((count, index) => {
        const slotX = pad.left + index * slot;
        const x = slotX + (slot - barW) / 2;
        const h = Math.max(2, (count / axisMax) * innerH);
        const y = pad.top + innerH - h;
        const bucket = aggregate.buckets[index] || {};
        const title = bucket.fullLabel || labels[index];
        const rangeLabel = bucket.range ? `${bucket.range.from} to ${bucket.range.to}` : title;
        return `<rect class="chart-bar timeline-bucket" tabindex="0" role="link" data-bucket-index="${index}" aria-label="${escapeHtml(title)}: ${count} publications, ${escapeHtml(rangeLabel)}. Open matching publications." x="${x.toFixed(1)}" y="${y.toFixed(1)}" width="${barW.toFixed(1)}" height="${h.toFixed(1)}" rx="4" vector-effect="non-scaling-stroke"><title>${title}: ${count} publications. ${rangeLabel}. Opens matching publications.</title></rect>`;
      }).join("")}
      <path class="chart-line" d="${line}" vector-effect="non-scaling-stroke"></path>
      ${points.map((point, index) => `<circle class="chart-point timeline-bucket" tabindex="0" role="link" data-bucket-index="${index}" aria-label="${escapeHtml(aggregate.buckets[index]?.fullLabel || point.label)}: ${point.count} publications. Open matching publications." cx="${point.x.toFixed(1)}" cy="${point.y.toFixed(1)}" r="4.5" vector-effect="non-scaling-stroke"><title>${aggregate.buckets[index]?.fullLabel || point.label}: ${point.count} publications. Opens matching publications.</title></circle>`).join("")}
      ${labels.map((label, index) => {
        if (index % skip !== 0 && index !== labels.length - 1) return "";
        const x = pad.left + index * (innerW / counts.length) + (innerW / counts.length) / 2;
        const rotate = labels.length > 14 ? ` transform="rotate(-38 ${x.toFixed(1)} ${height - 22})"` : "";
        return `<text class="chart-x" x="${x.toFixed(1)}" y="${height - 22}" text-anchor="${labels.length > 14 ? "end" : "middle"}"${rotate}>${label}</text>`;
      }).join("")}
    </svg>`;
  renderAnalyticsLensResults();
}

if (timelineChart) {
  timelineChart.addEventListener("pointerover", (event) => {
    const target = event.target.closest?.(".timeline-bucket");
    if (!target || !currentTimelineView) return;
    const bucket = currentTimelineView.aggregate.buckets[Number(target.dataset.bucketIndex || -1)];
    if (bucket) setTimelineInsight(bucket);
  });
  timelineChart.addEventListener("pointerout", (event) => {
    if (event.relatedTarget?.closest?.(".timeline-bucket")) return;
    setTimelineInsight(null);
  });
  timelineChart.addEventListener("focusin", (event) => {
    const target = event.target.closest?.(".timeline-bucket");
    if (!target || !currentTimelineView) return;
    const bucket = currentTimelineView.aggregate.buckets[Number(target.dataset.bucketIndex || -1)];
    if (bucket) setTimelineInsight(bucket);
  });
  timelineChart.addEventListener("focusout", () => setTimelineInsight(null));
  timelineChart.addEventListener("click", (event) => {
    const target = event.target.closest?.(".timeline-bucket");
    if (!target || !currentTimelineView) return;
    const bucket = currentTimelineView.aggregate.buckets[Number(target.dataset.bucketIndex || -1)];
    if (bucket) openTimelineData("html", bucket);
  });
  timelineChart.addEventListener("keydown", (event) => {
    if (event.key !== "Enter" && event.key !== " ") return;
    const target = event.target.closest?.(".timeline-bucket");
    if (!target || !currentTimelineView) return;
    event.preventDefault();
    const bucket = currentTimelineView.aggregate.buckets[Number(target.dataset.bucketIndex || -1)];
    if (bucket) openTimelineData("html", bucket);
  });
}

for (const button of document.querySelectorAll(".timeline-controls button")) {
  button.addEventListener("click", () => {
    if (!button.dataset.range) return;
    document.querySelectorAll(".timeline-presets button").forEach((item) => item.classList.remove("is-active"));
    button.classList.add("is-active");
    renderTimeline({ preset: button.dataset.range || "10y" });
  });
}

if (timelineApply) {
  timelineApply.addEventListener("click", () => {
    const from = parseIsoDate(timelineFrom?.value);
    const to = parseIsoDate(timelineTo?.value);
    document.querySelectorAll(".timeline-presets button").forEach((item) => item.classList.remove("is-active"));
    if (!from || !to) {
      renderTimeline({ preset: "all" });
      return;
    }
    renderTimeline({ from, to });
  });
}

if (timelineReset) {
  timelineReset.addEventListener("click", () => {
    document.querySelectorAll(".timeline-presets button").forEach((item) => item.classList.toggle("is-active", item.dataset.range === "10y"));
    renderTimeline({ preset: "10y" });
  });
}

if (timelineOpenJson) {
  timelineOpenJson.addEventListener("click", () => openTimelineData("json"));
}

if (timelineOpenHtml) {
  timelineOpenHtml.addEventListener("click", () => openTimelineData("html"));
}

function applyAnalyticsLens() {
  currentAnalyticsFilters = getAnalyticsLensFilters();
  if (analyticsLens && activeAnalyticsFilterEntries().length) analyticsLens.open = true;
  document.querySelectorAll(".timeline-presets button").forEach((item) => item.classList.toggle("is-active", item.dataset.range === "10y"));
  renderTimeline({ preset: "10y" });
}

if (analyticsApply) {
  analyticsApply.addEventListener("click", applyAnalyticsLens);
}

if (analyticsClear) {
  analyticsClear.addEventListener("click", () => {
    Object.values(analyticsLensInputs).forEach((input) => {
      if (input) input.value = "";
    });
    currentAnalyticsFilters = {};
    document.querySelectorAll(".timeline-presets button").forEach((item) => item.classList.toggle("is-active", item.dataset.range === "10y"));
    renderTimeline({ preset: "10y" });
  });
}

Object.values(analyticsLensInputs).forEach((input) => {
  input?.addEventListener("keydown", (event) => {
    if (event.key !== "Enter") return;
    event.preventDefault();
    applyAnalyticsLens();
  });
});

if (publicationGrowthChart) {
  publicationGrowthChart.addEventListener("click", (event) => {
    const target = event.target.closest?.(".publication-growth-year");
    if (!target) return;
    openPublicationGrowthYear(target.dataset.growthYear);
  });
  publicationGrowthChart.addEventListener("keydown", (event) => {
    if (event.key !== "Enter" && event.key !== " ") return;
    const target = event.target.closest?.(".publication-growth-year");
    if (!target) return;
    event.preventDefault();
    openPublicationGrowthYear(target.dataset.growthYear);
  });
}

let publicationGrowthResizeTimer = null;
let timelineResizeTimer = null;
window.addEventListener("resize", () => {
  window.clearTimeout(publicationGrowthResizeTimer);
  window.clearTimeout(timelineResizeTimer);
  if (publicationGrowthChart) {
    publicationGrowthResizeTimer = window.setTimeout(renderPublicationGrowthChart, 120);
  }
  if (timelineChart) {
    timelineResizeTimer = window.setTimeout(() => {
      if (currentTimelineView) {
        renderTimeline({ from: new Date(currentTimelineView.from), to: new Date(currentTimelineView.to) });
        return;
      }
      renderTimeline({ preset: "10y" });
    }, 120);
  }
});

window.addEventListener("load", () => {
  syncRecencyBadges();
  scheduleIdleTask(() => {
    renderPublicationGrowthChart();
    if (window.location.hash === "#analytics" || document.querySelector("#analytics-modal")?.open) {
      renderTimeline({ preset: "10y" });
    }
  }, 2200);
  refreshIcons();
});

function initAlertScope() {
  const form = document.querySelector("#alert-enrollment .alert-form");
  if (!form) return;
  const targeting = form.querySelector(".alert-targeting");
  const scopedInputs = targeting ? Array.from(targeting.querySelectorAll("input, select")) : [];
  const radios = Array.from(form.querySelectorAll('input[name="alert_scope"]'));
  const targetedRadio = form.querySelector('input[name="alert_scope"][value="targeted"]');
  const allRadio = form.querySelector('input[name="alert_scope"][value="all"]');

  const sync = () => {
    const targeted = targetedRadio?.checked || false;
    if (targeting && targeted) targeting.open = true;
    scopedInputs.forEach((input) => {
      input.disabled = !targeted;
    });
  };

  radios.forEach((radio) => radio.addEventListener("change", sync));
  if (targeting) {
    targeting.addEventListener("toggle", () => {
      if (targeting.open && targetedRadio) {
        targetedRadio.checked = true;
        sync();
      } else if (!targeting.open && allRadio && !scopedInputs.some((input) => input.value)) {
        allRadio.checked = true;
        sync();
      }
    });
  }
  scopedInputs.forEach((input) => {
    input.addEventListener("input", () => {
      if (targetedRadio) targetedRadio.checked = true;
      sync();
    });
    input.addEventListener("change", () => {
      if (targetedRadio) targetedRadio.checked = true;
      sync();
    });
  });
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
    if (!target || target.matches("[disabled], .disabled, [aria-disabled='true']")) return;
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
  const selected = Array.from(document.querySelectorAll('select[name="sources[]"] option:checked'))
    .map((option) => option.textContent?.replace(/\s*\\(.*?\\)\s*$/, "").trim() || "")
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
  document.addEventListener("click", async (event) => {
    const link = event.target.closest?.("a[href]");
    if (!(link instanceof HTMLAnchorElement)) return;
    const url = new URL(link.href, window.location.href);
    if (!isDownloadEndpoint(url)) return;
    event.preventDefault();

    link.classList.add("is-loading");
    link.setAttribute("aria-busy", "true");
    const downloadUrl = augmentDownloadUrl(url);
    let entries = fallbackDownloadCount(downloadUrl);
    try {
      const response = await fetch(downloadUrl.href, {
        method: "HEAD",
        credentials: "same-origin",
        cache: "no-store",
      });
      if (response.ok) {
        entries = response.headers.get("X-Publication-Tracker-Export-Count") || entries;
      }
    } catch (error) {
      // Fall through to a conservative confirmation with the visible result count.
    }

    link.classList.remove("is-loading");
    link.removeAttribute("aria-busy");
    const confirmed = window.confirm(`Download publication data?\n\nEntries: ${entries}\nDate range: ${downloadDateRange(downloadUrl)}\nIncluded sources: ${downloadIncludedSources(downloadUrl)}`);
    if (!confirmed) return;
    const downloadLink = document.createElement("a");
    downloadLink.href = downloadUrl.href;
    downloadLink.target = link.target || "_self";
    downloadLink.rel = link.rel || "noopener";
    document.body.append(downloadLink);
    downloadLink.click();
    downloadLink.remove();
  });
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

function initPwa() {
  const installButton = document.querySelector("#install-app");
  const pushButton = document.querySelector("#push-app");
  let deferredPrompt = null;
  let refreshingForUpdate = false;
  const standalone = window.matchMedia("(display-mode: standalone)").matches || window.navigator.standalone === true;
  const installedFlag = localStorage.getItem("publicationTrackerInstalled") === "1";

  if ("serviceWorker" in navigator) {
    navigator.serviceWorker.addEventListener("controllerchange", () => {
      if (!refreshingForUpdate) return;
      window.location.reload();
    });
    navigator.serviceWorker.register("sw.js", { scope: "./", updateViaCache: "none" }).then((registration) => {
      const notifyUpdated = () => {
        sessionStorage.setItem("publicationTrackerUpdated", "1");
        refreshingForUpdate = true;
        showAppToast("Psilocybin Research Tracker updated. Reloading the app shell...");
      };
      registration.addEventListener("updatefound", () => {
        const worker = registration.installing;
        if (!worker) return;
        worker.addEventListener("statechange", () => {
          if (worker.state === "installed" && navigator.serviceWorker.controller) {
            notifyUpdated();
            worker.postMessage({ type: "SKIP_WAITING" });
          }
        });
      });
      registration.update().catch(() => {});
    }).catch(() => {});
    if (sessionStorage.getItem("publicationTrackerUpdated") === "1") {
      sessionStorage.removeItem("publicationTrackerUpdated");
      showAppToast("Psilocybin Research Tracker is up to date.");
      if ("Notification" in window && Notification.permission === "granted") {
        try {
          new Notification("Psilocybin Research Tracker updated", {
            body: "The installed research app has refreshed to the latest version.",
            icon: "assets/pwa/icon-192.png?v=20260709-network-v81",
            tag: "publication-tracker-app-update",
          });
        } catch (error) {}
      }
    }
  }

  const canPush = "serviceWorker" in navigator && "PushManager" in window && "Notification" in window;
  const shouldOfferPush = () => canPush && (standalone || localStorage.getItem("publicationTrackerInstalled") === "1");
  const updatePushButton = async () => {
    if (!pushButton) return;
    if (!shouldOfferPush()) {
      pushButton.hidden = true;
      return;
    }
    pushButton.hidden = false;
    if (Notification.permission === "granted") {
      const registration = await navigator.serviceWorker.ready.catch(() => null);
      const subscription = registration ? await registration.pushManager.getSubscription() : null;
      pushButton.querySelector("span").textContent = subscription ? "Push on" : "Enable push";
      pushButton.classList.toggle("is-enabled", !!subscription);
    } else {
      pushButton.querySelector("span").textContent = "Enable push";
      pushButton.classList.remove("is-enabled");
    }
  };

  if ((standalone || installedFlag) && installButton) {
    installButton.hidden = true;
  }

  window.addEventListener("beforeinstallprompt", (event) => {
    event.preventDefault();
    deferredPrompt = event;
    if (installButton) {
      installButton.hidden = false;
      installButton.removeAttribute("aria-disabled");
    }
  });

  if (installButton) {
    installButton.addEventListener("click", async () => {
      if (!deferredPrompt) {
        window.alert("Use your browser menu to install the tracker on this device.");
        return;
      }
      installButton.setAttribute("aria-disabled", "true");
      deferredPrompt.prompt();
      const choice = await deferredPrompt.userChoice.catch(() => ({ outcome: "dismissed" }));
      deferredPrompt = null;
      installButton.hidden = true;
      if (choice.outcome === "accepted") {
        localStorage.setItem("publicationTrackerInstalled", "1");
        updatePushButton();
        window.alert("Psilocybin Research Tracker installed.");
      }
    });
  }

  window.addEventListener("appinstalled", () => {
    deferredPrompt = null;
    localStorage.setItem("publicationTrackerInstalled", "1");
    if (installButton) installButton.hidden = true;
    updatePushButton();
    window.alert("Psilocybin Research Tracker installed. Use Enable push to receive new-publication notifications on this device.");
  });

  if (pushButton) {
    pushButton.addEventListener("click", async () => {
      try {
        if (!canPush) {
          window.alert("Push notifications are not supported by this browser.");
          return;
        }
        const permission = Notification.permission === "granted" ? "granted" : await Notification.requestPermission();
        if (permission !== "granted") {
          window.alert("Push notifications were not enabled.");
          return;
        }
        const registration = await navigator.serviceWorker.ready;
        const existing = await registration.pushManager.getSubscription();
        if (existing) {
          window.alert("Push notifications are already enabled for this device.");
          await updatePushButton();
          return;
        }
        const keyResponse = await fetch("push.php?action=public-key", { headers: { Accept: "application/json" }, cache: "no-store" });
        if (!keyResponse.ok) throw new Error("Could not load push key");
        const keyData = await keyResponse.json();
        const subscription = await registration.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: base64UrlToUint8Array(keyData.publicKey || ""),
        });
        const saveResponse = await fetch("push.php?action=subscribe", {
          method: "POST",
          headers: { "Content-Type": "application/json", Accept: "application/json" },
          body: JSON.stringify(subscription),
        });
        if (!saveResponse.ok) throw new Error("Could not save push subscription");
        window.alert("Push notifications enabled. You will be notified when newly imported psilocybin or psilocin publications are added.");
        await updatePushButton();
      } catch (error) {
        window.alert("Could not enable push notifications on this device.");
      }
    });
  }

  updatePushButton();
}

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
  toast.textContent = message;
  toast.hidden = false;
  requestAnimationFrame(() => toast.classList.add("is-visible"));
  window.clearTimeout(showAppToast.timer);
  showAppToast.timer = window.setTimeout(() => {
    toast.classList.remove("is-visible");
    window.setTimeout(() => {
      toast.hidden = true;
    }, 220);
  }, timeout);
}

function initCollectionExport() {
  const boxes = [...document.querySelectorAll("[data-collection-paper]")];
  const trigger = document.querySelector("[data-export-selected]");
  if (!boxes.length || !trigger) return;
  const sync = () => {
    const count = boxes.filter((box) => box.checked).length;
    trigger.disabled = count === 0;
    trigger.querySelector("span").textContent = count > 0 ? `Export selected (${count})` : "Export selected";
  };
  boxes.forEach((box) => box.addEventListener("change", sync));
  trigger.addEventListener("click", () => {
    const ids = boxes.filter((box) => box.checked).map((box) => box.value);
    if (!ids.length) return;
    const url = new URL("export.php", window.location.href);
    url.searchParams.set("format", "bibtex");
    ids.forEach((id) => url.searchParams.append("ids[]", id));
    window.location.href = url.href;
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

function initCitationNetwork() {
  const host = document.querySelector("[data-citation-network]");
  const dataScript = document.querySelector("#citation-network-data");
  if (!host || !dataScript) return;
  let graph;
  try {
    graph = JSON.parse(dataScript.textContent || "{}");
  } catch (error) {
    host.innerHTML = '<div class="citation-network-empty">Network data could not be loaded.</div>';
    return;
  }
  const nodes = Array.isArray(graph.nodes) ? graph.nodes.map((node, index) => ({ ...node, index })) : [];
  const nodeById = new Map(nodes.map((node) => [node.id, node]));
  const edges = (Array.isArray(graph.edges) ? graph.edges : []).filter((edge) => nodeById.has(edge.source) && nodeById.has(edge.target));
  if (!nodes.length) {
    const printButton = document.querySelector("[data-citation-print]");
    if (printButton) {
      printButton.disabled = true;
      printButton.setAttribute("aria-disabled", "true");
      printButton.setAttribute("title", "No displayed references to print");
    }
    host.innerHTML = '<div class="citation-network-empty">No graph records matched these filters.</div>';
    return;
  }

  const detail = document.querySelector("[data-citation-detail]");
  const fitButton = document.querySelector("[data-citation-fit]");
  const labelButton = document.querySelector("[data-citation-labels]");
  const printButton = document.querySelector("[data-citation-print]");
  const fullscreenButton = document.querySelector("[data-citation-fullscreen]");
  const fullscreenTarget = document.querySelector("[data-citation-fullscreen-target]");
  const searchInput = document.querySelector("[data-citation-search]");
  const seedLimitSelect = document.querySelector("[data-citation-seed-limit]");
  const seedPresetButtons = [...document.querySelectorAll("[data-citation-seed-preset]")];
  const clearSearchButton = document.querySelector("[data-citation-clear-search]");
  const copySelectedButton = document.querySelector("[data-citation-copy-selected]");
  const focusSelectedButton = document.querySelector("[data-citation-focus-selected]");
  const shareViewButton = document.querySelector("[data-citation-share-view]");
  const exportJsonButton = document.querySelector("[data-citation-export-json]");
  const exportSubgraphButton = document.querySelector("[data-citation-export-subgraph]");
  const exportCsvButton = document.querySelector("[data-citation-export-csv]");
  const layoutModeSelect = document.querySelector("[data-citation-layout-mode]");
  const labelModeSelect = document.querySelector("[data-citation-label-mode]");
  const clusterToggle = document.querySelector("[data-citation-clusters]");
  const nodeTypeInputs = [...document.querySelectorAll("[data-citation-node-type]")];
  const edgeTypeInputs = [...document.querySelectorAll("[data-citation-edge-type]")];
  const insight = document.querySelector("[data-citation-insight]");
  const typeRadius = { paper: 13, reference: 8, author: 9, topic: 9, journal: 9 };
  const labels = { paper: "Paper", reference: "Reference", author: "Author", topic: "Topic", journal: "Journal" };
  const relationLabels = { cites: "Cites", author: "Author link", topic: "Topic link", journal: "Journal link" };
  const svgNs = "http://www.w3.org/2000/svg";
  const svg = document.createElementNS(svgNs, "svg");
  svg.setAttribute("role", "presentation");
  host.replaceChildren(svg);
  const hoverPreview = document.createElement("div");
  hoverPreview.className = "citation-node-preview";
  hoverPreview.hidden = true;
  host.append(hoverPreview);
  const defs = document.createElementNS(svgNs, "defs");
  const arrow = document.createElementNS(svgNs, "marker");
  arrow.setAttribute("id", "citation-arrow");
  arrow.setAttribute("viewBox", "0 0 10 10");
  arrow.setAttribute("refX", "9");
  arrow.setAttribute("refY", "5");
  arrow.setAttribute("markerWidth", "7");
  arrow.setAttribute("markerHeight", "7");
  arrow.setAttribute("orient", "auto-start-reverse");
  const arrowPath = document.createElementNS(svgNs, "path");
  arrowPath.setAttribute("d", "M 0 0 L 10 5 L 0 10 z");
  arrow.append(arrowPath);
  defs.append(arrow);
  const edgeLayer = document.createElementNS(svgNs, "g");
  const nodeLayer = document.createElementNS(svgNs, "g");
  svg.append(defs, edgeLayer, nodeLayer);

  const dimensions = () => {
    const rect = host.getBoundingClientRect();
    return { width: Math.max(320, rect.width), height: Math.max(420, rect.height || 640) };
  };
  let { width, height } = dimensions();
  const center = () => ({ x: width / 2, y: height / 2 });
  const layoutLabels = {
    force: "Organic map",
    radial: "Citation rings",
    timeline: "Publication timeline",
  };
  const layoutMode = () => layoutModeSelect?.value || "force";
  const initialParams = new URLSearchParams(window.location.search);
  if (layoutModeSelect && ["force", "radial", "timeline"].includes(initialParams.get("layout") || "")) {
    layoutModeSelect.value = initialParams.get("layout") || "force";
  }
  if (labelModeSelect && ["important", "all", "selected", "off"].includes(initialParams.get("labels") || "")) {
    labelModeSelect.value = initialParams.get("labels") || "important";
  }
  if (clusterToggle && initialParams.get("clusters") === "0") {
    clusterToggle.checked = false;
  }
  const paperYears = nodes
    .filter((node) => node.type === "paper" && /^\d{4}/.test(String(node.date || "")))
    .map((node) => Number(String(node.date).slice(0, 4)));
  const minPaperYear = paperYears.length ? Math.min(...paperYears) : new Date().getFullYear() - 1;
  const maxPaperYear = paperYears.length ? Math.max(...paperYears) : new Date().getFullYear();
  const nodesByType = nodes.reduce((groups, node) => {
    if (!groups[node.type]) groups[node.type] = [];
    groups[node.type].push(node);
    return groups;
  }, {});
  const nodeTypeIndexes = new Map();
  Object.values(nodesByType).forEach((group) => {
    group.forEach((node, index) => nodeTypeIndexes.set(node.id, index));
  });
  const layoutIndex = (node) => nodeTypeIndexes.get(node.id) || 0;
  const layoutCount = (type) => nodesByType[type]?.length || 1;
  const nodeYear = (node) => {
    const match = String(node.date || "").match(/^(\d{4})/);
    return match ? Number(match[1]) : Math.round((minPaperYear + maxPaperYear) / 2);
  };
  const layoutTarget = (node) => {
    const mode = layoutMode();
    const c = center();
    const margin = 54;
    if (mode === "timeline") {
      const yearSpan = Math.max(1, maxPaperYear - minPaperYear);
      const x = margin + ((nodeYear(node) - minPaperYear) / yearSpan) * Math.max(1, width - margin * 2);
      const bands = { topic: .16, author: .29, paper: .48, reference: .69, journal: .84 };
      const index = layoutIndex(node);
      const count = layoutCount(node.type);
      const jitter = count > 1 ? ((index / Math.max(1, count - 1)) - .5) * 54 : 0;
      return { x, y: height * (bands[node.type] || .5) + jitter };
    }
    if (mode === "radial") {
      const index = layoutIndex(node);
      const count = layoutCount(node.type);
      const angle = (Math.PI * 2 * index) / count - Math.PI / 2;
      const radiusScale = { paper: .14, author: .28, topic: .39, reference: .5, journal: .59 };
      const radius = Math.min(width, height) * (radiusScale[node.type] || .35);
      return { x: c.x + Math.cos(angle) * radius, y: c.y + Math.sin(angle) * radius };
    }
    return c;
  };
  const settleLayout = (iterations = 90) => {
    for (let i = 0; i < iterations; i++) tick();
    render();
  };
  const applyLayoutMode = (snap = false) => {
    const mode = layoutMode();
    host.dataset.citationCurrentLayout = mode;
    if (snap && mode !== "force") {
      nodes.forEach((node) => {
        const target = layoutTarget(node);
        node.x = target.x;
        node.y = target.y;
        node.vx = 0;
        node.vy = 0;
      });
      settleLayout(28);
      return;
    }
    settleLayout(mode === "force" ? 90 : 36);
  };

  nodes.forEach((node, index) => {
    const angle = (Math.PI * 2 * index) / Math.max(nodes.length, 1);
    const radius = Math.min(width, height) * (node.type === "paper" ? 0.23 : 0.35);
    node.x = width / 2 + Math.cos(angle) * radius;
    node.y = height / 2 + Math.sin(angle) * radius;
    node.vx = 0;
    node.vy = 0;
    node.r = typeRadius[node.type] || 8;
    if (node.type === "paper") node.r += Math.min(7, Number(node.weight || 1));
    node.degree = 0;
    node.searchText = [
      node.label,
      node.authors,
      node.journal,
      node.doi,
      node.pubmed_id,
      node.source,
      node.status,
      node.type,
    ].filter(Boolean).join(" ").toLowerCase();
  });

  edges.forEach((edge) => {
    const source = nodeById.get(edge.source);
    const target = nodeById.get(edge.target);
    if (source) source.degree += 1;
    if (target) target.degree += 1;
  });

  const edgeElements = edges.map((edge) => {
    const line = document.createElementNS(svgNs, "line");
    line.classList.add("citation-edge", `citation-edge-${edge.type || "related"}`);
    if (edge.type === "cites") line.setAttribute("marker-end", "url(#citation-arrow)");
    edgeLayer.append(line);
    return { edge, line };
  });

  const connectedEdgesForNode = (node) => edges.filter((edge) => edge.source === node.id || edge.target === node.id);
  const isClusteringNode = (node) => node.type === "author" || node.type === "topic" || node.type === "journal";
  const clusteringVisible = () => !clusterToggle || clusterToggle.checked;
  const visibleTypes = () => nodeTypeInputs.length
    ? new Set(nodeTypeInputs.filter((input) => input.checked).map((input) => input.dataset.citationNodeType))
    : new Set(nodes.map((node) => node.type));
  const visibleEdgeTypes = () => edgeTypeInputs.length
    ? new Set(edgeTypeInputs.filter((input) => input.checked).map((input) => input.dataset.citationEdgeType))
    : new Set(edges.map((edge) => edge.type));
  const currentSearchTerm = () => (searchInput?.value || "").trim().toLowerCase();
  const nodeTypeVisible = (node) => (!nodeTypeInputs.length || visibleTypes().has(node.type)) && (clusteringVisible() || !isClusteringNode(node));
  const edgeTypeVisible = (edge) => {
    if (edgeTypeInputs.length && !visibleEdgeTypes().has(edge.type)) return false;
    if (!clusteringVisible() && edge.type !== "cites") return false;
    return true;
  };
  const applySeedLimit = (value) => {
    const url = new URL(window.location.href);
    url.searchParams.set("limit", value || "1");
    url.searchParams.set("layout", layoutMode());
    if (labelModeSelect?.value) url.searchParams.set("labels", labelModeSelect.value);
    url.searchParams.set("clusters", clusteringVisible() ? "1" : "0");
    url.hash = "network";
    window.location.href = url.href;
  };
  const updateSavedViewUrl = () => {
    const url = new URL(window.location.href);
    url.searchParams.set("layout", layoutMode());
    if (labelModeSelect?.value) url.searchParams.set("labels", labelModeSelect.value);
    url.searchParams.set("clusters", clusteringVisible() ? "1" : "0");
    if (selectedState.focusNodeId) url.searchParams.set("network_node", selectedState.focusNodeId);
    else url.searchParams.delete("network_node");
    url.hash = "network";
    window.history.replaceState(null, "", url.href);
    return url;
  };

  const visibleGraphSnapshot = (selectedNeighborhoodOnly = false) => {
    const focus = graphFocusSets();
    const visibleNodeIds = new Set();
    const visibleNodes = nodes.filter((node) => {
      const show = selectedNeighborhoodOnly ? isInSelectedNeighborhood(node, true) && nodeTypeVisible(node) : shouldShowNode(node, focus);
      if (show) visibleNodeIds.add(node.id);
      return show;
    });
    const visibleEdges = edges.filter((edge) => visibleNodeIds.has(edge.source) && visibleNodeIds.has(edge.target) && shouldShowEdge(edge, focus));
    return { focus, nodes: visibleNodes, edges: visibleEdges };
  };

  const downloadCitationGraphFile = (filename, content, type) => {
    const blob = new Blob([content], { type });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = filename;
    document.body.append(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
  };

  const csvCell = (value) => `"${String(value ?? "").replaceAll('"', '""')}"`;

  const selectedNodeText = () => {
    const node = selectedState.node;
    if (!node) return "";
    const relationLines = connectedEdgesForNode(node)
      .slice(0, 20)
      .map((edge) => `- ${relationSentence(node, edge)}`);
    return [
      `${labels[node.type] || "Node"}: ${node.label || "Untitled"}`,
      node.authors ? `Authors: ${splitCitationNames(node.authors).join("; ")}` : "",
      node.journal ? `Journal: ${node.journal}` : "",
      node.date ? `Date: ${node.date}` : "",
      nodeDoi(node) ? `DOI: ${nodeDoi(node)}` : "",
      node.pubmed_id ? `PubMed ID: ${node.pubmed_id}` : "",
      node.source ? `Source: ${node.source}` : "",
      node.status ? `Status: ${node.status}` : "",
      node.url ? `URL: ${new URL(node.url, window.location.href).href}` : "",
      relationLines.length ? `Connected evidence:\n${relationLines.join("\n")}` : "",
    ].filter(Boolean).join("\n");
  };

  const printableReferenceNodes = () => nodes
    .filter((node) => node.type === "paper" || node.type === "reference")
    .map((node) => ({
      type: node.type,
      title: node.label || "Untitled reference",
      authors: node.authors || "",
      journal: node.journal || "",
      date: node.date || "",
      doi: node.doi || (node.type === "reference" ? node.label : ""),
      pubmedId: node.pubmed_id || "",
      source: node.source || (node.type === "reference" ? "External DOI reference" : ""),
      status: node.status || "",
      url: node.url || "",
    }))
    .sort((a, b) => {
      if (a.type !== b.type) return a.type === "paper" ? -1 : 1;
      return String(b.date || "").localeCompare(String(a.date || "")) || String(a.title).localeCompare(String(b.title));
    });

  const citationPrintReferenceText = (item) => {
    if (item.type === "reference" && item.doi) return `External DOI reference. doi:${item.doi}`;
    return printReferenceText(item);
  };

  const citationNetworkPrintHtml = (items) => {
    const paperCount = items.filter((item) => item.type === "paper").length;
    const referenceCount = items.filter((item) => item.type === "reference").length;
    const dateRange = printDateRangeLabel(items);
    const sources = printSourcesLabel(items);
    const generated = new Date().toLocaleString(undefined, { dateStyle: "medium", timeStyle: "short" });
    const pageUrl = window.location.href;
    return `<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Citation network references | Psilocybin Research</title>
  <style>
    @page{margin:16mm 14mm}
    body{margin:0;color:#111;font-family:Georgia,"Times New Roman",serif;font-size:11pt;line-height:1.42}
    header{border-bottom:1px solid #111;margin-bottom:14px;padding-bottom:8px;font-family:Arial,Helvetica,sans-serif;font-size:9pt;color:#222}
    header h1{margin:0 0 6px;font-size:15pt;line-height:1.2}
    header dl{display:grid;grid-template-columns:34mm 1fr;gap:3px 8px;margin:0}
    header dt{font-weight:700}
    header dd{margin:0;overflow-wrap:anywhere}
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
      <h1>Citation network references</h1>
      <dl>
        <dt>Generated</dt><dd>${escapeHtml(generated)}</dd>
        <dt>Displayed entries</dt><dd>${escapeHtml(String(items.length))}</dd>
        <dt>Papers</dt><dd>${escapeHtml(String(paperCount))}</dd>
        <dt>External DOIs</dt><dd>${escapeHtml(String(referenceCount))}</dd>
        <dt>Date range</dt><dd>${escapeHtml(dateRange)}</dd>
        <dt>Sources</dt><dd>${escapeHtml(sources)}</dd>
        <dt>Network URL</dt><dd>${escapeHtml(pageUrl)}</dd>
      </dl>
    </header>
    <ol>
      ${items.map((item) => `<li>${escapeHtml(citationPrintReferenceText(item))}</li>`).join("")}
    </ol>
    <footer><a href="https://psilocybin-research.com/citation-network.php">psilocybin-research.com/citation-network.php</a></footer>
  </main>
</body>
</html>`;
  };

  const printDisplayedCitationReferences = () => {
    const items = printableReferenceNodes();
    if (!items.length) {
      showAppToast("No displayed citation references are available to print.");
      return;
    }
    const paperCount = items.filter((item) => item.type === "paper").length;
    const referenceCount = items.filter((item) => item.type === "reference").length;
    if (!window.confirm(`Print ${items.length} displayed ${items.length === 1 ? "reference" : "references"}?\n\nPapers: ${paperCount}\nExternal DOI references: ${referenceCount}`)) return;
    const frame = document.createElement("iframe");
    frame.title = "Printable citation network references";
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
      showAppToast("Could not prepare the citation network print view.");
      return;
    }
    printDocument.open();
    printDocument.write(citationNetworkPrintHtml(items));
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
  };

  const fullscreenEnabled = document.fullscreenEnabled || document.webkitFullscreenEnabled;
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
  const syncCitationFullscreen = () => {
    if (!fullscreenButton || !fullscreenTarget || !fullscreenEnabled) return;
    const active = fullscreenElement() === fullscreenTarget;
    fullscreenButton.hidden = false;
    fullscreenButton.setAttribute("aria-pressed", active ? "true" : "false");
    fullscreenButton.setAttribute("title", active ? "Exit citation network fullscreen" : "Open citation network fullscreen");
    fullscreenButton.innerHTML = `<i data-icon="${active ? "minimize" : "maximize"}" aria-hidden="true"></i><span>${active ? "Exit fullscreen" : "Fullscreen"}</span>`;
    fullscreenTarget.classList.toggle("is-citation-fullscreen", active);
    refreshIcons();
    window.setTimeout(fitGraph, 80);
  };
  if (fullscreenButton && fullscreenTarget) {
    if (!fullscreenEnabled) {
      fullscreenButton.hidden = true;
    } else {
      fullscreenButton.hidden = false;
      fullscreenButton.addEventListener("click", async () => {
        try {
          if (fullscreenElement() === fullscreenTarget) {
            await exitFullscreen();
            showAppToast("Exited citation network fullscreen.");
          } else {
            if (fullscreenElement()) await exitFullscreen();
            await requestFullscreen(fullscreenTarget);
            showAppToast("Citation network fullscreen enabled.");
          }
        } catch (error) {
          showAppToast("Fullscreen is not available in this browser window.");
        } finally {
          window.setTimeout(syncCitationFullscreen, 80);
        }
      });
      document.addEventListener("fullscreenchange", syncCitationFullscreen);
      document.addEventListener("webkitfullscreenchange", syncCitationFullscreen);
      syncCitationFullscreen();
    }
  }

  if (printButton) {
    const printableCount = printableReferenceNodes().length;
    printButton.disabled = printableCount === 0;
    printButton.setAttribute("aria-disabled", printableCount === 0 ? "true" : "false");
    printButton.setAttribute("title", printableCount === 0 ? "No displayed references to print" : "Print displayed citation references");
    printButton.addEventListener("click", printDisplayedCitationReferences);
  }

  const selectedState = {
    node: null,
    focusNodeId: initialParams.get("network_node") || "",
  };
  const splitCitationNames = (value) => String(value || "")
    .split(",")
    .map((item) => item.trim())
    .filter(Boolean);
  const authorLinksHtml = (authors) => splitCitationNames(authors)
    .slice(0, 12)
    .map((author) => `<a href="authors.php?author=${encodeURIComponent(author)}">${escapeHtml(author)}</a>`)
    .join(", ");
  const nodeDoi = (node) => node.doi || (node.type === "reference" ? node.label : "");
  const nodeDetailDescription = (node) => {
    if (node.type === "paper") {
      return "Indexed publication. Citation links point toward referenced papers or external DOI records; dashed links explain shared authors, topics, and journals.";
    }
    if (node.type === "reference") {
      return "External DOI reference cited by one or more indexed papers. Full title, authors, journal, and date appear only when that DOI is also indexed as a publication record.";
    }
    if (node.type === "author") return "Author node. Connected papers show where this name appears in the current graph.";
    if (node.type === "topic") return "Topic node. Connected papers share this stored topic tag in the current graph.";
    if (node.type === "journal") return "Journal node. Connected papers were published or indexed under this journal/source label.";
    return "Relationship node used to explain why publications cluster together.";
  };
  const relationCardMeta = (node) => [
    node.authors ? `Authors: ${splitCitationNames(node.authors).slice(0, 4).join("; ")}` : "",
    node.journal ? `Journal: ${node.journal}` : "",
    node.date ? `Date: ${node.date}` : "",
    nodeDoi(node) ? `DOI: ${nodeDoi(node)}` : "",
    node.source ? `Source: ${node.source}` : "",
    node.status ? `Status: ${node.status}` : "",
  ].filter(Boolean);
  const selectedNeighborhoodIds = (fallbackToSelected = false) => {
    const centerId = selectedState.focusNodeId || (fallbackToSelected ? selectedState.node?.id : "") || "";
    if (!centerId) return new Set();
    const ids = new Set([centerId]);
    edges.forEach((edge) => {
      if (edge.source === centerId) ids.add(edge.target);
      if (edge.target === centerId) ids.add(edge.source);
    });
    return ids;
  };
  const isInSelectedNeighborhood = (node, fallbackToSelected = false) => {
    const ids = selectedNeighborhoodIds(fallbackToSelected);
    return !ids.size || ids.has(node.id);
  };
  const focusSelectedNode = (node = selectedState.node) => {
    if (!node) {
      showAppToast("Select a network node before focusing.");
      return;
    }
    selectedState.focusNodeId = selectedState.focusNodeId === node.id ? "" : node.id;
    updateSavedViewUrl();
    updateGraphState();
    showAppToast(selectedState.focusNodeId ? "Focused selected node neighborhood." : "Node focus cleared.");
  };
  const compactNodeMeta = (node) => [
    node.date,
    node.journal,
    nodeDoi(node) ? `DOI: ${nodeDoi(node)}` : "",
    node.source,
    node.status,
  ].filter(Boolean).join(" · ");
  const relatedListHtml = (node) => {
    const related = Array.isArray(node.related) ? node.related.slice(0, 5) : [];
    if (!related.length) return "";
    return `<div class="citation-node-related"><h3>Related indexed papers</h3>${related.map((paper, index) => `<a href="${escapeHtml(paper.url || "#")}"><strong>${escapeHtml(String(index + 1))}. ${escapeHtml(paper.label || "Untitled publication")}</strong><small>${escapeHtml([paper.authors, paper.journal, paper.date, paper.doi ? `DOI: ${paper.doi}` : "", paper.status].filter(Boolean).join(" · "))}</small></a>`).join("")}</div>`;
  };
  const previewHtml = (node) => `
    <strong>${escapeHtml(labels[node.type] || "Node")}: ${escapeHtml(node.label || "Untitled node")}</strong>
    <span>${escapeHtml(node.authors || nodeDetailDescription(node))}</span>
    ${compactNodeMeta(node) ? `<em>${escapeHtml(compactNodeMeta(node))}</em>` : ""}
  `;
  const showNodePreview = (node, event) => {
    hoverPreview.innerHTML = previewHtml(node);
    hoverPreview.hidden = false;
    moveNodePreview(event);
  };
  const moveNodePreview = (event) => {
    if (hoverPreview.hidden) return;
    const rect = host.getBoundingClientRect();
    hoverPreview.style.left = `${Math.min(rect.width - 260, Math.max(10, event.clientX - rect.left + 14))}px`;
    hoverPreview.style.top = `${Math.min(rect.height - 130, Math.max(10, event.clientY - rect.top + 14))}px`;
  };
  const hideNodePreview = () => {
    hoverPreview.hidden = true;
  };
  const relationSentence = (node, edge) => {
    const otherId = edge.source === node.id ? edge.target : edge.source;
    const other = nodeById.get(otherId);
    const relation = relationLabels[edge.type] || "Related";
    if (!other) return relation;
    if (edge.type === "cites") {
      return edge.source === node.id
        ? `Cites ${labels[other.type] || "node"}: ${other.label || other.id}`
        : `Cited by ${labels[other.type] || "node"}: ${other.label || other.id}`;
    }
    return `${relation}: ${other.label || other.id}`;
  };
  const updateDetail = (node) => {
    selectedState.node = node;
    nodeLayer.querySelectorAll(".citation-node").forEach((element) => {
      element.classList.toggle("is-selected", element.dataset.nodeId === node.id);
    });
    updateGraphState();
    if (!detail) return;
    const nodeRelations = connectedEdgesForNode(node)
      .map((edge) => ({ edge, other: nodeById.get(edge.source === node.id ? edge.target : edge.source) }))
      .filter((item) => item.other)
      .sort((a, b) => String(a.edge.type).localeCompare(String(b.edge.type)) || String(a.other.label).localeCompare(String(b.other.label)))
      .slice(0, 14);
    const meta = [
      node.authors ? `<div><dt>Authors</dt><dd class="citation-detail-authors">${authorLinksHtml(node.authors)}</dd></div>` : "",
      node.date ? `<div><dt>Date</dt><dd>${escapeHtml(node.date)}</dd></div>` : "",
      node.journal ? `<div><dt>Journal</dt><dd>${escapeHtml(node.journal)}</dd></div>` : "",
      nodeDoi(node) ? `<div><dt>DOI</dt><dd>${escapeHtml(nodeDoi(node))}</dd></div>` : "",
      node.source ? `<div><dt>Source</dt><dd>${escapeHtml(node.source)}</dd></div>` : "",
      node.status ? `<div><dt>Status</dt><dd>${escapeHtml(node.status)}</dd></div>` : "",
      `<div><dt>Type</dt><dd>${escapeHtml(labels[node.type] || node.type || "Node")}</dd></div>`,
      `<div><dt>Visible links</dt><dd>${escapeHtml(String(connectedEdgesForNode(node).length))}</dd></div>`,
    ].filter(Boolean).join("");
    const matchNote = node.reference_match
      ? `<p class="citation-match-note">This node was matched from a cited DOI/reference to an indexed publication${node.matched_reference_doi ? ` (${escapeHtml(node.matched_reference_doi)})` : ""}.</p>`
      : "";
    const action = `<div class="citation-network-detail-actions">
      ${node.url ? `<a class="primary iconed" href="${escapeHtml(node.url)}"><i data-icon="external-link" aria-hidden="true"></i><span>Open</span></a>` : ""}
      <button class="secondary" type="button" data-citation-detail-focus>${selectedState.focusNodeId === node.id ? "Clear node focus" : "Focus on this node"}</button>
      <button class="secondary" type="button" data-citation-detail-copy>Copy context</button>
    </div>`;
    const relationList = nodeRelations.length
      ? `<div class="citation-node-relations" data-citation-relations><h3>Connected evidence</h3>${nodeRelations.map(({ edge, other }) => `<a href="${escapeHtml(other.url || "#")}" ${other.url ? "" : 'aria-disabled="true"'}><span>${escapeHtml(relationSentence(node, edge))}</span><em>${escapeHtml(labels[other.type] || other.type || "Node")}</em>${relationCardMeta(other).length ? `<small>${relationCardMeta(other).map(escapeHtml).join(" · ")}</small>` : ""}</a>`).join("")}</div>`
      : '<div class="citation-node-relations" data-citation-relations><h3>Connected evidence</h3><p>No visible relationships for this node under the current filters.</p></div>';
    detail.innerHTML = `
      <span class="eyebrow">Selected ${escapeHtml(labels[node.type] || "node")}</span>
      <h2>${escapeHtml(node.label || "Untitled node")}</h2>
      <p>${escapeHtml(nodeDetailDescription(node))}</p>
      ${matchNote}
      <dl class="detail-list">${meta}</dl>
      ${action}
      ${relationList}
      ${relatedListHtml(node)}
    `;
    refreshIcons();
  };
  detail?.addEventListener("click", async (event) => {
    if (event.target.closest?.("[data-citation-detail-focus]")) {
      focusSelectedNode();
      if (selectedState.node) updateDetail(selectedState.node);
      return;
    }
    if (event.target.closest?.("[data-citation-detail-copy]")) {
      const text = selectedNodeText();
      if (!text) return;
      try {
        await navigator.clipboard.writeText(text);
        showAppToast("Selected network context copied.");
      } catch (error) {
        window.prompt("Copy selected network context:", text);
      }
    }
  });

  const nodeElements = nodes.map((node) => {
    const group = document.createElementNS(svgNs, "g");
    group.classList.add("citation-node", `citation-node-${node.type || "unknown"}`);
    group.dataset.nodeId = node.id;
    group.setAttribute("tabindex", "0");
    group.setAttribute("role", "button");
    group.setAttribute("aria-label", `${labels[node.type] || "Node"}: ${node.label || ""}`);
    const circle = document.createElementNS(svgNs, "circle");
    circle.setAttribute("r", String(node.r));
    const title = document.createElementNS(svgNs, "title");
    title.textContent = `${labels[node.type] || "Node"}: ${node.label || ""}${node.doi ? `; DOI ${node.doi}` : ""}${node.date ? `; ${node.date}` : ""}`;
    const text = document.createElementNS(svgNs, "text");
    text.setAttribute("x", String(node.r + 6));
    text.setAttribute("y", "4");
    const label = String(node.label || "Node");
    text.textContent = label.length > 44 ? `${label.slice(0, 41)}...` : label;
    group.append(title, circle, text);
    nodeLayer.append(group);
    group.addEventListener("click", () => updateDetail(node));
    group.addEventListener("pointerenter", (event) => showNodePreview(node, event));
    group.addEventListener("pointermove", moveNodePreview);
    group.addEventListener("pointerleave", hideNodePreview);
    group.addEventListener("keydown", (event) => {
      if (event.key === "Enter" || event.key === " ") {
        event.preventDefault();
        updateDetail(node);
      }
    });
    let dragging = false;
    const move = (event) => {
      if (!dragging) return;
      const rect = svg.getBoundingClientRect();
      node.x = Math.max(node.r + 4, Math.min(width - node.r - 4, event.clientX - rect.left));
      node.y = Math.max(node.r + 4, Math.min(height - node.r - 4, event.clientY - rect.top));
      node.vx = 0;
      node.vy = 0;
      render();
    };
    group.addEventListener("pointerdown", (event) => {
      dragging = true;
      group.setPointerCapture?.(event.pointerId);
      updateDetail(node);
    });
    group.addEventListener("pointermove", move);
    group.addEventListener("pointerup", (event) => {
      dragging = false;
      group.releasePointerCapture?.(event.pointerId);
    });
    group.addEventListener("pointercancel", () => {
      dragging = false;
    });
    return { node, group, text };
  });


  function tick() {
    const c = center();
    for (const node of nodes) {
      const mode = layoutMode();
      const target = mode === "force" ? c : layoutTarget(node);
      const pull = mode === "force"
        ? (node.type === "paper" ? 0.008 : 0.004)
        : (node.type === "paper" ? 0.045 : 0.035);
      node.vx += (target.x - node.x) * pull;
      node.vy += (target.y - node.y) * pull;
    }
    for (const edge of edges) {
      const source = nodeById.get(edge.source);
      const target = nodeById.get(edge.target);
      if (!source || !target) continue;
      const dx = target.x - source.x;
      const dy = target.y - source.y;
      const distance = Math.max(1, Math.hypot(dx, dy));
      const desired = edge.type === "cites" ? 110 : 92;
      const force = (distance - desired) * (layoutMode() === "force" ? 0.004 : 0.0016) * Math.max(0.6, Number(edge.weight || 1));
      const fx = (dx / distance) * force;
      const fy = (dy / distance) * force;
      source.vx += fx;
      source.vy += fy;
      target.vx -= fx;
      target.vy -= fy;
    }
    for (let i = 0; i < nodes.length; i++) {
      for (let j = i + 1; j < nodes.length; j++) {
        const a = nodes[i];
        const b = nodes[j];
        const dx = b.x - a.x;
        const dy = b.y - a.y;
        const distance = Math.max(1, Math.hypot(dx, dy));
        const minDistance = a.r + b.r + 34;
        if (distance >= minDistance) continue;
        const force = (minDistance - distance) * 0.018;
        const fx = (dx / distance) * force;
        const fy = (dy / distance) * force;
        a.vx -= fx;
        a.vy -= fy;
        b.vx += fx;
        b.vy += fy;
      }
    }
    for (const node of nodes) {
      node.vx *= 0.78;
      node.vy *= 0.78;
      node.x = Math.max(node.r + 6, Math.min(width - node.r - 6, node.x + node.vx));
      node.y = Math.max(node.r + 6, Math.min(height - node.r - 6, node.y + node.vy));
    }
  }

  function graphFocusSets() {
    const term = currentSearchTerm();
    const matching = new Set();
    const neighbors = new Set();
    if (term) {
      nodes.forEach((node) => {
        if (node.searchText.includes(term)) matching.add(node.id);
      });
      edges.forEach((edge) => {
        if (matching.has(edge.source)) neighbors.add(edge.target);
        if (matching.has(edge.target)) neighbors.add(edge.source);
      });
    }
    return { term, matching, neighbors };
  }

  function shouldShowNode(node, focus = graphFocusSets()) {
    if (!nodeTypeVisible(node)) return false;
    if (!isInSelectedNeighborhood(node)) return false;
    if (!focus.term) return true;
    return focus.matching.has(node.id) || focus.neighbors.has(node.id);
  }

  function shouldShowEdge(edge, focus = graphFocusSets()) {
    const source = nodeById.get(edge.source);
    const target = nodeById.get(edge.target);
    if (!source || !target || !edgeTypeVisible(edge)) return false;
    if (!shouldShowNode(source, focus) || !shouldShowNode(target, focus)) return false;
    if (!focus.term) return true;
    return focus.matching.has(edge.source) || focus.matching.has(edge.target);
  }

  function shouldShowLabel(node, focus) {
    const mode = labelModeSelect?.value || (labelButton?.getAttribute("aria-pressed") === "false" ? "off" : "important");
    if (mode === "off") return false;
    if (mode === "all") return true;
    if (mode === "selected") return selectedState.node?.id === node.id || focus.matching.has(node.id);
    return node.type === "paper" || node.degree >= 3 || focus.matching.has(node.id) || selectedState.node?.id === node.id;
  }

  function updateGraphState() {
    const focus = graphFocusSets();
    let visibleNodeCount = 0;
    let visibleEdgeCount = 0;
    edgeElements.forEach(({ edge, line }) => {
      const show = shouldShowEdge(edge, focus);
      line.hidden = !show;
      line.classList.toggle("is-highlighted", Boolean(focus.term && (focus.matching.has(edge.source) || focus.matching.has(edge.target))));
      if (show) visibleEdgeCount++;
    });
    nodeElements.forEach(({ node, group, text }) => {
      const show = shouldShowNode(node, focus);
      const matched = focus.matching.has(node.id);
      const neighbor = focus.neighbors.has(node.id);
      group.hidden = !show;
      group.classList.toggle("is-match", matched);
      group.classList.toggle("is-neighbor", Boolean(focus.term && neighbor && !matched));
      group.classList.toggle("is-dimmed", Boolean(focus.term && show && !matched && !neighbor));
      text.style.display = show && shouldShowLabel(node, focus) ? "" : "none";
      if (show) visibleNodeCount++;
    });
    if (insight) {
      const topology = layoutLabels[layoutMode()] || "Network";
      const focusLabel = selectedState.focusNodeId ? " Focused on one node neighborhood." : "";
      const clusterLabel = clusteringVisible() ? "" : " Clustering nodes hidden.";
      insight.textContent = focus.term
        ? `${topology}: ${visibleNodeCount} nodes and ${visibleEdgeCount} links around ${focus.matching.size} direct match${focus.matching.size === 1 ? "" : "es"}.${focusLabel}${clusterLabel}`
        : `${topology}: showing ${visibleNodeCount} nodes and ${visibleEdgeCount} links in the current graph.${focusLabel}${clusterLabel}`;
    }
  }

  function render() {
    svg.setAttribute("viewBox", `0 0 ${width} ${height}`);
    edgeElements.forEach(({ edge, line }) => {
      const source = nodeById.get(edge.source);
      const target = nodeById.get(edge.target);
      if (!source || !target) return;
      line.setAttribute("x1", source.x.toFixed(1));
      line.setAttribute("y1", source.y.toFixed(1));
      line.setAttribute("x2", target.x.toFixed(1));
      line.setAttribute("y2", target.y.toFixed(1));
    });
    nodeElements.forEach(({ node, group }) => {
      group.setAttribute("transform", `translate(${node.x.toFixed(1)} ${node.y.toFixed(1)})`);
    });
    updateGraphState();
  }

  function fitGraph() {
    ({ width, height } = dimensions());
    const minX = Math.min(...nodes.map((node) => node.x));
    const maxX = Math.max(...nodes.map((node) => node.x));
    const minY = Math.min(...nodes.map((node) => node.y));
    const maxY = Math.max(...nodes.map((node) => node.y));
    const graphWidth = Math.max(1, maxX - minX);
    const graphHeight = Math.max(1, maxY - minY);
    const scale = Math.min((width - 64) / graphWidth, (height - 64) / graphHeight, 1.8);
    nodes.forEach((node) => {
      node.x = 32 + (node.x - minX) * scale + Math.max(0, (width - 64 - graphWidth * scale) / 2);
      node.y = 32 + (node.y - minY) * scale + Math.max(0, (height - 64 - graphHeight * scale) / 2);
    });
    render();
  }

  applyLayoutMode(false);
  fitGraph();
  updateDetail(nodes.find((node) => node.id === selectedState.focusNodeId) || nodes.find((node) => node.type === "paper") || nodes[0]);

  fitButton?.addEventListener("click", fitGraph);
  labelButton?.addEventListener("click", () => {
    const modes = ["important", "all", "selected", "off"];
    const current = labelModeSelect?.value || "important";
    const next = modes[(modes.indexOf(current) + 1) % modes.length] || "important";
    if (labelModeSelect) labelModeSelect.value = next;
    labelButton.setAttribute("aria-pressed", next === "off" ? "false" : "true");
    labelButton.querySelector("span").textContent = next === "off" ? "Labels off" : `Labels: ${next}`;
    updateGraphState();
  });
  labelModeSelect?.addEventListener("change", () => {
    const mode = labelModeSelect.value || "important";
    labelButton?.setAttribute("aria-pressed", mode === "off" ? "false" : "true");
    const label = labelButton?.querySelector("span");
    if (label) label.textContent = mode === "off" ? "Labels off" : `Labels: ${mode}`;
    updateSavedViewUrl();
    updateGraphState();
  });
  layoutModeSelect?.addEventListener("change", () => {
    updateSavedViewUrl();
    applyLayoutMode(true);
    fitGraph();
    showAppToast(`Network topology: ${layoutLabels[layoutMode()] || "updated"}.`);
  });
  clusterToggle?.addEventListener("change", () => {
    updateSavedViewUrl();
    updateGraphState();
    showAppToast(clusteringVisible() ? "Clustering nodes shown." : "Clustering nodes hidden.");
  });
  searchInput?.addEventListener("input", updateGraphState);
  seedLimitSelect?.addEventListener("change", () => {
    applySeedLimit(seedLimitSelect.value || "1");
  });
  seedPresetButtons.forEach((button) => {
    button.addEventListener("click", () => applySeedLimit(button.dataset.citationSeedPreset || "1"));
  });
  copySelectedButton?.addEventListener("click", async () => {
    const text = selectedNodeText();
    if (!text) {
      showAppToast("Select a network node before copying context.");
      return;
    }
    try {
      await navigator.clipboard.writeText(text);
      showAppToast("Selected network context copied.");
    } catch (error) {
      window.prompt("Copy selected network context:", text);
    }
  });
  focusSelectedButton?.addEventListener("click", () => {
    focusSelectedNode();
    if (selectedState.node) updateDetail(selectedState.node);
  });
  shareViewButton?.addEventListener("click", async () => {
    const url = updateSavedViewUrl().href;
    try {
      await navigator.clipboard.writeText(url);
      showAppToast("Shareable network view URL copied.");
    } catch (error) {
      window.prompt("Copy network view URL:", url);
    }
  });
  exportJsonButton?.addEventListener("click", () => {
    const snapshot = visibleGraphSnapshot();
    downloadCitationGraphFile(
      "psilocybin-citation-network.json",
      JSON.stringify({
        generated_at: new Date().toISOString(),
        page_url: window.location.href,
        visible_nodes: snapshot.nodes.length,
        visible_edges: snapshot.edges.length,
        search: snapshot.focus.term,
        nodes: snapshot.nodes,
        edges: snapshot.edges,
      }, null, 2),
      "application/json"
    );
    showAppToast("Visible citation network JSON exported.");
  });
  exportSubgraphButton?.addEventListener("click", () => {
    const snapshot = visibleGraphSnapshot(true);
    downloadCitationGraphFile(
      "psilocybin-citation-network-focused-subgraph.json",
      JSON.stringify({
        generated_at: new Date().toISOString(),
        page_url: updateSavedViewUrl().href,
        selected_node: selectedState.node,
        focus_node_id: selectedState.focusNodeId || selectedState.node?.id || "",
        visible_nodes: snapshot.nodes.length,
        visible_edges: snapshot.edges.length,
        nodes: snapshot.nodes,
        edges: snapshot.edges,
      }, null, 2),
      "application/json"
    );
    showAppToast("Focused citation subgraph JSON exported.");
  });
  exportCsvButton?.addEventListener("click", () => {
    const snapshot = visibleGraphSnapshot();
    const nodeRows = [
      ["record_type", "id", "type", "label", "authors", "journal", "date", "doi", "pubmed_id", "source", "status", "url"].map(csvCell).join(","),
      ...snapshot.nodes.map((node) => [
        "node", node.id, node.type, node.label, node.authors, node.journal, node.date, nodeDoi(node), node.pubmed_id, node.source, node.status, node.url ? new URL(node.url, window.location.href).href : "",
      ].map(csvCell).join(",")),
      "",
      ["record_type", "id", "type", "source", "target", "weight"].map(csvCell).join(","),
      ...snapshot.edges.map((edge) => ["edge", edge.id, edge.type, edge.source, edge.target, edge.weight].map(csvCell).join(",")),
    ].join("\n");
    downloadCitationGraphFile("psilocybin-citation-network.csv", nodeRows, "text/csv");
    showAppToast("Visible citation network CSV exported.");
  });
  clearSearchButton?.addEventListener("click", () => {
    if (searchInput) searchInput.value = "";
    nodeTypeInputs.forEach((input) => input.checked = true);
    edgeTypeInputs.forEach((input) => input.checked = true);
    selectedState.focusNodeId = "";
    if (clusterToggle) clusterToggle.checked = true;
    updateSavedViewUrl();
    updateGraphState();
    showAppToast("Citation network focus cleared.");
  });
  [...nodeTypeInputs, ...edgeTypeInputs].forEach((input) => input.addEventListener("change", updateGraphState));
  window.addEventListener("resize", () => {
    ({ width, height } = dimensions());
    applyLayoutMode(layoutMode() !== "force");
    fitGraph();
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

function initPublicationTracker() {
  if (document.documentElement.dataset.publicationTrackerInitialized === "1") return;
  document.documentElement.dataset.publicationTrackerInitialized = "1";
  initPwa();
  initAppSheets();
  initSectionNavigation();
  initSettingsMenus();
  initSidebarControls();
  initNavSidebarCollapse();
  initFullscreenToggle();
  initPrintResults();
  initNativeShare();
  initEntryChoices();
  initAjaxSearch();
  initScrollProgress();
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
  initCitationNetwork();
  initAlertVanta();
  initDownloadConfirmations();
  initApiConfirmations();
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", initPublicationTracker, { once: true });
} else {
  initPublicationTracker();
}
