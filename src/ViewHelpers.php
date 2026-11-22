<?php
declare(strict_types=1);

function active_range(string $value, string $current): string
{
    return $value === $current ? 'checked' : '';
}

function query_with(array $changes): string
{
    $query = canonical_query_params($_GET);
    foreach ($changes as $key => $value) {
        if ($value === null) {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }
    $query = canonical_query_params($query);
    return '?' . http_build_query($query);
}

function canonical_query_params(array $query): array
{
    if (isset($query['q']) && !isset($query['search'])) {
        $query['search'] = $query['q'];
    }
    unset($query['q']);
    if (isset($query['range']) && is_scalar($query['range']) && trim((string)$query['range']) !== 'custom') {
        unset($query['from'], $query['to']);
    }
    return $query;
}

/** Public, bounded query parameters for filter controls; omit operational parameters. */
function search_filter_query(array $request): array
{
    $request = canonical_query_params($request);
    $allowed = ['notification','search','author','journal','topic','study_type','cited_doi','year','from','to','added_from','added_to','range','sources','publication_statuses','substances','sort','per_page'];
    $query = [];
    foreach ($allowed as $key) if (isset($request[$key])) {
        if (in_array($key, ['journal','topic'], true) && is_array($request[$key])) {
            $values = AlertFilters::values($request[$key]);
            if ($values) $query[$key] = $values;
        } elseif (in_array($key, ['sources','publication_statuses','substances'], true)) {
            $values = is_array($request[$key]) ? $request[$key] : [$request[$key]];
            $values = array_values(array_unique(array_filter(array_map(static fn($v): string => is_scalar($v) ? mb_substr(trim((string)$v),0,240) : '', $values))));
            if ($values) $query[$key] = $values;
        } elseif (is_scalar($request[$key]) && trim((string)$request[$key]) !== '') {
            $query[$key] = mb_substr(trim((string)$request[$key]),0,500);
        }
    }
    return $query;
}

function search_filter_url(array $request, array $changes): string
{
    $query = search_filter_query($request);
    foreach ($changes as $key=>$value) {
        if ($value === null || $value === []) unset($query[$key]); else $query[$key] = $value;
    }
    $query['page'] = 1;
    return './?' . http_build_query(canonical_query_params($query)) . '#papers';
}

/** Each chip removes its own restriction and preserves the other active filters. */
function active_search_filters(array $filters, array $request): array
{
    $chips = [];
    $add = static function(string $key,string $label,array $changes) use (&$chips,$request): void {
        $chips[] = ['key'=>$key,'label'=>$label,'url'=>search_filter_url($request,$changes)];
    };
    foreach (['q'=>'Search','author'=>'Author','journal'=>'Journal','topic'=>'Topic','study_type'=>'Study type','cited_doi'=>'Cites DOI','year'=>'Year'] as $key=>$label) {
        $value = trim((string)($filters[$key] ?? ''));
        if ($value !== '') $add($key,$label.': '.(in_array($key,['journal','topic'],true)?implode(', ',AlertFilters::displayNames($value)):$value),[$key === 'q' ? 'search' : $key=>null]);
    }
    foreach (['sources'=>'Source','publication_statuses'=>'Status','substances'=>'Substance'] as $key=>$label) {
        $values = array_values(array_unique(array_map('strval',(array)($filters[$key] ?? []))));
        if ($key === 'substances') {
            if (!isset($request[$key]) || !array_diff(['psilocybin','psilocin'],$values)) continue;
        }
        foreach ($values as $value) {
            $remaining = array_values(array_filter($values,static fn(string $v): bool => $v !== $value));
            $display = $key === 'publication_statuses' ? publication_status_label($value) : $value;
            $add($key,$label.': '.$display,[$key=>$remaining ?: null]);
        }
    }
    $range = is_scalar($request['range'] ?? '') ? (string)($request['range'] ?? 'all') : 'all';
    $from = trim((string)($filters['from'] ?? '')); $to = trim((string)($filters['to'] ?? ''));
    if ($from !== '' || $to !== '' || in_array($range,['month','year','5y','custom'],true)) {
        $label = match($range) {'month'=>'Last month','year'=>'Last year','5y'=>'Last 5 years',default=>($from ?: 'Any date').' to '.($to ?: 'Any date')};
        $add('dates','Date: '.$label,['range'=>'all','from'=>null,'to'=>null]);
    }
    $addedFrom = trim((string)($filters['added_from'] ?? '')); $addedTo = trim((string)($filters['added_to'] ?? ''));
    if ($addedFrom !== '' || $addedTo !== '') $add('added_dates','Added: '.($addedFrom ?: 'Any date').' to '.($addedTo ?: 'Any date'),['added_from'=>null,'added_to'=>null]);
    return $chips;
}

function reset_search_filters_url(array $filters): string
{
    $query = ['range'=>'all','page'=>1];
    if (trim((string)($filters['q'] ?? '')) !== '') $query['search'] = trim((string)$filters['q']);
    foreach (['sort','per_page'] as $key) if (!empty($filters[$key])) $query[$key] = $filters[$key];
    return './?' . http_build_query($query) . '#papers';
}

function source_filter_url(string $sourceName): string
{
    return '?' . http_build_query([
        'sources' => [$sourceName],
        'range' => 'all',
        'page' => 1,
    ]) . '#papers';
}

function tracker_query_url(array $params, string $fragment = 'papers'): string
{
    $params = canonical_query_params(array_merge(['range' => 'all', 'page' => 1], $params));
    return './?' . http_build_query($params) . ($fragment !== '' ? '#' . $fragment : '');
}

function chip_link(string $label, array $params, string $class = 'soft'): string
{
    $label = trim($label);
    if ($label === '') {
        return '';
    }
    return '<a class="' . h(trim('tag-link ' . $class)) . '" href="' . h(tracker_query_url($params)) . '">' . h($label) . '</a>';
}

function split_tag_values(?string $value, int $limit = 0): array
{
    $items = array_values(array_unique(array_filter(array_map('trim', explode(',', (string)$value)), static fn(string $item): bool => $item !== '')));
    return $limit > 0 ? array_slice($items, 0, $limit) : $items;
}

function highlight_search_terms(?string $text, ?string $query): string
{
    $text = (string)$text;
    $query = trim((string)$query);
    if ($text === '' || $query === '') {
        return h($text);
    }
    preg_match_all('/[\p{L}\p{N}]+(?:[-\'’][\p{L}\p{N}]+)*/u', $query, $matches);
    $terms = [];
    foreach ($matches[0] ?? [] as $term) {
        $term = trim((string)$term);
        $normalized = mb_strtolower($term, 'UTF-8');
        if (mb_strlen($term, 'UTF-8') < 2 || in_array($normalized, ['and', 'or', 'not'], true)) {
            continue;
        }
        $terms[$normalized] = $term;
        if (count($terms) >= 8) {
            break;
        }
    }
    if (!$terms) {
        return h($text);
    }
    uasort($terms, static fn(string $left, string $right): int => mb_strlen($right, 'UTF-8') <=> mb_strlen($left, 'UTF-8'));
    $alternatives = implode('|', array_map(static fn(string $term): string => preg_quote($term, '/'), array_values($terms)));
    $pattern = '/((?<![\p{L}\p{N}])(?:' . $alternatives . ')(?![\p{L}\p{N}]))/iu';
    $exactPattern = '/^(?:' . $alternatives . ')$/iu';
    $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
    if ($parts === false) {
        return h($text);
    }
    $html = '';
    foreach ($parts as $part) {
        $escaped = h($part);
        $html .= preg_match($exactPattern, $part) === 1
            ? '<mark class="search-keyword-highlight">' . $escaped . '</mark>'
            : $escaped;
    }
    return $html;
}

/**
 * Render a consistent, crawl-safe metadata set for public HTML pages.
 * Titles remain page-owned so they stay visible during source inspection.
 */
function detail_page_seo(string $title, string $description, string $path = '', array $options = []): string
{
    $baseUrl = rtrim(Config::publicBaseUrl(), '/') . '/';
    $canonical = preg_match('~^https?://~i', $path) ? $path : $baseUrl . ltrim($path, '/');
    $robots = (string)($options['robots'] ?? 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1');
    $type = (string)($options['type'] ?? 'website');
    $image = (string)($options['image'] ?? $baseUrl . 'assets/social/psilocybin-research-eu-restored-20261102.png');
    $imageAlt = (string)($options['image_alt'] ?? 'Psilocybin mushroom with a small European Union emblem acknowledging historical FP7 CogNovo support, grant 604764.');
    $siteName = 'Psilocybin Research';

    $tags = [
        funding_meta_tags(),
        agent_discovery_links(),
        '<meta name="theme-color" content="#123c31">',
        '<meta name="color-scheme" content="light">',
        '<meta name="description" content="' . h($description) . '">',
        '<meta name="robots" content="' . h($robots) . '">',
        '<link rel="canonical" href="' . h($canonical) . '">',
        '<meta property="og:locale" content="en_US">',
        '<meta property="og:site_name" content="' . h($siteName) . '">',
        '<meta property="og:type" content="' . h($type) . '">',
        '<meta property="og:title" content="' . h($title) . '">',
        '<meta property="og:description" content="' . h($description) . '">',
        '<meta property="og:url" content="' . h($canonical) . '">',
        '<meta property="og:image" content="' . h($image) . '">',
        '<meta property="og:image:width" content="' . (int)($options['image_width'] ?? 512) . '">',
        '<meta property="og:image:height" content="' . (int)($options['image_height'] ?? 512) . '">',
        '<meta property="og:image:alt" content="' . h($imageAlt) . '">',
        '<meta name="twitter:card" content="summary_large_image">',
        '<meta name="twitter:title" content="' . h($title) . '">',
        '<meta name="twitter:description" content="' . h($description) . '">',
        '<meta name="twitter:image" content="' . h($image) . '">',
        '<meta name="twitter:image:alt" content="' . h($imageAlt) . '">',
    ];
    return implode("\n  ", $tags);
}

function json_ld_script(array $data): string
{
    return '<script type="application/ld+json">' . json_encode(
        LinkedData::normalize($data),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
    ) . '</script>';
}

function research_navigation_groups(): array
{
    return ResearchNavigation::groups();
}

function tracker_tool_catalog(): array
{
    return ResearchNavigation::catalog();
}

function tracker_tool_finder(): string
{
    $catalog = json_encode(tracker_tool_catalog(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    return '<div class="tool-finder" data-tool-finder hidden>'
        . '<button class="tool-finder-launch" type="button" aria-label="Find a tool or page" title="Find a tool or page" aria-expanded="false" aria-controls="tool-finder-panel"><i data-icon="tools" aria-hidden="true"></i></button>'
        . '<div class="tool-finder-panel" id="tool-finder-panel">'
        . '<label class="sr-only" for="tool-finder-input">Find a tool or page</label>'
        . '<div class="tool-finder-field"><i data-icon="tools" aria-hidden="true"></i><input id="tool-finder-input" type="search" placeholder="Find a tool or page…" autocomplete="off" maxlength="100" aria-controls="tool-finder-results" aria-describedby="tool-finder-status"></div>'
        . '<div class="tool-finder-matches" hidden><p id="tool-finder-status" role="status" aria-live="polite"></p><ul id="tool-finder-results" aria-label="Matching tools and pages"></ul><a class="tool-finder-browse" href="research-hub.php#tool-directory">Browse all tools</a></div>'
        . '</div><script type="application/json" data-tool-catalog>'.$catalog.'</script></div>'
        . '<noscript><a href="research-hub.php#tool-directory">Browse all tools and pages</a></noscript>';
}

function research_task_navigation(string $current=''): string
{
    $current = ResearchNavigation::currentKey($current);
    $groups = research_navigation_groups();
    $link = static function (array $item) use ($current): string {
        [$key,$href,$label,$icon] = $item;
        return '<a href="'.h($href).'" title="'.h($label).'"'
            .($key===$current?' aria-current="page"':'')
            .($key==='analytics' && $current==='publications'?' data-open-analytics':'').($key==='data-sources'?' data-open-app-info aria-haspopup="dialog"':'').'>'
            .'<i data-icon="'.h($icon).'" aria-hidden="true"></i><span class="research-nav-label">'.h($label).'</span>'.($href === 'research-workspace.php' ? '<span class="research-unseen-badge" data-sidebar-reading-count hidden></span>' : '').'</a>';
    };
    $html = tracker_tool_finder().'<div class="research-task-nav">';
    $primary = ResearchNavigation::primary();
    foreach ($primary as $item) $html .= $link($item);
    $primaryKeys = array_column($primary, 0);
    foreach ($groups as $title => [$icon,$sections]) {
        $active = false;
        foreach ($sections as $items) $active = $active || (!in_array($current, $primaryKeys, true) && in_array($current,array_column($items,0),true));
        $html .= '<details class="research-nav-section'.($active?' is-current-group':'').'"><summary title="'.h($title).'">'
            .'<i data-icon="'.h($icon).'" aria-hidden="true"></i><span class="research-nav-label">'.h($title).'</span>'
            .'<i class="research-nav-chevron" data-icon="chevron-down" aria-hidden="true"></i></summary>'
            .'<div class="research-task-links">'.(count($sections) > 1 ? '<p class="research-flyout-title">'.h($title).'</p>' : '');
        foreach ($sections as $label => $items) {
            if (count($sections) === 1) {
                foreach ($items as $item) if (!in_array($item[0], $primaryKeys, true)) $html .= $link($item);
                continue;
            }
            if ($label === 'Condition guides') {
                $html .= '<details class="research-nav-subgroup"><summary><span>Condition guides</span><i class="research-nav-chevron" data-icon="chevron-down" aria-hidden="true"></i></summary><div class="research-nav-leaves">';
                foreach ($items as $item) $html .= $link($item);
                $html .= '</div></details>';
                continue;
            }
            $html .= '<div class="research-nav-subgroup"><p class="research-nav-section-label">'.h($label).'</p><div class="research-nav-leaves">';
            foreach ($items as $item) if (!in_array($item[0], $primaryKeys, true)) $html .= $link($item);
            $html .= '</div></div>';
        }
        $html .= '</div></details>';
    }
    return $html.$link(['research-hub','research-hub.php','Tools','wrench']).'</div>';
}

/** Apply navigation state synchronously before the shared header can paint. */
function navigation_bootstrap_script(): string
{
    return <<<'HTML'
<script data-navigation-bootstrap>
(() => {
  const root = document.documentElement;
  root.classList.add('js');
  const compact = window.matchMedia('(max-width: 1180px)').matches;
  let collapsed = compact;
  try {
    const key = compact ? 'publicationTrackerNavSidebarCollapsedMobile' : 'publicationTrackerNavSidebarCollapsedDesktop';
    const saved = window.localStorage.getItem(key);
    if (saved !== null) collapsed = saved === '1';
  } catch (_) {}
  root.classList.toggle('nav-sidebar-collapsed', collapsed);
})();
</script>
HTML;
}

function detail_page_header(string $current = '', bool $isAdmin = false, bool $enhanceNavigation = true): string
{
    $nav = research_task_navigation($current);
    $search = $current === 'publications'
        ? '<button class="header-publication-search" type="button" data-open-advanced data-full-search aria-label="Search publications" aria-haspopup="dialog" aria-controls="advanced-filters"><i data-icon="search" aria-hidden="true"></i><span>Search publications</span></button>'
        : '<a class="header-publication-search" href="./#publication-search" aria-label="Search publications"><i data-icon="search" aria-hidden="true"></i><span>Search publications</span></a>';
    return ($enhanceNavigation ? navigation_bootstrap_script() : '') . '<header class="topbar">'
        . '<div class="primary-sidebar-content" id="primary-sidebar-content">'
        . '<div class="primary-sidebar-header">'
        . '<button class="nav-sidebar-toggle" id="nav-sidebar-toggle" type="button" aria-expanded="true" aria-controls="primary-sidebar-body" title="Collapse sidebar">'
        . '<i data-icon="chevron-left" aria-hidden="true"></i>'
        . '<span class="nav-menu-glyph" aria-hidden="true"><span></span><span></span><span></span></span>'
        . '<span class="sr-only">Collapse sidebar</span>'
        . '</button>'
        . '<button class="mobile-pwa-install" type="button" data-mobile-install-app hidden aria-label="Install the Psilocybin Research app" title="Install app"><i data-icon="download" aria-hidden="true"></i><span class="sr-only">Install app</span></button>'
        . '<a class="brand brand-lockup" href="./">'
        . '<img class="brand-icon brand-icon-mushroom" src="assets/mushroom-brand-mark.webp" srcset="assets/mushroom-brand-mark-96.webp 96w, assets/mushroom-brand-mark-144.webp 144w, assets/mushroom-brand-mark-192.webp 192w" sizes="46px" alt="" width="46" height="46">'
        . '<span class="brand-text"><strong>Psilocybin-Research.com</strong><em>Searchable psilocybin and psilocin bibliometric database.</em></span>'
        . '</a>'
        . $search
        . '<button class="floating-utility-button global-fullscreen-button" id="sidebar-fullscreen-toggle" type="button" hidden aria-pressed="false" title="Open fullscreen"><i data-icon="maximize" aria-hidden="true"></i><span>Fullscreen</span></button>'
        . (is_file(__DIR__ . '/../account.php') ? '<a class="account-header-button" href="/account.php" data-account-navigation><i data-icon="user-round" aria-hidden="true"></i><span data-account-navigation-label style="position:absolute;width:1px;height:1px;overflow:hidden;clip-path:inset(50%);white-space:nowrap">Account</span></a>' : '')
        . '<button class="floating-utility-button global-share-button" id="sidebar-share-results" type="button" hidden title="Share this page" aria-label="Share this page"><i data-icon="share-2" aria-hidden="true"></i><span>Share</span></button>'
        . '<button class="floating-utility-button global-print-button" id="sidebar-print-results" type="button" title="Print this page" aria-label="Print this page"><i data-icon="printer" aria-hidden="true"></i><span>Print</span></button>'
        . '</div>'
        . '<div class="primary-sidebar-body" id="primary-sidebar-body">'
        . '<nav aria-label="Research sections">' . $nav . '</nav>'
        . tracker_action_controls($current, $isAdmin)
        . '</div></div>'
        . '</header>'
        . (is_file(__DIR__.'/../assets/account-presence.js')?'<script src="'.h(FrontendAssets::url('assets/account-presence.js')).'" defer></script>':'')
        . (is_file(__DIR__.'/../assets/account-analysis-device-status.js')?'<script type="module" src="/assets/account-analysis-device-status.js?v=20261110-device-status-v5"></script>':'');
}

/** Shared sidebar actions for every secondary research page. */
function tracker_action_controls(string $current = '', bool $isAdmin = false): string
{

    return '<details class="sidebar-preferences"><summary><i data-icon="bell-plus" aria-hidden="true"></i><span>Alerts</span><i class="research-nav-chevron" data-icon="chevron-down" aria-hidden="true"></i></summary><div class="top-actions">'
        . '<a href="./#alerts"' . ($current === 'publications' ? ' data-open-alerts' : '') . '><i data-icon="mail" aria-hidden="true"></i><span>Email alerts</span></a>'
        . '<button class="push-app" id="push-app" type="button"><i data-icon="bell-plus" aria-hidden="true"></i><span>Push notifications</span></button>'
        . '</div></details>'
        . '<details class="sidebar-preferences"' . ($current === 'offline-r' ? ' open' : '') . '><summary><i data-icon="download" aria-hidden="true"></i><span>App &amp; offline</span><i class="research-nav-chevron" data-icon="chevron-down" aria-hidden="true"></i></summary><div class="top-actions">'
        . '<button class="install-app" id="install-app" type="button" hidden><i data-icon="download" aria-hidden="true"></i><span>Install app</span></button>'
        . '<button class="install-app" type="button" data-open-offline><i data-icon="download" aria-hidden="true"></i><span>Offline library</span></button>'
        . '<a href="/r-analysis/offline/"' . ($current === 'offline-r' ? ' aria-current="page"' : '') . '><i data-icon="r-script" aria-hidden="true"></i><span>Offline R analysis</span></a>'
        . '</div></details>'
        . ($isAdmin ? '<a class="account-nav-link admin-link" href="#admin"><i data-icon="shield" aria-hidden="true"></i><span>Admin</span></a>' : '')
        . tracker_info_dialog();
}

function tracker_info_dialog(): string
{
    return <<<'HTML'
<dialog class="app-info-modal" id="app-info-modal" aria-labelledby="app-info-title">
  <div class="app-info-sheet">
    <div class="advanced-filter-head">
      <div>
        <h2 id="app-info-title">Data sources</h2>
        <p>Source-labeled psilocybin and psilocin literature for search, monitoring, export, and citation workflows.</p>
      </div>
      <button type="button" class="advanced-filter-close" data-close-app-info aria-label="Close data sources"><i data-icon="x" aria-hidden="true"></i></button>
    </div>
    <div class="app-info-body">
      <section class="app-info-network-section" aria-labelledby="app-info-network-title">
        <h3 id="app-info-network-title">How the research index is built</h3>
        <figure class="app-info-network">
          <svg viewBox="0 0 560 360" role="img" aria-labelledby="app-info-graph-title app-info-graph-desc">
            <title id="app-info-graph-title">Eight research sources feed Psilocybin-Research.com</title>
            <desc id="app-info-graph-desc">PubMed, OpenAlex, Crossref, Europe PMC, ClinicalTrials.gov, PsyArXiv, bioRxiv, and medRxiv provide records that are reconciled into the central research index.</desc>
            <defs><marker id="app-info-arrow" viewBox="0 0 8 8" refX="7" refY="4" markerWidth="5" markerHeight="5" orient="auto"><path d="M0 0 L8 4 L0 8 z"></path></marker></defs>
            <g class="app-info-network-links" aria-hidden="true">
              <path d="M280 58 L280 111" style="--flow-delay:0s"></path>
              <path d="M370 97 L328 132" style="--flow-delay:-.35s"></path>
              <path d="M385 180 L349 180" style="--flow-delay:-.7s"></path>
              <path d="M370 263 L328 228" style="--flow-delay:-1.05s"></path>
              <path d="M280 302 L280 249" style="--flow-delay:-1.4s"></path>
              <path d="M190 263 L232 228" style="--flow-delay:-1.75s"></path>
              <path d="M175 180 L211 180" style="--flow-delay:-2.1s"></path>
              <path d="M190 97 L232 132" style="--flow-delay:-2.45s"></path>
            </g>
            <g class="app-info-network-hub">
              <circle cx="280" cy="180" r="69"></circle>
              <text x="280" y="174"><tspan x="280">psilocybin-</tspan><tspan x="280" dy="20">research.com</tspan></text>
            </g>
            <g class="app-info-network-nodes">
              <g transform="translate(225 23)"><rect width="110" height="34" rx="12"></rect><text x="55" y="22">PubMed</text></g>
              <g transform="translate(335 80)"><rect width="110" height="34" rx="12"></rect><text x="55" y="22">OpenAlex</text></g>
              <g transform="translate(385 163)"><rect width="110" height="34" rx="12"></rect><text x="55" y="22">Crossref</text></g>
              <g transform="translate(335 246)"><rect width="110" height="34" rx="12"></rect><text x="55" y="22">Europe PMC</text></g>
              <g transform="translate(213 303)"><rect width="134" height="34" rx="12"></rect><text x="67" y="22">ClinicalTrials.gov</text></g>
              <g transform="translate(115 246)"><rect width="110" height="34" rx="12"></rect><text x="55" y="22">PsyArXiv</text></g>
              <g transform="translate(65 163)"><rect width="110" height="34" rx="12"></rect><text x="55" y="22">bioRxiv</text></g>
              <g transform="translate(115 80)"><rect width="110" height="34" rx="12"></rect><text x="55" y="22">medRxiv</text></g>
            </g>
          </svg>
          <figcaption>Source records are fetched, reconciled, and labeled before appearing in the live index. The original sources remain authoritative.</figcaption>
        </figure>
      </section>
      <section>
        <h3>Research workflow</h3>
        <p>Search, filter, export, analyze, and monitor publications with source labels, publication status, citation links, alerts, API access, and a rights-safe SQLite download. <a href="about.php">Read about the methods and limitations.</a> Explore the <a href="research-dataset.php">dataset and reuse guide</a> or <a href="research-trends.php">research trends by year</a>.</p>
      </section>
    </div>
  </div>
</dialog>
HTML;
}

function detail_scroll_top(): string
{
    return '<button class="scroll-top" id="scroll-top" type="button" aria-label="Scroll to top"><i data-icon="arrow-up" aria-hidden="true"></i><span>Top</span></button>';
}

/** A consistent compact footer for all human-facing tracker pages. */
function tracker_footer(): string
{
    static $footer = null;
    if ($footer !== null) return $footer;
    $sources = [];
    $stats = ['total' => 0];
    $statuses = [];
    $lastUpdate = null;
    $latestDoi = null;
    $latestAddedAt = null;
    $started = microtime(true);
    try {
        $db = new Database();
        $db->initialize();
        $repo = new PublicationRepository($db);
        $stats = $repo->stats();
        $sources = array_map(static fn(array $row): string => (string)$row['source_name'], $repo->sources());
        foreach ($db->pdo()->query('SELECT publication_status, COUNT(*) AS count FROM publications WHERE hidden = 0 AND false_positive = 0 GROUP BY publication_status') as $row) {
            $statuses[(string)$row['publication_status']] = (int)$row['count'];
        }
        $lastUpdate = $db->pdo()->query("SELECT finished_at FROM fetch_runs WHERE status = 'ok' ORDER BY finished_at DESC LIMIT 1")->fetchColumn() ?: null;
        $latest = $db->pdo()->query("SELECT doi, date_added FROM publications WHERE hidden = 0 AND false_positive = 0 AND doi IS NOT NULL AND doi != '' ORDER BY date_added DESC, id DESC LIMIT 1")->fetch();
        if (is_array($latest)) { $latestDoi = normalize_doi((string)$latest['doi']); $latestAddedAt = (string)$latest['date_added']; }
    } catch (Throwable) {}
    $sourceText = $sources ? implode(', ', $sources) : 'Unavailable';
    $sourceCount = count($sources);
    $latest = $latestDoi !== null
        ? h(format_utc_display($latestAddedAt)) . ' · <a href="https://doi.org/' . h($latestDoi) . '" target="_blank" rel="noopener">DOI ' . h($latestDoi) . '</a>'
        : 'unavailable';
    $visitor = '<a href="visitor-statistics.php">Visitor statistics</a>';
    $queryMs = number_format((microtime(true) - $started) * 1000, 1);
    $footer = '<footer class="footer tracker-footer">'
        . '<div class="footer-summary"><span>Data sources: ' . h($sourceText) . '.</span><strong>Live index: ' . h(number_format((int)($stats['total'] ?? 0))) . ' records from ' . h(number_format($sourceCount)) . ' sources · ' . h(number_format((int)($stats['journals'] ?? 0))) . ' journals; ' . h(number_format($statuses['published'] ?? 0)) . ' published, ' . h(number_format($statuses['preprint'] ?? 0)) . ' preprints, ' . h(number_format($statuses['clinical trial'] ?? 0)) . ' clinical trials.</strong><span>Coverage is not exhaustive; verify records before citation or clinical interpretation.</span></div>'
        . '<div class="footer-db-meta">Generated ' . h(format_utc_display(current_utc())) . ' · <a href="research-dataset.php#verified-downloads">Public SQLite download</a> · <a href="research-dataset.php#download-parquet">Parquet</a> · <a href="research-dataset.php#download-parquet">Feather</a> · Last update ' . h(format_utc_display($lastUpdate)) . ' · Last DOI article added ' . $latest . ' · DB query ' . h($queryMs) . ' ms. · Version 2.1.6 · ' . h(gmdate('Y')) . '. <span id="client-environment" data-client-environment>Browser and operating system details loading.</span></div>'
        . '<div class="footer-app-meta">Application created by <a href="editorial-policy.php#author">Dr. Christopher B. Germann</a> · Faculty of Health, School of Medicine, University of Witten/Herdecke · <a href="about.php">About</a> · <a href="/credits.php">Credits and licences</a> · <a href="editorial-policy.php">Editorial policy</a> · <a href="data-protection.php">Data protection</a> · ' . $visitor . '.</div>'
        . '<div class="footer-research-resources"><a href="research-hub.php">Free research tools</a> · <a href="research-workspace.php">Reading list</a> · <a href="psilocybin-research-guide/">Research reading guide</a> · <a href="psilocybin-alcohol-use-disorder-research/">Alcohol research evidence</a> · <a href="psilocybin-depression-research/">Depression research evidence</a> · <a href="research-dataset.php">Research dataset, downloads and citation</a> · <a href="for-ai.html">Data and AI access guide</a> · <a href="for-ai.html#connect-using-mcp">Model Context Protocol</a> · <a href="https://github.com/psilocybin-research/psilocybin-research-tracker" target="_blank" rel="noopener">GitHub</a> · <a href="https://doi.org/10.5281/zenodo.21293525" target="_blank" rel="noopener">Zenodo</a> · <a href="research-trends.php">Psilocybin research trends</a> · <a href="psilocybin-research-brief-2026-10/">October 2026 research brief</a> · <a href="https://research-and-innovation.ec.europa.eu/strategy/strategy-research-and-innovation/our-digital-future/open-science_en" target="_blank" rel="noopener">European Commission · Open Science</a></div>'
        . funding_acknowledgement() . '</footer>';
    return $footer;
}

function detail_footer(): string
{
    return tracker_footer();
}

/** Server-confirmed result window, without redistributing any record text. */
function publication_loading_context(array $result, array $filters, string $description = ''): array
{
    $total = max(0, (int)$result['total']);
    $count = count($result['rows']);
    $all = $result['per_page'] === 'all';
    $start = $count ? (($result['page'] - 1) * (int)$result['per_page']) + 1 : 0;
    $end = $count ? $start + $count - 1 : 0;
    $title = $all ? 'Loading all ' . number_format($count) . ' matching records…'
        : 'Loading ' . number_format($count) . ' of ' . number_format($total) . ' matching records…';
    $sort = match ($filters['sort']) { 'newest' => 'Newest first', 'oldest' => 'Oldest first', default => 'Recently added first' };
    $detail = 'Records ' . number_format($start) . '–' . number_format($end)
        . ' · Page ' . $result['page'] . ' of ' . $result['pages'] . ' · ' . $sort;
    if ($description !== '') $detail .= ' · ' . $description;
    return ['title' => $title, 'detail' => $detail];
}

function detail_preloader(string $label = 'Loading data…', string $ariaLabel = 'Loading page data'): string
{
    return '<div class="app-preloader" id="app-preloader" role="status" aria-live="polite" aria-label="' . h($ariaLabel) . '">'
        . '<div class="preloader-shell">'
        . '<strong class="preloader-title">' . h($label) . '</strong>'
        . '<span class="preloader-meter" aria-hidden="true"><span></span></span>'
        . '</div></div>';
}

/**
 * Crawlable links to substantial, curated trial collections. These are kept
 * in page context instead of the compact primary sidebar.
 */
function trial_discovery_links(string $currentPath = ''): string
{
    $groups = [
        'Recruitment' => [
            ['recruiting-psilocybin-clinical-trials/', 'Recruiting psilocybin clinical trials'],
        ],
        'Conditions' => [
            ['psilocybin-clinical-trials-depression/', 'Depression trials'],
            ['psilocybin-clinical-trials-alcohol-use-disorder/', 'Alcohol use disorder trials'],
            ['psilocybin-clinical-trials-ptsd/', 'PTSD trials'],
            ['psilocybin-clinical-trials-ocd/', 'OCD trials'],
            ['psilocybin-clinical-trials-cancer-distress/', 'Cancer-related distress trials'],
            ['psilocybin-clinical-trials-anxiety/', 'Anxiety trials'],
            ['psilocybin-clinical-trials-opioid-use-disorder/', 'Opioid use disorder trials'],
            ['psilocybin-clinical-trials-healthy-volunteers/', 'Healthy volunteer trials'],
        ],
        'Countries' => [
            ['psilocybin-clinical-trials-united-states/', 'United States trials'],
            ['psilocybin-clinical-trials-canada/', 'Canada trials'],
            ['psilocybin-clinical-trials-united-kingdom/', 'United Kingdom trials'],
            ['psilocybin-clinical-trials-switzerland/', 'Switzerland trials'],
        ],
        'Phases' => [
            ['early-phase-1-psilocybin-clinical-trials/', 'Early Phase 1 trials'],
            ['phase-1-psilocybin-clinical-trials/', 'Phase 1 trials'],
            ['phase-2-psilocybin-clinical-trials/', 'Phase 2 trials'],
            ['phase-3-psilocybin-clinical-trials/', 'Phase 3 trials'],
        ],
    ];
    $html = '<div class="trial-discovery-grid">';
    foreach ($groups as $label => $links) {
        $html .= '<section><h3>' . h($label) . '</h3><ul>';
        foreach ($links as [$path, $text]) {
            if ($path === $currentPath) {
                $html .= '<li><span aria-current="page">' . h($text) . '</span></li>';
            } else {
                $html .= '<li><a href="' . h($path) . '">' . h($text) . '</a></li>';
            }
        }
        $html .= '</ul></section>';
    }
    return $html . '</div>';
}

function funding_meta_tags(): string
{
    $funding = LinkedData::funding();
    return '<meta name="funding" content="' . h($funding['funder']['name'] . ' | ' . $funding['name'] . ' | ' . $funding['identifier'] . ' | Historical infrastructure support') . '">'
        . "\n  " . '<meta name="DC.relation" content="' . h($funding['url']) . '">';
}

function funding_acknowledgement(): string
{
    return '<aside class="footer-funding" aria-label="Historical funding acknowledgement">'
        . '<div class="footer-funding-logos" aria-label="CogNovo, Marie Curie Actions and European Union">'
        . '<a class="footer-funding-logo footer-funding-cognovo" href="https://cognovo.eu/" target="_blank" rel="noopener noreferrer" aria-label="CogNovo programme">'
        . '<img src="assets/funding/cognovo-logo.png" alt="CogNovo" width="415" height="155" loading="lazy" decoding="async">'
        . '</a>'
        . '<a class="footer-funding-logo footer-funding-marie" href="https://marie-sklodowska-curie-actions.ec.europa.eu/" target="_blank" rel="noopener noreferrer" aria-label="Marie Skłodowska-Curie Actions">'
        . '<img src="assets/funding/marie-curie.svg" alt="Marie Curie Actions" width="57" height="77" loading="lazy" decoding="async">'
        . '</a>'
        . '<a class="footer-funding-logo footer-funding-eu" href="https://cordis.europa.eu/project/id/604764/reporting" target="_blank" rel="noopener noreferrer" aria-label="European Union FP7 CogNovo grant 604764">'
        . '<img src="assets/funding/eu-marie-curie-actions.jpg" alt="European Union Research and Innovation Marie Curie Actions" width="481" height="150" loading="lazy" decoding="async">'
        . '</a>'
        . '</div>'
        . '<p class="footer-funding-copy">The psilocybin research infrastructure and psilocybin-research.com were developed with support from the European Union’s 7<sup>th</sup> Framework Programme through the Marie Skłodowska-Curie Initial Training Network CogNovo (grant agreement No. 604764).</p>'
        . '<p class="footer-funding-copy"><a href="https://cordis.europa.eu/project/id/604764" target="_blank" rel="noopener noreferrer">European Commission · FP7-PEOPLE-2013-ITN-604764</a></p>'
        . '<p class="footer-funding-copy"><a href="https://ukri.psilocybin-research.com/msca-impact/">MSCA impact story at the Royal Society London</a> (available in 20 languages) · <a href="https://www.discover.ukri.org/msca-uk30-event/msca-uk30-campaign/msca-case-studies/#section-85jP6XzNib" hreflang="en" target="_blank" rel="noopener noreferrer" aria-label="UKRI MSCA feature (English, opens in a new tab)">UKRI MSCA feature</a></p>'
        . '</aside>';
}

function hidden_query_inputs(array $exclude = []): string
{
    $html = '';
    $exclude = array_fill_keys($exclude, true);
    $append = function (string $name, mixed $value) use (&$html, &$append): void {
        if (is_array($value)) {
            foreach ($value as $key => $childValue) {
                $append($name . '[' . $key . ']', $childValue);
            }
            return;
        }
        $html .= '<input type="hidden" name="' . h($name) . '" value="' . h((string)$value) . '">' . "\n";
    };
    foreach (canonical_query_params($_GET) as $key => $value) {
        if (isset($exclude[(string)$key])) {
            continue;
        }
        $append((string)$key, $value);
    }
    return $html;
}

function publication_status_label(?string $status): string
{
    $status = PublicationRepository::normalizePublicationStatus($status);
    return match ($status) {
        'preprint' => 'PREPRINT (not peer reviewed)',
        'clinical trial' => 'CLINICAL TRIAL',
        'protocol' => 'PROTOCOL',
        'review' => 'REVIEW',
        default => 'Published',
    };
}

function publication_status_class(?string $status): string
{
    return 'status-' . str_replace(' ', '-', PublicationRepository::normalizePublicationStatus($status));
}

function publication_recency_badge(?string $publicationDate): string
{
    $publicationDate = trim((string)$publicationDate);
    if ($publicationDate === '') {
        return '';
    }
    try {
        $published = new DateTimeImmutable(substr($publicationDate, 0, 10), new DateTimeZone('UTC'));
        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
    } catch (Throwable) {
        return '';
    }
    $days = (int)$published->diff($today)->format('%r%a');
    if ($days < 0 || $days > 31) {
        return '';
    }
    $label = $days <= 7 ? 'New this week' : 'New this month';
    $class = $days <= 7 ? 'recency-week' : 'recency-month';
    return '<span class="recency-badge ' . h($class) . '" data-publication-date="' . h($publicationDate) . '">' . h($label) . '</span>';
}

/** Explain only visible public metadata; never reproduce private source text. */
function search_match_explanation(array $paper, string $query): string
{
    $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach (['title'=>'title', 'authors'=>'authors', 'journal'=>'journal', 'doi'=>'DOI', 'pubmed_id'=>'PubMed identifier', 'topic_tags'=>'derived topics', 'study_type'=>'derived study type'] as $key=>$label) {
        $text = mb_strtolower((string)($paper[$key] ?? ''));
        if ($tokens && count(array_filter($tokens, static fn($token)=>str_contains($text, $token))) === count($tokens)) return 'Query terms appear in the ' . $label . '.';
    }
    return 'Matched indexed metadata, identifiers, or source text. Open the source to check relevance.';
}

/** Public documentation and discovery, separate from private operational instructions. */
function agent_discovery_links(): string
{
    return '<link rel="describedby" href="/llms.txt" type="text/plain">'
        . '<link rel="describedby" href="/for-ai.html" type="text/html" title="Research API and MCP access">'
        . '<link rel="describedby" href="/for-ai.txt" type="text/plain">'
        . '<link rel="describedby" href="https://raw.githubusercontent.com/psilocybin-research/psilocybin-research-tracker/main/docs/research-agent-guide.md" type="text/plain">'
        . '<link rel="ard" href="/.well-known/ard.json" type="application/json">'
        . '<link rel="service-desc" href="/docs/research-api.openapi.json" type="application/vnd.oai.openapi+json">';
}
