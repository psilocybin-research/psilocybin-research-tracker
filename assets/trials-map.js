(() => {
  "use strict";

  const chartModuleUrl = new URL('./chart-tools.mjs', document.currentScript.src);
  chartModuleUrl.search = new URL(document.currentScript.src).search;
  let chartToolsRequest;
  const chartTools = () => chartToolsRequest ||= import(chartModuleUrl.href).catch(error => { chartToolsRequest = null; throw error; });
  const chartRevisions = new WeakMap();
  const chartKeys = new WeakMap();

  const dataNode = document.querySelector("#trials-map-data");
  const mapHost = document.querySelector("[data-trial-map]");
  if (!dataNode || !mapHost) return;

  let dataset;
  try {
    dataset = JSON.parse(dataNode.textContent || "{}");
  } catch {
    mapHost.innerHTML = '<div class="trial-map-empty">The trial geography could not be loaded.</div>';
    return;
  }

  const trials = Array.isArray(dataset.trials) ? dataset.trials : [];
  const statusLabels = dataset.facets?.status_labels || {};
  const openStatuses = new Set(["RECRUITING", "NOT_YET_RECRUITING", "ENROLLING_BY_INVITATION"]);
  const hiddenStatuses = new Set();
  const activeStatuses = new Set(["ACTIVE_NOT_RECRUITING"]);
  const completedStatuses = new Set(["COMPLETED"]);
  const $ = (selector) => document.querySelector(selector);
  const controls = {
    search: $("[data-trial-search]"),
    status: $("[data-trial-status]"),
    country: $("[data-trial-country]"),
    recruiting: $("[data-trial-recruiting]"),
    phase: $("[data-trial-phase]"),
    condition: $("[data-trial-condition]"),
    intervention: $("[data-trial-intervention]"),
    substance: $("[data-trial-substance]"),
    from: $("[data-trial-year-from]"),
    to: $("[data-trial-year-to]"),
    sort: $("[data-trial-sort]"),
  };
  const profile = $("[data-trial-profile]");
  const resultsList = $("[data-trial-results-list]");
  const resultsCount = $("[data-trial-results-count]");
  const resultsContext = $("[data-trial-results-context]");
  const mapSummary = $("[data-trial-map-summary]");
  const mapInsight = $("[data-trial-map-insight]");
  const selectedSiteNode = $("[data-trial-selected-site]");
  const loadMore = $("[data-trial-load-more]");
  const filterCount = $("[data-trial-filter-count]");
  const mobileDialog = $("[data-trial-mobile-dialog]");
  const mobileDialogTitle = $("[data-trial-mobile-title]");
  const mobileDialogContent = $("[data-trial-mobile-content]");
  const state = {
    selectedSite: "",
    selectedTrial: "",
    visibleLimit: 30,
    viewBox: { x: 0, y: 0, width: 1000, height: 500 },
    filtered: [],
    markerIndex: new Map(),
  };
  let svg;
  let markerLayer;
  let tooltip;
  let mapReady = false;
  let searchTimer;
  let suppressMapClickUntil = 0;

  const escapeHtml = (value) => String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");

  const textLabel = (value) => String(value || "")
    .toLowerCase()
    .replaceAll("_", " ")
    .replace(/\b\w/g, (letter) => letter.toUpperCase());
  const phaseLabel = (value) => String(value || "") === "NA" ? "Not applicable" : textLabel(value).replace(/Phase(\d)/, "Phase $1");
  const statusLabel = (status) => statusLabels[status] || textLabel(status) || "Unknown status";
  const statusGroup = (status) => openStatuses.has(status) ? "open" : activeStatuses.has(status) ? "active" : completedStatuses.has(status) ? "complete" : "stopped";
  const dateLabel = (value) => {
    if (!value) return "Not reported";
    const parts = String(value).split("-");
    if (parts.length === 2) return `${parts[0]}-${parts[1]}`;
    const date = new Date(`${value}T00:00:00Z`);
    return Number.isNaN(date.getTime()) ? String(value) : new Intl.DateTimeFormat(undefined, { year: "numeric", month: "short", day: "numeric", timeZone: "UTC" }).format(date);
  };
  const numberLabel = (value) => Number.isFinite(Number(value)) ? new Intl.NumberFormat().format(Number(value)) : "Not reported";
  const trialCountries = (trial) => [...new Set([...(trial.countries || []), ...(trial.sites || []).map(site => site.country)].filter(Boolean))];
  const trialSearchText = (trial) => [
    trial.nct_id, trial.title, trial.sponsor, trial.status_label,
    ...(trial.conditions || []), ...(trial.substances || []),
    ...(trial.interventions || []).flatMap((item) => [item.name, item.type]),
    ...(trial.sites || []).flatMap((site) => [site.facility, site.city, site.region, site.country]),
  ].join(" ").toLocaleLowerCase();

  function addOptions(select, values, labeler = (value) => value) {
    if (!select) return;
    [...new Set(values.filter(Boolean))]
      .sort((a, b) => labeler(a).localeCompare(labeler(b)))
      .forEach((value) => {
        const option = document.createElement("option");
        option.value = value;
        option.textContent = labeler(value);
        select.append(option);
      });
  }

  function populateControls() {
    addOptions(controls.status, Object.keys(dataset.facets?.statuses || {}), statusLabel);
    addOptions(controls.country, Object.keys(dataset.facets?.countries || {}));
    addOptions(controls.phase, Object.keys(dataset.facets?.phases || {}), phaseLabel);
    addOptions(controls.condition, Object.keys(dataset.facets?.conditions || {}));
    addOptions(controls.intervention, Object.keys(dataset.facets?.interventions || {}));
    addOptions(controls.substance, trials.flatMap((trial) => trial.substances || []), textLabel);
  }

  function restoreStateFromUrl() {
    const params = new URLSearchParams(window.location.search);
    const mappings = { q: "search", status: "status", country: "country", phase: "phase", condition: "condition", intervention: "intervention", substance: "substance", from: "from", to: "to", sort: "sort" };
    Object.entries(mappings).forEach(([param, key]) => {
      const value = params.get(param) || "";
      if (!controls[key]) return;
      if (controls[key].tagName === "SELECT" && value && ![...controls[key].options].some((option) => option.value === value)) return;
      controls[key].value = value;
    });
    if (controls.recruiting) controls.recruiting.checked = params.get("recruiting") === "1";
    (params.get("hidden_statuses") || "").split(",").filter((type) => ["open", "active", "complete", "stopped"].includes(type)).forEach((type) => hiddenStatuses.add(type));
    state.selectedSite = params.get("site") || "";
    state.selectedTrial = params.get("trial") || "";
  }

  function currentFilters() {
    return {
      q: controls.search?.value.trim().toLocaleLowerCase() || "",
      status: controls.status?.value || "",
      country: controls.country?.value || "",
      recruiting: Boolean(controls.recruiting?.checked),
      phase: controls.phase?.value || "",
      condition: controls.condition?.value || "",
      intervention: controls.intervention?.value || "",
      substance: controls.substance?.value || "",
      from: Number(controls.from?.value) || null,
      to: Number(controls.to?.value) || null,
      sort: controls.sort?.value || "relevance",
    };
  }

  function filteredTrials() {
    const filters = currentFilters();
    const rows = trials.filter((trial) => {
      if (hiddenStatuses.has(statusGroup(trial.status))) return false;
      if (filters.q && !trialSearchText(trial).includes(filters.q)) return false;
      if (filters.status && trial.status !== filters.status) return false;
      if (filters.country && !trialCountries(trial).includes(filters.country)) return false;
      if (filters.recruiting && !openStatuses.has(trial.status)) return false;
      if (filters.phase && !(trial.phases || []).includes(filters.phase)) return false;
      if (filters.condition && !(trial.conditions || []).includes(filters.condition)) return false;
      if (filters.intervention && !(trial.interventions || []).some((item) => item.name === filters.intervention)) return false;
      if (filters.substance && !(trial.substances || []).includes(filters.substance)) return false;
      const startYear = Number(String(trial.start_date || "").slice(0, 4)) || null;
      if (filters.from && (!startYear || startYear < filters.from)) return false;
      if (filters.to && (!startYear || startYear > filters.to)) return false;
      if (state.selectedSite && !(trial.sites || []).some((site) => site.key === state.selectedSite)) return false;
      return true;
    });
    const statusRank = { RECRUITING: 0, NOT_YET_RECRUITING: 1, ENROLLING_BY_INVITATION: 2, ACTIVE_NOT_RECRUITING: 3, COMPLETED: 4, SUSPENDED: 5, TERMINATED: 6, WITHDRAWN: 7, UNKNOWN: 8 };
    rows.sort((a, b) => {
      if (filters.sort === "updated") return String(b.last_update_date || "").localeCompare(String(a.last_update_date || ""));
      if (filters.sort === "start") return String(b.start_date || "").localeCompare(String(a.start_date || ""));
      if (filters.sort === "enrollment") return Number(b.enrollment?.count || 0) - Number(a.enrollment?.count || 0);
      if (filters.sort === "title") return String(a.title).localeCompare(String(b.title));
      return (statusRank[a.status] ?? 9) - (statusRank[b.status] ?? 9) || String(b.last_update_date || "").localeCompare(String(a.last_update_date || ""));
    });
    return rows;
  }

  function siteAggregates(rows) {
    const sites = new Map();
    rows.forEach((trial) => (trial.sites || []).forEach((site) => {
      if (!sites.has(site.key)) sites.set(site.key, { ...site, trials: [] });
      sites.get(site.key).trials.push(trial);
    }));
    return [...sites.values()];
  }

  function activeAdvancedCount() {
    const filters = currentFilters();
    return [filters.phase, filters.condition, filters.intervention, filters.substance, filters.from, filters.to].filter(Boolean).length;
  }

  function updateUrl() {
    const filters = currentFilters();
    const params = new URLSearchParams();
    if (document.body.classList.contains('is-embedded')) params.set('embed', '1');
    if (filters.q) params.set("q", controls.search.value.trim());
    ["status", "country", "phase", "condition", "intervention", "substance"].forEach((key) => { if (filters[key]) params.set(key, filters[key]); });
    if (filters.recruiting) params.set("recruiting", "1");
    if (filters.from) params.set("from", String(filters.from));
    if (filters.to) params.set("to", String(filters.to));
    if (filters.sort !== "relevance") params.set("sort", filters.sort);
    if (state.selectedSite) params.set("site", state.selectedSite);
    if (state.selectedTrial) params.set("trial", state.selectedTrial);
    if (hiddenStatuses.size) params.set("hidden_statuses", [...hiddenStatuses].sort().join(","));
    const query = params.toString();
    window.history.replaceState({ trialsMap: true }, "", `${window.location.pathname}${query ? `?${query}` : ""}${window.location.hash}`);
  }

  async function barChart(host, entries, total, labeler = (value) => value) {
    if (!host) return;
    const revision = (chartRevisions.get(host) || 0) + 1;
    chartRevisions.set(host, revision);
    const top = entries.slice(0, 4);
    const key = JSON.stringify([top, total]);
    let tools;
    try { tools = await chartTools(); } catch {
      host.textContent = top.map(([label, count]) => `${labeler(label)}: ${numberLabel(count)}`).join('; ') || 'No data in this view';
      return;
    }
    if (chartRevisions.get(host) !== revision) return;
    if (chartKeys.get(host) === key && host.querySelector("canvas")) return;
    if (!top.length) { tools.clearChart(host); host.innerHTML = '<p class="trial-mini-empty">No data in this view</p>'; return; }
    const heading = host.previousElementSibling?.textContent || 'Indexed trial counts';
    const labels = top.map(([label]) => labeler(label));
    const values = top.map(([,count]) => count);
    const chart = await tools.mountChart(host, {
      type: 'bar', data: { labels, datasets: [{ label: 'Indexed trial records', data: values, backgroundColor: '#1a6b54', borderRadius: 3, maxBarThickness: 22 }] },
      options: { indexAxis: 'y',
        scales: { x: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#d8d2c466' } }, y: { grid: { display: false }, ticks: { font: { size: 10 }, callback(value) { const text = this.getLabelForValue(value); return text.length > 24 ? (text.match(/.{1,22}(?:\s|$)|.{1,22}/g) || [text]).map(s => s.trim()) : text; } } } },
        plugins: { legend: { display: false }, tooltip: { callbacks: { afterLabel: item => `${total ? Math.round(values[item.dataIndex] / total * 100) : 0}% of matching trial records` } } },
      },
    }, { height: Math.max(170, labels.length * 42 + 25), label: heading, headers: ['Category', 'Trial records', 'Share'], rows: labels.map((label,i) => [label, values[i], total ? Math.round(values[i] / total * 100) + '%' : '0%']), filename: 'psilocybin-trial-map-' + (host.hasAttribute('data-trial-phase-bars') ? 'phases' : host.hasAttribute('data-trial-country-bars') ? 'countries' : 'status') + '.png' });
    if (chart && chartRevisions.get(host) === revision) chartKeys.set(host, key);
  }

  function renderResearchLens(rows) {
    const counts = (values) => values.reduce((map, value) => value ? map.set(value, (map.get(value) || 0) + 1) : map, new Map());
    const ranked = (map) => [...map.entries()].sort((a, b) => b[1] - a[1] || String(a[0]).localeCompare(String(b[0])));
    const statuses = counts(rows.map((trial) => trial.status));
    const phases = counts(rows.flatMap((trial) => trial.phases?.length ? trial.phases : ["UNSPECIFIED"]));
    const countries = counts(rows.flatMap(trialCountries));
    barChart($("[data-trial-status-bars]"), ranked(statuses), rows.length, statusLabel);
    barChart($("[data-trial-phase-bars]"), ranked(phases), rows.length, (value) => value === "UNSPECIFIED" ? "Phase not specified" : phaseLabel(value));
    barChart($("[data-trial-country-bars]"), ranked(countries), rows.length);
    const narrative = $("[data-trial-narrative]");
    if (!narrative) return;
    if (!rows.length) {
      narrative.textContent = "No trials match this combination. Remove one filter to broaden the comparison.";
      return;
    }
    const open = rows.filter((trial) => openStatuses.has(trial.status)).length;
    const mapped = rows.filter((trial) => trial.sites?.length).length;
    const topCountry = ranked(countries)[0];
    const topPhase = ranked(phases)[0];
    narrative.innerHTML = `<strong>${escapeHtml(numberLabel(rows.length))}</strong> trials match. <strong>${escapeHtml(numberLabel(open))}</strong> are open or upcoming and <strong>${escapeHtml(numberLabel(mapped))}</strong> report mappable sites.${topCountry ? ` ${escapeHtml(topCountry[0])} appears most often (${escapeHtml(numberLabel(topCountry[1]))} trials).` : ""}${topPhase ? ` The most common phase classification is ${escapeHtml(topPhase[0] === "UNSPECIFIED" ? "not specified" : phaseLabel(topPhase[0]))}.` : ""}`;
  }

  function trialCard(trial) {
    const countries = trialCountries(trial);
    const location = countries.length ? `${countries.slice(0, 2).join(", ")}${countries.length > 2 ? ` +${countries.length - 2}` : ""}` : "No mapped site";
    const intervention = (trial.interventions || []).slice(0, 2).map((item) => item.name).join(" · ") || "Intervention not reported";
    return `<article class="trial-record ${state.selectedTrial === trial.nct_id ? "is-selected" : ""}" data-trial-id="${escapeHtml(trial.nct_id)}">
      <div class="trial-record-topline"><span class="trial-status status-${statusGroup(trial.status)}">${escapeHtml(trial.status_label)}</span><span>${escapeHtml(trial.nct_id)}</span><span>Updated ${escapeHtml(dateLabel(trial.last_update_date))}</span></div>
      <button type="button" class="trial-record-select" data-trial-select="${escapeHtml(trial.nct_id)}"><strong>${escapeHtml(trial.title)}</strong></button>
      <p>${escapeHtml(trial.sponsor || "Sponsor not reported")}</p>
      <dl><div><dt>Phase</dt><dd>${escapeHtml((trial.phases || []).map(phaseLabel).join(", ") || "Not specified")}</dd></div><div><dt>Enrollment</dt><dd>${escapeHtml(numberLabel(trial.enrollment?.count))}</dd></div><div><dt>Intervention</dt><dd>${escapeHtml(intervention)}</dd></div><div><dt>Geography</dt><dd>${escapeHtml(location)}</dd></div></dl>
      <div class="trial-record-tags">${(trial.conditions || []).slice(0, 3).map((condition) => `<span>${escapeHtml(condition)}</span>`).join("")}</div>
    </article>`;
  }

  function renderResults(rows) {
    if (!resultsList) return;
    const visible = rows.slice(0, state.visibleLimit);
    resultsList.innerHTML = visible.length ? visible.map(trialCard).join("") : `<div class="trial-results-empty"><i data-icon="search" aria-hidden="true"></i><h3>No matching trials</h3><p>Try clearing the selected site or broadening one filter.</p><button class="secondary" type="button" data-trial-empty-reset>Reset the view</button></div>`;
    resultsCount.textContent = `${numberLabel(rows.length)} trial${rows.length === 1 ? "" : "s"}`;
    const mapped = rows.filter((trial) => trial.sites?.length).length;
    resultsContext.textContent = `${numberLabel(mapped)} with mapped sites · registry facts only; descriptions and raw payloads are excluded.`;
    if (loadMore) {
      loadMore.hidden = visible.length >= rows.length;
      loadMore.textContent = `Show ${numberLabel(Math.min(30, rows.length - visible.length))} more of ${numberLabel(rows.length)}`;
    }
    resultsList.querySelectorAll("[data-trial-select]").forEach((button) => button.addEventListener("click", () => selectTrial(button.dataset.trialSelect || "", true)));
    resultsList.querySelector("[data-trial-empty-reset]")?.addEventListener("click", resetView);
    if (typeof window.refreshIcons === "function") window.refreshIcons();
  }

  function designValue(value) {
    return value ? textLabel(value) : "Not reported";
  }

  function renderProfile(trial) {
    if (!profile) return;
    if (!trial) {
      profile.innerHTML = `<span class="eyebrow">2 · Inspect a study</span><h2>Choose a site or trial</h2><p>Select a map marker or record to inspect design and timing.</p><p class="trial-profile-tip"><i data-icon="info" aria-hidden="true"></i><span>Use the research lens to compare the current set before opening a registry record.</span></p>`;
      if (typeof window.refreshIcons === "function") window.refreshIcons();
      return;
    }
    const sites = (trial.sites || []).slice(0, 8);
    const conditions = (trial.conditions || []).map((value) => `<span>${escapeHtml(value)}</span>`).join("");
    profile.innerHTML = `<span class="eyebrow">2 · Inspect a study</span>
      <div class="trial-profile-status"><span class="trial-status status-${statusGroup(trial.status)}">${escapeHtml(trial.status_label)}</span><span>${escapeHtml(trial.nct_id)}</span></div>
      <h2>${escapeHtml(trial.title)}</h2>
      <p class="trial-profile-sponsor">${escapeHtml(trial.sponsor || "Sponsor not reported")}</p>
      <dl class="trial-profile-facts">
        <div><dt>Phase</dt><dd>${escapeHtml((trial.phases || []).map(phaseLabel).join(", ") || "Not specified")}</dd></div>
        <div><dt>Enrollment</dt><dd>${escapeHtml(numberLabel(trial.enrollment?.count))}${trial.enrollment?.type ? ` <small>${escapeHtml(textLabel(trial.enrollment.type))}</small>` : ""}</dd></div>
        <div><dt>Start</dt><dd>${escapeHtml(dateLabel(trial.start_date))}</dd></div>
        <div><dt>Primary completion</dt><dd>${escapeHtml(dateLabel(trial.primary_completion_date))}</dd></div>
        <div><dt>Purpose</dt><dd>${escapeHtml(designValue(trial.design?.primary_purpose))}</dd></div>
        <div><dt>Allocation</dt><dd>${escapeHtml(designValue(trial.design?.allocation))}</dd></div>
        <div><dt>Model</dt><dd>${escapeHtml(designValue(trial.design?.intervention_model))}</dd></div>
        <div><dt>Masking</dt><dd>${escapeHtml(designValue(trial.design?.masking))}</dd></div>
      </dl>
      ${conditions ? `<div class="trial-profile-tags" aria-label="Conditions">${conditions}</div>` : ""}
      <div class="trial-profile-section"><h3>Interventions</h3><ul>${(trial.interventions || []).map((item) => `<li><strong>${escapeHtml(item.name)}</strong>${item.type ? `<span>${escapeHtml(textLabel(item.type))}</span>` : ""}</li>`).join("") || "<li>Not reported</li>"}</ul></div>
      <details class="trial-profile-sites" ${state.selectedSite ? "open" : ""}><summary>${numberLabel(trial.sites?.length || 0)} mapped site${trial.sites?.length === 1 ? "" : "s"}</summary><ul>${sites.map((site) => `<li><strong>${escapeHtml(site.facility || site.city || "Study site")}</strong><span>${escapeHtml([site.city, site.region, site.country].filter(Boolean).join(", "))}</span></li>`).join("") || "<li>No coordinate-bearing site reported.</li>"}${trial.sites?.length > sites.length ? `<li><span>+${trial.sites.length - sites.length} more in the registry record</span></li>` : ""}</ul></details>
      <div class="trial-profile-actions"><a class="primary" href="${escapeHtml(trial.registry_url)}" target="_blank" rel="noopener">Open registry record <i data-icon="external-link" aria-hidden="true"></i></a><a class="secondary" href="${escapeHtml(trial.tracker_url)}">Database record</a></div>`;
    if (typeof window.refreshIcons === "function") window.refreshIcons();
  }

  const usesMobileMapSheet = () => window.matchMedia("(max-width: 900px), (pointer: coarse)").matches;

  function syncMobileDialogState(open) {
    document.documentElement.classList.toggle("modal-open", open);
    document.body.classList.toggle("modal-open", open);
  }

  function closeMobileSiteDialog() {
    if (!mobileDialog) return;
    if (typeof mobileDialog.close === "function" && mobileDialog.open) mobileDialog.close();
    else mobileDialog.removeAttribute("open");
    syncMobileDialogState(false);
  }

  function openMobileSiteDialog(site) {
    if (!mobileDialog || !mobileDialogContent || !mobileDialogTitle || !usesMobileMapSheet()) return;
    const siteTrials = Array.isArray(site.trials) ? site.trials : [];
    const selectedTrial = siteTrials.find((trial) => trial.nct_id === state.selectedTrial) || siteTrials[0];
    if (!selectedTrial) return;
    state.selectedTrial = selectedTrial.nct_id;
    renderProfile(selectedTrial);
    updateMarkerSelection(selectedTrial);
    updateUrl();
    const location = [site.city, site.region, site.country].filter(Boolean).join(", ");
    mobileDialogTitle.textContent = site.facility || site.city || "Research site";
    mobileDialogContent.innerHTML = `
      <p class="trial-map-mobile-location"><i data-icon="map-pin" aria-hidden="true"></i><span>${escapeHtml(location || "Location not reported")} · ${numberLabel(siteTrials.length)} trial${siteTrials.length === 1 ? "" : "s"}</span></p>
      ${siteTrials.length > 1 ? `<div class="trial-map-mobile-trials" aria-label="Trials registered at this site">${siteTrials.slice(0, 12).map((trial) => `<button type="button" class="${trial.nct_id === selectedTrial.nct_id ? "is-active" : ""}" data-trial-mobile-select="${escapeHtml(trial.nct_id)}"><span class="trial-status status-${statusGroup(trial.status)}">${escapeHtml(trial.status_label)}</span><strong>${escapeHtml(trial.title)}</strong><small>${escapeHtml(trial.nct_id)}${trial.phases?.length ? ` · ${escapeHtml(trial.phases.map(phaseLabel).join(", "))}` : ""}</small></button>`).join("")}</div>` : ""}
      <div class="trial-map-mobile-profile">${profile?.innerHTML || ""}</div>`;
    mobileDialogContent.querySelectorAll("[data-trial-mobile-select]").forEach((button) => {
      button.addEventListener("click", () => {
        const nctId = button.dataset.trialMobileSelect || "";
        selectTrial(nctId, false);
        openMobileSiteDialog(site);
      });
    });
    if (!mobileDialog.open) {
      if (typeof mobileDialog.showModal === "function") mobileDialog.showModal();
      else mobileDialog.setAttribute("open", "");
    }
    syncMobileDialogState(true);
    if (typeof window.refreshIcons === "function") window.refreshIcons();
    window.setTimeout(() => mobileDialog.querySelector("[data-trial-mobile-close]")?.focus({ preventScroll: true }), 20);
  }

  function selectTrial(nctId, scrollProfile = false) {
    const trial = trials.find((item) => item.nct_id === nctId);
    if (!trial) return;
    state.selectedTrial = trial.nct_id;
    renderProfile(trial);
    renderResults(state.filtered);
    updateMarkerSelection(trial);
    updateUrl();
    if (scrollProfile && usesMobileMapSheet() && mobileDialog && profile) {
      mobileDialogTitle.textContent = trial.nct_id || "Trial details";
      mobileDialogContent.innerHTML = `<div class="trial-map-mobile-profile">${profile.innerHTML}</div>`;
      if (!mobileDialog.open) mobileDialog.showModal();
      mobileDialogContent.scrollTop = 0;
      syncMobileDialogState(true);
    }
  }

  function updateMarkerSelection(trial) {
    markerLayer?.querySelectorAll(".trial-map-marker").forEach((marker) => marker.classList.remove("is-trial-selected"));
    if (!trial) return;
    (trial.sites || []).forEach((site) => state.markerIndex.get(site.key)?.classList.add("is-trial-selected"));
  }

  function renderSelectedSite(sites) {
    if (!selectedSiteNode) return;
    const site = sites.find((item) => item.key === state.selectedSite);
    if (!site) {
      if (state.selectedSite) state.selectedSite = "";
      selectedSiteNode.hidden = true;
      selectedSiteNode.innerHTML = "";
      return;
    }
    selectedSiteNode.hidden = false;
    selectedSiteNode.innerHTML = `<div><i data-icon="map-pin" aria-hidden="true"></i><span><strong>${escapeHtml(site.facility || site.city || "Selected research site")}</strong><small>${escapeHtml([site.city, site.region, site.country].filter(Boolean).join(", "))} · ${numberLabel(site.trials.length)} trial${site.trials.length === 1 ? "" : "s"}</small></span></div><button type="button" data-trial-clear-site aria-label="Clear selected site"><i data-icon="x" aria-hidden="true"></i></button>`;
    selectedSiteNode.querySelector("[data-trial-clear-site]")?.addEventListener("click", () => { state.selectedSite = ""; state.visibleLimit = 30; renderAll({ fit: false }); });
    if (typeof window.refreshIcons === "function") window.refreshIcons();
  }

  function renderMarkers(rows) {
    if (!markerLayer) return [];
    const sites = siteAggregates(rows);
    markerLayer.replaceChildren();
    state.markerIndex.clear();
    sites.forEach((site) => {
      const x = (site.lon + 180) / 360 * 1000;
      const y = (90 - site.lat) / 180 * 500;
      const groups = site.trials.map((trial) => statusGroup(trial.status));
      const group = groups.includes("open") ? "open" : groups.includes("active") ? "active" : groups.includes("complete") ? "complete" : "stopped";
      const radius = Math.min(19, 5 + Math.sqrt(site.trials.length) * 2.4);
      const marker = document.createElementNS("http://www.w3.org/2000/svg", "g");
      marker.classList.add("trial-map-marker", `status-${group}`);
      if (site.key === state.selectedSite) marker.classList.add("is-selected");
      marker.dataset.mapX = x.toFixed(2);
      marker.dataset.mapY = y.toFixed(2);
      marker.setAttribute("transform", `translate(${marker.dataset.mapX} ${marker.dataset.mapY})`);
      marker.setAttribute("role", "button");
      marker.setAttribute("tabindex", "0");
      marker.setAttribute("aria-label", `${site.facility || site.city || "Research site"}, ${site.country || "country not reported"}: ${site.trials.length} trial${site.trials.length === 1 ? "" : "s"}`);
      marker.innerHTML = `<circle class="trial-marker-halo" r="${Math.max(22, radius + 5).toFixed(1)}"></circle><circle class="trial-marker-core" r="${radius.toFixed(1)}"></circle><text text-anchor="middle" dominant-baseline="central">${site.trials.length}</text>`;
      const choose = () => {
        if (Date.now() < suppressMapClickUntil) return;
        const mobileSheet = usesMobileMapSheet();
        state.selectedSite = mobileSheet ? site.key : state.selectedSite === site.key ? "" : site.key;
        state.selectedTrial = state.selectedSite ? site.trials[0]?.nct_id || "" : state.selectedTrial;
        state.visibleLimit = 30;
        renderAll({ fit: false });
        if (state.selectedTrial) renderProfile(trials.find((trial) => trial.nct_id === state.selectedTrial));
        if (mobileSheet && state.selectedSite) openMobileSiteDialog(site);
      };
      marker.addEventListener("click", choose);
      marker.addEventListener("keydown", (event) => { if (event.key === "Enter" || event.key === " ") { event.preventDefault(); choose(); } });
      marker.addEventListener("pointerenter", (event) => showTooltip(event, site));
      marker.addEventListener("pointermove", (event) => moveTooltip(event));
      marker.addEventListener("pointerleave", hideTooltip);
      markerLayer.append(marker);
      state.markerIndex.set(site.key, marker);
    });
    updateMarkerSelection(trials.find((trial) => trial.nct_id === state.selectedTrial));
    updateMarkerZoomScale();
    return sites;
  }

  function updateMarkerZoomScale() {
    if (!markerLayer) return;
    const zoomLevel = 1000 / Math.max(90, state.viewBox.width);
    // Professional web maps keep markers legible in screen space while the
    // geography expands beneath them. A slight close-zoom reduction prevents
    // dense site markers from covering countries or nearby facilities.
    const screenScale = Math.max(.76, Math.min(1, Math.pow(zoomLevel, -.12)));
    const localScale = screenScale / zoomLevel;
    markerLayer.querySelectorAll(".trial-map-marker").forEach((marker) => {
      const x = marker.dataset.mapX || "0";
      const y = marker.dataset.mapY || "0";
      marker.setAttribute("transform", `translate(${x} ${y}) scale(${localScale.toFixed(3)})`);
    });
    svg?.style.setProperty("--trial-map-zoom", zoomLevel.toFixed(3));
  }

  function showTooltip(event, site) {
    if (!tooltip) return;
    const statuses = [...new Set(site.trials.map((trial) => trial.status_label))];
    tooltip.innerHTML = `<strong>${escapeHtml(site.facility || site.city || "Research site")}</strong><span>${escapeHtml([site.city, site.region, site.country].filter(Boolean).join(", "))}</span><em>${numberLabel(site.trials.length)} trial${site.trials.length === 1 ? "" : "s"} · ${escapeHtml(statuses.slice(0, 2).join(", "))}</em>`;
    tooltip.hidden = false;
    moveTooltip(event);
  }

  function moveTooltip(event) {
    if (!tooltip || tooltip.hidden) return;
    const rect = mapHost.getBoundingClientRect();
    const left = Math.min(rect.width - 250, Math.max(8, event.clientX - rect.left + 14));
    const top = Math.min(rect.height - 110, Math.max(8, event.clientY - rect.top + 14));
    tooltip.style.transform = `translate(${left}px, ${top}px)`;
  }

  function hideTooltip() {
    if (tooltip) tooltip.hidden = true;
  }

  function setViewBox(next) {
    const width = Math.min(1000, Math.max(90, next.width));
    const height = Math.min(500, Math.max(45, next.height));
    state.viewBox = {
      x: Math.min(1000 - width, Math.max(0, next.x)),
      y: Math.min(500 - height, Math.max(0, next.y)),
      width,
      height,
    };
    svg?.setAttribute("viewBox", `${state.viewBox.x} ${state.viewBox.y} ${state.viewBox.width} ${state.viewBox.height}`);
    updateMarkerZoomScale();
  }

  function zoom(factor, centerX = state.viewBox.x + state.viewBox.width / 2, centerY = state.viewBox.y + state.viewBox.height / 2) {
    const width = state.viewBox.width * factor;
    const height = state.viewBox.height * factor;
    setViewBox({ x: centerX - width / 2, y: centerY - height / 2, width, height });
  }

  function fitSites(sites, animate = true) {
    if (!svg || !sites.length) {
      setViewBox({ x: 0, y: 0, width: 1000, height: 500 });
      return;
    }
    const xs = sites.map((site) => (site.lon + 180) / 360 * 1000);
    const ys = sites.map((site) => (90 - site.lat) / 180 * 500);
    const padding = 55;
    let x = Math.max(0, Math.min(...xs) - padding);
    let y = Math.max(0, Math.min(...ys) - padding);
    let width = Math.min(1000 - x, Math.max(180, Math.max(...xs) - Math.min(...xs) + padding * 2));
    let height = Math.min(500 - y, Math.max(90, Math.max(...ys) - Math.min(...ys) + padding * 2));
    const hostRatio = Math.max(1.2, mapHost.clientWidth / Math.max(320, mapHost.clientHeight));
    if (width / height < hostRatio) { const targetWidth = height * hostRatio; x -= (targetWidth - width) / 2; width = targetWidth; }
    else { const targetHeight = width / hostRatio; y -= (targetHeight - height) / 2; height = targetHeight; }
    if (animate && !window.matchMedia("(prefers-reduced-motion: reduce)").matches) svg.classList.add("is-view-changing");
    setViewBox({ x, y, width, height });
    window.setTimeout(() => svg?.classList.remove("is-view-changing"), 260);
  }

  function geometryPath(geometry) {
    if (!geometry || !geometry.coordinates) return "";
    const polygonPath = (rings) => rings.map((ring) => ring.map(([lon, lat], index) => `${index ? "L" : "M"}${((lon + 180) / 360 * 1000).toFixed(2)},${((90 - lat) / 180 * 500).toFixed(2)}`).join(" ") + " Z").join(" ");
    if (geometry.type === "Polygon") return polygonPath(geometry.coordinates);
    if (geometry.type === "MultiPolygon") return geometry.coordinates.map(polygonPath).join(" ");
    return "";
  }

  async function initializeMap() {
    try {
      const response = await fetch(mapHost.dataset.worldMap, { cache: "force-cache" });
      if (!response.ok) throw new Error("World map request failed");
      const world = await response.json();
      svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
      svg.setAttribute("viewBox", "0 0 1000 500");
      svg.setAttribute("role", "group");
      svg.setAttribute("aria-label", "World geography and selectable clinical trial site markers");
      svg.setAttribute("preserveAspectRatio", "xMidYMid meet");
      svg.setAttribute("tabindex", "0");
      const grid = document.createElementNS("http://www.w3.org/2000/svg", "g");
      grid.classList.add("trial-map-grid");
      for (let lon = -120; lon <= 120; lon += 60) grid.innerHTML += `<path d="M${(lon + 180) / 360 * 1000} 0V500"></path>`;
      for (let lat = -60; lat <= 60; lat += 30) grid.innerHTML += `<path d="M0 ${(90 - lat) / 180 * 500}H1000"></path>`;
      const countries = document.createElementNS("http://www.w3.org/2000/svg", "g");
      countries.classList.add("trial-map-countries");
      (world.features || []).forEach((feature) => {
        const path = document.createElementNS("http://www.w3.org/2000/svg", "path");
        path.setAttribute("d", geometryPath(feature.geometry));
        path.setAttribute("data-country", feature.properties?.name || "");
        countries.append(path);
      });
      markerLayer = document.createElementNS("http://www.w3.org/2000/svg", "g");
      markerLayer.classList.add("trial-map-markers");
      svg.append(grid, countries, markerLayer);
      tooltip = document.createElement("div");
      tooltip.className = "trial-map-tooltip";
      tooltip.hidden = true;
      mapHost.replaceChildren(svg, tooltip);
      mapReady = true;
      initializePanZoom();
      renderAll({ fit: true });
    } catch {
      mapHost.innerHTML = '<div class="trial-map-empty"><h3>Map geometry unavailable</h3><p>The synchronized trial list remains available below.</p></div>';
      mapInsight.textContent = "Map geometry could not be loaded; trial records and research summaries remain usable.";
    }
  }

  function initializePanZoom() {
    const pointers = new Map();
    let gesture = null;
    let tapCandidate = null;

    const pointerValues = () => [...pointers.values()];
    const midpoint = (first, second) => ({
      x: (first.x + second.x) / 2,
      y: (first.y + second.y) / 2,
    });
    const distance = (first, second) => Math.hypot(second.x - first.x, second.y - first.y);
    const beginGesture = () => {
      const active = pointerValues();
      if (!active.length) {
        gesture = null;
        svg.classList.remove("is-dragging", "is-pinching");
        return;
      }
      if (active.length === 1) {
        gesture = { type: "pan", x: active[0].x, y: active[0].y, view: { ...state.viewBox } };
        svg.classList.add("is-dragging");
        svg.classList.remove("is-pinching");
        return;
      }
      const rect = svg.getBoundingClientRect();
      const center = midpoint(active[0], active[1]);
      gesture = {
        type: "pinch",
        distance: Math.max(1, distance(active[0], active[1])),
        view: { ...state.viewBox },
        worldX: state.viewBox.x + (center.x - rect.left) / rect.width * state.viewBox.width,
        worldY: state.viewBox.y + (center.y - rect.top) / rect.height * state.viewBox.height,
      };
      svg.classList.remove("is-dragging");
      svg.classList.add("is-pinching");
    };

    svg.addEventListener("pointerdown", (event) => {
      if (event.pointerType === "touch" && mapHost.dataset.mapInteractive === "false") return;
      const marker = event.target.closest?.(".trial-map-marker");
      tapCandidate = event.pointerType === "touch" && pointers.size === 0 && marker
        ? { marker, x: event.clientX, y: event.clientY, pointerId: event.pointerId } : null;
      if (event.pointerType === "mouse" && event.button !== 0) return;
      if (event.pointerType === "mouse" && event.target.closest?.(".trial-map-marker")) return;
      pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
      svg.setPointerCapture(event.pointerId);
      beginGesture();
    });
    svg.addEventListener("pointermove", (event) => {
      if (!pointers.has(event.pointerId)) return;
      if (tapCandidate && Math.hypot(event.clientX - tapCandidate.x, event.clientY - tapCandidate.y) > 8) tapCandidate = null;
      pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
      const rect = svg.getBoundingClientRect();
      const active = pointerValues();
      if (active.length >= 2) {
        if (gesture?.type !== "pinch") beginGesture();
        suppressMapClickUntil = Date.now() + 350;
        const center = midpoint(active[0], active[1]);
        const scale = gesture.distance / Math.max(1, distance(active[0], active[1]));
        const width = Math.min(1000, Math.max(90, gesture.view.width * scale));
        const height = Math.min(500, Math.max(45, gesture.view.height * scale));
        setViewBox({
          x: gesture.worldX - (center.x - rect.left) / rect.width * width,
          y: gesture.worldY - (center.y - rect.top) / rect.height * height,
          width,
          height,
        });
      } else if (gesture?.type === "pan") {
        if (Math.hypot(event.clientX - gesture.x, event.clientY - gesture.y) > 6) suppressMapClickUntil = Date.now() + 350;
        setViewBox({
          ...gesture.view,
          x: gesture.view.x - (event.clientX - gesture.x) / rect.width * gesture.view.width,
          y: gesture.view.y - (event.clientY - gesture.y) / rect.height * gesture.view.height,
        });
      }
      event.preventDefault();
    });
    const stopPointer = (event) => {
      const tapped = event.type === "pointerup" && tapCandidate?.pointerId === event.pointerId && pointers.size === 1
        && Math.hypot(event.clientX - tapCandidate.x, event.clientY - tapCandidate.y) <= 8 ? tapCandidate.marker : null;
      tapCandidate = null;
      pointers.delete(event.pointerId);
      if (svg.hasPointerCapture(event.pointerId)) svg.releasePointerCapture(event.pointerId);
      beginGesture();
      if (tapped) {
        suppressMapClickUntil = 0;
        tapped.dispatchEvent(new MouseEvent("click", { bubbles: true }));
        suppressMapClickUntil = Date.now() + 400;
      }
    };
    svg.addEventListener("pointerup", stopPointer);
    svg.addEventListener("pointercancel", stopPointer);
    svg.addEventListener("wheel", (event) => {
      if (!event.ctrlKey && !event.metaKey && !$(".is-trial-fullscreen")) return;
      event.preventDefault();
      const rect = svg.getBoundingClientRect();
      const centerX = state.viewBox.x + (event.clientX - rect.left) / rect.width * state.viewBox.width;
      const centerY = state.viewBox.y + (event.clientY - rect.top) / rect.height * state.viewBox.height;
      zoom(event.deltaY > 0 ? 1.16 : .86, centerX, centerY);
    }, { passive: false });
    svg.addEventListener("dblclick", (event) => {
      event.preventDefault();
      const rect = svg.getBoundingClientRect();
      const centerX = state.viewBox.x + (event.clientX - rect.left) / rect.width * state.viewBox.width;
      const centerY = state.viewBox.y + (event.clientY - rect.top) / rect.height * state.viewBox.height;
      zoom(.68, centerX, centerY);
    });
    svg.addEventListener("keydown", (event) => {
      const panX = state.viewBox.width * .12;
      const panY = state.viewBox.height * .12;
      if (["+", "="].includes(event.key)) zoom(.76);
      else if (["-", "_"].includes(event.key)) zoom(1.3);
      else if (event.key === "ArrowLeft") setViewBox({ ...state.viewBox, x: state.viewBox.x - panX });
      else if (event.key === "ArrowRight") setViewBox({ ...state.viewBox, x: state.viewBox.x + panX });
      else if (event.key === "ArrowUp") setViewBox({ ...state.viewBox, y: state.viewBox.y - panY });
      else if (event.key === "ArrowDown") setViewBox({ ...state.viewBox, y: state.viewBox.y + panY });
      else if (event.key === "0") fitSites(siteAggregates(state.filtered));
      else return;
      event.preventDefault();
    });
  }

  function renderAll({ fit = false } = {}) {
    state.filtered = filteredTrials();
    const baseRows = state.selectedSite ? trials.filter((trial) => {
      const filters = currentFilters();
      const oldSite = state.selectedSite;
      state.selectedSite = "";
      const match = filteredTrials().some((item) => item.nct_id === trial.nct_id);
      state.selectedSite = oldSite;
      return match;
    }) : state.filtered;
    const sites = mapReady ? renderMarkers(baseRows) : siteAggregates(baseRows);
    renderSelectedSite(sites);
    if (!state.selectedSite && state.filtered !== baseRows) state.filtered = filteredTrials();
    renderResults(state.filtered);
    renderResearchLens(state.filtered);
    const mappedTrials = state.filtered.filter((trial) => trial.sites?.length).length;
    const mappedSites = siteAggregates(state.filtered).length;
    const countries = new Set(state.filtered.flatMap(trialCountries));
    document.querySelectorAll("[data-trial-legend]").forEach((button) => {
      const shown = !hiddenStatuses.has(button.dataset.trialLegend);
      button.setAttribute("aria-pressed", String(shown));
      button.title = `${shown ? "Hide" : "Show"} ${button.textContent.trim().toLowerCase()}`;
    });
    if (mapSummary) mapSummary.textContent = `${numberLabel(mappedSites)} sites · ${numberLabel(mappedTrials)} trials · ${numberLabel(countries.size)} countries in the current view`;
    if (mapInsight) mapInsight.textContent = state.selectedSite
      ? `Focused on one registry-reported site. ${numberLabel(state.filtered.length)} associated trial${state.filtered.length === 1 ? "" : "s"} shown below.`
      : `${numberLabel(state.filtered.length)} of ${numberLabel(trials.length)} trials match. Select a marker to compare the studies registered at that site.`;
    const advanced = activeAdvancedCount();
    if (filterCount) filterCount.textContent = advanced ? `${advanced} advanced filter${advanced === 1 ? "" : "s"}` : "No advanced filters";
    if (fit && mapReady) fitSites(siteAggregates(state.filtered));
    const selectedTrial = trials.find((trial) => trial.nct_id === state.selectedTrial);
    if (selectedTrial) renderProfile(selectedTrial); else renderProfile(null);
    updateUrl();
  }

  function resetView() {
    hiddenStatuses.clear();
    Object.entries(controls).forEach(([key, control]) => {
      if (!control) return;
      if (key === "recruiting") control.checked = false;
      else control.value = key === "sort" ? "relevance" : "";
    });
    state.selectedSite = "";
    state.selectedTrial = "";
    state.visibleLimit = 30;
    renderAll({ fit: true });
    controls.search?.focus();
  }

  function exportCsv() {
    const fields = ["NCT ID", "Title", "Sponsor", "Status", "Phase", "Enrollment", "Conditions", "Interventions", "Start date", "Primary completion", "Countries", "Mapped sites", "Registry URL"];
    const safe = (value) => {
      let text = String(value ?? "").replaceAll('"', '""');
      if (/^[=+\-@]/.test(text)) text = `'${text}`;
      return `"${text}"`;
    };
    const rows = state.filtered.map((trial) => [trial.nct_id, trial.title, trial.sponsor, trial.status_label, (trial.phases || []).map(phaseLabel).join("; "), trial.enrollment?.count ?? "", (trial.conditions || []).join("; "), (trial.interventions || []).map((item) => item.name).join("; "), trial.start_date, trial.primary_completion_date, trialCountries(trial).join("; "), trial.sites?.length || 0, trial.registry_url]);
    const blob = new Blob([[fields, ...rows].map((row) => row.map(safe).join(",")).join("\r\n")], { type: "text/csv;charset=utf-8" });
    const link = document.createElement("a");
    link.href = URL.createObjectURL(blob);
    link.download = `psilocybin-trials-map-${new Date().toISOString().slice(0, 10)}.csv`;
    link.click();
    window.setTimeout(() => URL.revokeObjectURL(link.href), 1000);
  }

  async function copyViewLink() {
    updateUrl();
    try {
      await navigator.clipboard.writeText(window.location.href);
      if (typeof window.showAppToast === "function") window.showAppToast("Shareable trials map view copied");
    } catch {
      window.prompt("Copy this trials map view:", window.location.href);
    }
  }

  function bindControls() {
    document.querySelectorAll("[data-trial-legend]").forEach((button) => button.addEventListener("click", () => {
      const type = button.dataset.trialLegend;
      if (hiddenStatuses.has(type)) hiddenStatuses.delete(type); else hiddenStatuses.add(type);
      state.selectedSite = "";
      state.selectedTrial = "";
      state.visibleLimit = 30;
      renderAll({ fit: false });
    }));
    Object.entries(controls).forEach(([key, control]) => {
      if (!control) return;
      const eventName = key === "search" ? "input" : "change";
      control.addEventListener(eventName, () => {
        state.visibleLimit = 30;
        if (key !== "sort") { state.selectedSite = ""; state.selectedTrial = ""; }
        if (key === "search") {
          window.clearTimeout(searchTimer);
          searchTimer = window.setTimeout(() => renderAll({ fit: true }), 130);
        } else renderAll({ fit: key !== "sort" });
      });
    });
    $("[data-trial-reset]")?.addEventListener("click", resetView);
    $("[data-trial-fit]")?.addEventListener("click", () => fitSites(siteAggregates(state.filtered)));
    $("[data-trial-zoom-in]")?.addEventListener("click", () => zoom(.72));
    $("[data-trial-zoom-out]")?.addEventListener("click", () => zoom(1.38));
    $("[data-trial-share]")?.addEventListener("click", copyViewLink);
    $("[data-trial-export]")?.addEventListener("click", exportCsv);
    loadMore?.addEventListener("click", () => { state.visibleLimit += 30; renderResults(state.filtered); });
    if (mobileDialog) {
      mobileDialog.addEventListener("click", (event) => {
        if (event.target === mobileDialog) closeMobileSiteDialog();
      });
      mobileDialog.addEventListener("close", () => syncMobileDialogState(false));
      mobileDialog.addEventListener("cancel", () => syncMobileDialogState(false));
    }
    const fullscreenButton = $("[data-trial-fullscreen]");
    const fullscreenTarget = $("[data-trial-map-fullscreen-target]");
    if (fullscreenButton && fullscreenTarget) {
      if (mobileDialog) fullscreenTarget.append(mobileDialog);
      let fallback = false;
      let savedScroll = 0;
      const nativeElement = () => document.fullscreenElement || document.webkitFullscreenElement;
      const syncFullscreen = () => {
        const active = fallback || nativeElement() === fullscreenTarget;
        fullscreenTarget.classList.toggle("is-trial-fullscreen", active);
        fullscreenTarget.classList.toggle("trial-fullscreen-fallback", fallback);
        document.documentElement.classList.toggle("trial-fullscreen-open", fallback);
        fullscreenButton.setAttribute("aria-pressed", String(active));
        fullscreenButton.querySelector("span").textContent = active ? "Exit fullscreen" : "Fullscreen";
        window.setTimeout(() => fitSites(siteAggregates(state.filtered), false), 120);
      };
      fullscreenButton.hidden = false;
      fullscreenButton.addEventListener("click", async () => {
        if (fallback) {
          fallback = false;
          syncFullscreen();
          window.scrollTo({ top: savedScroll, behavior: "auto" });
          return;
        }
        try {
          if (nativeElement() === fullscreenTarget) {
            if (document.exitFullscreen) await document.exitFullscreen(); else await document.webkitExitFullscreen();
          } else if (fullscreenTarget.requestFullscreen && document.fullscreenEnabled) {
            await fullscreenTarget.requestFullscreen();
          } else if (fullscreenTarget.webkitRequestFullscreen && document.webkitFullscreenEnabled) {
            await fullscreenTarget.webkitRequestFullscreen();
          } else { savedScroll = window.scrollY; fallback = true; }
        } catch { savedScroll = window.scrollY; fallback = true; }
        syncFullscreen();
      });
      document.addEventListener("fullscreenchange", syncFullscreen);
      document.addEventListener("webkitfullscreenchange", syncFullscreen);
      document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && fallback && !mobileDialog?.open) {
          fallback = false; syncFullscreen(); window.scrollTo({ top: savedScroll, behavior: "auto" });
        }
      });
      if ("ResizeObserver" in window) {
        let frame;
        new ResizeObserver(() => {
          cancelAnimationFrame(frame);
          frame = requestAnimationFrame(() => fitSites(siteAggregates(state.filtered), false));
        }).observe(mapHost);
      }
    }
  }

  populateControls();
  restoreStateFromUrl();
  bindControls();
  state.filtered = filteredTrials();
  renderResults(state.filtered);
  renderResearchLens(state.filtered);
  const initialTrial = trials.find((trial) => trial.nct_id === state.selectedTrial);
  renderProfile(initialTrial || null);
  initializeMap();
})();
