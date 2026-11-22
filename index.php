<?php
declare(strict_types=1);

require_once __DIR__ . '/src/helpers.php';
WebRequestSecurity::protectPost(false);
$fullForms = ($_GET['form_options'] ?? '') === 'full';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Existing installed WebAPKs can retain the former launch URL for months.
// Strip only our legacy launch marker, preserving actual research filters.
if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true) && ($_GET['source'] ?? null) === 'pwa') {
    $launchQuery = $_GET;
    unset($launchQuery['source']);
    $launchQuery = http_build_query($launchQuery, '', '&', PHP_QUERY_RFC3986);
    header('Location: /' . ($launchQuery !== '' ? '?' . $launchQuery : ''), true, 302);
    exit;
}

$db = new Database();
$db->initialize();
$repo = new PublicationRepository($db);
$runs = new FetchRunRepository($db);
$alerts = new AlertService($db, $repo);

$message = null;
$error = null;
$hasAccountSystem = class_exists('AccountAuth');
$isSessionAdmin = $hasAccountSystem && AccountAuth::isAdmin();
$isAdmin = $isSessionAdmin || (isset($_POST['admin_token']) && hash_equals(Config::adminToken(), (string)($_POST['admin_token'] ?? '')) && (!$hasAccountSystem || AccountAuth::legacyAllowed()));
$action = request_value('action');
$publicRefreshCooldownSeconds = 3600;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['curate', 'merge', 'refresh'], true) && $isSessionAdmin) {
        AccountAuth::requireCsrf($_POST['account_csrf'] ?? null);
        if ($action === 'merge') AccountAuth::requireUser(new AccountStore(), true);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'subscribe') {
        if (!request_value('privacy_consent')) {
            throw new InvalidArgumentException('Please confirm the alert data-use notice before subscribing.');
        }
        WebRequestSecurity::rateLimit([['alert-ip', (string)($_SERVER['REMOTE_ADDR'] ?? ''), 8, 600], ['alert-address', SensitiveData::canonicalEmail((string)request_value('email', '')), 3, 3600]]);
        $alertScope = request_value('alert_scope', 'all');
        $subscription = $alerts->subscribe(
            (string)request_value('email', ''),
            (string)request_value('frequency', 'daily'),
            request_array('alert_substances'),
            $alertScope === 'targeted' ? request_value('alert_keywords') : null,
            $alertScope === 'targeted' ? request_value('alert_author') : null,
            $alertScope === 'targeted' ? AlertFilters::request('alert_journal') : null,
            $alertScope === 'targeted' ? AlertFilters::request('alert_topic') : null,
            $alertScope === 'targeted' ? request_value('alert_cited_doi') : null,
            request_array('event_types')
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
    if (!$e instanceof InvalidArgumentException) OperationalLogger::exception('web.request_failed', $e);
    $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'The request could not be completed. Please try again later.';
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
    $executedSearchParts[] = 'Journal: ' . implode(', ', AlertFilters::displayNames($filters['journal']));
}
if (trim((string)($filters['topic'] ?? '')) !== '') {
    $executedSearchParts[] = 'Topic: ' . implode(', ', AlertFilters::displayNames($filters['topic']));
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
if (($range === 'custom' || isset($_GET['from']) || isset($_GET['to'])) && (trim((string) $from) !== '' || trim((string) $to) !== '')) {
    $executedSearchParts[] = 'Date: ' . (trim((string) $from) !== '' ? $from : 'Any') . ' to ' . (trim((string) $to) !== '' ? $to : 'Any');
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
$searchHighlightQuery = trim((string)$filters['q']);

$activeFilterChips = active_search_filters($filters, $_GET);
if (!empty($filters['notification'])) {
    $activeFilterChips[] = ['key'=>'notification','label'=>'Research notification','url'=>search_filter_url($_GET, ['notification'=>null,'page'=>1])];
}

$hasNonQueryFilters = (bool)array_filter($activeFilterChips, static fn(array $chip): bool => $chip['key'] !== 'q');
$resetFiltersUrl = reset_search_filters_url($filters);
$result = $repo->search($filters);
$entryOptions = array_values(array_filter([10, 20, 50, 100, 200], static fn (int $option): bool => $option < (int)$result['total']));
$selectedEntryOption = $result['per_page'] === 'all' || (int)$result['per_page'] >= (int)$result['total'] ? 'all' : (string)$result['per_page'];
if ($selectedEntryOption !== 'all' && !in_array((int)$selectedEntryOption, $entryOptions, true)) {
    $entryOptions[] = (int)$selectedEntryOption;
    sort($entryOptions);
}
$loadingContext = publication_loading_context($result, $filters, $currentSearchLabel);
$preloaderLabel = $loadingContext['title'];
$preloaderDetail = $loadingContext['detail'];
$stats = $repo->stats();
$latestLimit = 25;
$latestPapers = $repo->latest($latestLimit);
$journals = $repo->journals();
$years = $repo->years();
$latestRuns = $runs->latestRuns();
$latestErrors = $runs->latestErrors();
$latestSuccessfulRun = $runs->latestSuccessful();
$lastSuccessfulUpdate = $latestSuccessfulRun['finished_at'] ?? null;
$topics = $repo->topics();
$studyTypes = $repo->studyTypes();
$sources = $repo->sources();
$publicationStatuses = $repo->publicationStatuses();
$selectedSources = $filters['sources'] ?? [];
$selectedStatuses = $filters['publication_statuses'] ?: array_keys(PublicationRepository::publicationStatusOptions());
$advancedFiltersActive = (bool)($filters['year'] || $filters['journal'] || $filters['topic'] || $filters['study_type'] || $selectedSources || $range === 'custom' || $selectedStatuses !== array_keys(PublicationRepository::publicationStatusOptions()));
$analytics = $repo->analytics(false);
$adminPaperId = request_value('paper_id') ?: ($result['rows'][0]['id'] ?? null);
$adminPaper = $isAdmin && $adminPaperId ? $repo->findById((int)$adminPaperId) : null;
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
    $firstTrendYear = min(array_keys($publicationTrendByYear));
    $lastTrendYear = max(array_keys($publicationTrendByYear));
    for ($year = $firstTrendYear; $year <= $lastTrendYear; $year++) {
        $publicationGrowthYears[] = ['year' => $year, 'count' => $publicationTrendByYear[$year] ?? 0];
    }
}
$publicationGrowthTotal = array_sum(array_column($publicationGrowthYears, 'count'));
$publicationGrowthLatest = $publicationGrowthYears ? (int)end($publicationGrowthYears)['count'] : 0;
$publicationGrowthLatestYear = $publicationGrowthYears ? (int)end($publicationGrowthYears)['year'] : null;
$publicationGrowthFirstYear = $publicationGrowthYears ? (int)$publicationGrowthYears[0]['year'] : null;
$assetVersion = '20261101-sidebar-close-left-v302';
$appVersion = '2.1.6';
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
$shareImageUrl = $baseUrl . 'assets/social/psilocybin-research-eu-restored-20261102.png';
$shareImageAlt = 'Psilocybin mushroom with a small European Union emblem acknowledging historical FP7 CogNovo support, grant 604764.';
$trackingQueryKeys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid'];
$indexAffectingQueryKeys = array_values(array_diff(array_keys($_GET), $trackingQueryKeys));
$homepageRobots = $indexAffectingQueryKeys === []
    ? 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1'
    : 'noindex, follow, noarchive';
if ($indexAffectingQueryKeys !== []) {
    header('X-Robots-Tag: noindex, follow, noarchive');
}
$maintainerSchema = LinkedData::maintainer();
$latestJsonLdItems = [];
$trialModel = new TrialMapService($db);
foreach (array_slice($latestPapers, 0, 10) as $index => $paper) {
    $latestJsonLdItems[] = ['@type' => 'ListItem', 'position' => $index + 1,
        'url' => $baseUrl . 'publication.php?id=' . (int)$paper['id'],
        'item' => LinkedData::record($paper, [], [], $paper['publication_status'] === 'clinical trial' ? $trialModel->forPublication((int)$paper['id']) : null)];
}
$jsonLd = [
    [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => 'Psilocybin Research',
        'alternateName' => 'Psilocybin Research Database',
        'url' => $canonicalUrl,
        'description' => 'A source-aware research index for psilocybin and psilocin publications, preprints, protocols, reviews, and clinical trials.',
        'potentialAction' => [
            '@type' => 'SearchAction',
            'target' => ['@type' => 'EntryPoint', 'urlTemplate' => $canonicalUrl . '?search={search_term_string}#papers'],
            'query-input' => 'required name=search_term_string',
        ],
    ],
    [
        '@context' => 'https://schema.org',
        '@type' => 'WebApplication',
        'name' => 'Psilocybin Research Tracker',
        'applicationCategory' => 'ResearchApplication',
        'operatingSystem' => 'Any',
        'url' => $canonicalUrl,
        'description' => 'Search source-labeled psilocybin and psilocin publications, preprints, trials, citations, analytics, and alerts.',
        'image' => $shareImageUrl,
        'author' => $maintainerSchema,
        'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD'],
    ],
    LinkedData::dataset(),
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
  <?= funding_meta_tags() ?>
  <?= agent_discovery_links() ?>
  <meta name="theme-color" content="#123c31">
  <meta name="color-scheme" content="light">
  <meta name="application-name" content="Psilocybin Research Tracker">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="Psilocybin Research Tracker">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="description" content="Search psilocybin and psilocin research for free. Explore publications, trials, evidence guides and researcher networks, with API, SQLite and citation exports.">
  <meta name="keywords" content="psilocybin research, psilocin publications, psychedelic therapy, psilocybin clinical trials, psychedelic neuroscience, microdosing research, PubMed psilocybin, Crossref psilocybin, Europe PMC psilocybin">
  <meta name="robots" content="<?= h($homepageRobots) ?>">
  <meta name="author" content="Dr. Christopher B. Germann">
  <link rel="canonical" href="<?= h($canonicalUrl) ?>">
  <link rel="alternate" type="application/json" title="Psilocybin research publication API" href="<?= h($baseUrl) ?>api.php?resource=latest&amp;limit=25">
  <meta property="og:type" content="website">
  <meta property="og:locale" content="en_US">
  <meta property="og:site_name" content="Psilocybin-Research.com">
  <meta property="og:title" content="Psilocybin Research Database">
  <meta property="og:description" content="Search publications, preprints, clinical trials, citations, authors, evidence views, exports, and alerts in one living research database.">
  <meta property="og:url" content="<?= h($canonicalUrl) ?>">
  <meta property="og:image" content="<?= h($shareImageUrl) ?>">
  <meta property="og:image:secure_url" content="<?= h($shareImageUrl) ?>">
  <meta property="og:image:type" content="image/png">
  <meta property="og:image:width" content="512">
  <meta property="og:image:height" content="512">
  <meta property="og:image:alt" content="<?= h($shareImageAlt) ?>">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="Psilocybin Research Database">
  <meta name="twitter:description" content="Search publications, preprints, clinical trials, citations, authors, evidence views, exports, and alerts in one living research database.">
  <meta name="twitter:image" content="<?= h($shareImageUrl) ?>">
  <meta name="twitter:image:alt" content="<?= h($shareImageAlt) ?>">
  <title>Psilocybin Research Database | Publications and Clinical Trials</title>
  <link rel="icon" type="image/x-icon" href="/favicon.ico">
  <link rel="apple-touch-icon" href="assets/pwa/apple-touch-icon.png?v=20260906-crossed-tools-v143">
  <link rel="manifest" href="manifest.webmanifest">
  <link rel="preload" href="assets/preloader-mushroom-desktop.webp" as="image" media="(min-width: 701px)" fetchpriority="high">
  <link rel="preload" href="assets/preloader-mushroom-mobile.webp" as="image" media="(max-width: 700px)" fetchpriority="high">
  <link rel="preload" href="assets/fonts/roboto-latin.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="<?= h(FrontendAssets::url('assets/home.min.css')) ?>">
  <?= json_ld_script(LinkedData::document(array_map(static function (array $node): array { unset($node['@context']); return $node; }, $jsonLd))) ?>
  <?php if (!$fullForms): ?><script>
    document.documentElement.classList.add('js', 'is-loading');
    try {
      const compactNavigation = window.matchMedia('(max-width: 1180px)').matches;
      const navigationPreferenceKey = compactNavigation ? 'publicationTrackerNavSidebarCollapsedMobile' : 'publicationTrackerNavSidebarCollapsedDesktop';
      const navigationPreference = window.localStorage.getItem(navigationPreferenceKey);
      if ((navigationPreference === null && compactNavigation) || navigationPreference === '1') {
        document.documentElement.classList.add('nav-sidebar-collapsed');
      }
    } catch (error) {}
  </script><?php endif; ?>
</head>
<body class="task-database-page<?= $fullForms ? ' expanded-forms' : '' ?>">
<div class="app-preloader" id="app-preloader" role="status" aria-live="polite" aria-label="Loading database...">
  <div class="preloader-shell">
    <strong class="preloader-title">Loading database...</strong>
    <span class="preloader-detail" hidden></span>
    <span class="preloader-meter" aria-hidden="true"><span></span></span>
  </div>
</div>
<?= detail_page_header('publications', $isAdmin, !$fullForms) ?>
<noscript><style>.task-paper .paper-citation-menu>summary>span{display:inline!important}</style><p class="native-form-entry"><a href="?form_options=full#advanced-filters">Open full search and email-alert forms</a></p></noscript>


<section class="hero-band task-search-hero" aria-labelledby="tracker-heading">
  <div class="hero-card">
    <div class="hero-copy">
      <div class="hero-title-lockup">
        <i data-icon="search" aria-hidden="true"></i>
        <div>
          <h1 id="tracker-heading">Psilocybin research database</h1>
          <p>Find papers and trials. Save, compare, and cite your research.</p>
        </div>
      <a class="research-film-button" href="research-in-motion/"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="6" width="12" height="12" rx="3"/><path d="m15 10 6-3v10l-6-3z"/></svg><span>Psilocybin Research in <em>Motion</em></span></a>
      </div>
      <div class="hero-action-panel">
        <div class="hero-action-row">
          <div class="hero-search-stack">
            <form class="hero-search" method="get" action="./#papers" role="search" aria-label="Search publications" toolname="search_publications" tooldescription="Search public psilocybin and psilocin research metadata by title, author, identifier or topic and display matching publication records." toolautosubmit>
              <label class="sr-only" for="hero-q">Search publications</label>
              <i data-icon="search" aria-hidden="true"></i>
              <input id="hero-q" type="search" name="search" toolparamdescription="Publication title, author name, DOI, PMID, or topic. Empty shows the current catalogue." value="<?= h($filters['q']) ?>" placeholder="Title, author, DOI or topic…" aria-describedby="hero-search-help">
              <button type="submit">Search</button>
            </form>
            <p class="hero-search-help" id="hero-search-help">Search titles, authors, identifiers, and indexed metadata.</p>
          </div>
          <button class="hero-settings-menu hero-filter-shortcut" type="button" data-open-advanced aria-label="<?= $advancedFiltersActive ? 'Advanced filters active' : 'Open advanced filters' ?>"><i data-icon="settings" aria-hidden="true"></i><span>Filters</span></button>
        </div>
        <div class="hero-action-summary hero-index-summary" aria-label="Publication index status">
          <div><strong><?= h(number_format($stats['total'])) ?> indexed records</strong><span>Updated <time id="hero-updated-text"><?= h(format_utc_display($lastSuccessfulUpdate)) ?></time></span></div>
          <?php if ($hasSearchQuery): ?><span><?= h(number_format($result['total'])) ?> matching records</span><?php endif; ?>
        </div>
      </div>
      <?php if ($message): ?><div class="notice hero-notice success" role="status"><?= h($message) ?></div><?php endif; ?>
      <?php if ($error): ?><div class="notice hero-notice error" role="alert"><?= h($error) ?></div><?php endif; ?>
    </div>

  </div>
</section>

<main class="app-shell">
  <form class="advanced-filter-form" method="get" action="./#papers" aria-label="Advanced publication filters">
    <dialog class="advanced-filter-modal" id="advanced-filters" aria-labelledby="advanced-filters-title"<?= $fullForms ? ' open' : '' ?>>
      <div class="advanced-filter-sheet">
        <div class="advanced-filter-head">
          <div>
            <h2 id="advanced-filters-title">Search publications</h2>
            <p>Search the database, then refine only as needed.</p>
          </div>
          <button type="button" class="advanced-filter-close" data-close-advanced aria-label="Close advanced filters"><i data-icon="x" aria-hidden="true"></i></button>
        </div>
      <div class="advanced-filter-body accordion-body">
        <label class="field publication-search-field" for="publication-search-query"><span>Title, author, DOI or topic</span><input id="publication-search-query" type="search" name="search" value="<?= h($filters['q']) ?>" placeholder="Search publications…" data-advanced-search-query aria-describedby="publication-search-hint"></label>
        <p id="publication-search-hint" class="publication-search-hint">Your current filters stay applied. Expand filters to review or change them.</p>
        <details class="publication-search-options" open><summary>Refine search <span data-search-filter-count></span><i data-icon="chevron-down" aria-hidden="true"></i></summary><div class="publication-search-options-body">
        <p class="filter-help">Choose a year, topic, or evidence status. Open More filters for a precise bibliographic search.</p>
        <label class="field">
          <span>Publication year</span>
          <select name="year">
            <option value="">All years</option>
            <?php foreach ($years as $year): ?>
              <option value="<?= h((string)$year['year']) ?>" <?= (string)$filters['year'] === (string)$year['year'] ? 'selected' : '' ?>><?= h((string)$year['year']) ?> (<?= h((string)$year['count']) ?>)</option>
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
        <?= AlertFilters::picker('topic', 'Topics', array_column($topics, 'name'), $filters['topic'], !$fullForms) ?>
        <details class="specialist-filters" <?= ($filters['journal'] || $selectedSources || $filters['study_type'] || $from || $to) ? 'open' : '' ?>><summary>More filters · journal, source, study type, dates</summary><div class="specialist-filter-grid">
        <?= AlertFilters::picker('journal', 'Journals', array_column($journals, 'journal'), $filters['journal'], !$fullForms) ?>
        <?= AlertFilters::picker('sources', 'Source databases', array_column($sources, 'source_name'), $selectedSources, !$fullForms) ?>
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

        </div></details>
      </div></details>
      </div>
        <div class="advanced-filter-actions">
          <a class="secondary advanced-filter-reset" href="./#papers" title="Clear search and all filters" data-reset-advanced>Reset all</a>
          <button class="secondary" type="button" data-close-advanced>Cancel</button>
          <button class="primary iconed" type="submit"><i data-icon="search" aria-hidden="true"></i><span>Show results</span></button>
        </div>
      </div>
    </dialog>
    <?= hidden_query_inputs(['q', 'search', 'year', 'journal', 'sources', 'publication_statuses', 'topic', 'study_type', 'from', 'to', 'range', 'page', 'sort', 'per_page', 'form_options']) ?>
    <input type="hidden" name="page" value="1">
    <input type="hidden" name="sort" value="<?= h($filters['sort']) ?>">
    <input type="hidden" name="per_page" value="<?= h((string)$filters['per_page']) ?>">
  </form>

  <!-- offline:results:start -->
  <section class="results<?= $hasSearchQuery ? ' has-search' : '' ?>" id="papers" data-notification-batch="<?= h((string)($filters['notification'] ?? '')) ?>" tabindex="-1" data-result-total="<?= (int)$result['total'] ?>" data-search-query="<?= h(http_build_query($_GET)) ?>" data-loading-title="<?= h($preloaderLabel) ?>" data-loading-detail="<?= h($preloaderDetail) ?>">
    <?php if ($message): ?><div class="notice success" role="status"><?= h($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="notice error" role="alert"><?= h($error) ?></div><?php endif; ?>
    <!-- offline:context:start -->
    <?php if ($hasSearchQuery): ?>
    <div class="search-context panel is-executed" id="search-context" tabindex="-1" aria-live="polite">
      <i data-icon="search" aria-hidden="true"></i>
      <div>
        <span>Current search and filters</span>
        <strong><?= $currentSearchQuery !== '' ? h('“' . $currentSearchQuery . '”') : 'Filtered publications' ?></strong>
        <nav class="active-search-filters" aria-label="Active search and filters">
          <?php foreach ($activeFilterChips as $chip): ?><a class="active-filter-chip" data-filter-key="<?= h($chip['key']) ?>" href="<?= h($chip['url']) ?>" aria-label="<?= h('Remove ' . $chip['label']) ?>"><span><?= h($chip['label']) ?></span><i data-icon="x" aria-hidden="true"></i></a><?php endforeach; ?>
        </nav>
        <em><?= h(number_format($result['total'])) ?> matching records are displayed. Exact title and identifier matches appear first; other matches follow your selected date order.</em>
      </div>
      <?php if ($hasNonQueryFilters): ?><a class="filter-reset" href="<?= h($resetFiltersUrl) ?>">Reset filters</a><?php else: ?><a class="search-context-reset" href="./#papers">Clear search</a><?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- offline:context:end -->
    <div class="paper-panel panel" id="publication-results" tabindex="-1">
      <?php if ($hasSearchQuery): ?>
        <div class="result-title-block publication-results-heading">
          <span>Filtered publication index</span>
          <h2 id="paper-results-title">Search results</h2>
          <p><?= h(number_format($result['total'])) ?> matching records. Adjust the search and filters above.</p>
          <button class="secondary iconed search-alert-trigger" type="button" data-open-alerts data-alert-from-search><i data-icon="bell-plus" aria-hidden="true"></i><span>Create alert for this search</span></button>
        </div>
      <?php else: ?>
        <h2 class="sr-only" id="paper-results-title">Publications and trials</h2>
      <?php endif; ?>
      <div class="result-bar-sentinel" data-result-bar-sentinel aria-hidden="true"></div>
      <div class="result-bar has-desktop-result-controls" data-smart-result-bar data-active-filter-count="<?= count(array_filter($activeFilterChips, static fn(array $chip): bool => $chip['key'] !== 'q')) ?>">
        <a class="desktop-result-search secondary iconed" href="?form_options=full#advanced-filters" data-open-advanced data-full-search aria-haspopup="dialog" aria-controls="advanced-filters"><i data-icon="search" aria-hidden="true"></i><span>Search</span></a>
        <button class="secondary iconed result-filter-trigger" type="button" data-open-advanced aria-label="Open publication filters" aria-haspopup="dialog" aria-controls="advanced-filters"><i data-icon="list-filter" aria-hidden="true"></i><span>Filters</span><?php $resultFilterCount = count(array_filter($activeFilterChips, static fn(array $chip): bool => $chip['key'] !== 'q')); if ($resultFilterCount): ?><b class="desktop-filter-count"><?= $resultFilterCount ?></b><?php endif; ?></button>
        <form class="result-quick-controls" method="get" action="<?= $hasSearchQuery ? './#search-context' : './#papers' ?>" aria-label="Sort and result count">
          <?= hidden_query_inputs(['sort', 'per_page', 'page']) ?>
          <input type="hidden" name="page" value="1">
          <label>
            <span>Sort</span>
            <select name="sort" aria-label="Sort matching results">
              <option value="newly_added" <?= $filters['sort'] === 'newly_added' ? 'selected' : '' ?>>Recently added</option>
              <option value="newest" <?= $filters['sort'] === 'newest' ? 'selected' : '' ?>>Newest first</option>
              <option value="oldest" <?= $filters['sort'] === 'oldest' ? 'selected' : '' ?>>Oldest first</option>
            </select>
          </label>
          <label>
            <span>Entries</span>
            <select name="per_page" aria-label="Entries per page" <?= $result['total'] === 0 ? 'disabled' : '' ?>>
              <?php foreach ($entryOptions as $option): ?>
                <option value="<?= h((string)$option) ?>" <?= $selectedEntryOption === (string)$option ? 'selected' : '' ?>><?= h((string)$option) ?> entries</option>
              <?php endforeach; ?>
              <option value="all" <?= $selectedEntryOption === 'all' ? 'selected' : '' ?>><?= $result['total'] === 0 ? 'No results' : 'All ' . h(number_format((int)$result['total'])) . ' entries' ?></option>
            </select>
          </label>
          <span class="sr-only">Changes apply immediately.</span>
          <noscript><button class="secondary" type="submit">Apply view</button></noscript>
        </form>
        <div class="result-actions">
          <details class="command-menu result-settings-menu">
            <summary aria-label="Open research tools" aria-haspopup="dialog" aria-controls="publication-export-menu"><i data-icon="tools" aria-hidden="true"></i><span>Tools</span></summary>
            <div class="command-menu-panel result-settings-panel results-toolbar" id="publication-export-menu" role="dialog" aria-modal="false" aria-labelledby="publication-export-menu-title">
              <div class="export-menu-header">
                <strong id="publication-export-menu-title">Research tools</strong>
                <button class="export-menu-close" type="button" data-close-export-menu aria-label="Close research tools"><i data-icon="x" aria-hidden="true"></i></button>
              </div>
              <div class="publication-exports compact-export-menu" aria-label="Export data from current publication list">
                <button class="collection-export-trigger" type="button" data-export-selected disabled><i data-icon="download" aria-hidden="true"></i><span>Export selected</span></button>
                <a href="export.php?<?= h(http_build_query(array_merge(canonical_query_params($_GET), ['format' => 'bibtex']))) ?>" target="_blank" rel="noopener"><i data-icon="braces" aria-hidden="true"></i><span>BibTeX</span></a>
                <a href="export.php?<?= h(http_build_query(array_merge(canonical_query_params($_GET), ['format' => 'latex']))) ?>" target="_blank" rel="noopener"><i data-icon="file-type" aria-hidden="true"></i><span>LaTeX</span></a>
                <a href="export.php?<?= h(http_build_query(array_merge(canonical_query_params($_GET), ['format' => 'ris']))) ?>" target="_blank" rel="noopener"><i data-icon="copy" aria-hidden="true"></i><span>RIS</span></a>
                <a href="export.php?<?= h(http_build_query(array_merge(canonical_query_params($_GET), ['format' => 'csv']))) ?>" target="_blank" rel="noopener"><i data-icon="table" aria-hidden="true"></i><span>CSV</span></a>
                <a href="export.php?<?= h(http_build_query(array_merge(canonical_query_params($_GET), ['format' => 'json']))) ?>" target="_blank" rel="noopener"><i data-icon="braces" aria-hidden="true"></i><span>JSON</span></a>
                <a href="database.php" target="_blank" rel="noopener"><i data-icon="database" aria-hidden="true"></i><span>SQLite database</span></a>
                <a href="research-dataset.php#download-for-r"><i data-icon="r-script" aria-hidden="true"></i><span>Download for R / RStudio (.rds)</span></a>
                <a href="research-dataset.php#download-parquet"><i data-icon="table" aria-hidden="true"></i><span>Parquet / Feather · full snapshot</span></a>
                <a href="citation-network.php?<?= h(http_build_query(array_merge(array_intersect_key(canonical_query_params($_GET), array_flip(['search','q','author','topic','journal','year','range','from','to','sources','publication_statuses','substances'])), ['limit' => 12]))) ?>"><i data-icon="network" aria-hidden="true"></i><span>Explore matching papers in network</span></a>
                <a href="tools/psilocybin_bibliometrics_visnetwork.R" download><i data-icon="r-script" aria-hidden="true"></i><span>R bibliometrics script</span></a>
                <a href="api.php?<?= h(http_build_query(array_merge(canonical_query_params($_GET), ['resource' => 'papers', 'per_page' => 'all', 'page' => 1]))) ?>" target="_blank" rel="noopener"><i data-icon="braces" aria-hidden="true"></i><span>Open API</span></a>
              </div>
            </div>
          </details>
          <div class="pager">
            <a class="icon-btn <?= $result['page'] <= 1 ? 'disabled' : '' ?>" href="<?= h(query_with(['page' => max(1, $result['page'] - 1)])) ?>" aria-label="Previous page"><i data-icon="chevron-left" aria-hidden="true"></i></a>
            <span class="pager-position"><span class="pager-prefix">Page </span><?= h((string)$result['page']) ?><span class="pager-word"> of </span><span class="pager-compact" aria-hidden="true">/</span><?= h((string)$result['pages']) ?></span>
            <a class="icon-btn <?= $result['page'] >= $result['pages'] ? 'disabled' : '' ?>" href="<?= h(query_with(['page' => min($result['pages'], $result['page'] + 1)])) ?>" aria-label="Next page"><i data-icon="chevron-right" aria-hidden="true"></i></a>
          </div>
        </div>
      </div>

      <!-- offline:body:start -->
      <div class="matching-results-body" id="matching-results-body" tabindex="-1">
      <?php if (!$stats['total']): ?>
        <div class="state">
          <strong>No publications imported yet.</strong>
          <p>Run <code>php bin/update.php --backfill</code> or use the protected admin refresh.</p>
        </div>
      <?php elseif (!$result['rows']): ?>
        <div class="state">
          <strong>No publications match <?= $hasNonQueryFilters ? 'this search and filter combination' : ($currentSearchQuery !== '' ? h('“' . $currentSearchQuery . '”') : 'this combination of filters') ?>.</strong>
          <?php if ($hasNonQueryFilters): ?><p>Your filters apply together. Remove an individual chip above or reset the filters<?= $currentSearchQuery !== '' ? ' while keeping your search words' : '' ?>.</p><?php endif; ?>
          <?php if ($currentSearchQuery !== ''): ?><p>For a specific paper, try its DOI or PMID, or fewer distinctive title words. A missing result does not establish that no research exists; coverage is not exhaustive.</p><?php else: ?><p>Try a broader date range or fewer source, topic and publication-status restrictions.</p><?php endif; ?>
          <div class="state-actions">
            <?php if ($hasNonQueryFilters): ?><a class="primary search-recovery-link" href="<?= h($resetFiltersUrl) ?>"><?= $currentSearchQuery !== '' ? 'Keep search, reset filters' : 'Reset filters' ?></a><?php endif; ?>
            <?php foreach (array_slice(array_values(array_filter($activeFilterChips,static fn(array $chip):bool=>$chip['key']!=='q')),0,3) as $chip): ?><a class="secondary search-recovery-link" href="<?= h($chip['url']) ?>"><?= h('Remove ' . $chip['label']) ?></a><?php endforeach; ?>
            <button class="secondary iconed" type="button" data-open-advanced><i data-icon="settings" aria-hidden="true"></i><span>Review filters</span></button>
            <a class="search-context-reset" href="./#papers">Clear search and filters</a>
          </div>
        </div>
      <?php else: ?>
        <div class="paper-list">
          <?php foreach ($result['rows'] as $paper): ?>
            <article class="paper task-paper">
              <div class="paper-main">
                <h3><a href="publication.php?id=<?= (int)$paper['id'] ?>"><?= highlight_search_terms((string)$paper['title'], $searchHighlightQuery) ?></a></h3>
              </div>
              <div class="paper-meta">
                <strong><?php if ($paper['journal']): ?><a class="tag-link plain-link" href="<?= h(tracker_query_url(['journal' => (string)$paper['journal']])) ?>"><?= highlight_search_terms((string)$paper['journal'], $searchHighlightQuery) ?></a><?php else: ?><?= h(PublicationMetadata::venue($paper)['label']) ?><?php endif; ?></strong>
                <span><?= h($paper['publication_date'] ?: 'Unknown date') ?></span>
                <span class="author-links">
                  <?php $authorLinks = []; foreach (split_tag_values((string)$paper['authors'], 6) as $authorName) { $authorLinks[] = '<a href="authors.php?author=' . h(urlencode($authorName)) . '">' . highlight_search_terms($authorName, $searchHighlightQuery) . '</a>'; } echo $authorLinks ? implode(', ', $authorLinks) : 'Authors unavailable'; ?>
                </span>
                <?php if ($paper['doi']): ?><a class="paper-doi" href="https://doi.org/<?= h($paper['doi']) ?>" target="_blank" rel="noopener"><i data-icon="link" aria-hidden="true"></i><span>DOI <?= h($paper['doi']) ?></span></a><?php endif; ?>
              </div>
              <div class="tags paper-evidence">
                <?= publication_recency_badge($paper['publication_date'] ?? null) ?>
                <?= chip_link(publication_status_label($paper['publication_status'] ?? null), ['publication_statuses' => [PublicationRepository::normalizePublicationStatus($paper['publication_status'] ?? null)]], 'status-badge ' . publication_status_class($paper['publication_status'] ?? null)) ?>
              </div>
              <?php if ($currentSearchQuery !== ''): ?><p class="match-explanation"><?= h(search_match_explanation($paper, $currentSearchQuery)) ?></p><?php endif; ?>
              <details class="paper-classifications"><summary>Derived topics and study type</summary><p>Search aids derived from indexed metadata; verify the study design in the source.</p><div class="tags">
                <?php foreach (split_tag_values((string)($paper['topic_tags'] ?? ''), 3) as $tag): ?><?= chip_link($tag, ['topic' => $tag], 'soft') ?><?php endforeach; ?>
                <?php if (!empty($paper['study_type'])): ?><?= chip_link((string)$paper['study_type'], ['study_type' => (string)$paper['study_type']], 'soft') ?><?php endif; ?>
                <a href="publication.php?id=<?= (int)$paper['id'] ?>">All topics and provenance</a>
              </div></details>
              <div class="links paper-actions">
                  <button class="iconed" type="button" data-save-paper="<?= (int)$paper['id'] ?>"><i data-icon="book-marked" aria-hidden="true"></i><span>Save to reading list</span></button>
                  <button class="copy-citation iconed" type="button" data-citation="<?= h(ExportService::citationText($paper)) ?>"><i data-icon="copy" aria-hidden="true"></i><span>Copy citation</span></button>
                <details class="paper-citation-menu"><summary aria-label="More actions for <?= h($paper['title']) ?>"><i data-icon="more-horizontal" aria-hidden="true"></i><span>More</span></summary><div>
                  <a class="paper-detail-link" href="citation-network.php?paper=<?= (int)$paper['id'] ?>" aria-label="<?= h('Citation network: ' . $paper['title']) ?>"><i data-icon="network" aria-hidden="true"></i><span>View in citation network</span></a>
                  <button class="copy-bibtex" type="button" data-bibtex="<?= h(ExportService::bibtex([$paper])) ?>" aria-label="Copy BibTeX"><i data-icon="copy" aria-hidden="true"></i><span>Copy BibTeX</span></button>
                <a class="paper-detail-link" href="publication.php?id=<?= (int)$paper['id'] ?>" aria-label="<?= h('Publication details: ' . $paper['title']) ?>"><i data-icon="file-type" aria-hidden="true"></i><span>Publication details</span></a>
                <?php if ($paper['pubmed_id']): ?><a class="paper-detail-link" href="https://pubmed.ncbi.nlm.nih.gov/<?= h($paper['pubmed_id']) ?>/" target="_blank" rel="noopener" aria-label="PubMed <?= h($paper['pubmed_id']) ?>"><i data-icon="external-link" aria-hidden="true"></i><span>View on PubMed</span></a><?php endif; ?>
                <label class="collection-pick"><input type="checkbox" data-collection-paper value="<?= (int)$paper['id'] ?>"> <span>Select for export</span></label>
                </div></details>
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
      <!-- offline:body:end -->
    </div>
  </section>

  <!-- offline:results:end -->
  <section class="database-support panel" aria-label="Guides and database information">
      <details class="hero-resources"><summary>Research guides and tools <i data-icon="chevron-down" aria-hidden="true"></i></summary>
      <nav class="research-shortcuts" aria-label="Research starting points">
        <a href="psilocybin-research-guide/">Read and interpret research</a>
        <a href="psilocybin-depression-research/">Understand depression evidence</a>
        <a href="recruiting-psilocybin-clinical-trials/">Find recruiting trials</a>
        <a href="research-dataset.php">Download and cite the dataset</a>
        <a href="research-dataset.php#download-for-r">Download for R / RStudio (.rds)</a> · <a href="research-dataset.php#download-parquet">Parquet</a> · <a href="research-dataset.php#download-parquet">Feather</a>
        <a href="research-trends.php">Explore research trends</a>
      </nav>
      </details>


    <details class="hero-evidence evidence-disclosure" aria-label="Database details">
      <summary>
        <span class="hero-evidence-summary-head">
          <i data-icon="database" aria-hidden="true"></i>
          <span>Database details</span>
        </span>
      </summary>
      <div class="hero-evidence-grid">
        <div class="hero-evidence-summary-metrics" aria-label="Database metrics">
          <a class="database-summary-metric" href="<?= h(tracker_query_url(['substances' => ['psilocybin']])) ?>" aria-label="Show psilocybin records">
            <strong><?= h(number_format($stats['psilocybin'])) ?></strong>
            <span>Psilocybin records</span>
          </a>
          <a class="database-summary-metric" href="<?= h(tracker_query_url(['substances' => ['psilocin']])) ?>" aria-label="Show psilocin records">
            <strong><?= h(number_format($stats['psilocin'])) ?></strong>
            <span>Psilocin records</span>
          </a>
          <a class="database-summary-metric" href="#journal-data" aria-label="Show journal data">
            <strong><?= h(number_format($stats['journals'])) ?></strong>
            <span>Journal labels</span>
          </a>
          <a class="database-summary-metric" href="#topic-data" aria-label="Show research topic data">
            <strong><?= h(number_format(count($topics))) ?></strong>
            <span>Research topics</span>
          </a>
        </div>
        <a class="evidence-card" href="<?= h(tracker_query_url(['publication_statuses' => ['published']])) ?>" aria-label="Show published records">
          <span>Published</span>
          <strong><?= h(number_format($statusCounts['published'] ?? 0)) ?></strong>
          <em>Published / formal records</em>
        </a>
        <a class="evidence-card" href="<?= h(tracker_query_url(['publication_statuses' => ['preprint']])) ?>" aria-label="Show preprint records">
          <span>Preprints</span>
          <strong><?= h(number_format($statusCounts['preprint'] ?? 0)) ?></strong>
          <em>Marked as not peer reviewed</em>
        </a>
        <a class="evidence-card" href="<?= h(tracker_query_url(['publication_statuses' => ['clinical trial']])) ?>" aria-label="Show clinical trial registrations">
          <span>Clinical trial registrations</span>
          <strong><?= h(number_format($statusCounts['clinical trial'] ?? 0)) ?></strong>
          <em>ClinicalTrials.gov records</em>
        </a>
        <a class="evidence-card" href="<?= h(tracker_query_url(['publication_statuses' => ['protocol']])) ?>" aria-label="Show protocol records">
          <span>Protocols</span>
          <strong><?= h(number_format($statusCounts['protocol'] ?? 0)) ?></strong>
          <em>Study protocol records</em>
        </a>
        <p class="database-metrics-note">Substance categories overlap; their counts must not be summed. Journal labels exclude preprint and trial-registry venues and may include name variants. Research topics are derived screening categories. Publication status does not independently verify peer review.</p>
        <section class="source-ledger publication-growth-mini" aria-label="Psilocybin publication growth over time">
          <div class="evidence-accordion-body">
            <h3>Publication timeline</h3>
            <div class="publication-growth-head">
              <strong id="publication-growth-summary" aria-live="polite"><?= h(number_format(array_sum(array_column(array_filter($publicationGrowthYears, static fn(array $row): bool => $row['year'] >= 2020), 'count')))) ?> records since 2020</strong>
              <div class="publication-growth-range" role="group" aria-label="Publication timeline range" data-range="recent">
                <button type="button" data-growth-range="recent" aria-pressed="true" aria-controls="publication-growth-chart">Since 2020</button>
                <button type="button" data-growth-range="full" aria-pressed="false" aria-controls="publication-growth-chart">Since <?= h((string)$publicationGrowthFirstYear) ?></button>
              </div>
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
        </section>
        <a class="evidence-card" href="tools/psilocybin_bibliometrics_visnetwork.R" download aria-label="Download R bibliometrics and citation-network script">
          <span>R toolkit</span>
          <strong>visNetwork</strong>
          <em>Bibliometrics, live SQLite analysis, HTML graph, and PDF export</em>
        </a>
        <details class="source-ledger evidence-accordion data-ledger" id="journal-data" aria-label="Journal data">
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

        <details class="source-ledger evidence-accordion data-ledger" id="topic-data" aria-label="Research topic data">
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
      </div>
    </details>
  </section>

  <aside class="right-rail">
    <dialog class="alert-modal" id="alert-enrollment" aria-labelledby="alert-enrollment-title"<?= $fullForms ? ' open' : '' ?>>
      <form method="post" class="alert-form alert-sheet">
        <div class="advanced-filter-head">
          <div>
            <h2 id="alert-enrollment-title">Create email alert</h2>
            <p>Get a digest of newly indexed research in your inbox. Choose how often and what you want to follow.</p>
          </div>
          <button type="button" class="advanced-filter-close" data-close-alerts aria-label="Close email alert form"><i data-icon="x" aria-hidden="true"></i></button>
        </div>
        <input type="hidden" name="action" value="subscribe">
        <p class="alert-search-note" data-alert-search-note hidden></p>
        <label class="field"><span>Email address</span><input type="email" name="email" placeholder="Enter your email address" required></label>
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
            <?= AlertFilters::picker('alert_journal', 'Journals', array_column($journals, 'journal'), null, !$fullForms) ?>
            <?= AlertFilters::picker('alert_topic', 'Topics', array_column($topics, 'name'), null, !$fullForms) ?>
            <label class="field"><span>Cites DOI</span><input type="text" name="alert_cited_doi" placeholder="10.1038/..." inputmode="url"></label>
            <p class="field-note">Citation alerts match publications whose available reference metadata contains this DOI.</p>
          </div>
        </details>
        <details class="alert-research-changes">
          <summary>Optional research-change notifications</summary>
          <fieldset><legend class="sr-only">Research-change notification types</legend><?php foreach(ResearchChanges::TYPES as $value=>$label): ?><label><input type="checkbox" name="event_types[]" value="<?= h($value) ?>" <?= isset($subscription)&&in_array($value,explode(',',(string)($subscription['event_types']??'')),true)?'checked':'' ?>> <?= h($label) ?></label><?php endforeach; ?><p class="field-note">Observed after signup: registry changes and newly linked result references. A registry reference may point to an older paper. Journal and citation filters apply to publications; changes use keywords, author/sponsor and topic filters.</p></fieldset>
        </details>
        <label class="consent-check"><input type="checkbox" name="privacy_consent" value="1" required> I agree that my email address and selected filters are stored to confirm and generate this alert. Email addresses and alert access tokens are encrypted at rest. Digests start only after I confirm by email, and every digest includes manage and unsubscribe links. Details are in the <a href="data-protection.php" target="_blank" rel="noopener">data protection notice</a>.</label>
        <div class="alert-review"><i data-icon="calendar-clock" aria-hidden="true"></i><div><strong>Alert summary</strong><span data-alert-review role="status" aria-live="polite"></span></div></div>
        <div class="advanced-filter-actions">
          <button class="secondary" type="button" data-close-alerts>Cancel</button>
          <button class="primary iconed" type="submit"><i data-icon="bell-plus" aria-hidden="true"></i><span>Create email alert</span></button>
        </div>
        <p class="privacy-note compact">No tracking pixel is used. Ignore the confirmation email if you did not request the alert. Unsubscribing disables the alert immediately. The app does not send your searches to external frontend analytics services.</p>
      </form>
    </dialog>

    <!-- Notification preferences are requested only when opened. -->

    <dialog class="analytics-modal" id="analytics-modal" aria-labelledby="analytics-modal-title">
      <div class="analytics-sheet">
        <div class="advanced-filter-head">
          <div>
            <h2 id="analytics-modal-title">Analytics</h2>
            <p>Explore publication timelines, source trends, journals, topics, and authors for the current database index.</p>
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
        <?php if ($isSessionAdmin): ?><input type="hidden" name="account_csrf" value="<?= h(AccountAuth::csrf()) ?>"><?php else: ?><label class="field"><span>Admin token</span><input type="password" name="admin_token" autocomplete="off"></label><?php endif; ?>
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
          <?php if ($isSessionAdmin): ?><input type="hidden" name="account_csrf" value="<?= h(AccountAuth::csrf()) ?>"><?php else: ?><label class="field"><span>Admin token</span><input type="password" name="admin_token" autocomplete="off"></label><?php endif; ?>
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
        <?php if ($isSessionAdmin): ?><input type="hidden" name="account_csrf" value="<?= h(AccountAuth::csrf()) ?>"><?php else: ?><label class="field"><span>Admin token</span><input type="password" name="admin_token" autocomplete="off"></label><?php endif; ?>
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

<?= tracker_footer() ?>
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
      <div><strong><?= h(number_format($statusCounts['clinical trial'] ?? 0)) ?></strong><span>Clinical trial registrations</span></div>
      <div><strong><?= h(number_format($statusCounts['protocol'] ?? 0)) ?></strong><span>Protocols</span></div>
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
<div class="page-tools" aria-label="Page tools">
</div>
<button class="scroll-top" id="scroll-top" type="button" aria-label="Scroll to top" title="Scroll to top">
  <i data-icon="arrow-up" aria-hidden="true"></i><span>Top</span>
</button>
<script type="application/json" id="analytics-data"><?= json_encode($analytics, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<?php if (!$fullForms): ?><script src="<?= h(FrontendAssets::url('assets/app.min.js')) ?>" defer></script><?php endif; ?>
<script src="<?= h(FrontendAssets::url('assets/research-explorer.min.js')) ?>" defer></script>
<?= AlertFilters::optionCatalogScript() ?>
</body>
</html>
