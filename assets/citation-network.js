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
  if (graph.mode === "paper") return;
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
  const zoomInButton = document.querySelector("[data-citation-zoom-in]");
  const zoomOutButton = document.querySelector("[data-citation-zoom-out]");
  const labelButton = document.querySelector("[data-citation-labels]");
  const printButton = document.querySelector("[data-citation-print]");
  const fullscreenButton = document.querySelector("[data-citation-fullscreen]");
  const fullscreenTarget = document.querySelector("[data-citation-fullscreen-target]");
  const searchInput = document.querySelector("[data-citation-search]");
  const seedLimitSelect = document.querySelector("[data-citation-seed-limit]");
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
  const legendButtons = [...document.querySelectorAll("[data-citation-legend-type]")];
  const edgeTypeInputs = [...document.querySelectorAll("[data-citation-edge-type]")];
  const insight = document.querySelector("[data-citation-insight]");
  const typeRadius = { paper: 13, reference: 8, author: 9, topic: 9, journal: 9 };
  const labels = { paper: "Paper", reference: "Reference", author: "Author", topic: "Topic", journal: "Journal" };
  const relationLabels = { cites: "Cites", author: "Author link", topic: "Topic link", journal: "Journal link" };
  const svgNs = "http://www.w3.org/2000/svg";
  const svg = document.createElementNS(svgNs, "svg");
  svg.setAttribute("role", "group");
  svg.setAttribute("aria-label", "Citation network. Drag empty space to pan and pinch with two fingers to zoom.");
  svg.setAttribute("tabindex", "0");
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
  const viewportLayer = document.createElementNS(svgNs, "g");
  viewportLayer.classList.add("citation-network-viewport");
  const edgeLayer = document.createElementNS(svgNs, "g");
  const nodeLayer = document.createElementNS(svgNs, "g");
  viewportLayer.append(edgeLayer, nodeLayer);
  svg.append(defs, viewportLayer);

  const dimensions = () => {
    const rect = host.getBoundingClientRect();
    return { width: Math.max(320, rect.width), height: Math.max(420, rect.height || 640) };
  };
  let { width, height } = dimensions();
  const citationView = { scale: 1, x: 0, y: 0 };
  const applyCitationView = () => {
    viewportLayer.setAttribute("transform", `translate(${citationView.x.toFixed(2)} ${citationView.y.toFixed(2)}) scale(${citationView.scale.toFixed(4)})`);
  };
  const resetCitationView = () => {
    citationView.scale = 1;
    citationView.x = 0;
    citationView.y = 0;
    applyCitationView();
  };
  const zoomCitationView = (factor, clientX, clientY) => {
    const rect = svg.getBoundingClientRect();
    const localX = Number.isFinite(clientX) ? clientX - rect.left : rect.width / 2;
    const localY = Number.isFinite(clientY) ? clientY - rect.top : rect.height / 2;
    const worldX = (localX - citationView.x) / citationView.scale;
    const worldY = (localY - citationView.y) / citationView.scale;
    const nextScale = Math.min(4, Math.max(.5, citationView.scale * factor));
    citationView.x = localX - worldX * nextScale;
    citationView.y = localY - worldY * nextScale;
    citationView.scale = nextScale;
    applyCitationView();
  };
  const center = () => ({ x: width / 2, y: height / 2 });
  const layoutLabels = {
    force: "Organic network",
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
  const visibleLegendTypes = new Set(nodes.map((node) => node.type));
  const visibleTypes = () => nodeTypeInputs.length
    ? new Set(nodeTypeInputs.filter((input) => input.checked).map((input) => input.dataset.citationNodeType))
    : visibleLegendTypes;
  const visibleEdgeTypes = () => edgeTypeInputs.length
    ? new Set(edgeTypeInputs.filter((input) => input.checked).map((input) => input.dataset.citationEdgeType))
    : new Set(edges.map((edge) => edge.type));
  const currentSearchTerm = () => (searchInput?.value || "").trim().toLowerCase();
  const nodeTypeVisible = (node) => visibleTypes().has(node.type) && (clusteringVisible() || !isClusteringNode(node));
  const edgeTypeVisible = (edge) => {
    if (edgeTypeInputs.length && !visibleEdgeTypes().has(edge.type)) return false;
    if (!clusteringVisible() && edge.type !== "cites") return false;
    return true;
  };
  const applySeedLimit = (value) => {
    const url = new URL(window.location.href);
    url.searchParams.set("limit", value || "8");
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
  let fallbackFullscreen = false;
  let fullscreenScrollY = 0;
  const enterFallbackFullscreen = () => {
    if (!fullscreenTarget || fallbackFullscreen) return;
    fullscreenScrollY = window.scrollY;
    fallbackFullscreen = true;
    fullscreenTarget.classList.add("citation-fullscreen-fallback");
    document.documentElement.classList.add("citation-fullscreen-open");
    document.body.classList.add("citation-fullscreen-open");
    syncCitationFullscreen();
  };
  const exitFallbackFullscreen = () => {
    if (!fullscreenTarget || !fallbackFullscreen) return;
    fallbackFullscreen = false;
    fullscreenTarget.classList.remove("citation-fullscreen-fallback");
    document.documentElement.classList.remove("citation-fullscreen-open");
    document.body.classList.remove("citation-fullscreen-open");
    syncCitationFullscreen();
    window.scrollTo({ top: fullscreenScrollY, left: 0, behavior: "auto" });
  };
  const syncCitationFullscreen = () => {
    if (!fullscreenButton || !fullscreenTarget) return;
    const active = fullscreenElement() === fullscreenTarget || fallbackFullscreen;
    fullscreenButton.hidden = false;
    fullscreenButton.setAttribute("aria-pressed", active ? "true" : "false");
    fullscreenButton.setAttribute("title", active ? "Exit citation network fullscreen" : "Open citation network fullscreen");
    fullscreenButton.innerHTML = `<i data-icon="${active ? "minimize" : "maximize"}" aria-hidden="true"></i><span>${active ? "Exit fullscreen" : "Fullscreen"}</span>`;
    fullscreenTarget.classList.toggle("is-citation-fullscreen", active);
    refreshIcons();
    window.setTimeout(fitGraph, 80);
  };
  if (fullscreenButton && fullscreenTarget) {
    fullscreenButton.hidden = false;
    fullscreenButton.addEventListener("click", async () => {
      if (fallbackFullscreen) {
        exitFallbackFullscreen();
        showAppToast("Exited citation network fullscreen.");
        return;
      }
      try {
        if (fullscreenElement() === fullscreenTarget) {
          await exitFullscreen();
          showAppToast("Exited citation network fullscreen.");
        } else if (fullscreenEnabled) {
          if (fullscreenElement()) await exitFullscreen();
          await requestFullscreen(fullscreenTarget);
          showAppToast("Citation network fullscreen enabled.");
        } else {
          enterFallbackFullscreen();
          showAppToast("Citation network fullscreen enabled.");
        }
      } catch (error) {
        enterFallbackFullscreen();
        showAppToast("Citation network fullscreen enabled.");
      } finally {
        window.setTimeout(syncCitationFullscreen, 80);
      }
    });
    document.addEventListener("keydown", (event) => {
      if (event.key !== "Escape" || !fallbackFullscreen) return;
      exitFallbackFullscreen();
      showAppToast("Exited citation network fullscreen.");
    });
    document.addEventListener("fullscreenchange", syncCitationFullscreen);
    document.addEventListener("webkitfullscreenchange", syncCitationFullscreen);
    window.addEventListener("pagehide", () => {
      if (fallbackFullscreen) exitFallbackFullscreen();
    });
    syncCitationFullscreen();
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
  const syncCitationSelectionActions = () => {
    const hasSelection = Boolean(selectedState.node);
    [copySelectedButton, focusSelectedButton, exportSubgraphButton].forEach((button) => {
      if (!button) return;
      button.disabled = !hasSelection;
      button.setAttribute("aria-disabled", hasSelection ? "false" : "true");
      button.title = hasSelection ? "" : "Select a node first";
    });
  };
  syncCitationSelectionActions();
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
    const keepEvidenceOpen = selectedState.node?.id === node.id && detail?.querySelector('.citation-evidence-disclosure')?.open;
    selectedState.node = node;
    syncCitationSelectionActions();
    nodeLayer.querySelectorAll(".citation-node").forEach((element) => {
      element.classList.toggle("is-selected", element.dataset.nodeId === node.id);
    });
    updateGraphState();
    if (!detail) return;
    const allRelations = connectedEdgesForNode(node);
    const nodeRelations = allRelations
      .map((edge) => ({ edge, other: nodeById.get(edge.source === node.id ? edge.target : edge.source) }))
      .filter((item) => item.other)
      .sort((a, b) => String(a.edge.type).localeCompare(String(b.edge.type)) || String(a.other.label).localeCompare(String(b.other.label)))
      .slice(0, 14);
    const meta = [
      node.authors ? `<div><dt>Authors</dt><dd class="citation-detail-authors">${authorLinksHtml(node.authors)}</dd></div>` : "",
      node.date ? `<div><dt>Date</dt><dd>${escapeHtml(node.date)}</dd></div>` : "",
      node.journal ? `<div><dt>Journal</dt><dd>${escapeHtml(node.journal)}</dd></div>` : "",
      nodeDoi(node) ? `<div><dt>DOI</dt><dd><a href="https://doi.org/${escapeHtml(nodeDoi(node))}" target="_blank" rel="noopener">${escapeHtml(nodeDoi(node))}</a></dd></div>` : "",
      node.source ? `<div><dt>Source</dt><dd>${escapeHtml(node.source)}</dd></div>` : "",
      node.status ? `<div><dt>Status</dt><dd>${escapeHtml(node.status)}</dd></div>` : "",
      `<div><dt>Type</dt><dd>${escapeHtml(labels[node.type] || node.type || "Node")}</dd></div>`,
      `<div><dt>Graph connections</dt><dd>${escapeHtml(String(connectedEdgesForNode(node).length))}</dd></div>`,
    ].filter(Boolean).join("");
    const matchNote = node.reference_match
      ? `<p class="citation-match-note">This node was matched from a cited DOI/reference to an indexed publication${node.matched_reference_doi ? ` (${escapeHtml(node.matched_reference_doi)})` : ""}.</p>`
      : "";
    const action = `<div class="citation-network-detail-actions">
      ${node.type === 'paper' && /^paper:\d+$/.test(node.id) ? `<a class="secondary" href="citation-network.php?paper=${escapeHtml(node.id.slice(6))}">Open paper citations</a>` : ''}
      ${node.url ? `<a class="primary iconed" href="${escapeHtml(node.url)}"><i data-icon="external-link" aria-hidden="true"></i><span>Open</span></a>` : ""}
      <button class="secondary" type="button" data-citation-detail-focus>${selectedState.focusNodeId === node.id ? "Clear node focus" : "Focus on this node"}</button>
      <button class="secondary" type="button" data-citation-detail-copy>Copy context</button>
    </div>`;
    const relationCards = nodeRelations.length
      ? nodeRelations.map(({ edge, other }) => `<a href="${escapeHtml(other.url || "#")}" ${other.url ? "" : 'aria-disabled="true" tabindex="-1"'}><span>${escapeHtml(relationSentence(node, edge))}</span><em>${escapeHtml(labels[other.type] || other.type || "Node")}</em>${relationCardMeta(other).length ? `<small>${relationCardMeta(other).map(escapeHtml).join(" · ")}</small>` : ""}</a>`).join("")
      : '<p>No connected records are available for this node.</p>';
    const relationList = `<details class="citation-evidence-disclosure"${keepEvidenceOpen ? ' open' : ''}><summary><span>Connected evidence</span><small>${allRelations.length}</small><i data-icon="chevron-down" aria-hidden="true"></i></summary><div class="citation-node-relations" data-citation-relations>${nodeRelations.length < allRelations.length ? `<p class="citation-relations-limit">Showing ${nodeRelations.length} of ${allRelations.length} connections. Export the selection for the complete list.</p>` : ''}${relationCards}</div></details>`;
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
    const jump = document.querySelector('[data-citation-selection-jump]');
    if (jump) { jump.hidden = false; jump.setAttribute('aria-label', `View selected ${labels[node.type] || 'node'}: ${node.label || 'Untitled'}`); }
    refreshIcons();
  };
  document.querySelector('[data-citation-selection-jump]')?.addEventListener('click', () => {
    detail?.focus({preventScroll: true});
  });
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

  let suppressNodeClicksUntil = 0;
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
    group.addEventListener("click", (event) => {
      if (Date.now() < suppressNodeClicksUntil) {
        event.preventDefault();
        return;
      }
      updateDetail(node);
    });
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
      const graphX = (event.clientX - rect.left - citationView.x) / citationView.scale;
      const graphY = (event.clientY - rect.top - citationView.y) / citationView.scale;
      node.x = Math.max(node.r + 4, Math.min(width - node.r - 4, graphX));
      node.y = Math.max(node.r + 4, Math.min(height - node.r - 4, graphY));
      node.vx = 0;
      node.vy = 0;
      render();
    };
    group.addEventListener("pointerdown", (event) => {
      if (event.pointerType === "touch") return;
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
      line.style.display = show ? "" : "none";
      line.classList.toggle("is-highlighted", Boolean(focus.term && (focus.matching.has(edge.source) || focus.matching.has(edge.target))));
      if (show) visibleEdgeCount++;
    });
    nodeElements.forEach(({ node, group, text }) => {
      const show = shouldShowNode(node, focus);
      const matched = focus.matching.has(node.id);
      const neighbor = focus.neighbors.has(node.id);
      group.hidden = !show;
      group.style.display = show ? "" : "none";
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
    resetCitationView();
    render();
  }

  function initializeCitationViewport() {
    const pointers = new Map();
    let gesture = null;
    const values = () => [...pointers.values()];
    const midpoint = (first, second) => ({ x: (first.x + second.x) / 2, y: (first.y + second.y) / 2 });
    const distance = (first, second) => Math.hypot(second.x - first.x, second.y - first.y);
    const beginGesture = () => {
      const active = values();
      if (!active.length) {
        gesture = null;
        svg.classList.remove("is-panning", "is-pinching");
        return;
      }
      if (active.length === 1) {
        gesture = {
          type: "pan",
          point: { ...active[0] },
          view: { ...citationView },
          moved: false,
        };
        svg.classList.add("is-panning");
        svg.classList.remove("is-pinching");
        return;
      }
      const rect = svg.getBoundingClientRect();
      const center = midpoint(active[0], active[1]);
      gesture = {
        type: "pinch",
        distance: Math.max(1, distance(active[0], active[1])),
        view: { ...citationView },
        worldX: (center.x - rect.left - citationView.x) / citationView.scale,
        worldY: (center.y - rect.top - citationView.y) / citationView.scale,
        moved: false,
      };
      svg.classList.remove("is-panning");
      svg.classList.add("is-pinching");
    };

    svg.addEventListener("pointerdown", (event) => {
      if (event.pointerType === "mouse" && event.button !== 0) return;
      if (event.pointerType !== "touch" && event.target.closest?.(".citation-node")) return;
      pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
      svg.setPointerCapture?.(event.pointerId);
      beginGesture();
    });
    svg.addEventListener("pointermove", (event) => {
      if (!pointers.has(event.pointerId)) return;
      pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
      const active = values();
      if (active.length >= 2) {
        if (gesture?.type !== "pinch") beginGesture();
        const rect = svg.getBoundingClientRect();
        const center = midpoint(active[0], active[1]);
        const movedDistance = distance(active[0], active[1]);
        const nextScale = Math.min(4, Math.max(.5, gesture.view.scale * movedDistance / gesture.distance));
        citationView.scale = nextScale;
        citationView.x = center.x - rect.left - gesture.worldX * nextScale;
        citationView.y = center.y - rect.top - gesture.worldY * nextScale;
        gesture.moved = true;
      } else if (gesture?.type === "pan") {
        const dx = event.clientX - gesture.point.x;
        const dy = event.clientY - gesture.point.y;
        citationView.x = gesture.view.x + dx;
        citationView.y = gesture.view.y + dy;
        gesture.moved = gesture.moved || Math.hypot(dx, dy) > 6;
      }
      if (gesture?.moved) suppressNodeClicksUntil = Date.now() + 350;
      applyCitationView();
      event.preventDefault();
    });
    const stopPointer = (event) => {
      pointers.delete(event.pointerId);
      if (svg.hasPointerCapture?.(event.pointerId)) svg.releasePointerCapture?.(event.pointerId);
      beginGesture();
    };
    svg.addEventListener("pointerup", stopPointer);
    svg.addEventListener("pointercancel", stopPointer);
    svg.addEventListener("wheel", (event) => {
      event.preventDefault();
      zoomCitationView(event.deltaY > 0 ? .86 : 1.16, event.clientX, event.clientY);
    }, { passive: false });
    svg.addEventListener("dblclick", (event) => {
      event.preventDefault();
      zoomCitationView(1.35, event.clientX, event.clientY);
    });
    svg.addEventListener("keydown", (event) => {
      if (["+", "="].includes(event.key)) zoomCitationView(1.25);
      else if (["-", "_"].includes(event.key)) zoomCitationView(.8);
      else if (event.key === "ArrowLeft") citationView.x += 48;
      else if (event.key === "ArrowRight") citationView.x -= 48;
      else if (event.key === "ArrowUp") citationView.y += 48;
      else if (event.key === "ArrowDown") citationView.y -= 48;
      else if (event.key === "0") fitGraph();
      else return;
      applyCitationView();
      event.preventDefault();
    });
  }

  applyLayoutMode(false);
  fitGraph();
  initializeCitationViewport();
  updateDetail(nodes.find((node) => node.id === selectedState.focusNodeId) || nodes.find((node) => node.type === "paper") || nodes[0]);

  fitButton?.addEventListener("click", fitGraph);
  zoomInButton?.addEventListener("click", () => zoomCitationView(1.25));
  zoomOutButton?.addEventListener("click", () => zoomCitationView(.8));
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
    applySeedLimit(seedLimitSelect.value || "8");
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
    legendButtons.forEach((button) => {
      visibleLegendTypes.add(button.dataset.citationLegendType);
      button.setAttribute("aria-pressed", "true");
      button.setAttribute("title", `Hide ${button.textContent.trim().toLowerCase()}`);
    });
    edgeTypeInputs.forEach((input) => input.checked = true);
    selectedState.focusNodeId = "";
    if (clusterToggle) clusterToggle.checked = true;
    updateSavedViewUrl();
    updateGraphState();
    showAppToast("Graph view reset.");
  });
  [...nodeTypeInputs, ...edgeTypeInputs].forEach((input) => input.addEventListener("change", updateGraphState));
  legendButtons.forEach((button) => button.addEventListener("click", () => {
    const type = button.dataset.citationLegendType;
    if (!type) return;
    const isVisible = visibleLegendTypes.has(type);
    if (isVisible) visibleLegendTypes.delete(type); else visibleLegendTypes.add(type);
    button.setAttribute("aria-pressed", isVisible ? "false" : "true");
    button.setAttribute("title", `${isVisible ? "Show" : "Hide"} ${button.textContent.trim().toLowerCase()}`);
    updateGraphState();
    showAppToast(`${button.textContent.trim()} ${isVisible ? "hidden" : "shown"}.`);
  }));
  window.addEventListener("resize", () => {
    ({ width, height } = dimensions());
    applyLayoutMode(layoutMode() !== "force");
    fitGraph();
  });
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", initCitationNetwork, { once: true });
} else {
  initCitationNetwork();
}
