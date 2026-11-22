<?php
declare(strict_types=1);

require_once __DIR__ . '/src/helpers.php';

$db = new Database();
$db->initialize();
$dataset = (new TrialMapService($db))->recruitingDataset();
$summary = $dataset['summary'];
$trials = $dataset['trials'];
$facets = $dataset['facets'];
$assetVersion = '20261011-evidence-performance-v198';
$baseUrl = rtrim(Config::publicBaseUrl(), '/') . '/';
$canonicalPath = 'recruiting-psilocybin-clinical-trials/';
$canonicalUrl = $baseUrl . $canonicalPath;
$pageTitle = 'Recruiting Psilocybin Clinical Trials | Live Study Map';
$description = 'Find recruiting psilocybin clinical trials by condition, country, phase, sponsor, and study site, updated from ClinicalTrials.gov with direct registry links.';
$registryChecked = (string)($dataset['registry_checked_at_utc'] ?? '');
$robots = $_GET ? 'noindex, follow' : 'index, follow, max-image-preview:large, max-snippet:-1';
if ($_GET) header('X-Robots-Tag: noindex, follow');
$authorSchema = [
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

$itemList = [];
foreach ($trials as $position => $trial) {
    $medicalTrial = LinkedData::trial($trial);
    $itemList[] = [
        '@type' => 'ListItem',
        'position' => $position + 1,
        'item' => $medicalTrial,
    ];
}

$structuredData = [
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'CollectionPage',
            '@id' => $canonicalUrl . '#page',
            'name' => $pageTitle,
            'headline' => 'Recruiting Psilocybin Clinical Trials',
            'description' => $description,
            'url' => $canonicalUrl,
            'author' => $authorSchema,
            'reviewedBy' => $authorSchema,
            'mainEntity' => ['@id' => $canonicalUrl . '#trials'],
            'about' => LinkedData::term('topic', 'Clinical Trial'),
            'isPartOf' => ['@type' => 'WebSite', 'name' => 'Psilocybin Research', 'url' => $baseUrl],
            'breadcrumb' => ['@id' => $canonicalUrl . '#breadcrumb'],
        ],
        [
            '@type' => 'ItemList',
            '@id' => $canonicalUrl . '#trials',
            'name' => 'Recruiting psilocybin clinical trials',
            'numberOfItems' => (int)$summary['trials'],
            'itemListOrder' => 'https://schema.org/ItemListOrderDescending',
            'itemListElement' => $itemList,
        ],
        [
            '@type' => 'BreadcrumbList',
            '@id' => $canonicalUrl . '#breadcrumb',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Psilocybin Research', 'item' => $baseUrl],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Clinical Trials', 'item' => $baseUrl . 'trials.php'],
                ['@type' => 'ListItem', 'position' => 3, 'name' => 'Recruiting Psilocybin Clinical Trials', 'item' => $canonicalUrl],
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
  <title><?= h($pageTitle) ?></title>
  <?= detail_page_seo($pageTitle, $description, $canonicalPath, ['robots' => $robots]) ?>
  <?= json_ld_script($structuredData) ?>
  <link rel="stylesheet" href="<?= h(FrontendAssets::url('assets/styles.min.css')) ?>">
  <script src="<?= h(FrontendAssets::url('assets/app.min.js')) ?>" defer></script>
</head>
<body class="detail-page recruiting-trials-page">
<?= detail_preloader('Finding recruiting trials…', 'Loading recruiting psilocybin clinical trials') ?>
<?= detail_page_header() ?>
<main class="detail-shell recruiting-trials-shell" id="main">
  <nav class="recruiting-breadcrumbs" aria-label="Breadcrumb">
    <a href="./">Research index</a><span aria-hidden="true">/</span><a href="trials.php">Clinical trials</a><span aria-hidden="true">/</span><span aria-current="page">Recruiting trials</span>
  </nav>

  <section class="panel recruiting-trials-hero">
    <div class="recruiting-trials-hero-copy">
      <span class="eyebrow">Live ClinicalTrials.gov status</span>
      <h1>Recruiting Psilocybin Clinical Trials</h1>
      <p>Find registry-listed psilocybin studies currently marked <strong>Recruiting</strong>, compare their conditions, phases, sponsors, and study locations, and confirm participation details in the authoritative registry record.</p>
      <div class="recruiting-trials-actions">
        <a class="primary iconed" href="<?= h($canonicalPath) ?>#recruiting-trials-map"><i data-icon="map-pin" aria-hidden="true"></i><span>Explore recruiting trials map</span></a>
        <a class="secondary iconed" href="https://clinicaltrials.gov/search?intr=Psilocybin&aggFilters=status:rec" target="_blank" rel="noopener"><i data-icon="external-link" aria-hidden="true"></i><span>Verify at ClinicalTrials.gov</span></a>
      </div>
      <p class="recruiting-trials-caution"><i data-icon="info" aria-hidden="true"></i><span>Registry status is not a guarantee of local availability or eligibility. Always verify the study record and contact the listed study team through ClinicalTrials.gov.</span></p>
      <p><a href="<?= h($canonicalPath) ?>#participation">How to investigate participation and what to ask</a></p>
    </div>
    <div class="recruiting-trials-summary" aria-label="Current recruiting psilocybin trial summary">
      <div><strong><?= h(number_format((int)$summary['trials'])) ?></strong><span>recruiting trials</span></div>
      <div><strong><?= h(number_format((int)$summary['sites'])) ?></strong><span>registry-listed sites</span></div>
      <div><strong><?= h(number_format((int)$summary['countries'])) ?></strong><span>countries represented</span></div>
      <p><span>Registry facts checked</span><strong><?= h(format_utc_display($registryChecked)) ?></strong></p>
    </div>
  </section>

  <section class="panel recruiting-landscape" aria-labelledby="recruiting-landscape-heading">
    <div class="section-head-row">
      <div>
        <span class="eyebrow">Current recruiting landscape</span>
        <h2 id="recruiting-landscape-heading">Where and what is recruiting now</h2>
        <p>Counts below are recomputed automatically from records whose exact ClinicalTrials.gov overall status is <strong>RECRUITING</strong>.</p>
      </div>
    </div>
    <div class="recruiting-facet-grid">
      <article>
        <h3><i data-icon="map-pin" aria-hidden="true"></i><span>Leading countries</span></h3>
        <ol><?php foreach (array_slice($facets['countries'], 0, 8, true) as $label => $count): ?><li><span><?= h((string)$label) ?></span><strong><?= h(number_format((int)$count)) ?> trials</strong></li><?php endforeach; ?></ol>
      </article>
      <article>
        <h3><i data-icon="list-filter" aria-hidden="true"></i><span>Common conditions</span></h3>
        <ol><?php foreach (array_slice($facets['conditions'], 0, 8, true) as $label => $count): ?><li><span><?= h((string)$label) ?></span><strong><?= h(number_format((int)$count)) ?></strong></li><?php endforeach; ?></ol>
      </article>
      <article>
        <h3><i data-icon="clipboard-list" aria-hidden="true"></i><span>Trial phases</span></h3>
        <ol><?php foreach (array_slice($facets['phases'], 0, 8, true) as $label => $count): ?><li><span><?= h($phaseLabel((string)$label)) ?></span><strong><?= h(number_format((int)$count)) ?></strong></li><?php endforeach; ?></ol>
      </article>
    </div>
  </section>

  <section class="panel recruiting-map-embed" id="recruiting-trials-map" aria-labelledby="recruiting-map-heading">
    <div class="section-head-row">
      <div>
        <span class="eyebrow">Interactive recruiting map</span>
        <h2 id="recruiting-map-heading">Explore recruiting study locations</h2>
        <p>The embedded map opens with the exact <strong>Recruiting</strong> filter applied. Use its country, condition, phase, intervention, and date controls to refine the current registry view.</p>
      </div>
      <a class="secondary iconed" href="trials-map.php?status=RECRUITING"><i data-icon="maximize" aria-hidden="true"></i><span>Open full map</span></a>
    </div>
    <iframe src="trials-map.php?status=RECRUITING&amp;embed=1" title="Interactive map of recruiting psilocybin clinical trials" loading="lazy" referrerpolicy="strict-origin-when-cross-origin"></iframe>
    <noscript><p><a href="trials-map.php?status=RECRUITING">Open the recruiting psilocybin trials map</a> to explore study locations.</p></noscript>
  </section>

  <section class="panel detail-card evidence-questions" id="participation" aria-labelledby="participation-heading">
    <span class="eyebrow">Before contacting a study</span><h2 id="participation-heading">How to find and investigate a psilocybin clinical trial</h2>
    <ol>
      <li><strong>Find a relevant record.</strong> Compare the condition, country, phase and sites. This collection uses exact overall Recruiting status; individual sites can have a different status.</li>
      <li><strong>Open the ClinicalTrials.gov entry.</strong> Read its current participation criteria, last update and contacts. Eligibility is determined by the study team, not by this database.</li>
      <li><strong>Contact the listed team.</strong> Ask whether your local site is accepting inquiries and how its screening works. Neither this website nor ClinicalTrials.gov enrolls participants.</li>
      <li><strong>Discuss the study with your clinician.</strong> Ask about experimental interventions, alternatives, medication requirements, follow-up and how your usual care would be affected.</li>
    </ol>
    <p>This process follows <a href="https://clinicaltrials.gov/find-studies/for-patients/common-questions">ClinicalTrials.gov's participation guidance</a>. A registry entry is a source of study information, not a personal recommendation.</p>
    <h3>What should I ask before agreeing to participate?</h3>
    <p>Ask about assignment to placebo or other comparison groups, required visits and travel, known risks, support after the intervention, privacy, and how to withdraw. Request a clear explanation of the consent documents. The <a href="https://www.hhs.gov/ohrp/education-and-outreach/about-research-participation/questions-to-ask/index.html">HHS research-participation question guide</a> provides a fuller checklist.</p>
    <h3>Do I need to stop antidepressants or other medication?</h3>
    <p>Requirements vary by protocol and personal circumstances. Read the source criteria and ask the study team and your prescriber. <strong>Do not stop or taper medication based on a search result or this list.</strong> Missing medication information here does not mean that continuation is allowed.</p>
    <h3>Are studies free, and do they pay participants?</h3>
    <p>Costs, compensation and travel support are study-specific. Ask which procedures are covered, what expenses you would bear, whether compensation is offered, and who pays for care if research causes harm. This tracker does not infer financial terms from a Recruiting status.</p>
    <h3>Does Recruiting mean a place is available near me?</h3>
    <p>No. Overall registry status does not guarantee availability at a particular site or eligibility for a particular person. A mapped location is a registry-listed site, not a confirmed appointment.</p>
    <h3>Can participation tell me whether psilocybin will help?</h3>
    <p>A trial is research with uncertain outcomes. Participants may receive a comparator intervention, and personal benefit is not guaranteed. For published depression findings, read the <a href="psilocybin-depression-research/">depression research evidence guide</a>; registrations and outcome reports serve different purposes.</p>
    <p class="evidence-guide-meta">Participation sources checked 7 October 2026 · <a href="editorial-policy.php">Methods, authorship and corrections</a>.</p>
  </section>

  <section class="panel recruiting-records" id="recruiting-trial-records" aria-labelledby="recruiting-records-heading">
    <div class="section-head-row">
      <div>
        <span class="eyebrow">Server-rendered registry index</span>
        <h2 id="recruiting-records-heading"><?= h(number_format((int)$summary['trials'])) ?> recruiting psilocybin trials</h2>
        <p>These factual summaries are available without JavaScript. Open the linked ClinicalTrials.gov record for current eligibility, contacts, and site-level availability.</p>
      </div>
    </div>
    <div class="recruiting-record-grid">
      <?php foreach ($trials as $trial): ?>
        <?php
          $phaseText = $trial['phases'] ? implode(' · ', array_map($phaseLabel, $trial['phases'])) : 'Phase not reported';
          $conditionText = $trial['conditions'] ? implode(' · ', array_slice($trial['conditions'], 0, 4)) : 'Condition not reported';
          $locations = [];
          foreach (array_slice($trial['sites'], 0, 3) as $site) {
              $label = $locationLabel($site);
              if ($label !== '') {
                  $locations[] = $label;
              }
          }
        ?>
        <article class="recruiting-record" id="<?= h(strtolower((string)$trial['nct_id'])) ?>">
          <div class="recruiting-record-status"><span>Recruiting</span><a href="<?= h((string)$trial['registry_url']) ?>" target="_blank" rel="noopener"><?= h((string)$trial['nct_id']) ?></a></div>
          <h3><?= h((string)$trial['title']) ?></h3>
          <p class="recruiting-record-sponsor"><?= h((string)($trial['sponsor'] ?: 'Sponsor not reported')) ?></p>
          <dl>
            <div><dt>Conditions</dt><dd><?= h($conditionText) ?></dd></div>
            <div><dt>Phase</dt><dd><?= h($phaseText) ?></dd></div>
            <div><dt>Enrollment</dt><dd><?= $trial['enrollment']['count'] !== null ? h(number_format((int)$trial['enrollment']['count'])) . ' ' . h(strtolower((string)$trial['enrollment']['type'])) : 'Not reported' ?></dd></div>
            <div><dt>Locations</dt><dd><?= $locations ? h(implode(' · ', $locations)) : 'No mapped site reported' ?><?= count($trial['sites']) > 3 ? h(' · +' . (count($trial['sites']) - 3) . ' more') : '' ?></dd></div>
            <div><dt>Registry updated</dt><dd><?= h((string)($trial['last_update_date'] ?: 'Not reported')) ?></dd></div>
          </dl>
          <div class="recruiting-record-actions">
            <a class="primary iconed" href="<?= h((string)$trial['registry_url']) ?>" target="_blank" rel="noopener"><i data-icon="external-link" aria-hidden="true"></i><span>Check eligibility and contacts</span></a>
            <a class="secondary iconed" href="<?= h((string)$trial['tracker_url']) ?>"><i data-icon="book-open" aria-hidden="true"></i><span>Database record</span></a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="panel recruiting-method" aria-labelledby="recruiting-method-heading">
    <div><span class="eyebrow">Provenance and interpretation</span><h2 id="recruiting-method-heading">What “Recruiting” means here</h2></div>
    <div>
      <p><strong>Exact status:</strong> this page includes only records whose latest indexed ClinicalTrials.gov overall status is <strong>RECRUITING</strong>. “Not yet recruiting,” “enrolling by invitation,” and “active, not recruiting” are excluded.</p>
      <p><strong>Automatic updates:</strong> the list, counts, facets, and map are regenerated from the database after scheduled ClinicalTrials.gov refreshes. Registry facts can lag local changes, so the source record remains authoritative.</p>
      <p><strong>Rights-safe scope:</strong> titles, identifiers, sponsors, design facts, dates, interventions, conditions, and mapped sites are shown. Registry descriptions, contacts, raw payloads, and source-derived keywords are not redistributed.</p>
      <p><strong>Editorial responsibility:</strong> authorship, collection rules, corrections, and scientific limitations are documented in the <a href="editorial-policy.php">editorial policy and research methodology</a>.</p>
    </div>
  </section>
  <section class="panel trial-discovery-section" aria-labelledby="related-trial-collections-heading">
    <div class="section-head-row"><div><span class="eyebrow">Related research views</span><h2 id="related-trial-collections-heading">Explore trials by condition, country, and phase</h2><p>Continue with curated, automatically updated trial collections built from the same source-preserving registry data.</p></div></div>
    <?= trial_discovery_links($canonicalPath) ?>
  </section>
</main>
<?= detail_footer() ?>
<?= detail_scroll_top() ?>
</body>
</html>
