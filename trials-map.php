<?php
declare(strict_types=1);

require_once __DIR__ . '/src/helpers.php';

$db = new Database();
$db->initialize();
$dataset = (new TrialMapService($db))->dataset();
$summary = $dataset['summary'];
$assetVersion = '20261017-pwa-funnel-v272';
$isEmbedded = request_value('embed') === '1';
$pageTitle = 'Psychedelic Trials Map | Psilocybin Research Sites Worldwide';
$description = 'Explore psilocybin and psilocin clinical trials by mapped research site, recruitment status, phase, condition, intervention, sponsor, and date.';
$baseUrl = rtrim(Config::publicBaseUrl(), '/') . '/';
$json = json_encode(
    $dataset,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($pageTitle) ?></title>
  <?= detail_page_seo($pageTitle, $description, 'trials-map.php', ['robots' => $isEmbedded ? 'noindex, follow, noarchive' : 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1']) ?>
  <?= json_ld_script(['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => $pageTitle, 'description' => $description, 'url' => $baseUrl . 'trials-map.php', 'numberOfItems' => (int)$summary['trials'], 'spatialCoverage' => ['@type' => 'Place', 'name' => 'Worldwide'], 'about' => LinkedData::term('topic', 'Clinical Trial'), 'isPartOf' => ['@type' => 'WebSite', 'name' => 'Psilocybin Research', 'url' => $baseUrl]]) ?>
  <link rel="preload" href="assets/preloader-mushroom-desktop.webp" as="image" media="(min-width: 701px)" fetchpriority="high">
  <link rel="preload" href="assets/preloader-mushroom-mobile.webp" as="image" media="(max-width: 700px)" fetchpriority="high">
  <link rel="stylesheet" href="<?= h(FrontendAssets::url('assets/styles.min.css')) ?>">
  <script src="<?= h(FrontendAssets::url('assets/app.min.js')) ?>" defer></script>
  <script src="<?= h(FrontendAssets::url('assets/trials-map.min.js')) ?>" defer></script>
  <script>document.documentElement.classList.add('js', 'is-loading');</script>
</head>
<body class="detail-page trials-map-page<?= $isEmbedded ? ' is-embedded' : '' ?>">
<?php if (!$isEmbedded): ?>
<?= detail_preloader('Mapping trial sites…', 'Loading the psychedelic trials map') ?>
<?= detail_page_header('trials-map') ?>
<?php endif; ?>
<main class="detail-shell trials-map-shell">
  <section class="panel detail-hero trials-map-hero">
    <div class="trials-map-hero-copy">
      <div class="detail-hero-intro">
        <div><span class="eyebrow">Geographic trial intelligence</span><h1>Where psychedelic trials happen</h1><p>Explore registry-reported psilocybin and psilocin study sites, then compare recruitment status, phase, indication, sponsor, design, and timing without losing the geographic context.</p></div>
      </div>
      <div class="trials-map-hero-actions">
        <a class="primary iconed" href="#trial-map"><i data-icon="map-pin" aria-hidden="true"></i><span>Explore the map</span></a>
        <a class="secondary iconed" href="recruiting-psilocybin-clinical-trials/"><i data-icon="search" aria-hidden="true"></i><span>Recruiting psilocybin trials</span></a>
        <a class="secondary iconed" href="trials.php"><i data-icon="clipboard-list" aria-hidden="true"></i><span>Open trial tracker</span></a>
      </div>
    </div>
    <div class="trials-map-hero-stats" aria-label="Indexed trial geography summary">
      <div><strong><?= h(number_format((int)$summary['trials'])) ?></strong><span>trial records</span></div>
      <div><strong><?= h(number_format((int)$summary['sites'])) ?></strong><span>mapped sites</span></div>
      <div><strong><?= h(number_format((int)$summary['countries'])) ?></strong><span>countries</span></div>
      <div><strong><?= h(number_format((int)$summary['recruiting'])) ?></strong><span>open or upcoming</span></div>
      <p class="trials-map-freshness"><i aria-hidden="true"></i><span>Auto-updated after scheduled ClinicalTrials.gov refreshes</span><small>Registry facts checked <?= h(format_utc_display((string)($dataset['registry_checked_at_utc'] ?? ''))) ?></small></p>
    </div>
  </section>

  <section class="trial-map-workspace" id="trial-map" data-trial-map-fullscreen-target>
    <div class="panel trial-map-card">
      <div class="section-head-row trial-map-heading">
        <div>
          <span class="eyebrow">1 · Explore geography</span>
          <h2>Research sites</h2>
          <p data-trial-map-summary>Preparing the indexed trial geography…</p>
        </div>
      </div>
      <div class="trial-map-toolbar map-explorer-toolbar">
        <p class="trial-map-gesture-hint"><i data-icon="move" aria-hidden="true"></i><span data-map-hint>Tap a site for details · Drag to pan · Ctrl + scroll to zoom</span></p>
        <div class="trial-map-actions map-explorer-actions" aria-label="Map controls">
          <button class="secondary map-touch-toggle" type="button" data-map-interaction aria-pressed="false">Move map</button>
          <button class="secondary compact" type="button" data-trial-zoom-out aria-label="Zoom out"><span aria-hidden="true">−</span></button>
          <button class="secondary compact" type="button" data-trial-zoom-in aria-label="Zoom in"><span aria-hidden="true">+</span></button>
          <button class="secondary" type="button" data-trial-fit><i data-icon="maximize" aria-hidden="true"></i><span>Fit results</span></button>
          <button class="secondary" type="button" data-trial-fullscreen hidden aria-pressed="false"><i data-icon="maximize" aria-hidden="true"></i><span>Fullscreen</span></button>
        </div>
      </div>
      <a class="map-options-link" href="#trial-map-filters">Filters &amp; results ↓</a>
      <div class="trial-map-legend" aria-label="Show or hide recruitment statuses" role="group">
        <button type="button" data-trial-legend="open" aria-pressed="true" title="Hide open or upcoming"><i class="trial-legend-open" aria-hidden="true"></i>Open or upcoming</button>
        <button type="button" data-trial-legend="active" aria-pressed="true" title="Hide active, closed to recruitment"><i class="trial-legend-active" aria-hidden="true"></i>Active, closed to recruitment</button>
        <button type="button" data-trial-legend="complete" aria-pressed="true" title="Hide completed"><i class="trial-legend-complete" aria-hidden="true"></i>Completed</button>
        <button type="button" data-trial-legend="stopped" aria-pressed="true" title="Hide stopped or unknown"><i class="trial-legend-stopped" aria-hidden="true"></i>Stopped or unknown</button>
        <span class="trial-legend-size"><i></i>Circle size = trials at site</span>
      </div>
      <div class="trial-map-canvas" data-trial-map data-world-map="assets/world-110m.geojson?v=<?= h($assetVersion) ?>" aria-label="Interactive world map of registry-reported psychedelic clinical trial sites" role="region">
        <div class="trial-map-loading"><span class="spinner" aria-hidden="true"></span><span>Building the trials map…</span></div>
      </div>
      <div class="trial-map-insight" data-trial-map-insight aria-live="polite">Map and list will remain synchronized as you filter.</div>
  <section class="trial-command-bar" id="trial-map-filters" tabindex="-1" aria-label="Essential trial map filters">
    <label class="trial-command-search">
      <span>Find a trial</span>
      <span class="input-with-icon"><i data-icon="search" aria-hidden="true"></i><input type="search" data-trial-search autocomplete="off" placeholder="Condition, sponsor, intervention, NCT ID"></span>
    </label>
    <label><span>Recruitment status</span><select data-trial-status><option value="">All statuses</option></select></label>
    <label><span>Country</span><select data-trial-country><option value="">All countries</option></select></label>
    <label class="trial-recruiting-toggle"><span>Recruiting lens</span><input type="checkbox" data-trial-recruiting><em>Open or upcoming only</em></label>
    <button class="secondary trial-reset" type="button" data-trial-reset><i data-icon="refresh-cw" aria-hidden="true"></i><span>Reset</span></button>
  </section>
      <p class="trial-map-note"><strong>How to read this:</strong> one trial can appear at several sites. Markers represent registry-reported facilities with coordinates, not participant eligibility or real-time appointment availability. Select a marker to focus its trials.</p>
    </div>

    <aside class="panel trial-profile" data-trial-profile aria-live="polite">
      <span class="eyebrow">2 · Inspect a study</span>
      <h2>Choose a site or trial</h2>
      <p>Select a map marker or a record below to inspect its design and registry timeline here.</p>
      <div class="trial-profile-empty-grid" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
      <p class="trial-profile-tip"><i data-icon="info" aria-hidden="true"></i><span>Map selection never hides the full filter state. Clear the selected site at any time to return to the broader evidence view.</span></p>
    </aside>
  </section>



  <section class="panel trial-research-lens" aria-labelledby="trial-lens-heading">
    <div class="section-head-row">
      <div>
        <span class="eyebrow">3 · Compare the evidence landscape</span>
        <h2 id="trial-lens-heading">Research lens</h2>
        <p>Summaries update with the map, making geographic concentration and study maturity visible before record-level review.</p>
      </div>
      <button class="secondary" type="button" data-trial-share><i data-icon="share-2" aria-hidden="true"></i><span>Copy view link</span></button>
    </div>
    <div class="trial-lens-grid">
      <article><span>Status mix</span><div class="trial-mini-bars" data-trial-status-bars></div></article>
      <article><span>Phase coverage</span><div class="trial-mini-bars" data-trial-phase-bars></div></article>
      <article><span>Leading countries</span><div class="trial-mini-bars" data-trial-country-bars></div></article>
      <article class="trial-lens-narrative"><span>Current view</span><p data-trial-narrative>Preparing a concise interpretation of the selected evidence landscape…</p></article>
    </div>
  </section>

  <section class="panel trial-results-panel" id="trial-results">
    <div class="trial-results-heading">
      <div>
        <span class="eyebrow">4 · Review records</span>
        <h2 data-trial-results-count>Trial records</h2>
        <p data-trial-results-context>Registry facts only; descriptions and source payloads are not redistributed.</p>
      </div>
      <div class="trial-results-actions">
        <label><span>Sort</span><select data-trial-sort><option value="relevance">Recruitment relevance</option><option value="updated">Recently updated</option><option value="start">Newest start date</option><option value="enrollment">Largest enrollment</option><option value="title">Title A–Z</option></select></label>
        <button class="secondary" type="button" data-trial-export><i data-icon="table" aria-hidden="true"></i><span>Export view</span></button>
      </div>
    </div>
    <details class="trial-advanced-filters">
      <summary><span><i data-icon="list-filter" aria-hidden="true"></i>Refine phase, condition, intervention, substance, or dates</span><em data-trial-filter-count>No advanced filters</em></summary>
      <div class="trial-advanced-grid">
        <label><span>Phase</span><select data-trial-phase><option value="">All phases</option></select></label>
        <label><span>Condition</span><select data-trial-condition><option value="">All conditions</option></select></label>
        <label><span>Intervention</span><select data-trial-intervention><option value="">All interventions</option></select></label>
        <label><span>Substance</span><select data-trial-substance><option value="">All substances</option></select></label>
        <label><span>Start year from</span><input type="number" min="1900" max="2100" step="1" inputmode="numeric" data-trial-year-from placeholder="Any year"></label>
        <label><span>Start year to</span><input type="number" min="1900" max="2100" step="1" inputmode="numeric" data-trial-year-to placeholder="Any year"></label>
      </div>
    </details>
    <div class="trial-selected-site" data-trial-selected-site hidden></div>
    <div class="trial-record-list" data-trial-results-list></div>
    <button class="secondary trial-load-more" type="button" data-trial-load-more hidden>Show more records</button>
  </section>

  <section class="panel trial-method-note">
    <div>
      <span class="eyebrow">Interpretation and provenance</span>
      <h2>Built for discovery, not recruitment advice</h2>
    </div>
    <div>
      <p>Statuses, dates, enrollment, and locations are factual fields reported by study sponsors to ClinicalTrials.gov and can lag local site changes. Confirm every decision in the linked registry record and with the study team.</p>
      <p>The page exposes an allowlisted projection only: titles, identifiers, sponsors, study design facts, dates, interventions, conditions, and site coordinates. Abstracts, registry descriptions, source-derived keywords, contacts, and raw payloads remain excluded.</p>
      <p class="trial-map-attribution">Geographic context: <a href="https://www.naturalearthdata.com/" target="_blank" rel="noopener">Natural Earth</a>, public domain. Boundaries do not imply a position on legal status or sovereignty. Collection rules and corrections are documented in the <a href="editorial-policy.php">editorial policy</a>.</p>
    </div>
  </section>
  <?php if (!$isEmbedded): ?>
  <section class="panel trial-discovery-section" aria-labelledby="map-trial-discovery-heading">
    <div class="section-head-row"><div><span class="eyebrow">Crawlable research collections</span><h2 id="map-trial-discovery-heading">Explore trials by research question</h2><p>Use the map for interactive comparison or open a server-rendered collection for current records, counts, direct registry links, and a focused geographic view.</p></div></div>
    <?= trial_discovery_links() ?>
  </section>
  <?php endif; ?>
</main>
<?php if (!$isEmbedded): ?>
<?= detail_footer() ?>
<?php endif; ?>
<dialog class="trial-map-mobile-dialog" data-trial-mobile-dialog aria-labelledby="trial-map-mobile-title">
  <section class="trial-map-mobile-sheet">
    <header class="trial-map-mobile-header">
      <div><span>Selected research site</span><h2 id="trial-map-mobile-title" data-trial-mobile-title>Site details</h2></div>
      <form method="dialog"><button class="secondary" type="submit" data-trial-mobile-close aria-label="Close site details" title="Close"><span aria-hidden="true">×</span></button></form>
    </header>
    <div class="trial-map-mobile-content" data-trial-mobile-content></div>
  </section>
</dialog>
<script type="application/json" id="trials-map-data"><?= $json ?></script>
<?= detail_scroll_top() ?>
</body>
</html>
