// Loaded only when the analytics dialog or publication-growth chart is used.
export function init({ escapeHtml, printDateRangeLabel, printReferenceText, printSourcesLabel, refreshIcons }) {
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
const chartToolsUrl = new URL('../chart-tools.mjs', import.meta.url);
chartToolsUrl.search = new URL(import.meta.url).search;
let chartToolsRequest;
let loadedChartTools;
const chartTools = () => chartToolsRequest ||= import(chartToolsUrl.href).then(tools => loadedChartTools = tools).catch(error => { chartToolsRequest = null; throw error; });
let growthRevision = 0;
let timelineRevision = 0;
let timelinePresentationKey = null;
const DAY_MS = 86400000;
let currentTimelineView = null;
let currentAnalyticsFilters = {};

const publicationGrowthChart = document.querySelector("#publication-growth-chart");
const publicationGrowthDataElement = document.querySelector("#publication-growth-data");

let analyticsDataCache = null;
let analyticsDataRequest = null;

function analyticsData() {
  if (analyticsDataCache) return analyticsDataCache;
  if (!analyticsDataElement) return {};
  try {
    analyticsDataCache = JSON.parse(analyticsDataElement.textContent || "{}");
    return analyticsDataCache;
  } catch (error) {
    return {};
  }
}

async function ensureAnalyticsPapersLoaded() {
  const data = analyticsData();
  if (Array.isArray(data.timeline_papers)) return data;
  if (analyticsDataRequest) return analyticsDataRequest;
  analyticsDataRequest = fetch("api.php?resource=analytics", {
    headers: { Accept: "application/json" },
    credentials: "same-origin",
  }).then((response) => {
    if (!response.ok) throw new Error(`Analytics request failed (${response.status})`);
    return response.json();
  }).then((payload) => {
    analyticsDataCache = { ...data, ...payload };
    return analyticsDataCache;
  }).catch(() => data).finally(() => {
    analyticsDataRequest = null;
  });
  return analyticsDataRequest;
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
    paper.publication_status, paper.substance_tags, paper.topic_tags, paper.study_type,
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

let publicationGrowthRange = "recent";

async function renderPublicationGrowthChart() {
  if (!publicationGrowthChart) return;
  const revision = ++growthRevision;
  const data = publicationGrowthData();
  const rows = Array.isArray(data?.rows) ? data.rows.map(row => ({ year: Number(row.year), count: Number(row.count || 0) })).filter(row => Number.isFinite(row.year) && (publicationGrowthRange === 'full' || row.year >= 2020)) : [];
  let tools;
  try { tools = await chartTools(); } catch { publicationGrowthChart.textContent = rows.map(row => `${row.year}: ${row.count}`).join('; ') || 'No dated records available.'; return; }
  if (revision !== growthRevision) return;
  if (!rows.length) { tools.clearChart(publicationGrowthChart); publicationGrowthChart.innerHTML = '<p class="publication-growth-empty">Timeline data will appear after dated records are indexed.</p>'; return; }
  let total = 0;
  const cumulative = rows.map(row => total += row.count);
  const summary = document.querySelector('#publication-growth-summary');
  if (summary) summary.textContent = `${total.toLocaleString()} records since ${rows[0].year}`;
  publicationGrowthChart.removeAttribute('role');
  const labels = rows.map(row => String(row.year));
  const onSelect = index => openPublicationGrowthYear(rows[index].year);
  await tools.mountChart(publicationGrowthChart, {
    type: 'bar', data: { labels, datasets: [
      { label: 'Annual records', data: rows.map(row => row.count), backgroundColor: '#1a6b54', borderRadius: 2, maxBarThickness: 18, order: 2 },
      { type: 'line', label: 'Cumulative records in this range', data: cumulative, yAxisID: 'cumulative', borderColor: '#bd8a35', borderWidth: 2, pointRadius: 2, pointHoverRadius: 4, order: 1 },
    ] }, options: {
      scales: { x: { grid: { display: false }, ticks: { maxTicksLimit: 8, maxRotation: 0, font: { size: 10 } } }, y: { beginAtZero: true, ticks: { precision: 0, maxTicksLimit: 3, font: { size: 10 } }, grid: { color: '#d8d2c466' } }, cumulative: { display: false, beginAtZero: true, min: 0, max: total } },
      plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false } },
      onClick(event, elements) { if (elements.length) onSelect(elements[0].index); },
      onHover(event, elements, chart) { chart.canvas.style.cursor = elements.length ? 'pointer' : 'default'; },
    },
  }, { height: 126, label: `Annual publication counts from ${labels[0]} to ${labels[labels.length-1]}`, headers: ['Publication year', 'Annual records', 'Cumulative in range'], rows: rows.map((row,i) => [row.year, row.count, cumulative[i]]), onSelect, filename: 'psilocybin-publication-growth.png' });
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
    source: "Psilocybin Research Database",
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
            <div><dt>Text access</dt><dd>Source-derived abstracts and keywords are not redistributed; use the source record link.</dd></div>
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

async function renderTimeline(options = { preset: "10y" }) {
  const revision = ++timelineRevision;
  if (!timelineChart) return;
  const data = analyticsData();
  const sourcePapers = filteredAnalyticsPapers();
  const rows = activeAnalyticsFilterEntries().length ? timelineRowsFromPapers(sourcePapers) : (data.timeline || []);
  const bounds = timelineBounds(rows);
  if (!bounds) {
    currentTimelineView = null;
    loadedChartTools?.clearChart(timelineChart);
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
    loadedChartTools?.clearChart(timelineChart);
    timelineChart.innerHTML = '<p class="empty-chart">No publications in this range.</p>';
    renderAnalyticsLensResults();
    return;
  }
  renderAnalyticsLensResults();
  let tools;
  try { tools = await chartTools(); } catch { timelineChart.textContent = aggregate.buckets.map(bucket => `${bucket.fullLabel}: ${bucket.count}`).join('; '); return; }
  if (revision !== timelineRevision) return;
  const presentationKey = JSON.stringify([aggregate.buckets, timelineChart.clientWidth < 560]);
  if (presentationKey === timelinePresentationKey && timelineChart.querySelector('canvas')) return;
  const onSelect = index => openTimelineData('html', aggregate.buckets[index]);
  timelineChart.removeAttribute('role');
  const chart = await tools.mountChart(timelineChart, {
    type: 'bar', data: { labels, datasets: [{ label: 'Indexed publications', data: counts, backgroundColor: '#1a6b54', hoverBackgroundColor: '#123c31', borderRadius: 4, maxBarThickness: 34 }] },
    options: {
      scales: { x: { grid: { display: false }, ticks: { maxTicksLimit: timelineChart.clientWidth < 560 ? 7 : 12, maxRotation: 38 } }, y: { beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'Publications' }, grid: { color: '#d8d2c466' } } },
      plugins: { legend: { display: false }, tooltip: { callbacks: { title: items => aggregate.buckets[items[0].dataIndex].fullLabel, afterLabel: item => { const bucket = aggregate.buckets[item.dataIndex]; return `${bucket.range.from} to ${bucket.range.to}`; } } } },
      onClick(event, elements) { if (elements.length) onSelect(elements[0].index); },
      onHover(event, elements, chart) { chart.canvas.style.cursor = elements.length ? 'pointer' : 'default'; setTimelineInsight(elements.length ? aggregate.buckets[elements[0].index] : null); },
    },
  }, { height: timelineChart.clientWidth < 560 ? 282 : 360, label: 'Publication timeline', headers: ['Publication period', 'Records', 'From', 'To'], rows: aggregate.buckets.map(bucket => [bucket.fullLabel, bucket.count, bucket.range.from, bucket.range.to]), onSelect, filename: 'psilocybin-publication-timeline.png' });
  if (chart && revision === timelineRevision) timelinePresentationKey = presentationKey;

  renderAnalyticsLensResults();
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

document.querySelectorAll("[data-growth-range]").forEach((button) => {
  button.addEventListener("click", () => {
    const range = button.dataset.growthRange;
    if (range === publicationGrowthRange) return;
    publicationGrowthRange = range;
    button.closest(".publication-growth-range").dataset.range = range;
    document.querySelectorAll("[data-growth-range]").forEach((control) => {
      control.setAttribute("aria-pressed", String(control.dataset.growthRange === range));
    });
    renderPublicationGrowthChart(true);
  });
});





  return {
    renderGrowth: renderPublicationGrowthChart,
    async open() {
      renderTimeline(currentTimelineView ? { from: new Date(currentTimelineView.from), to: new Date(currentTimelineView.to) } : { preset: '10y' });
      await ensureAnalyticsPapersLoaded();
      if (document.querySelector('#analytics-modal')?.open) renderTimeline(currentTimelineView ? { from: new Date(currentTimelineView.from), to: new Date(currentTimelineView.to) } : { preset: '10y' });
    }
  };
}
