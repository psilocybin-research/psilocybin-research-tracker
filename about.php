<?php
declare(strict_types=1);

require_once __DIR__ . '/src/helpers.php';

$assetVersion = '20260713-push-footer-v89';
$baseUrl = Config::publicBaseUrl();
$canonicalUrl = $baseUrl . 'about.php';
$shareImageUrl = $baseUrl . 'assets/pwa/icon-512.png?v=20260713-push-footer-v89';
$description = 'About the Psilocybin Research Publication Tracker: searchable literature database, analytics, exports, alerts, privacy, encryption, and source coverage.';
$aboutStats = [
    'engine' => 'SQLite',
    'records' => null,
    'sources' => null,
    'storage_status' => 'unknown',
    'backup_status' => 'unknown',
    'alert_security_fields' => 0,
    'push_security_fields' => 0,
];

try {
    $db = new Database();
    $db->initialize();
    $repo = new PublicationRepository($db);
    $stats = $repo->stats();
    $sources = $repo->sources();
    $health = (new HealthService($db, $repo, new FetchRunRepository($db)))->report();
    $aboutStats['engine'] = str_starts_with(Config::databaseDsn(), 'sqlite:') ? 'SQLite + WAL' : 'PDO database';
    $aboutStats['records'] = (int)($stats['total'] ?? 0);
    $aboutStats['sources'] = count($sources);
    $aboutStats['storage_status'] = (string)($health['checks']['storage_security']['status'] ?? 'unknown');
    $aboutStats['backup_status'] = (string)($health['checks']['backup']['status'] ?? 'unknown');

    $countSecurityColumns = static function (PDO $pdo, string $table): int {
        try {
            $columns = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();
        } catch (Throwable) {
            return 0;
        }
        return count(array_filter($columns, static function (array $column): bool {
            $name = (string)($column['name'] ?? '');
            return str_ends_with($name, '_cipher') || str_ends_with($name, '_blind_index');
        }));
    };
    $aboutStats['alert_security_fields'] = $countSecurityColumns($db->pdo(), 'alert_subscriptions');
    $aboutStats['push_security_fields'] = $countSecurityColumns($db->pdo(), 'push_subscriptions');
} catch (Throwable $e) {
    OperationalLogger::exception('about.stats_failed', $e);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>About the Publication Tracker | Psilocybin Research</title>
  <meta name="description" content="<?= h($description) ?>">
  <link rel="canonical" href="<?= h($canonicalUrl) ?>">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="Psilocybin Research">
  <meta property="og:title" content="About the Psilocybin Research Publication Tracker">
  <meta property="og:description" content="<?= h($description) ?>">
  <meta property="og:url" content="<?= h($canonicalUrl) ?>">
  <meta property="og:image" content="<?= h($shareImageUrl) ?>">
  <meta property="og:image:secure_url" content="<?= h($shareImageUrl) ?>">
  <meta property="og:image:type" content="image/png">
  <meta property="og:image:width" content="512">
  <meta property="og:image:height" content="512">
  <meta property="og:image:alt" content="Psilocybin Research Publication Tracker logo">
  <meta name="twitter:card" content="summary">
  <meta name="twitter:title" content="About the Psilocybin Research Publication Tracker">
  <meta name="twitter:description" content="<?= h($description) ?>">
  <meta name="twitter:image" content="<?= h($shareImageUrl) ?>">
  <meta name="twitter:image:alt" content="Psilocybin Research Publication Tracker logo">
  <link rel="icon" href="assets/logo.png?v=20260713-push-footer-v89">
  <link rel="apple-touch-icon" href="assets/pwa/apple-touch-icon.png?v=20260713-push-footer-v89">
  <link rel="manifest" href="manifest.webmanifest?v=20260713-push-footer-v89">
  <link rel="stylesheet" href="assets/styles.min.css?v=<?= h($assetVersion) ?>">
  <script>
    document.documentElement.classList.add('js');
    try {
      const compactNavigation = window.matchMedia('(max-width: 1180px)').matches;
      const navigationPreferenceKey = compactNavigation ? 'publicationTrackerNavSidebarCollapsedMobile' : 'publicationTrackerNavSidebarCollapsedDesktop';
      const navigationPreference = window.localStorage.getItem(navigationPreferenceKey);
      if ((navigationPreference === null && compactNavigation) || navigationPreference === '1') {
        document.documentElement.classList.add('nav-sidebar-collapsed');
      }
    } catch (error) {}
  </script>
  <script type="application/ld+json">
<?= json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'AboutPage',
    'name' => 'About the Psilocybin Research Publication Tracker',
    'url' => $canonicalUrl,
    'description' => $description,
    'isPartOf' => [
        '@type' => 'WebApplication',
        'name' => 'Psilocybin Research Publication Tracker',
        'url' => $baseUrl,
        'applicationCategory' => 'ResearchApplication',
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n" ?>
  </script>
</head>
<body class="about-page">
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
      <a href="/"><i data-icon="book-marked" aria-hidden="true"></i><span>Publications</span></a>
      <a href="evidence.php"><i data-icon="grid-3x3" aria-hidden="true"></i><span>Evidence</span></a>
      <a href="trials.php"><i data-icon="clipboard-list" aria-hidden="true"></i><span>Trials</span></a>
      <a href="authors.php"><i data-icon="users" aria-hidden="true"></i><span>Authors</span></a>
      <a href="citation-network.php"><i data-icon="network" aria-hidden="true"></i><span>Citation Network</span></a>
      <a href="/#analytics"><i data-icon="network" aria-hidden="true"></i><span>Analytics</span></a>
      <a href="tools/psilocybin_bibliometrics_visnetwork.R" download><i data-icon="r-script" aria-hidden="true"></i><span>R script</span></a>
      <a href="/#alerts"><i data-icon="bell-plus" aria-hidden="true"></i><span>Alerts</span></a>
      <a href="export.php?format=json" target="_blank" rel="noopener"><i data-icon="download" aria-hidden="true"></i><span>Export data</span></a>
      <a href="api.php" target="_blank" rel="noopener"><i data-icon="braces" aria-hidden="true"></i><span>API</span></a>
      <a href="https://github.com/psilocybin-research/psilocybin-research-tracker" target="_blank" rel="noopener me"><i data-icon="github" aria-hidden="true"></i><span>GitHub</span></a>
      <a href="https://doi.org/10.5281/zenodo.21293526" target="_blank" rel="noopener" title="Fixed citable dataset snapshot on Zenodo"><i data-icon="zenodo" aria-hidden="true"></i><span>Zenodo DOI</span></a>
      <a href="about.php" aria-current="location"><i data-icon="circle-alert" aria-hidden="true"></i><span>About</span></a>
      <a href="data-protection.php"><i data-icon="shield" aria-hidden="true"></i><span>Data protection</span></a>
    </nav>
  </div>
</header>

<main class="about-shell" id="main">
  <section class="about-hero" aria-labelledby="about-title">
    <p class="eyebrow">About this application</p>
    <h1 id="about-title">A research-grade index for psilocybin and psilocin literature.</h1>
    <p>
      The Psilocybin Research Publication Tracker is a focused research application for finding,
      filtering, exporting, monitoring, and citing publications related to psilocybin and psilocin.
      It combines source-aware metadata ingestion, deduplication, publication-status labeling,
      analytics, alerting, exports, API access, PWA behavior, and public-safe operational monitoring
      for researchers, clinicians, journalists, policy analysts, and advanced readers who need fast
      access to scientific records without losing context.
    </p>
  </section>

  <section class="about-grid" aria-label="Application overview">
    <article class="about-panel">
      <h2>What It Does</h2>
      <p>
        The tracker combines literature metadata from PubMed, Crossref, Europe PMC, OpenAlex,
        medRxiv, bioRxiv, PsyArXiv, and ClinicalTrials.gov. It keeps peer-reviewed publications,
        preprints, reviews, protocols, and clinical trial records visibly distinct while making
        the database searchable, exportable, embeddable, monitorable, and installable as a PWA.
        Records can be searched by keyword, author, journal, year, date range, source database,
        publication status, topic, study type, and substance.
      </p>
      <ul class="about-list">
        <li>Shows latest publications and matching results with DOI, PubMed, source, topic, and status links.</li>
        <li>Separates published literature records, preprints, reviews, protocols, and clinical trials.</li>
        <li>Marks preprints clearly as not peer reviewed.</li>
        <li>Provides analytics for topics, journals, authors, sources, publication trends, and date ranges.</li>
        <li>Exports filtered records as BibTeX, RIS, CSV, JSON, and the full SQLite database.</li>
        <li>Provides a downloadable <a href="tools/psilocybin_bibliometrics_visnetwork.R" download>R bibliometrics script</a> for live SQLite analysis, tables, PDF figures, and an interactive visNetwork citation map.</li>
        <li>Offers JSON API access, embeddable widgets, PWA install support, and offline fallback behavior.</li>
      </ul>
    </article>

    <article class="about-panel">
      <h2>Alerts And Monitoring</h2>
      <p>
        Users can subscribe to publication alerts for broad psilocybin or psilocin coverage, or target
        updates by keyword, author, journal, topic, substance, and cited DOI. Alerts use double opt-in:
        no digest is sent until the confirmation link is opened.
      </p>
      <ul class="about-list">
        <li>Alert preferences can be changed, paused, resumed, unsubscribed, or deleted from the manage page.</li>
        <li>Daily update jobs import new records and can send email and Web Push notifications.</li>
        <li>Duplicate prevention records reduce repeated alert delivery for the same publication.</li>
      </ul>
    </article>

    <article class="about-panel">
      <h2>Privacy</h2>
      <p>
        The public website is designed to be self-contained. It serves local JavaScript, CSS, fonts,
        images, manifest, and service-worker assets, with no CDN JavaScript, CSS, or web fonts required
        for the app interface.
      </p>
      <ul class="about-list">
        <li>Public API, export, and widget responses expose publication metadata, not private subscriber data.</li>
        <li>Email alert templates contain no tracking pixel.</li>
        <li>Alert data is used for requested publication updates and preference management.</li>
        <li>Operational health output is public-safe and must not expose tokens, credentials, traces, or private runtime data.</li>
        <li>A dedicated <a href="data-protection.php">data protection notice</a> documents user data, publication data sources, update jobs, third-party requests, and deletion options in detail.</li>
      </ul>
    </article>

    <article class="about-panel">
      <h2>Encryption</h2>
      <p>
        Sensitive alert and push-subscription fields are encrypted at rest in the application database.
        The app stores encrypted values plus blind indexes so it can find a subscription without keeping
        plain email addresses, access tokens, confirmation tokens, or push endpoints in lookup columns.
      </p>
      <ul class="about-list">
        <li>Alert email addresses, alert access tokens, and confirmation tokens are stored encrypted at rest.</li>
        <li>Push endpoints, browser push keys, auth secrets, and user-agent strings are stored encrypted at rest where present.</li>
        <li>Web Push payloads are encrypted for delivery using the browser subscription keys and VAPID signing.</li>
        <li>HTTPS protects normal browser traffic in transit on the live site.</li>
      </ul>
    </article>

    <article class="about-panel about-panel-wide">
      <h2>Non-Sensitive Security Stats</h2>
      <div class="about-stat-grid" aria-label="Non-sensitive database and encryption status">
        <div><strong><?= h($aboutStats['records'] === null ? 'Available' : number_format((int)$aboutStats['records'])) ?></strong><span>Indexed records</span></div>
        <div><strong><?= h($aboutStats['sources'] === null ? 'Available' : number_format((int)$aboutStats['sources'])) ?></strong><span>Source databases</span></div>
        <div><strong><?= h((string)$aboutStats['engine']) ?></strong><span>Database engine</span></div>
      </div>
    </article>

    <article class="about-panel about-panel-wide">
      <h2>PHP Architecture</h2>
      <ul class="about-list about-architecture-list">
        <li><strong>Plain PHP front controller:</strong> `index.php` renders the search-first app shell, filters, latest publications, analytics entry points, alert enrollment, admin-only curation, and SEO metadata.</li>
        <li><strong>SQLite repository layer:</strong> `src/Database.php` handles schema bootstrap/migrations, while `src/PublicationRepository.php` owns search, dedupe, analytics, classification, and curation queries.</li>
        <li><strong>Importer orchestration:</strong> `src/PublicationService.php` coordinates PubMed, Crossref, Europe PMC, OpenAlex, preprint-server, PsyArXiv, and ClinicalTrials.gov fetchers under `src/Fetchers/`.</li>
        <li><strong>AJAX search flow:</strong> search, pagination, and filter changes use asynchronous JavaScript requests, `fetch()`, `DOMParser`, and section replacement so result panels update without a full-page reload when JavaScript is available.</li>
        <li><strong>Progressive enhancement:</strong> all core search and export forms still work as normal PHP GET/POST routes without JavaScript; JavaScript adds smoother interaction, copy-to-clipboard, modals, timeline inspection, install prompts, and Web Push enrollment.</li>
        <li><strong>Defensive request handling:</strong> `RequestFilters::fromGlobals()` normalizes incoming filters, output is escaped through view helpers, admin operations are POST-only, and public refresh is bounded by lock/cooldown controls.</li>
        <li><strong>Dependency-light frontend:</strong> local SVG icons, native dialog sheets, native SVG charts, CSS media queries, and small focused JavaScript initializers replace heavyweight client frameworks for the public app surface.</li>
        <li><strong>Public endpoints:</strong> `api.php`, `export.php`, `database.php`, `widget.php`, `widget.js.php`, `status.php`, and `health.php` expose rights-sanitized structured data and operational status without exposing private runtime secrets or unverified source text.</li>
        <li><strong>Notification services:</strong> `src/AlertService.php` and `src/PushService.php` handle double opt-in email alerts, preference management, Web Push subscriptions, encrypted payload delivery, and stale-subscription cleanup.</li>
        <li><strong>Runtime hardening:</strong> runtime data lives under `data/`, web access is denied by Apache rules, sensitive values are encrypted at rest, logs are JSONL with redaction, and SQLite backups are created through `bin/backup-sqlite.php`.</li>
      </ul>
    </article>

    <article class="about-panel about-panel-wide">
      <h2>Source Context And Automated Updates</h2>
      <p>
        Every publication row keeps source and status context so records do not collapse into an
        undifferentiated feed. The app stores a `source_name`, normalized publication status, DOI,
        PubMed ID where available, source URL, timestamps, topics, substances, and study-type
        classifications so users can trace records back to source systems. Source-derived text and
        unrestricted importer payloads may be retained privately for processing, but public routes
        expose only the rights-sanitized metadata core, abstract-availability indicators, and
        allowlisted factual provenance.
      </p>
      <ul class="about-list about-architecture-list">
        <li><strong>Source context:</strong> PubMed, Crossref, Europe PMC, OpenAlex, medRxiv, bioRxiv, PsyArXiv, and ClinicalTrials.gov records remain source-labeled in UI, API, exports, widgets, and analytics.</li>
        <li><strong>Status context:</strong> published literature records, preprints, reviews, protocols, and clinical trials keep separate status labels; preprints remain visibly marked as not peer reviewed.</li>
        <li><strong>Deduplication logic:</strong> imported records are matched by DOI, PubMed ID, and normalized title to reduce duplicate publications while preserving source metadata.</li>
        <li><strong>Daily cron update:</strong> production runs `php bin/update.php --daily` from cron at 03:20 server time, which corresponds to 01:20 UTC during Central European Summer Time.</li>
        <li><strong>Operational traceability:</strong> fetch runs, fetch errors, heartbeat files, JSONL logs, update freshness, backup freshness, and public-safe health checks make update state auditable without exposing secrets.</li>
        <li><strong>Manual and targeted updates:</strong> admin-only commands support backfills, date-window refreshes, source-specific imports, reclassification, and targeted PubMed ID imports when curated repair is needed.</li>
      </ul>
    </article>

    <article class="about-panel about-panel-wide">
      <h2>Data Compression And Speed</h2>
      <p>
        The app is built as a small, server-rendered PHP application with local assets and a compact
        SQLite data layer. The goal is to keep the first screen useful quickly while avoiding large
        frontend bundles, external CDNs, and unnecessary network round trips.
      </p>
      <ul class="about-list about-architecture-list">
        <li><strong>Compressed delivery:</strong> Apache is configured to use Deflate and Brotli modules when available, so HTML, CSS, JavaScript, JSON, manifest, and SVG responses can be transferred compressed.</li>
        <li><strong>Minified static assets:</strong> readable source files live in `assets/styles.css` and `assets/app.js`; production loads generated `assets/styles.min.css` and `assets/app.min.js` with versioned URLs.</li>
        <li><strong>Long-lived immutable caching:</strong> static images, fonts, CSS, JavaScript, icons, and manifest assets receive one-year immutable cache headers, while PHP/HTML responses use no-store semantics.</li>
        <li><strong>Local assets only:</strong> fonts, icons, imagery, service worker, manifest, and PWA icons are served from this domain, which removes CDN lookup latency and third-party frontend dependencies.</li>
        <li><strong>SQLite read performance:</strong> the database uses targeted indexes, source/status/topic/date filters, FTS5 where available, and WAL mode for better read behavior during update jobs.</li>
        <li><strong>Asynchronous interface updates:</strong> AJAX result loading updates only the changed result/filter sections instead of repainting the whole application shell.</li>
        <li><strong>Small runtime responses:</strong> API, export, widget, and health endpoints return focused JSON/HTML payloads; export/download routes are explicit rather than loading large datasets into the first viewport.</li>
        <li><strong>PWA caching strategy:</strong> the service worker caches the static app shell and uses network-first runtime requests so visitors get fresh publication data without re-downloading stable interface assets.</li>
        <li><strong>Operational checks:</strong> `health.php` monitors database reachability, update freshness, backups, storage permissions, logs, and heartbeat files so performance and reliability problems are visible early.</li>
      </ul>
    </article>

    <article class="about-panel about-panel-wide">
      <h2>Scientific Context And Limits</h2>
      <p>
        The database is a discovery and monitoring tool, not a clinical guideline and not a substitute
        for source verification. Bibliographic coverage is not exhaustive, source metadata can contain
        errors, and deterministic topic classification is only a navigation aid. Users should verify
        records at the publisher, registry, PubMed, DOI, or source database before citation, reporting,
        clinical interpretation, or policy use.
      </p>
    </article>
  </section>
</main>

<footer class="footer about-footer">
  <span>Application created by Dr. Christopher B. Germann · <a href="/">Open tracker</a> · <a href="api.php" target="_blank" rel="noopener">API</a> · <a href="data-protection.php">Data protection</a>.</span>
  <?= funding_acknowledgement() ?>
</footer>

<?= detail_scroll_top() ?>
<script src="assets/app.min.js?v=<?= h($assetVersion) ?>" defer></script>
</body>
</html>
