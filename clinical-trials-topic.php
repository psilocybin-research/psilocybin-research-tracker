<?php
declare(strict_types=1);

require_once __DIR__ . '/src/helpers.php';

$landings = require __DIR__ . '/config/trial-seo-landings.php';
$landingKey = trim((string)($_GET['landing'] ?? ''));
if (!isset($landings[$landingKey])) {
    http_response_code(404);
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="robots" content="noindex, nofollow"><title>Trial collection not found</title><body><main><h1>Trial collection not found</h1><p><a href="trials.php">Open the clinical trial tracker</a>.</p></main></body></html>';
    exit;
}
$definition = $landings[$landingKey];
$requestPath = ltrim((string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? ''), '/');
if ($requestPath === 'clinical-trials-topic.php') {
    header('Location: /' . $definition['path'], true, 301);
    exit;
}

$db = new Database();
$db->initialize();
$dataset = (new TrialMapService($db))->curatedDataset((array)$definition['filter']);
$summary = $dataset['summary'];
$trials = $dataset['trials'];
$facets = $dataset['facets'];
$assetVersion = '20261011-evidence-performance-v198';
$baseUrl = rtrim(Config::publicBaseUrl(), '/') . '/';
$canonicalUrl = $baseUrl . $definition['path'];
$registryChecked = (string)($dataset['registry_checked_at_utc'] ?? '');
$extraQueryKeys = array_diff(array_keys($_GET), ['landing']);
$robots = $extraQueryKeys === []
    ? 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1'
    : 'noindex, follow, noarchive';
if ($extraQueryKeys !== []) {
    header('X-Robots-Tag: noindex, follow, noarchive');
}

$phaseLabel = static function (string $phase): string {
    return match ($phase) {
        'EARLY_PHASE1' => 'Early Phase 1',
        'PHASE1' => 'Phase 1',
        'PHASE2' => 'Phase 2',
        'PHASE3' => 'Phase 3',
        'PHASE4' => 'Phase 4',
        'NA' => 'Not applicable',
        default => ucwords(strtolower(str_replace('_', ' ', $phase))),
    };
};
$locationLabel = static function (array $site): string {
    return implode(', ', array_values(array_filter([
        trim((string)($site['facility'] ?? '')),
        trim((string)($site['city'] ?? '')),
        trim((string)($site['region'] ?? '')),
        trim((string)($site['country'] ?? '')),
    ], static fn(string $value): bool => $value !== '')));
};
$mapQuery = http_build_query(array_merge((array)$definition['map_query'], ['embed' => '1']), '', '&', PHP_QUERY_RFC3986);
$fullMapQuery = http_build_query((array)$definition['map_query'], '', '&', PHP_QUERY_RFC3986);

$itemList = [];
foreach ($trials as $position => $trial) {
    $medicalTrial = LinkedData::trial($trial);
    $itemList[] = ['@type' => 'ListItem', 'position' => $position + 1, 'item' => $medicalTrial];
}
$author = [
    '@type' => 'Person',
    '@id' => $baseUrl . 'editorial-policy.php#author',
    'name' => 'Dr. Christopher B. Germann',
    'sameAs' => ['https://orcid.org/0000-0002-1573-4651'],
    'affiliation' => [
        '@type' => 'CollegeOrUniversity',
        'name' => 'Faculty of Health, School of Medicine, University of Witten/Herdecke',
        'url' => 'https://www.uni-wh.de/en/about-us/faculty-of-health/school-of-medicine',
    ],
];
$structuredData = [
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'CollectionPage', '@id' => $canonicalUrl . '#page', 'name' => $definition['title'],
            'headline' => $definition['h1'], 'description' => $definition['description'], 'url' => $canonicalUrl,
            'author' => $author, 'reviewedBy' => $author, 'mainEntity' => ['@id' => $canonicalUrl . '#trials'],
            'about' => LinkedData::term('topic', 'Clinical Trial'),
            'isPartOf' => ['@type' => 'WebSite', 'name' => 'Psilocybin Research', 'url' => $baseUrl],
            'breadcrumb' => ['@id' => $canonicalUrl . '#breadcrumb'],
        ],
        [
            '@type' => 'ItemList', '@id' => $canonicalUrl . '#trials', 'name' => $definition['h1'],
            'numberOfItems' => (int)$summary['trials'], 'itemListOrder' => 'https://schema.org/ItemListOrderDescending',
            'itemListElement' => $itemList,
        ],
        [
            '@type' => 'BreadcrumbList', '@id' => $canonicalUrl . '#breadcrumb',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Psilocybin Research', 'item' => $baseUrl],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Clinical Trials', 'item' => $baseUrl . 'trials.php'],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $definition['h1'], 'item' => $canonicalUrl],
            ],
        ],
    ],
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <base href="/">
  <title><?= h((string)$definition['title']) ?></title>
  <?= detail_page_seo((string)$definition['title'], (string)$definition['description'], (string)$definition['path'], ['robots' => $robots]) ?>
  <?= json_ld_script($structuredData) ?>
  <link rel="stylesheet" href="<?= h(FrontendAssets::url('assets/styles.min.css')) ?>">
  <script src="<?= h(FrontendAssets::url('assets/app.min.js')) ?>" defer></script>
</head>
<body class="detail-page recruiting-trials-page trial-topic-page">
<?= detail_preloader('Preparing the trial collection…', 'Loading curated clinical trial data') ?>
<?= detail_page_header('trials') ?>
<main class="detail-shell recruiting-trials-shell">
  <nav class="recruiting-breadcrumbs" aria-label="Breadcrumb">
    <a href="./">Research index</a><span aria-hidden="true">/</span><a href="trials.php">Clinical trials</a><span aria-hidden="true">/</span><span aria-current="page"><?= h((string)$definition['short_label']) ?></span>
  </nav>

  <section class="panel recruiting-trials-hero">
    <div class="recruiting-trials-hero-copy">
      <span class="eyebrow"><?= h((string)$definition['eyebrow']) ?></span>
      <h1><?= h((string)$definition['h1']) ?></h1>
      <p><?= h((string)$definition['intro']) ?></p>
      <div class="recruiting-trials-actions">
        <a class="primary iconed" href="<?= h((string)$definition['path']) ?>#trial-topic-map"><i data-icon="map-pin" aria-hidden="true"></i><span>Explore this trial map</span></a>
        <a class="secondary iconed" href="recruiting-psilocybin-clinical-trials/"><i data-icon="search" aria-hidden="true"></i><span>All recruiting trials</span></a>
      </div>
      <p class="recruiting-trials-caution"><i data-icon="info" aria-hidden="true"></i><span>Registry status is not a guarantee of local availability, eligibility, safety, or efficacy. Confirm every participation decision in the authoritative ClinicalTrials.gov record.</span></p>
      <?php if ($landingKey === 'psilocybin-clinical-trials-alcohol-use-disorder'): ?><p>For published findings, see the <a href="psilocybin-alcohol-use-disorder-research/">alcohol use disorder research guide</a>, including the positive 2022 drinking-outcome trial and inconclusive 2025 relapse-prevention trial.</p><?php endif; ?>
      <?php if ($landingKey === 'psilocybin-clinical-trials-depression'): ?><p>Looking for study findings rather than registrations? Read the <a href="psilocybin-depression-research/">depression research evidence guide</a>.</p><?php endif; ?>
    </div>
    <div class="recruiting-trials-summary" aria-label="Current trial collection summary">
      <div><strong><?= h(number_format((int)$summary['trials'])) ?></strong><span>matching trials</span></div>
      <div><strong><?= h(number_format((int)$summary['recruiting'])) ?></strong><span>currently recruiting</span></div>
      <div><strong><?= h(number_format((int)$summary['sites'])) ?></strong><span>registry-listed sites</span></div>
      <p><span>Registry facts checked</span><strong><?= h(format_utc_display($registryChecked)) ?></strong></p>
    </div>
  </section>

  <section class="panel recruiting-landscape" aria-labelledby="trial-topic-landscape-heading">
    <div class="section-head-row"><div><span class="eyebrow">Live registry synthesis</span><h2 id="trial-topic-landscape-heading">Status, geography, and study phase</h2><p><?= h((string)$definition['context']) ?></p></div></div>
    <div class="recruiting-facet-grid">
      <article><h3><i data-icon="refresh-cw" aria-hidden="true"></i><span>Recruitment status</span></h3><ol><?php foreach (array_slice($facets['statuses'], 0, 8, true) as $label => $count): ?><li><span><?= h((string)($facets['status_labels'][$label] ?? $label)) ?></span><strong><?= h(number_format((int)$count)) ?></strong></li><?php endforeach; ?></ol></article>
      <article><h3><i data-icon="map-pin" aria-hidden="true"></i><span>Leading countries</span></h3><ol><?php foreach (array_slice($facets['countries'], 0, 8, true) as $label => $count): ?><li><span><?= h((string)$label) ?></span><strong><?= h(number_format((int)$count)) ?></strong></li><?php endforeach; ?></ol></article>
      <article><h3><i data-icon="clipboard-list" aria-hidden="true"></i><span>Trial phases</span></h3><ol><?php foreach (array_slice($facets['phases'], 0, 8, true) as $label => $count): ?><li><span><?= h($phaseLabel((string)$label)) ?></span><strong><?= h(number_format((int)$count)) ?></strong></li><?php endforeach; ?></ol></article>
    </div>
  </section>

  <section class="panel recruiting-map-embed" id="trial-topic-map" aria-labelledby="trial-topic-map-heading">
    <div class="section-head-row"><div><span class="eyebrow">Interactive map</span><h2 id="trial-topic-map-heading">Explore matching study locations</h2><p>The map opens with this collection’s filter applied. Its controls can further narrow status, phase, condition, country, intervention, and dates.</p></div><a class="secondary iconed" href="trials-map.php?<?= h($fullMapQuery) ?>"><i data-icon="maximize" aria-hidden="true"></i><span>Open full map</span></a></div>
    <iframe src="trials-map.php?<?= h($mapQuery) ?>" title="Interactive map for <?= h((string)$definition['h1']) ?>" loading="lazy" referrerpolicy="strict-origin-when-cross-origin"></iframe>
  </section>

  <section class="panel recruiting-records" id="trial-topic-records" aria-labelledby="trial-topic-records-heading">
    <div class="section-head-row"><div><span class="eyebrow">Server-rendered source index</span><h2 id="trial-topic-records-heading"><?= h(number_format((int)$summary['trials'])) ?> matching registry records</h2><p>Every factual summary is crawlable without JavaScript. Use the direct registry link for current eligibility, contacts, and site availability.</p></div></div>
    <div class="recruiting-record-grid">
      <?php foreach ($trials as $trial): ?>
        <?php
          $phaseText = $trial['phases'] ? implode(' · ', array_map($phaseLabel, $trial['phases'])) : 'Phase not reported';
          $conditionText = $trial['conditions'] ? implode(' · ', array_slice($trial['conditions'], 0, 4)) : 'Condition not reported';
          $locations = [];
          foreach (array_slice($trial['sites'], 0, 3) as $site) {
              $label = $locationLabel($site);
              if ($label !== '') $locations[] = $label;
          }
          $statusClass = in_array($trial['status'], ['RECRUITING', 'NOT_YET_RECRUITING', 'ENROLLING_BY_INVITATION'], true)
              ? 'is-open'
              : (in_array($trial['status'], ['SUSPENDED', 'TERMINATED', 'WITHDRAWN'], true) ? 'is-stopped' : 'is-neutral');
        ?>
        <article class="recruiting-record" id="<?= h(strtolower((string)$trial['nct_id'])) ?>">
          <div class="recruiting-record-status"><span class="<?= h($statusClass) ?>"><?= h((string)$trial['status_label']) ?></span><a href="<?= h((string)$trial['registry_url']) ?>" target="_blank" rel="noopener"><?= h((string)$trial['nct_id']) ?></a></div>
          <h3><?= h((string)$trial['title']) ?></h3>
          <p class="recruiting-record-sponsor"><?= h((string)($trial['sponsor'] ?: 'Sponsor not reported')) ?></p>
          <dl>
            <div><dt>Conditions</dt><dd><?= h($conditionText) ?></dd></div>
            <div><dt>Phase</dt><dd><?= h($phaseText) ?></dd></div>
            <div><dt>Enrollment</dt><dd><?= $trial['enrollment']['count'] !== null ? h(number_format((int)$trial['enrollment']['count'])) . ' ' . h(strtolower((string)$trial['enrollment']['type'])) : 'Not reported' ?></dd></div>
            <div><dt>Locations</dt><dd><?= $locations ? h(implode(' · ', $locations)) : 'No mapped site reported' ?><?= count($trial['sites']) > 3 ? h(' · +' . (count($trial['sites']) - 3) . ' more') : '' ?></dd></div>
            <div><dt>Registry updated</dt><dd><?= h((string)($trial['last_update_date'] ?: 'Not reported')) ?></dd></div>
          </dl>
          <div class="recruiting-record-actions"><a class="primary iconed" href="<?= h((string)$trial['registry_url']) ?>" target="_blank" rel="noopener"><i data-icon="external-link" aria-hidden="true"></i><span>Open registry record</span></a><a class="secondary iconed" href="<?= h((string)$trial['tracker_url']) ?>"><i data-icon="book-open" aria-hidden="true"></i><span>Database record</span></a></div>
        </article>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="panel trial-discovery-section" aria-labelledby="related-trial-collections-heading">
    <div class="section-head-row"><div><span class="eyebrow">Related research views</span><h2 id="related-trial-collections-heading">Continue exploring clinical trials</h2><p>Move between curated condition, country, phase, and recruiting views without losing direct source provenance.</p></div></div>
    <?= trial_discovery_links((string)$definition['path']) ?>
  </section>

  <section class="panel recruiting-method" aria-labelledby="trial-topic-method-heading">
    <div><span class="eyebrow">Method and accountability</span><h2 id="trial-topic-method-heading">How this collection is maintained</h2></div>
    <div><p><strong>Automatic refresh:</strong> counts, facets, records, and the map update from the database after scheduled ClinicalTrials.gov refreshes. The registry remains authoritative.</p><p><strong>Rights-safe scope:</strong> the page exposes allowlisted factual metadata. Registry descriptions, contacts, raw payloads, and source-derived keywords are not redistributed.</p><p><strong>Editorial review:</strong> collection definitions, limitations, corrections, and authorship are documented in the <a href="editorial-policy.php">editorial and methodology policy</a>.</p></div>
  </section>
</main>
<?= detail_footer() ?>
<?= detail_scroll_top() ?>
</body>
</html>
