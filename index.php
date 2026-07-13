<?php
declare(strict_types=1);

require_once __DIR__ . '/src/helpers.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$db = new Database();
$db->initialize();
$repo = new PublicationRepository($db);
$runs = new FetchRunRepository($db);
$alerts = new AlertService($db, $repo);

$message = null;
$error = null;
$isAdmin = hash_equals(Config::adminToken(), (string)($_POST['admin_token'] ?? ''));
$action = request_value('action');
$publicRefreshCooldownSeconds = 3600;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'subscribe') {
        if (!request_value('privacy_consent')) {
            throw new InvalidArgumentException('Please confirm the alert data-use notice before subscribing.');
        }
        $alertScope = request_value('alert_scope', 'all');
        $subscription = $alerts->subscribe(
            (string)request_value('email', ''),
            (string)request_value('frequency', 'daily'),
            request_array('alert_substances'),
            $alertScope === 'targeted' ? request_value('alert_keywords') : null,
            $alertScope === 'targeted' ? request_value('alert_author') : null,
            $alertScope === 'targeted' ? request_value('alert_journal') : null,
            $alertScope === 'targeted' ? request_value('alert_topic') : null,
            $alertScope === 'targeted' ? request_value('alert_cited_doi') : null
        );
        if ((int)($subscription['active'] ?? 0) === 1 && !empty($subscription['confirmed_at'])) {
            $message = 'This alert is already confirmed. You will continue receiving matching publication digests.';
        } elseif ($alerts->sendConfirmation($subscription)) {
            $message = 'Please confirm your alert. We sent a confirmation email to ' . (string)$subscription['email'] . '; digests start only after that link is opened.';
        } else {
            $message = 'Alert request saved, but the confirmation email could not be sent right now. No digests will be generated until the address is confirmed.';
        }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'curate') {
        if (!$isAdmin) {
            throw new RuntimeException('Admin token required for curation.');
        }
        $repo->curate((int)request_value('paper_id', '0'), [
            'topic_tags' => request_value('curate_topic_tags'),
            'study_type' => request_value('curate_study_type'),
            'substance_tags' => request_value('curate_substance_tags'),
            'hidden' => request_value('curate_hidden') ? 1 : 0,
            'false_positive' => request_value('curate_false_positive') ? 1 : 0,
            'curation_notes' => request_value('curate_notes'),
        ]);
        $message = 'Curation changes saved.';
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'merge') {
        if (!$isAdmin) {
            throw new RuntimeException('Admin token required for merge.');
        }
        $repo->merge((int)request_value('merge_source_id', '0'), (int)request_value('merge_target_id', '0'));
        $message = 'Duplicate marked as merged.';
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'refresh') {
        if (!$isAdmin) {
            throw new RuntimeException('Admin token required for manual refresh.');
        }
        $summary = PublicationService::create($db)->refresh(request_value('refresh_from'), request_value('refresh_to'), (int)request_value('refresh_limit', '200'));
        $message = implode(' | ', $summary['messages']);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'public_refresh') {
        $latest = $runs->latestSuccessful();
        $lastUpdated = $latest['finished_at'] ?? null;
        $updatedAt = $lastUpdated ? DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string)$lastUpdated, new DateTimeZone('UTC')) : null;
        $ageSeconds = $updatedAt ? time() - $updatedAt->getTimestamp() : null;
        if ($ageSeconds !== null && $ageSeconds < $publicRefreshCooldownSeconds) {
            $message = 'Recent update already ran at ' . format_utc_display($lastUpdated) . '. Please try again later if new publications are missing.';
        } else {
            $lockPath = Config::dataDir() . '/public_refresh.lock';
            $lock = fopen($lockPath, 'c');
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
                $message = 'A refresh is already running. Stored publications remain available.';
            } else {
                try {
                    set_time_limit(120);
                    $summary = PublicationService::create($db)->refresh(gmdate('Y-m-d', strtotime('-7 days')), gmdate('Y-m-d'), 0);
                    $message = 'Public refresh completed. New publications: ' . (int)$summary['inserted'] . '; updated: ' . (int)$summary['updated'] . '; already stored: ' . (int)$summary['skipped'] . '; errors: ' . (int)$summary['errors'] . '. ' . implode(' | ', $summary['messages']);
                } finally {
                    flock($lock, LOCK_UN);
                    fclose($lock);
                }
            }
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$range = request_value('range', 'all');
$filters = RequestFilters::fromGlobals();
$from = $filters['from'];
$to = $filters['to'];
$substances = $filters['substances'];
$currentSearchQuery = trim((string)($filters['q'] ?? ''));
$executedSearchParts = [];
if ($currentSearchQuery !== '') {
    $executedSearchParts[] = 'Keyword: ' . $currentSearchQuery;
}
if (isset($_GET['substances']) && !empty($substances)) {
    $executedSearchParts[] = 'Substance: ' . implode(', ', array_map(static fn(string $substance): string => ucfirst($substance), $substances));
}
if (trim((string)($filters['author'] ?? '')) !== '') {
    $executedSearchParts[] = 'Author: ' . trim((string)$filters['author']);
}
if (trim((string)($filters['journal'] ?? '')) !== '') {
    $executedSearchParts[] = 'Journal: ' . trim((string)$filters['journal']);
}
if (trim((string)($filters['topic'] ?? '')) !== '') {
    $executedSearchParts[] = 'Topic: ' . trim((string)$filters['topic']);
}
if (trim((string)($filters['study_type'] ?? '')) !== '') {
    $executedSearchParts[] = 'Study type: ' . trim((string)$filters['study_type']);
}
if (trim((string)($filters['cited_doi'] ?? '')) !== '') {
    $executedSearchParts[] = 'Cites DOI: ' . trim((string)$filters['cited_doi']);
}
if (trim((string)($filters['year'] ?? '')) !== '') {
    $executedSearchParts[] = 'Year: ' . trim((string)$filters['year']);
}
if (($range === 'custom' || isset($_GET['from']) || isset($_GET['to'])) && ($from !== '' || $to !== '')) {
    $executedSearchParts[] = 'Date: ' . ($from !== '' ? $from : 'Any') . ' to ' . ($to !== '' ? $to : 'Any');
}
if (trim((string)($filters['added_from'] ?? '')) !== '' || trim((string)($filters['added_to'] ?? '')) !== '') {
    $executedSearchParts[] = 'Added: ' . (trim((string)($filters['added_from'] ?? '')) !== '' ? trim((string)$filters['added_from']) : 'Any') . ' to ' . (trim((string)($filters['added_to'] ?? '')) !== '' ? trim((string)$filters['added_to']) : 'Any');
}
if (isset($_GET['range']) && $range !== 'all' && $range !== 'custom') {
    $executedSearchParts[] = 'Range: ' . match ($range) {
        'month' => 'Last month',
        'year' => 'Last year',
        '5y' => 'Last 5 years',
        default => $range,
    };
}
if (!empty($filters['sources'])) {
    $executedSearchParts[] = 'Sources: ' . implode(', ', array_map('strval', (array)$filters['sources']));
}
if (!empty($filters['publication_statuses'])) {
    $executedSearchParts[] = 'Evidence: ' . implode(', ', array_map('strval', (array)$filters['publication_statuses']));
}
$hasSearchQuery = $executedSearchParts !== [];
$currentSearchLabel = $hasSearchQuery ? implode(' · ', $executedSearchParts) : '';

$result = $repo->search($filters);
$stats = $repo->stats();
$latestLimit = 25;
$latestPapers = $repo->latest($latestLimit);
$journals = $repo->journals();
$years = $repo->years();
$latestRuns = $runs->latestRuns();
$latestErrors = $runs->latestErrors();
$latestSuccessfulRun = $runs->latestSuccessful();
$latestSuccessfulBatch = $runs->latestSuccessfulBatchWindow();
$lastSuccessfulUpdate = $latestSuccessfulRun['finished_at'] ?? null;
$latestAddedRecordsUrl = null;
if (!empty($latestSuccessfulBatch['started_at']) && !empty($latestSuccessfulBatch['finished_at'])) {
    $latestAddedRecordsUrl = tracker_query_url([
        'added_from' => (string)$latestSuccessfulBatch['started_at'],
        'added_to' => (string)$latestSuccessfulBatch['finished_at'],
        'sort' => 'newly_added',
    ]);
}
$topics = $repo->topics();
$studyTypes = $repo->studyTypes();
$sources = $repo->sources();
$publicationStatuses = $repo->publicationStatuses();
$selectedSources = $filters['sources'] ?? [];
$selectedStatuses = $filters['publication_statuses'] ?: array_keys(PublicationRepository::publicationStatusOptions());
$advancedFiltersActive = (bool)($filters['year'] || $filters['journal'] || $filters['topic'] || $filters['study_type'] || $selectedSources || $range === 'custom' || $selectedStatuses !== array_keys(PublicationRepository::publicationStatusOptions()));
$analytics = $repo->analytics();
$adminPaper = $isAdmin ? (request_value('paper_id') ? $repo->findById((int)request_value('paper_id')) : ($result['rows'][0] ?? null)) : null;
$preview = null;
if ($isAdmin) {
    $previewDigests = $alerts->generateDue('daily', false);
    $preview = $previewDigests[0]['body'] ?? "No pending daily alert items.\nRun bin/alerts.php after new publications are imported.";
}
$sourceNames = array_map(static fn(array $source): string => (string)$source['source_name'], $sources);
$maxSourceCount = max(1, ...array_map(static fn(array $source): int => (int)$source['count'], $sources ?: [['count' => 1]]));
$statusCounts = [];
foreach ($publicationStatuses as $statusRow) {
    $statusCounts[(string)$statusRow['publication_status']] = (int)$statusRow['count'];
}
$publicationTrendRows = array_values(array_filter($analytics['trends'] ?? [], static fn(array $row): bool => !empty($row['year'])));
$publicationTrendByYear = [];
foreach ($publicationTrendRows as $row) {
    $publicationTrendByYear[(int)$row['year']] = (int)$row['count'];
}
$publicationGrowthYears = [];
if ($publicationTrendByYear) {
    $firstTrendYear = 2020;
    $lastTrendYear = max(array_keys($publicationTrendByYear));
    for ($year = $firstTrendYear; $year <= $lastTrendYear; $year++) {
        $publicationGrowthYears[] = ['year' => $year, 'count' => $publicationTrendByYear[$year] ?? 0];
    }
}
$publicationGrowthTotal = array_sum(array_column($publicationGrowthYears, 'count'));
$publicationGrowthLatest = $publicationGrowthYears ? (int)end($publicationGrowthYears)['count'] : 0;
$publicationGrowthLatestYear = $publicationGrowthYears ? (int)end($publicationGrowthYears)['year'] : null;
$publicationGrowthFirstYear = $publicationGrowthYears ? (int)$publicationGrowthYears[0]['year'] : null;
$assetVersion = '20260713-accordion-emphasis-v93';
$appVersion = '2.1.5';
$formatBytes = static function (int $bytes): string {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    return number_format(max(0, $bytes) / 1024, 1) . ' KB';
};
$databaseDsn = Config::databaseDsn();
$databaseSizeLabel = 'Unavailable';
if (str_starts_with($databaseDsn, 'sqlite:')) {
    $databasePath = substr($databaseDsn, 7);
    if ($databasePath !== ':memory:' && is_file($databasePath)) {
        $databaseSize = filesize($databasePath);
        if ($databaseSize !== false) {
            $databaseSizeLabel = $formatBytes((int)$databaseSize);
        }
    }
}
$footerQueryStarted = microtime(true);
$latestAddedWithDoi = null;
try {
    $latestAddedStmt = $db->pdo()->query("SELECT title, doi, source_url, date_added FROM publications WHERE hidden = 0 AND false_positive = 0 AND doi IS NOT NULL AND doi != '' ORDER BY date_added DESC, id DESC LIMIT 1");
    $latestAddedRow = $latestAddedStmt ? $latestAddedStmt->fetch() : false;
    $latestAddedWithDoi = is_array($latestAddedRow) ? clean_paper($latestAddedRow) : null;
} catch (Throwable $exception) {
    $latestAddedWithDoi = null;
}
$footerQueryMs = (microtime(true) - $footerQueryStarted) * 1000;
$latestAddedDoi = $latestAddedWithDoi ? normalize_doi((string)($latestAddedWithDoi['doi'] ?? '')) : null;
$latestAddedAt = $latestAddedWithDoi ? (string)($latestAddedWithDoi['date_added'] ?? '') : '';
$baseUrl = Config::publicBaseUrl();
$canonicalUrl = $baseUrl;
$shareImageUrl = $baseUrl . 'assets/pwa/icon-512.png?v=20260713-accordion-emphasis-v93';
$shareImageAlt = 'Psilocybin Research Publication Tracker logo';
$latestJsonLdItems = [];
foreach (array_slice($latestPapers, 0, 10) as $index => $paper) {
    $latestJsonLdItems[] = [
        '@type' => 'ListItem',
        'position' => $index + 1,
        'url' => $paper['source_url'] ?: ($paper['doi'] ? 'https://doi.org/' . normalize_doi((string)$paper['doi']) : $baseUrl),
        'item' => [
            '@type' => 'ScholarlyArticle',
            'name' => (string)$paper['title'],
            'headline' => (string)$paper['title'],
            'datePublished' => (string)($paper['publication_date'] ?: ''),
            'isPartOf' => ['@type' => 'Periodical', 'name' => (string)($paper['journal'] ?: 'Unknown journal')],
            'identifier' => array_values(array_filter([
                $paper['doi'] ? 'doi:' . normalize_doi((string)$paper['doi']) : null,
                $paper['pubmed_id'] ? 'pmid:' . (string)$paper['pubmed_id'] : null,
            ])),
            'about' => array_values(array_filter(array_map('trim', explode(',', (string)($paper['topic_tags'] ?? $paper['substance_tags'] ?? 'psilocybin research'))))),
        ],
    ];
}
$jsonLd = [
    [
        '@context' => 'https://schema.org',
        '@type' => 'WebApplication',
        'name' => 'Psilocybin Research Publication Tracker',
        'applicationCategory' => 'ResearchApplication',
        'operatingSystem' => 'Any',
        'url' => $canonicalUrl,
        'description' => 'Search source-labeled psilocybin and psilocin publications, preprints, trials, citations, analytics, and alerts.',
        'image' => $shareImageUrl,
        'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD'],
    ],
    [
        '@context' => 'https://schema.org',
        '@type' => 'Dataset',
        'name' => 'An integrated living dataset of psilocybin and psilocin publications, preprints, and trial records',
        'description' => 'A structured SQLite-backed index of psilocybin and psilocin publications, preprints, protocols, reviews, and clinical trials with source, status, topic, DOI, PubMed, export, API, and analytics metadata.',
        'url' => $canonicalUrl,
        'keywords' => 'psilocybin research, psilocin, psychedelic therapy, clinical trials, preprints, PubMed, Crossref, Europe PMC',
        'dateModified' => $lastSuccessfulUpdate ? gmdate('c', strtotime((string)$lastSuccessfulUpdate)) : gmdate('c'),
        'variableMeasured' => ['title', 'authors', 'journal', 'publication date', 'DOI', 'PubMed ID', 'source database', 'publication status', 'topic tags', 'study type', 'substance tags', 'abstract availability', 'text rights status'],
        'measurementTechnique' => ['PubMed E-utilities', 'Crossref API', 'Europe PMC REST API', 'ClinicalTrials.gov API v2', 'preprint server APIs'],
        'includedInDataCatalog' => ['@type' => 'DataCatalog', 'name' => 'Psilocybin-Research.com'],
        'distribution' => [
            ['@type' => 'DataDownload', 'encodingFormat' => 'application/vnd.sqlite3', 'contentUrl' => $baseUrl . 'database.php'],
            ['@type' => 'DataDownload', 'encodingFormat' => 'application/json', 'contentUrl' => $baseUrl . 'export.php?format=json'],
            ['@type' => 'DataDownload', 'encodingFormat' => 'text/csv', 'contentUrl' => $baseUrl . 'export.php?format=csv'],
            ['@type' => 'DataDownload', 'encodingFormat' => 'application/x-bibtex', 'contentUrl' => $baseUrl . 'export.php?format=bibtex'],
            ['@type' => 'DataDownload', 'encodingFormat' => 'application/x-tex', 'contentUrl' => $baseUrl . 'export.php?format=latex'],
            ['@type' => 'SoftwareSourceCode', 'programmingLanguage' => 'R', 'codeRepository' => 'https://github.com/psilocybin-research/psilocybin-research-tracker', 'contentUrl' => $baseUrl . 'tools/psilocybin_bibliometrics_visnetwork.R'],
        ],
    ],
    [
        '@context' => 'https://schema.org',
        '@type' => 'ItemList',
        'name' => 'Latest psilocybin and psilocin research publications',
        'url' => $canonicalUrl . '#papers',
        'numberOfItems' => count($latestJsonLdItems),
        'itemListElement' => $latestJsonLdItems,
    ],
];

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#04272d">
  <meta name="color-scheme" content="light">
  <meta name="application-name" content="Psilocybin Research Tracker">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="Psilocybin Research Tracker">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="description" content="Search source-labeled psilocybin and psilocin publications, preprints, trials, citations, analytics, and alerts.">
  <meta name="keywords" content="psilocybin research, psilocin publications, psychedelic therapy, psilocybin clinical trials, psychedelic neuroscience, microdosing research, PubMed psilocybin, Crossref psilocybin, Europe PMC psilocybin">
  <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
  <meta name="author" content="Psilocybin-Research.com">
  <link rel="canonical" href="<?= h($canonicalUrl) ?>">
  <link rel="alternate" type="application/json" title="Psilocybin research publication API" href="<?= h($baseUrl) ?>api.php?resource=latest&amp;limit=25">
  <meta property="og:type" content="website">
  <meta property="og:locale" content="en_US">
  <meta property="og:site_name" content="Psilocybin-Research.com">
  <meta property="og:title" content="Psilocybin Research Publication Tracker">
  <meta property="og:description" content="Search source-labeled psilocybin and psilocin publications, preprints, trials, citations, analytics, and alerts.">
  <meta property="og:url" content="<?= h($canonicalUrl) ?>">
  <meta property="og:image" content="<?= h($shareImageUrl) ?>">
  <meta property="og:image:secure_url" content="<?= h($shareImageUrl) ?>">
  <meta property="og:image:type" content="image/png">
  <meta property="og:image:width" content="512">
  <meta property="og:image:height" content="512">
  <meta property="og:image:alt" content="<?= h($shareImageAlt) ?>">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="Psilocybin Research Publication Tracker">
  <meta name="twitter:description" content="Search source-labeled psilocybin and psilocin publications, preprints, trials, citations, analytics, and alerts.">
  <meta name="twitter:image" content="<?= h($shareImageUrl) ?>">
  <meta name="twitter:image:alt" content="<?= h($shareImageAlt) ?>">
  <title>Publication Tracker | Psilocybin Research</title>
  <link rel="icon" href="assets/logo.png?v=20260713-accordion-emphasis-v93">
  <link rel="icon" type="image/png" sizes="192x192" href="assets/pwa/icon-192.png?v=20260713-accordion-emphasis-v93">
  <link rel="icon" type="image/png" sizes="512x512" href="assets/pwa/icon-512.png?v=20260713-accordion-emphasis-v93">
  <link rel="apple-touch-icon" href="assets/pwa/apple-touch-icon.png?v=20260713-accordion-emphasis-v93">
  <link rel="manifest" href="manifest.webmanifest?v=20260713-accordion-emphasis-v93">
  <link rel="preload" href="assets/preloader-mushroom-desktop.webp" as="image" media="(min-width: 701px)" fetchpriority="high">
  <link rel="preload" href="assets/preloader-mushroom-mobile.webp" as="image" media="(max-width: 700px)" fetchpriority="high">
  <link rel="preload" href="assets/fonts/roboto-latin.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="assets/fonts/roboto-latin-ext.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="assets/styles.min.css?v=<?= h($assetVersion) ?>">
  <script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?></script>
  <script>
    document.documentElement.classList.add('js', 'is-loading');
    try {
      const compactNavigation = window.matchMedia('(max-width: 1180px)').matches;
      const navigationPreferenceKey = compactNavigation ? 'publicationTrackerNavSidebarCollapsedMobile' : 'publicationTrackerNavSidebarCollapsedDesktop';
      const navigationPreference = window.localStorage.getItem(navigationPreferenceKey);
      if ((navigationPreference === null && compactNavigation) || navigationPreference === '1') {
        document.documentElement.classList.add('nav-sidebar-collapsed');
      }
    } catch (error) {}
  </script>
</head>
<body>
<div class="app-preloader" id="app-preloader" role="status" aria-live="polite" aria-label="Loading publication tracker">
  <div class="preloader-shell">
    <strong class="preloader-title">Loading data...</strong>
    <span class="preloader-meter" aria-hidden="true"><span></span></span>
  </div>
</div>
<header class="topbar">
  <button class="nav-sidebar-toggle" id="nav-sidebar-toggle" type="button" aria-expanded="true" aria-controls="primary-sidebar-content" title="Collapse sidebar">
    <i data-icon="chevron-left" aria-hidden="true"></i>
    <span class="sr-only">Collapse sidebar</span>
  </button>
  <div class="primary-sidebar-content" id="primary-sidebar-content">
  <a class="brand brand-lockup" href="/" aria-label="Psilocybin-Research.com publication tracker">
    <img class="brand-icon brand-icon-mushroom" src="assets/mushroom-brand-mark.webp" alt="" width="46" height="46">
    <span class="brand-text">
      <strong>Psilocybin-Research.com</strong>
      <em>Searchable psilocybin and psilocin bibliometric database.</em>
    </span>
  </a>
  <nav aria-label="Primary sections">
    <a href="#papers"><i data-icon="book-marked" aria-hidden="true"></i><span>Publications</span></a>
    <a href="evidence.php"><i data-icon="grid-3x3" aria-hidden="true"></i><span>Evidence</span></a>
    <a href="trials.php"><i data-icon="clipboard-list" aria-hidden="true"></i><span>Trials</span></a>
    <a href="authors.php"><i data-icon="users" aria-hidden="true"></i><span>Authors</span></a>
    <a href="citation-network.php"><i data-icon="network" aria-hidden="true"></i><span>Citation Network</span></a>
    <a href="#analytics" data-open-analytics><i data-icon="network" aria-hidden="true"></i><span>Analytics</span></a>
    <a href="tools/psilocybin_bibliometrics_visnetwork.R" download><i data-icon="r-script" aria-hidden="true"></i><span>R script</span></a>
    <a href="#alerts" data-open-alerts><i data-icon="bell-plus" aria-hidden="true"></i><span>Alerts</span></a>
    <a href="export.php?<?= h(http_build_query(array_merge(canonical_query_params($_GET), ['format' => 'json']))) ?>" target="_blank" rel="noopener" data-sidebar-export><i data-icon="download" aria-hidden="true"></i><span>Export data</span></a>
    <a href="api.php?<?= h(http_build_query(array_merge(canonical_query_params($_GET), ['resource' => 'papers', 'per_page' => 'all', 'page' => 1]))) ?>" target="_blank" rel="noopener"><i data-icon="braces" aria-hidden="true"></i><span>API</span></a>
    <a href="https://github.com/psilocybin-research/psilocybin-research-tracker" target="_blank" rel="noopener me"><i data-icon="github" aria-hidden="true"></i><span>GitHub</span></a>
    <a href="https://doi.org/10.5281/zenodo.21293526" target="_blank" rel="noopener" title="Fixed citable dataset snapshot on Zenodo"><i data-icon="zenodo" aria-hidden="true"></i><span>Zenodo DOI</span></a>
    <a href="about.php"><i data-icon="circle-alert" aria-hidden="true"></i><span>About</span></a>
    <a href="data-protection.php"><i data-icon="shield" aria-hidden="true"></i><span>Data protection</span></a>
  </nav>
  <div class="sidebar-status" aria-label="Publication tracker status">
    <span>Auto-updated</span>
    <em>Indexed records</em>
    <strong data-count-up="<?= h((string)(int)$stats['total']) ?>"><?= h(number_format($stats['total'])) ?></strong>
    <em>Updated <?= h(format_utc_display($lastSuccessfulUpdate)) ?></em>
    <?php if ($latestAddedRecordsUrl): ?><a class="sidebar-status-link" href="<?= h($latestAddedRecordsUrl) ?>">Show latest added records only</a><?php endif; ?>
  </div>
  <div class="top-actions">
    <button class="header-info-button" type="button" data-open-app-info aria-haspopup="dialog"><i data-icon="info" aria-hidden="true"></i><span>Info</span></button>
    <button class="install-app" id="install-app" type="button" hidden><i data-icon="download" aria-hidden="true"></i><span>Install</span></button>
    <button class="push-app" id="push-app" type="button" hidden><i data-icon="bell-plus" aria-hidden="true"></i><span>Push</span></button>
    <?php if ($isAdmin): ?>
    <a class="admin-link" href="#admin"><i data-icon="shield" aria-hidden="true"></i><span>Admin</span></a>
    <?php endif; ?>
  </div>
  </div>
</header>

<dialog class="app-info-modal" id="app-info-modal" aria-labelledby="app-info-title">
  <div class="app-info-sheet">
    <div class="advanced-filter-head">
      <div>
        <h2 id="app-info-title">Application overview</h2>
        <p>Source-labeled psilocybin and psilocin literature for search, monitoring, export, and citation workflows.</p>
      </div>
      <button type="button" class="advanced-filter-close" data-close-app-info aria-label="Close app information"><i data-icon="x" aria-hidden="true"></i></button>
    </div>
    <div class="app-info-body">
      <section>
        <h3>Research workflow</h3>
        <p>Search, filter, export, analyze, and monitor psilocybin and psilocin publications with source labels, publication status, citation links, alerts, API access, and full SQLite download.</p>
      </section>
      <section>
        <h3>Databases used</h3>
        <ul class="app-info-source-list">
          <li>PubMed</li>
          <li>Crossref</li>
          <li>Europe PMC</li>
          <li>OpenAlex</li>
          <li>medRxiv</li>
          <li>bioRxiv</li>
          <li>PsyArXiv</li>
          <li>ClinicalTrials.gov</li>
        </ul>
      </section>
    </div>
  </div>
</dialog>

<section class="hero-band" aria-labelledby="tracker-heading">
  <div class="hero-card">
    <div class="hero-copy">
      <div class="hero-title-lockup">
        <i data-icon="microscope" aria-hidden="true"></i>
        <div>
          <h1 id="tracker-heading">Psilocybin and psilocin research index</h1>
          <p>Find psilocybin and psilocin papers, preprints, trials, and citations with clear source context.</p>
        </div>
      </div>
      <div class="hero-action-panel">
        <div class="hero-action-row">
          <form class="hero-search" method="get" action="./#papers">
            <label class="sr-only" for="hero-q">Search publications</label>
            <i data-icon="search" aria-hidden="true"></i>
            <input id="hero-q" type="search" name="search" value="<?= h($filters['q']) ?>" placeholder="Search indexed metadata and derived topics...">
            <button type="submit">Search</button>
          </form>
          <button class="hero-settings-menu hero-filter-shortcut" type="button" data-open-advanced aria-label="<?= $advancedFiltersActive ? 'Advanced filters active' : 'Open advanced filters' ?>"><i data-icon="settings" aria-hidden="true"></i><span>Filters</span></button>
        </div>
        <div class="hero-action-summary" aria-label="Current publication index status">
          <div>
            <span>Database</span>
            <strong><?= h(number_format($stats['total'])) ?> indexed records</strong>
          </div>
          <div>
            <span><?= $hasSearchQuery ? 'Executed search' : 'Current display' ?></span>
            <strong><?= $hasSearchQuery ? h($currentSearchLabel) : 'Latest publications' ?></strong>
            <em><?= $hasSearchQuery ? h(number_format($result['total'])) . ' matching records are displayed.' : 'Full SQLite index sorted by newest first. Use search or filters to narrow the list.' ?></em>
          </div>
          <?php if ($hasSearchQuery): ?><a class="search-context-reset" href="./#papers">Clear search</a><?php endif; ?>
        </div>
      </div>
      <?php if ($message): ?><div class="notice hero-notice success" role="status"><?= h($message) ?></div><?php endif; ?>
      <?php if ($error): ?><div class="notice hero-notice error" role="alert"><?= h($error) ?></div><?php endif; ?>
    </div>
    <details class="hero-evidence evidence-disclosure" aria-label="Database summary" open>
      <summary>
        <span class="hero-evidence-summary-head">
          <span>Database</span>
          <em><?= h(number_format($stats['total'])) ?> indexed records</em>
        </span>
      </summary>
      <div class="hero-evidence-grid">
        <div class="hero-evidence-summary-metrics" aria-label="Database metrics">
          <a class="database-summary-metric" href="<?= h(tracker_query_url(['substances' => ['psilocybin']])) ?>" aria-label="Show psilocybin records">
            <strong><?= h(number_format($stats['psilocybin'])) ?></strong>
            <span>Psilocybin Records</span>
          </a>
          <a class="database-summary-metric" href="<?= h(tracker_query_url(['substances' => ['psilocin']])) ?>" aria-label="Show psilocin records">
            <strong><?= h(number_format($stats['psilocin'])) ?></strong>
            <span>Psilocin Records</span>
          </a>
          <a class="database-summary-metric" href="#journal-data" aria-label="Show journal data">
            <strong><?= h(number_format($stats['journals'])) ?></strong>
            <span>Journals</span>
          </a>
          <a class="database-summary-metric" href="#topic-data" aria-label="Show research topic data">
            <strong><?= h(number_format(count($topics))) ?></strong>
            <span>Research Topics</span>
          </a>
        </div>
        <a class="evidence-card" href="<?= h(tracker_query_url(['publication_statuses' => ['published']])) ?>" aria-label="Show published records">
          <span>Published</span>
          <strong><?= h(number_format($statusCounts['published'] ?? 0)) ?></strong>
          <em>Peer-reviewed / formal records</em>
        </a>
        <a class="evidence-card" href="<?= h(tracker_query_url(['publication_statuses' => ['preprint']])) ?>" aria-label="Show preprint records">
          <span>Preprints</span>
          <strong><?= h(number_format($statusCounts['preprint'] ?? 0)) ?></strong>
          <em>Marked as not peer reviewed</em>
        </a>
        <a class="evidence-card" href="<?= h(tracker_query_url(['publication_statuses' => ['clinical trial']])) ?>" aria-label="Show clinical trial records">
          <span>Clinical trials</span>
          <strong><?= h(number_format($statusCounts['clinical trial'] ?? 0)) ?></strong>
          <em>ClinicalTrials.gov records</em>
        </a>
        <a class="evidence-card" href="tools/psilocybin_bibliometrics_visnetwork.R" download aria-label="Download R bibliometrics and citation-network script">
          <span>R toolkit</span>
          <strong>visNetwork</strong>
          <em>Bibliometrics, live SQLite analysis, HTML graph, and PDF export</em>
        </a>
        <details class="source-ledger evidence-accordion data-ledger" id="journal-data" aria-label="Journal data" open>
          <summary>
            <span>Top journals</span>
            <em><?= h(number_format($stats['journals'])) ?> indexed journals</em>
          </summary>
          <div class="evidence-accordion-body">
            <table>
              <thead>
                <tr>
                  <th scope="col">Journal</th>
                  <th scope="col">Records</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach (array_slice($analytics['top_journals'], 0, 10) as $journalRow): ?>
                  <tr>
                    <th scope="row"><a class="source-filter-link" href="<?= h(tracker_query_url(['journal' => $journalRow['journal']])) ?>"><?= h((string)$journalRow['journal']) ?></a></th>
                    <td><?= h(number_format((int)$journalRow['count'])) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </details>
        <details class="source-ledger evidence-accordion data-ledger" id="topic-data" aria-label="Research topic data" open>
          <summary>
            <span>Research topics</span>
            <em><?= h(number_format(count($topics))) ?> topic filters</em>
          </summary>
          <div class="evidence-accordion-body">
            <div class="topic-data-grid">
              <?php foreach (array_slice($topics, 0, 18) as $topicRow): ?>
                <a class="topic-data-pill" href="<?= h(tracker_query_url(['topic' => $topicRow['name']])) ?>">
                  <span><?= h((string)$topicRow['name']) ?></span>
                  <strong><?= h(number_format((int)$topicRow['count'])) ?></strong>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        </details>
        <details class="source-ledger source-coverage-table evidence-accordion" aria-label="Source coverage">
          <summary>
            <span>Source coverage</span>
            <em><?= h(number_format(count($sourceNames))) ?> active sources</em>
          </summary>
          <div class="evidence-accordion-body">
          <table>
            <thead>
              <tr>
                <th scope="col">Source</th>
                <th scope="col">Count</th>
                <th scope="col">Share</th>
                <th scope="col">Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach (array_slice($sources, 0, 7) as $source): ?>
                <?php $sourceCount = (int)$source['count']; $sourcePct = $stats['total'] > 0 ? round(($sourceCount / max(1, (int)$stats['total']) * 100), 1) : 0; ?>
                <tr>
                  <th scope="row"><a class="source-filter-link" href="<?= h(source_filter_url((string)$source['source_name'])) ?>"><?= h((string)$source['source_name']) ?></a></th>
                  <td><?= h(number_format($sourceCount)) ?></td>
                  <td><?= h((string)$sourcePct) ?>%</td>
                  <td>Active</td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          </div>
        </details>
        <details class="source-ledger publication-growth-mini evidence-accordion" aria-label="Psilocybin publication growth over time">
          <summary>
            <span>Publication timeline</span>
            <em><?= h(number_format($publicationGrowthTotal)) ?> dated records</em>
          </summary>
          <div class="evidence-accordion-body">
            <div class="publication-growth-head">
              <strong><?= h(number_format($publicationGrowthTotal)) ?> records since <?= h((string)($publicationGrowthFirstYear ?? 2020)) ?></strong>
            </div>
            <?php if ($publicationGrowthYears): ?>
              <div
                id="publication-growth-chart"
                class="publication-growth-chart"
                role="img"
                aria-label="Annual psilocybin publication counts from <?= h((string)$publicationGrowthFirstYear) ?> to <?= h((string)$publicationGrowthLatestYear) ?>"
              ></div>
              <script type="application/json" id="publication-growth-data"><?= json_encode([
                  'from_year' => $publicationGrowthFirstYear,
                  'to_year' => $publicationGrowthLatestYear,
                  'latest_count' => $publicationGrowthLatest,
                  'total' => $publicationGrowthTotal,
                  'rows' => $publicationGrowthYears,
              ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
              <div class="publication-growth-foot">
                <span>Annual records</span>
                <span>Cumulative line</span>
              </div>
            <?php else: ?>
              <p class="publication-growth-empty">Timeline data will appear after dated records are indexed.</p>
            <?php endif; ?>
          </div>
        </details>
      </div>
    </details>
  </div>
</section>

<main class="app-shell">
  <form class="filters panel is-collapsed" method="get" action="./#papers" aria-label="Publication filters">
    <button class="sidebar-toggle" id="sidebar-toggle" type="button" aria-expanded="false" aria-controls="publication-filters-body" title="Expand filters">
      <i data-icon="chevron-down" aria-hidden="true"></i><span>Filters</span>
    </button>
    <div class="filters-body" id="publication-filters-body" hidden>
    <div class="panel-title">
      <a href="/">Reset all</a>
    </div>
    <label class="field">
      <span>Keyword search</span>
      <input type="search" name="search" value="<?= h($filters['q']) ?>" placeholder="Search indexed metadata and derived topics...">
    </label>
    <label class="field">
      <span>Author</span>
      <input type="search" name="author" value="<?= h($filters['author']) ?>" placeholder="e.g. Carhart-Harris">
    </label>

    <fieldset>
      <legend>Substances</legend>
      <label><input type="checkbox" name="substances[]" value="psilocybin" <?= in_array('psilocybin', $substances, true) ? 'checked' : '' ?>> Psilocybin <small><?= h((string)$stats['psilocybin']) ?></small></label>
      <label><input type="checkbox" name="substances[]" value="psilocin" <?= in_array('psilocin', $substances, true) ? 'checked' : '' ?>> Psilocin <small><?= h((string)$stats['psilocin']) ?></small></label>
    </fieldset>

    <fieldset>
      <legend>Date range quick select</legend>
      <label><input type="radio" name="range" value="month" <?= active_range('month', $range) ?>> Last month</label>
      <label><input type="radio" name="range" value="year" <?= active_range('year', $range) ?>> Last year</label>
      <label><input type="radio" name="range" value="5y" <?= active_range('5y', $range) ?>> Last 5 years</label>
      <label><input type="radio" name="range" value="all" <?= active_range('all', $range) ?>> All time</label>
      <label><input type="radio" name="range" value="custom" <?= active_range('custom', $range) ?>> Custom range</label>
    </fieldset>

    <button class="advanced-filter-trigger iconed" type="button" data-open-advanced><i data-icon="network" aria-hidden="true"></i><span><?= $advancedFiltersActive ? 'Advanced filters active' : 'Advanced filters' ?></span></button>
    <dialog class="advanced-filter-modal" id="advanced-filters" aria-labelledby="advanced-filters-title">
      <div class="advanced-filter-sheet">
        <div class="advanced-filter-head">
          <div>
            <h2 id="advanced-filters-title">Advanced filters</h2>
            <p>Refine the current publication search without losing source and evidence-status context.</p>
          </div>
          <button type="button" class="advanced-filter-close" data-close-advanced aria-label="Close advanced filters"><i data-icon="x" aria-hidden="true"></i></button>
        </div>
      <div class="accordion-body">
        <p class="filter-help">Use these only when the initial search is too broad. Filters are source-aware and preserve preprint, clinical trial, review, protocol, and published distinctions.</p>
        <label class="field">
          <span>Publication year</span>
          <select name="year">
            <option value="">All years</option>
            <?php foreach ($years as $year): ?>
              <option value="<?= h((string)$year['year']) ?>" <?= (string)$filters['year'] === (string)$year['year'] ? 'selected' : '' ?>><?= h((string)$year['year']) ?> (<?= h((string)$year['count']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </label>

        <label class="field">
          <span>Journal</span>
          <select name="journal">
            <option value="">All journals</option>
            <?php foreach ($journals as $journal): ?>
              <option value="<?= h((string)$journal['journal']) ?>" <?= (string)$filters['journal'] === (string)$journal['journal'] ? 'selected' : '' ?>><?= h((string)$journal['journal']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field">
          <span>Source database</span>
          <select name="sources[]" multiple size="<?= h((string)min(max(count($sources), 3), 7)) ?>">
            <?php foreach ($sources as $source): ?>
              <option value="<?= h((string)$source['source_name']) ?>" <?= in_array((string)$source['source_name'], $selectedSources, true) ? 'selected' : '' ?>><?= h((string)$source['source_name']) ?> (<?= h((string)$source['count']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </label>
        <fieldset class="evidence-status">
          <legend>Evidence status</legend>
          <?php foreach (PublicationRepository::publicationStatusOptions() as $status => $label): ?>
            <?php $statusCount = 0; foreach ($publicationStatuses as $row) { if (($row['publication_status'] ?? '') === $status) { $statusCount = (int)$row['count']; break; } } ?>
            <label><input type="checkbox" name="publication_statuses[]" value="<?= h($status) ?>" <?= in_array($status, $selectedStatuses, true) ? 'checked' : '' ?>> <?= h($label) ?> <small><?= h((string)$statusCount) ?></small></label>
          <?php endforeach; ?>
        </fieldset>
        <label class="field">
          <span>Topic</span>
          <select name="topic">
            <option value="">All topics</option>
            <?php foreach ($topics as $topic): ?>
              <option value="<?= h($topic['name']) ?>" <?= (string)$filters['topic'] === (string)$topic['name'] ? 'selected' : '' ?>><?= h($topic['name']) ?> (<?= h((string)$topic['count']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field">
          <span>Study type</span>
          <select name="study_type">
            <option value="">All study types</option>
            <?php foreach ($studyTypes as $type): ?>
              <option value="<?= h($type['study_type']) ?>" <?= (string)$filters['study_type'] === (string)$type['study_type'] ? 'selected' : '' ?>><?= h($type['study_type']) ?> (<?= h((string)$type['count']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </label>

        <div class="date-grid">
          <label class="field"><span>From</span><input type="date" name="from" value="<?= h($from) ?>"></label>
          <label class="field"><span>To</span><input type="date" name="to" value="<?= h($to) ?>"></label>
        </div>

      </div>
        <div class="advanced-filter-actions">
          <button class="secondary" type="button" data-close-advanced>Cancel</button>
          <button class="primary iconed" type="submit"><i data-icon="search" aria-hidden="true"></i><span>Apply advanced filters</span></button>
        </div>
      </div>
    </dialog>
    <input type="hidden" name="page" value="1">
    </div>
  </form>

  <section class="results<?= $hasSearchQuery ? ' has-search' : '' ?>" id="papers" tabindex="-1">
    <?php if ($message): ?><div class="notice success" role="status"><?= h($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="notice error" role="alert"><?= h($error) ?></div><?php endif; ?>
    <?php if ($hasSearchQuery): ?>
    <div class="search-context panel is-executed" aria-live="polite">
      <i data-icon="search" aria-hidden="true"></i>
      <div>
        <span>Executed search</span>
        <strong><?= h($currentSearchLabel) ?></strong>
        <em><?= h(number_format($result['total'])) ?> matching records are displayed.</em>
      </div>
      <a class="search-context-reset" href="./#papers">Clear search</a>
    </div>
    <?php endif; ?>

    <div class="paper-panel panel" id="publication-results" tabindex="-1">
      <div class="result-bar">
        <div class="result-title-block">
          <span><?= $hasSearchQuery ? 'Filtered publication index' : 'Default publication index' ?></span>
          <h2 id="paper-results-title"><?= $hasSearchQuery ? 'Search results' : 'Latest publications' ?></h2>
          <p><?= $hasSearchQuery ? h(number_format($result['total'])) . ' matching records for ' . h($currentSearchLabel) . '.' : h(number_format($result['total'])) . ' records from the full SQLite publication database, sorted by newest first.' ?></p>
        </div>
        <div class="result-actions">
          <details class="command-menu result-settings-menu">
            <summary aria-label="Result settings and export data"><i data-icon="settings" aria-hidden="true"></i><span class="sr-only">Result settings and export data</span></summary>
            <div class="command-menu-panel result-settings-panel results-toolbar">
              <form class="result-display-controls" method="get" action="./#papers" aria-label="Result display controls">
                <?= hidden_query_inputs(['sort', 'per_page', 'page']) ?>
                <input type="hidden" name="page" value="1">
                <label>
                  <span>Sort</span>
                  <select name="sort" aria-label="Sort matching results">
                    <option value="newest" <?= $filters['sort'] === 'newest' ? 'selected' : '' ?>>Most recent</option>
                    <option value="oldest" <?= $filters['sort'] === 'oldest' ? 'selected' : '' ?>>Oldest first</option>
                  </select>
                </label>
                <label>
                  <span>Show</span>
                  <select name="per_page" aria-label="Results per page">
                    <?php foreach ([10, 20, 50, 100, 200] as $option): ?>
                      <option value="<?= h((string)$option) ?>" <?= (string)$result['per_page'] === (string)$option ? 'selected' : '' ?>><?= h((string)$option) ?> per page</option>
                    <?php endforeach; ?>
                    <option value="all" <?= $result['per_page'] === 'all' ? 'selected' : '' ?>>All results</option>
                  </select>
                </label>
                <button class="secondary result-display-apply" type="submit">Apply</button>
              </form>
          <div class="publication-exports compact-export-menu" aria-label="Export data from current publication list">
                <button class="collection-export-trigger" type="button" data-export-selected disabled><i data-icon="download" aria-hidden="true"></i><span>Export selected</span></button>
                <a href="export.php?<?= h(http_build_query(array_merge(canonical_query_params($_GET), ['format' => 'bibtex']))) ?>" target="_blank" rel="noopener"><i data-icon="braces" aria-hidden="true"></i><span>BibTeX</span></a>
                <a href="export.php?<?= h(http_build_query(array_merge(canonical_query_params($_GET), ['format' => 'latex']))) ?>" target="_blank" rel="noopener"><i data-icon="file-type" aria-hidden="true"></i><span>LaTeX</span></a>
                <a href="export.php?<?= h(http_build_query(array_merge(canonical_query_params($_GET), ['format' => 'ris']))) ?>" target="_blank" rel="noopener"><i data-icon="copy" aria-hidden="true"></i><span>RIS</span></a>
                <a href="export.php?<?= h(http_build_query(array_merge(canonical_query_params($_GET), ['format' => 'csv']))) ?>" target="_blank" rel="noopener"><i data-icon="table" aria-hidden="true"></i><span>CSV</span></a>
                <a href="export.php?<?= h(http_build_query(array_merge(canonical_query_params($_GET), ['format' => 'json']))) ?>" target="_blank" rel="noopener"><i data-icon="braces" aria-hidden="true"></i><span>JSON</span></a>
                <a href="database.php" target="_blank" rel="noopener"><i data-icon="database" aria-hidden="true"></i><span>SQLite database</span></a>
                <a href="tools/psilocybin_bibliometrics_visnetwork.R" download><i data-icon="r-script" aria-hidden="true"></i><span>R bibliometrics script</span></a>
                <a href="api.php?<?= h(http_build_query(array_merge(canonical_query_params($_GET), ['resource' => 'papers', 'per_page' => 'all', 'page' => 1]))) ?>" target="_blank" rel="noopener"><i data-icon="braces" aria-hidden="true"></i><span>Open API</span></a>
              </div>
              <div class="command-menu-status">
                <span>Updated <strong id="last-updated-text"><?= h(format_utc_display($lastSuccessfulUpdate)) ?></strong></span>
                <div id="update-check" class="update-check" role="status" aria-live="polite">
                  <span class="spinner" aria-hidden="true"></span>
                  <span>Checking for newly indexed publications...</span>
                </div>
              </div>
            </div>
          </details>
          <div class="pager">
            <a class="icon-btn <?= $result['page'] <= 1 ? 'disabled' : '' ?>" href="<?= h(query_with(['page' => max(1, $result['page'] - 1)])) ?>" aria-label="Previous page"><i data-icon="chevron-left" aria-hidden="true"></i></a>
            <span>Page <?= h((string)$result['page']) ?> of <?= h((string)$result['pages']) ?></span>
            <a class="icon-btn <?= $result['page'] >= $result['pages'] ? 'disabled' : '' ?>" href="<?= h(query_with(['page' => min($result['pages'], $result['page'] + 1)])) ?>" aria-label="Next page"><i data-icon="chevron-right" aria-hidden="true"></i></a>
          </div>
        </div>
      </div>

      <div class="matching-results-body" id="matching-results-body" tabindex="-1">
      <?php if (!$stats['total']): ?>
        <div class="state">
          <strong>No publications imported yet.</strong>
          <p>Run <code>php bin/update.php --backfill</code> or use the protected admin refresh.</p>
        </div>
      <?php elseif (!$result['rows']): ?>
        <div class="state">
          <strong>No publications match these filters.</strong>
          <p>Broaden the date range or remove a keyword, journal, or substance filter.</p>
          <div class="state-actions">
            <?php if ($range !== 'all'): ?>
              <a class="primary iconed" href="<?= h(query_with(['range' => 'all', 'from' => null, 'to' => null, 'page' => 1])) ?>#papers"><i data-icon="search" aria-hidden="true"></i><span>Search all years</span></a>
            <?php endif; ?>
            <button class="primary iconed" type="button" data-open-advanced><i data-icon="network" aria-hidden="true"></i><span>Refine filters</span></button>
            <a class="secondary iconed" href="./#papers"><i data-icon="search" aria-hidden="true"></i><span>Clear search</span></a>
          </div>
        </div>
      <?php else: ?>
        <div class="paper-list">
          <?php foreach ($result['rows'] as $paper): ?>
            <article class="paper">
              <div class="paper-main">
                <label class="collection-pick"><input type="checkbox" data-collection-paper value="<?= h((string)$paper['id']) ?>"> <span>Select for export</span></label>
                <h3><a href="publication.php?id=<?= h((string)$paper['id']) ?>"><?= h($paper['title']) ?></a></h3>
                <p class="abstract rights-safe-text-note">
                  <?php if (trim((string)($paper['abstract'] ?? '')) !== ''): ?>
                    Abstract available at the source but not redistributed here.
                    <?php if (!empty($paper['source_url'])): ?><a href="<?= h((string)$paper['source_url']) ?>" target="_blank" rel="noopener">Read at source</a>.<?php endif; ?>
                  <?php else: ?>No abstract availability was recorded for this entry.<?php endif; ?>
                </p>
                <div class="links">
                  <a href="publication.php?id=<?= h((string)$paper['id']) ?>"><i data-icon="book-open" aria-hidden="true"></i> Details</a>
                  <?php if ($paper['doi']): ?><a href="https://doi.org/<?= h($paper['doi']) ?>" target="_blank" rel="noopener"><i data-icon="link" aria-hidden="true"></i> DOI <?= h($paper['doi']) ?></a><?php endif; ?>
                  <?php if ($paper['pubmed_id']): ?><a href="https://pubmed.ncbi.nlm.nih.gov/<?= h($paper['pubmed_id']) ?>/" target="_blank" rel="noopener"><i data-icon="external-link" aria-hidden="true"></i> PubMed <?= h($paper['pubmed_id']) ?></a><?php endif; ?>
                  <button class="copy-citation iconed" type="button" data-citation="<?= h(ExportService::citationText($paper)) ?>"><i data-icon="copy" aria-hidden="true"></i><span>Copy citation</span></button>
                  <button class="copy-bibtex iconed" type="button" data-bibtex="<?= h(ExportService::bibtex([$paper])) ?>"><i data-icon="copy" aria-hidden="true"></i><span>Copy BibTeX</span></button>
                </div>
              </div>
              <div class="paper-meta">
                <strong><?= $paper['journal'] ? chip_link((string)$paper['journal'], ['journal' => (string)$paper['journal']], 'plain-link') : 'Unknown journal' ?></strong>
                <span><?= h($paper['publication_date'] ?: 'Unknown date') ?></span>
                <span class="author-links">
                  <?php $authorLinks = []; foreach (split_tag_values((string)$paper['authors'], 6) as $authorName) { $authorLinks[] = '<a href="authors.php?author=' . h(urlencode($authorName)) . '">' . h($authorName) . '</a>'; } echo $authorLinks ? implode(', ', $authorLinks) : 'Authors unavailable'; ?>
                </span>
              </div>
              <div class="tags">
                <?= publication_recency_badge($paper['publication_date'] ?? null) ?>
                <?php if (!empty($paper['source_name'])): ?><?= chip_link((string)$paper['source_name'], ['sources' => [(string)$paper['source_name']]], 'source-badge') ?><?php endif; ?>
                <?= chip_link(publication_status_label($paper['publication_status'] ?? null), ['publication_statuses' => [PublicationRepository::normalizePublicationStatus($paper['publication_status'] ?? null)]], 'status-badge ' . publication_status_class($paper['publication_status'] ?? null)) ?>
                <?php foreach (split_tag_values((string)$paper['substance_tags']) as $tag): ?><?= chip_link($tag, ['substances' => [$tag]], '') ?><?php endforeach; ?>
                <?php foreach (split_tag_values((string)($paper['topic_tags'] ?? ''), 3) as $tag): ?><?= chip_link($tag, ['topic' => $tag], 'soft') ?><?php endforeach; ?>
                <?php if (!empty($paper['study_type'])): ?><?= chip_link((string)$paper['study_type'], ['study_type' => (string)$paper['study_type']], 'soft') ?><?php endif; ?>
                <a class="tag-link soft more-tags-link" href="publication.php?id=<?= h((string)$paper['id']) ?>">More</a>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
        <nav class="results-bottom-pager" aria-label="Results pagination">
          <a class="icon-btn <?= $result['page'] <= 1 ? 'disabled' : '' ?>" href="<?= h(query_with(['page' => max(1, $result['page'] - 1)])) ?>" aria-label="Previous page"><i data-icon="chevron-left" aria-hidden="true"></i></a>
          <span>Page <?= h((string)$result['page']) ?> of <?= h((string)$result['pages']) ?></span>
          <a class="icon-btn <?= $result['page'] >= $result['pages'] ? 'disabled' : '' ?>" href="<?= h(query_with(['page' => min($result['pages'], $result['page'] + 1)])) ?>" aria-label="Next page"><i data-icon="chevron-right" aria-hidden="true"></i></a>
        </nav>
      <?php endif; ?>
      </div>
    </div>
  </section>

  <aside class="right-rail">
    <dialog class="alert-modal" id="alert-enrollment" aria-labelledby="alert-enrollment-title">
      <form method="post" class="alert-form alert-sheet">
        <div class="advanced-filter-head">
          <div>
            <h2 id="alert-enrollment-title">Create email alert</h2>
            <p>Choose broad coverage or a precise bibliographic alert. Confirmation by email is required before digests are sent.</p>
          </div>
          <button type="button" class="advanced-filter-close" data-close-alerts aria-label="Close email alert form"><i data-icon="x" aria-hidden="true"></i></button>
        </div>
        <input type="hidden" name="action" value="subscribe">
        <div class="alert-intro">
          <strong>New publication notifications</strong>
          <span>Receive a digest whenever newly imported publications mention psilocybin or psilocin. Keep it broad, or narrow it to your research focus.</span>
        </div>
        <label class="field"><span>Email address</span><input type="email" name="email" placeholder="you@university.edu" required></label>
        <fieldset class="alert-mode"><legend>Alert coverage</legend>
          <label><input type="radio" name="alert_scope" value="all" checked> Any new psilocybin / psilocin publication</label>
          <label><input type="radio" name="alert_scope" value="targeted"> Only publications matching selected filters</label>
        </fieldset>
        <fieldset class="frequency-options"><legend>Digest frequency</legend>
          <label><input type="radio" name="frequency" value="daily" checked> Daily</label>
          <label><input type="radio" name="frequency" value="weekly"> Weekly</label>
          <label><input type="radio" name="frequency" value="monthly"> Monthly</label>
        </fieldset>
        <fieldset><legend>Substances</legend>
          <label><input type="checkbox" name="alert_substances[]" value="psilocybin" checked> Psilocybin</label>
          <label><input type="checkbox" name="alert_substances[]" value="psilocin" checked> Psilocin</label>
        </fieldset>
        <details class="alert-targeting">
          <summary>Research focus filters</summary>
          <div class="accordion-body">
            <label class="field"><span>Keywords</span><input type="text" name="alert_keywords" placeholder="e.g. depression, microdosing, OCD"></label>
            <label class="field"><span>Author</span><input type="text" name="alert_author" placeholder="e.g. Carhart-Harris"></label>
            <label class="field"><span>Journal</span><input type="text" name="alert_journal" placeholder="e.g. Neuropsychopharmacology"></label>
            <label class="field"><span>Topic</span><select name="alert_topic"><option value="">Any topic</option><?php foreach ($topics as $topic): ?><option value="<?= h($topic['name']) ?>"><?= h($topic['name']) ?></option><?php endforeach; ?></select></label>
            <label class="field"><span>Cites DOI</span><input type="text" name="alert_cited_doi" placeholder="10.1038/..." inputmode="url"></label>
            <p class="field-note">Citation alerts match publications whose available reference metadata contains this DOI.</p>
          </div>
        </details>
        <label class="consent-check"><input type="checkbox" name="privacy_consent" value="1" required> I agree that my email address and selected filters are stored to confirm and generate this alert. Email addresses and alert access tokens are encrypted at rest. Digests start only after I confirm by email, and every digest includes manage and unsubscribe links. Details are in the <a href="data-protection.php" target="_blank" rel="noopener">data protection notice</a>.</label>
        <div class="advanced-filter-actions">
          <button class="secondary" type="button" data-close-alerts>Cancel</button>
          <button class="primary iconed" type="submit"><i data-icon="bell-plus" aria-hidden="true"></i><span>Create email alert</span></button>
        </div>
        <p class="privacy-note compact">No tracking pixel is used. Ignore the confirmation email if you did not request the alert. Unsubscribing disables the alert immediately. The app does not send your searches to external frontend analytics services.</p>
      </form>
    </dialog>

    <dialog class="analytics-modal" id="analytics-modal" aria-labelledby="analytics-modal-title">
      <div class="analytics-sheet">
        <div class="advanced-filter-head">
          <div>
            <h2 id="analytics-modal-title">Analytics</h2>
            <p>Explore publication timelines, source trends, journals, topics, and authors for the current tracker index.</p>
          </div>
          <button type="button" class="advanced-filter-close" data-close-analytics aria-label="Close analytics"><i data-icon="x" aria-hidden="true"></i></button>
        </div>
        <div class="analytics-scrollbody">
          <details class="analytics-lens" id="analytics-lens">
            <summary>
              <span class="analytics-lens-summary-main">
                <i data-icon="search" aria-hidden="true"></i>
                <span>
                  <strong>Deep research lens</strong>
                  <em id="analytics-lens-summary">Search within analytics by keyword, author, journal, source, status, topic, or DOI.</em>
                </span>
              </span>
              <span class="analytics-lens-cta">Configure lens</span>
            </summary>
            <div class="analytics-lens-body">
              <div class="analytics-lens-primary">
                <label class="field"><span>Keyword</span><input type="search" id="analytics-keyword" placeholder="title, tags, DOI, PubMed ID"></label>
                <label class="field"><span>Author</span><input type="search" id="analytics-author" placeholder="e.g. Carhart-Harris"></label>
                <label class="field"><span>Journal</span><input type="search" id="analytics-journal" placeholder="e.g. Neuropsychopharmacology"></label>
              </div>
              <details class="analytics-nested-controls">
                <summary>Bibliographic fields</summary>
                <div class="analytics-lens-grid">
                  <label class="field"><span>DOI / PubMed ID</span><input type="search" id="analytics-identifier" placeholder="10.1038/... or PMID"></label>
                  <label class="field"><span>Source database</span><input type="search" id="analytics-source" placeholder="PubMed, OpenAlex, medRxiv..."></label>
                  <label class="field"><span>Status</span><select id="analytics-status"><option value="">Any status</option><?php foreach (PublicationRepository::publicationStatusOptions() as $status => $label): ?><option value="<?= h($status) ?>"><?= h($label) ?></option><?php endforeach; ?></select></label>
                </div>
              </details>
              <details class="analytics-nested-controls">
                <summary>Evidence fields</summary>
                <div class="analytics-lens-grid">
                  <label class="field"><span>Topic</span><input type="search" id="analytics-topic" placeholder="depression, safety, neuroscience..."></label>
                  <label class="field"><span>Study type</span><input type="search" id="analytics-study-type" placeholder="clinical, review, protocol..."></label>
                  <label class="field"><span>Substance</span><input type="search" id="analytics-substance" placeholder="psilocybin or psilocin"></label>
                </div>
              </details>
              <div class="analytics-lens-actions">
                <button class="primary iconed" type="button" id="analytics-apply"><i data-icon="search" aria-hidden="true"></i><span>Apply lens</span></button>
                <button type="button" id="analytics-clear">Clear</button>
                <a id="analytics-open-papers" href="./#papers"><i data-icon="list-filter" aria-hidden="true"></i><span>Open matching publications</span></a>
                <a id="analytics-export-latex" href="export.php?format=latex" target="_blank" rel="noopener"><i data-icon="file-type" aria-hidden="true"></i><span>Export LaTeX</span></a>
              </div>
            </div>
          </details>
          <div class="timeline-card">
            <div class="timeline-head">
              <h3>Publication timeline</h3>
              <span class="timeline-summary" id="timeline-summary">Loading range...</span>
            </div>
            <div class="timeline-insight" id="timeline-insight" aria-live="polite">
              Hover or focus a bar to inspect the publication bucket. Activate a bar to open the matching publications.
            </div>
            <div class="timeline-controls" aria-label="Timeline range">
              <div class="timeline-presets" role="group" aria-label="Timeline presets">
                <button type="button" data-range="1y">1Y</button>
                <button type="button" data-range="5y">5Y</button>
                <button type="button" data-range="10y" class="is-active">10Y</button>
                <button type="button" data-range="all">All</button>
              </div>
              <div class="timeline-custom" aria-label="Custom timeline range">
                <label><span>From</span><input type="date" id="timeline-from"></label>
                <label><span>To</span><input type="date" id="timeline-to"></label>
                <button type="button" id="timeline-apply">Apply</button>
                <button type="button" id="timeline-reset">Reset</button>
              </div>
              <div class="timeline-open" aria-label="Open current timeline data">
                <button type="button" id="timeline-open-json">Open JSON</button>
                <button type="button" id="timeline-open-html">Open HTML</button>
              </div>
            </div>
            <div id="publication-timeline" class="timeline-chart" role="img" aria-label="Publication timeline chart"></div>
          </div>
          <section class="analytics-results" aria-labelledby="analytics-results-title">
            <div class="analytics-results-head">
              <h3 id="analytics-results-title">Lens results</h3>
              <span id="analytics-results-count">Showing the current timeline set.</span>
            </div>
            <div class="analytics-results-list" id="analytics-results-list"></div>
          </section>
          <h3>Publication trends</h3>
          <div class="mini-bars">
            <?php $maxTrend = max(array_column($analytics['trends'] ?: [['count' => 1]], 'count')); foreach (array_slice($analytics['trends'], -10) as $row): ?>
              <span title="<?= h((string)$row['year']) ?>: <?= h((string)$row['count']) ?>"><i style="height:<?= h((string)max(4, round(((int)$row['count'] / $maxTrend) * 70))) ?>px"></i><small><?= h((string)$row['year']) ?></small></span>
            <?php endforeach; ?>
          </div>
          <h3>Top journals</h3>
          <ul class="rank-list"><?php foreach (array_slice($analytics['top_journals'], 0, 5) as $row): ?><li><a class="rank-filter-link" href="<?= h(query_with(['journal' => $row['journal'], 'page' => 1])) ?>#papers"><span><?= h($row['journal']) ?></span><strong><?= h((string)$row['count']) ?></strong></a></li><?php endforeach; ?></ul>
          <h3>Top topics</h3>
          <ul class="rank-list"><?php foreach (array_slice($analytics['topics'], 0, 6) as $row): ?><li><a class="rank-filter-link" href="<?= h(query_with(['topic' => $row['name'], 'page' => 1])) ?>#papers"><span><?= h($row['name']) ?></span><strong><?= h((string)$row['count']) ?></strong></a></li><?php endforeach; ?></ul>
          <h3>Top authors</h3>
          <ul class="rank-list"><?php foreach (array_slice($analytics['top_authors'], 0, 5) as $row): ?><li><a class="rank-filter-link" href="<?= h(query_with(['author' => $row['name'], 'page' => 1])) ?>#papers"><span><?= h($row['name']) ?></span><strong><?= h((string)$row['count']) ?></strong></a></li><?php endforeach; ?></ul>
        </div>
      </div>
    </dialog>

    <?php if ($isAdmin): ?>
    <details class="panel accordion digest">
      <summary>Email digest preview</summary>
      <div class="accordion-body">
      <pre><?= h($preview) ?></pre>
      </div>
    </details>

    <details class="panel accordion admin" id="admin">
      <summary>Admin / debug</summary>
      <div class="accordion-body">
      <form method="post">
        <input type="hidden" name="action" value="refresh">
        <label class="field"><span>Admin token</span><input type="password" name="admin_token" autocomplete="off"></label>
        <div class="date-grid">
          <label class="field"><span>From</span><input type="date" name="refresh_from" value="<?= h(gmdate('Y-m-d', strtotime('-1 year'))) ?>"></label>
          <label class="field"><span>Limit</span><input type="number" name="refresh_limit" min="1" max="500" value="200"></label>
        </div>
        <button class="secondary" type="submit">Manual refresh</button>
      </form>
      <h3>Last fetch status</h3>
      <ul class="debug-list">
        <?php foreach ($latestRuns as $run): ?><li><strong><?= h($run['source']) ?></strong> <?= h($run['status']) ?> · +<?= h((string)$run['imported_count']) ?> / updated <?= h((string)$run['updated_count']) ?> / skipped <?= h((string)($run['skipped_count'] ?? 0)) ?> / errors <?= h((string)$run['error_count']) ?> · <?= h($run['finished_at'] ?: $run['started_at']) ?></li><?php endforeach; ?>
      </ul>
      <?php if ($latestErrors): ?>
        <h3>Recent errors</h3>
        <ul class="debug-list errors"><?php foreach ($latestErrors as $fetchError): ?><li><?= h($fetchError['source']) ?>: <?= h($fetchError['message']) ?></li><?php endforeach; ?></ul>
      <?php endif; ?>
      <h3>Curation</h3>
      <?php if ($adminPaper): ?>
        <form method="post" class="curation-form">
          <input type="hidden" name="action" value="curate">
          <label class="field"><span>Admin token</span><input type="password" name="admin_token" autocomplete="off"></label>
          <label class="field"><span>Paper ID</span><input type="number" name="paper_id" value="<?= h((string)$adminPaper['id']) ?>"></label>
          <label class="field"><span>Substance tags</span><input type="text" name="curate_substance_tags" value="<?= h($adminPaper['substance_tags']) ?>"></label>
          <label class="field"><span>Topic tags</span><input type="text" name="curate_topic_tags" value="<?= h($adminPaper['topic_tags'] ?? '') ?>"></label>
          <label class="field"><span>Study type</span><input type="text" name="curate_study_type" value="<?= h($adminPaper['study_type'] ?? '') ?>"></label>
          <label><input type="checkbox" name="curate_hidden" value="1" <?= !empty($adminPaper['hidden']) ? 'checked' : '' ?>> Hide publication</label>
          <label><input type="checkbox" name="curate_false_positive" value="1" <?= !empty($adminPaper['false_positive']) ? 'checked' : '' ?>> Mark false positive</label>
          <label class="field"><span>Notes</span><input type="text" name="curate_notes" value="<?= h($adminPaper['curation_notes'] ?? '') ?>"></label>
          <button class="secondary" type="submit">Save curation</button>
        </form>
      <?php endif; ?>
      <form method="post" class="curation-form">
        <input type="hidden" name="action" value="merge">
        <label class="field"><span>Admin token</span><input type="password" name="admin_token" autocomplete="off"></label>
        <div class="date-grid">
          <label class="field"><span>Duplicate ID</span><input type="number" name="merge_source_id"></label>
          <label class="field"><span>Canonical ID</span><input type="number" name="merge_target_id"></label>
        </div>
        <button class="secondary" type="submit">Merge duplicate</button>
      </form>
      </div>
    </details>
    <?php endif; ?>
  </aside>
</main>

<footer class="footer">
  <span>
    Data sources: <?= h(implode(', ', $sourceNames)) ?>.
    <button class="footer-stats-trigger" id="footer-stats-trigger" type="button" aria-haspopup="dialog" aria-controls="source-stats-modal">
      Live index: <?= h(number_format($stats['total'])) ?> records from <?= h(number_format(count($sourceNames))) ?> sources;
      <?= h(number_format($statusCounts['published'] ?? 0)) ?> published,
      <?= h(number_format($statusCounts['preprint'] ?? 0)) ?> preprints,
      <?= h(number_format($statusCounts['clinical trial'] ?? 0)) ?> clinical trials.
    </button>
    Coverage is not exhaustive; verify records before citation or clinical interpretation.
  </span>
  <span class="footer-db-meta">
    Generated <?= h(format_utc_display(current_utc())) ?> · SQLite size <?= h($databaseSizeLabel) ?> · Last update <?= h(format_utc_display($lastSuccessfulUpdate)) ?> · Last DOI article added
    <?php if ($latestAddedDoi): ?><?= h(format_utc_display($latestAddedAt)) ?> · <a href="https://doi.org/<?= h($latestAddedDoi) ?>" target="_blank" rel="noopener">DOI <?= h($latestAddedDoi) ?></a><?php else: ?>unavailable<?php endif; ?> · DB query <?= h(number_format($footerQueryMs, 1)) ?> ms.
  </span>
  <span class="footer-app-meta">
    Application created by Dr. Christopher B. Germann · <a href="about.php">About</a> · <a href="data-protection.php">Data protection</a> · Version <?= h($appVersion) ?> · <?= h(gmdate('Y')) ?>.
    <span id="client-environment" data-client-environment>Browser and operating system details loading.</span>
  </span>
  <?= funding_acknowledgement() ?>
</footer>
<dialog class="source-stats-modal" id="source-stats-modal" aria-labelledby="source-stats-title">
  <div class="source-stats-card">
    <header>
      <div>
        <h2 id="source-stats-title">Live source coverage</h2>
        <p><?= h(number_format($stats['total'])) ?> indexed records across <?= h(number_format(count($sourceNames))) ?> active sources.</p>
      </div>
      <form method="dialog">
        <button class="source-stats-close" type="submit" data-close-source-stats aria-label="Close source stats"><i data-icon="x" aria-hidden="true"></i></button>
      </form>
    </header>
    <div class="source-stats-summary" aria-label="Publication status totals">
      <div><strong><?= h(number_format($statusCounts['published'] ?? 0)) ?></strong><span>Published</span></div>
      <div><strong><?= h(number_format($statusCounts['preprint'] ?? 0)) ?></strong><span>Preprints</span></div>
      <div><strong><?= h(number_format($statusCounts['clinical trial'] ?? 0)) ?></strong><span>Clinical trials</span></div>
    </div>
    <ol class="source-stats-list" aria-label="Records by source">
      <?php foreach ($sources as $source): ?>
        <?php $sourceCount = (int)$source['count']; $sourcePct = $stats['total'] > 0 ? round(($sourceCount / max(1, (int)$stats['total']) * 100), 1) : 0; ?>
        <li>
          <div>
            <strong><?= h((string)$source['source_name']) ?></strong>
            <span><?= h(number_format($sourceCount)) ?> records · <?= h((string)$sourcePct) ?>%</span>
          </div>
          <i style="--source-share: <?= h((string)max(3, round(($sourceCount / $maxSourceCount) * 100))) ?>%"></i>
        </li>
      <?php endforeach; ?>
    </ol>
    <p class="source-stats-note">Counts exclude hidden records and curated false positives. Preprints remain labeled as not peer reviewed in search results and exports.</p>
  </div>
</dialog>
<div class="app-toast" id="app-toast" role="status" aria-live="polite" hidden></div>
<div class="scroll-progress" id="scroll-progress" aria-hidden="true"><span></span></div>
<div class="page-tools" aria-label="Page tools">
  <button class="floating-utility-button sidebar-fullscreen-toggle" id="sidebar-fullscreen-toggle" type="button" hidden aria-pressed="false">
    <i data-icon="maximize" aria-hidden="true"></i><span>Fullscreen</span>
  </button>
  <button class="floating-utility-button sidebar-print-results" id="sidebar-print-results" type="button">
    <i data-icon="printer" aria-hidden="true"></i><span>Print results</span>
  </button>
  <button class="floating-utility-button sidebar-share-results" id="sidebar-share-results" type="button" hidden>
    <i data-icon="share-2" aria-hidden="true"></i><span>Share results</span>
  </button>
</div>
<button class="scroll-top" id="scroll-top" type="button" aria-label="Scroll to top" title="Scroll to top">
  <i data-icon="arrow-up" aria-hidden="true"></i><span>Top</span>
</button>
<script type="application/json" id="analytics-data"><?= json_encode($analytics, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<script src="assets/app.min.js?v=<?= h($assetVersion) ?>" defer></script>
</body>
</html>
