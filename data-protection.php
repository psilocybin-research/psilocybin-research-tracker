<?php
declare(strict_types=1);

require_once __DIR__ . '/src/helpers.php';

$assetVersion = '20260712-funding-footer-v88';
$baseUrl = Config::publicBaseUrl();
$canonicalUrl = $baseUrl . 'data-protection.php';
$shareImageUrl = $baseUrl . 'assets/pwa/icon-512.png?v=20260712-funding-footer-v88';
$description = 'Data protection notice for the Psilocybin Research Publication Tracker, including user data, source context, alerts, push notifications, logs, and automated updates.';
$contactEmail = 'christopher-germann@uni-wh.de';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Data Protection Notice | Psilocybin Research</title>
  <meta name="description" content="<?= h($description) ?>">
  <link rel="canonical" href="<?= h($canonicalUrl) ?>">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="Psilocybin Research">
  <meta property="og:title" content="Data Protection Notice">
  <meta property="og:description" content="<?= h($description) ?>">
  <meta property="og:url" content="<?= h($canonicalUrl) ?>">
  <meta property="og:image" content="<?= h($shareImageUrl) ?>">
  <meta property="og:image:secure_url" content="<?= h($shareImageUrl) ?>">
  <meta property="og:image:type" content="image/png">
  <meta property="og:image:width" content="512">
  <meta property="og:image:height" content="512">
  <meta property="og:image:alt" content="Psilocybin Research Publication Tracker logo">
  <meta name="twitter:card" content="summary">
  <meta name="twitter:title" content="Data Protection Notice">
  <meta name="twitter:description" content="<?= h($description) ?>">
  <meta name="twitter:image" content="<?= h($shareImageUrl) ?>">
  <meta name="twitter:image:alt" content="Psilocybin Research Publication Tracker logo">
  <link rel="icon" href="assets/logo.png?v=20260712-funding-footer-v88">
  <link rel="icon" type="image/png" sizes="192x192" href="assets/pwa/icon-192.png?v=20260712-funding-footer-v88">
  <link rel="icon" type="image/png" sizes="512x512" href="assets/pwa/icon-512.png?v=20260712-funding-footer-v88">
  <link rel="apple-touch-icon" href="assets/pwa/apple-touch-icon.png?v=20260712-funding-footer-v88">
  <link rel="manifest" href="manifest.webmanifest?v=20260712-funding-footer-v88">
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
    '@type' => 'PrivacyPolicy',
    'name' => 'Data Protection Notice',
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
<body class="about-page data-protection-page">
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
      <a href="about.php"><i data-icon="circle-alert" aria-hidden="true"></i><span>About</span></a>
      <a href="data-protection.php" aria-current="location"><i data-icon="shield" aria-hidden="true"></i><span>Data protection</span></a>
    </nav>
  </div>
</header>

<main class="about-shell" id="main">
  <section class="about-hero" aria-labelledby="data-protection-title">
    <p class="eyebrow">Data protection notice</p>
    <h1 id="data-protection-title">What data is processed, where it goes, and why.</h1>
    <p>
      This notice describes the data processing of the Psilocybin Research Publication Tracker.
      The app is designed as a self-contained PHP and SQLite research tool with local frontend
      assets, no third-party analytics scripts, no CDN JavaScript, no CDN CSS, and no tracking
      pixels in alert emails. Last updated: July 10, 2026.
    </p>
  </section>

  <section class="about-grid" aria-label="Data protection details">
    <article class="about-panel about-panel-wide">
      <h2>Controller And Contact</h2>
      <p>
        The publication tracker is operated for Psilocybin-Research.com by Dr. Christopher B.
        Germann. Data protection questions, access requests, correction requests, deletion
        requests, and objections can be sent through the contact details provided by the website
        operator. Alert emails use the configured sender address <a href="mailto:<?= h($contactEmail) ?>"><?= h($contactEmail) ?></a>.
      </p>
      <p>
        The website imprint/legal notice provides the authoritative provider-identification
        information. This page describes the data processing activities of the Psilocybin Research
        Tracker application.
      </p>
    </article>

    <article class="about-panel">
      <h2>Core App Use</h2>
      <p>
        When a visitor opens the tracker, the server receives normal HTTP request data required to
        deliver the page: IP address, request time, requested URL, HTTP method, response status,
        user agent, and referrer if the browser sends one. The application uses this data for
        secure delivery, abuse prevention, debugging, operational reliability, and server log
        analysis.
      </p>
      <ul class="about-list">
        <li>No third-party frontend analytics script is loaded by the app.</li>
        <li>No CDN JavaScript, CSS, or web font is required for the interface.</li>
        <li>Search and filter requests are processed on this server against the local SQLite database.</li>
        <li>AJAX requests update result panels asynchronously; they are sent to this domain, not to external analytics providers.</li>
      </ul>
    </article>

    <article class="about-panel">
      <h2>Search, API, Export, And Widget Data</h2>
      <p>
        Search terms, filters, pagination settings, sort settings, export format choices, API
        parameters, and widget parameters are transmitted to this server when users submit forms,
        open API/export URLs, or use asynchronous result loading. These values are used to return
        matching publication metadata and are not sent to PubMed, Crossref, Europe PMC, OpenAlex,
        preprint servers, ClinicalTrials.gov, or frontend analytics services during normal visitor
        searches.
      </p>
    </article>

    <article class="about-panel about-panel-wide">
      <h2>Email Alerts</h2>
      <p>
        Email alerts are optional. If a user creates an alert, the app stores the email address,
        selected substances, optional keyword, author, journal, topic, cited DOI, delivery
        frequency, confirmation state, active state, timestamps, and delivery records needed to
        prevent duplicate messages. Digests are sent only after double opt-in confirmation.
      </p>
      <p>
        Alert preferences can be broad or targeted. In targeted mode, users may configure specific
        search terms, research topics, authors, journals, substances, and cited DOI matches. These
        values are part of the email alert configuration and are used only to decide which newly
        imported publications should be included in the requested confirmation or digest emails.
        Digest emails show the current research filters and delivery frequency so recipients can
        see why the matching-publication email was sent.
      </p>
      <ul class="about-list about-architecture-list">
        <li><strong>Purpose:</strong> confirm the requested alert, send matching publication digests, manage preferences, pause delivery, unsubscribe, and delete alert data.</li>
        <li><strong>Storage protection:</strong> email addresses, access tokens, and confirmation tokens are encrypted at rest. Blind indexes support lookup without keeping those lookup values in plaintext columns.</li>
        <li><strong>Email configuration:</strong> the recipient address, delivery frequency, confirmation status, active/paused state, and configured alert filters are retained so the app can send only the requested emails.</li>
        <li><strong>Email delivery:</strong> confirmation and digest messages are sent through the server mail system using PHP mail configuration. The recipient email address necessarily leaves the app for email transport.</li>
        <li><strong>No email tracking pixel:</strong> alert and confirmation templates do not include tracking pixels.</li>
        <li><strong>Unsubscribe:</strong> each digest contains manage and unsubscribe links; one-click unsubscribe headers are included where supported by mail clients.</li>
        <li><strong>Deletion:</strong> the alert management page can delete the subscription and related delivery records for that alert token.</li>
      </ul>
    </article>

    <article class="about-panel about-panel-wide">
      <h2>Email Alert Encryption Model</h2>
      <p>
        The alert system separates the values needed for delivery from the values needed for lookup.
        Sensitive alert fields are encrypted before storage, while blind indexes let the app find a
        subscription without searching plaintext email addresses or tokens.
      </p>
      <div class="protection-flow" role="img" aria-label="Email alert encryption model from consent to encrypted storage, confirmation, delivery, and user control">
        <div class="protection-step">
          <span class="protection-step-number">1</span>
          <strong>User consent</strong>
          <p>Email address and selected research filters are submitted after the alert data-use notice is accepted.</p>
        </div>
        <div class="protection-arrow" aria-hidden="true"></div>
        <div class="protection-step">
          <span class="protection-step-number">2</span>
          <strong>PHP validation</strong>
          <p>The app normalizes alert frequency, substances, search terms, author, journal, topic, and cited DOI.</p>
        </div>
        <div class="protection-arrow" aria-hidden="true"></div>
        <div class="protection-step protection-step-secure">
          <span class="protection-step-number">3</span>
          <strong>Encrypted storage</strong>
          <p>Email addresses, manage tokens, and confirmation tokens are stored as ciphertext plus blind-index lookup fields.</p>
        </div>
        <div class="protection-arrow" aria-hidden="true"></div>
        <div class="protection-step">
          <span class="protection-step-number">4</span>
          <strong>Double opt-in</strong>
          <p>No digest is sent until the confirmation link is opened. Ignored confirmation emails do not activate delivery.</p>
        </div>
        <div class="protection-arrow" aria-hidden="true"></div>
        <div class="protection-step">
          <span class="protection-step-number">5</span>
          <strong>Delivery and control</strong>
          <p>Digests use the server mail system, include no tracking pixel, and contain manage, unsubscribe, and delete options.</p>
        </div>
      </div>
      <p class="privacy-note compact">
        Plain email addresses are necessarily used at the moment of email transport. They are not
        exposed in public API, export, widget, health, or database-download endpoints.
      </p>
    </article>

    <article class="about-panel about-panel-wide">
      <h2>Web Push And PWA Data</h2>
      <p>
        PWA installation is handled by the browser. If a user enables Web Push, the app stores the
        browser push endpoint, public key, auth secret, content encoding, optional user-agent
        information, timestamps, and active state needed to send notifications about newly imported
        publications. Push endpoints and browser push keys are encrypted at rest where present.
      </p>
      <ul class="about-list">
        <li>Web Push delivery uses the browser vendor's push service associated with the subscription endpoint.</li>
        <li>Push payloads are encrypted for the browser subscription and signed with VAPID keys.</li>
        <li>The service worker caches local app-shell assets and uses network-first requests for runtime data.</li>
        <li>Users can revoke push permission in their browser or operating-system notification settings.</li>
      </ul>
    </article>

    <article class="about-panel about-panel-wide">
      <h2>Publication Data Sources And Source Context</h2>
      <p>
        The database contains publication and registry metadata from public bibliographic and
        research-data services. The active source stack is PubMed, Crossref, Europe PMC, OpenAlex,
        medRxiv, bioRxiv, PsyArXiv, and ClinicalTrials.gov. The private runtime database can retain
        source-derived abstracts, descriptions, keywords, and importer payloads for ingestion,
        relevance screening, deduplication, classification, and source tracing. Public pages, APIs,
        exports, widgets, and database downloads do not redistribute that unverified source text.
        They expose a rights-sanitized metadata core containing bibliographic facts, source links,
        derived annotations, abstract-availability indicators, and allowlisted factual provenance.
      </p>
      <ul class="about-list about-architecture-list">
        <li><strong>PubMed / NCBI E-utilities:</strong> biomedical citation metadata and PubMed identifiers.</li>
        <li><strong>Crossref:</strong> DOI metadata, journal/publisher metadata, dates, and reference metadata where available.</li>
        <li><strong>Europe PMC:</strong> biomedical literature metadata, preprint coverage, publication-status signals, and PubMed/PMC-linked records.</li>
        <li><strong>OpenAlex:</strong> broad scholarly metadata, author display names, ORCID/OpenAlex author IDs, citation counts, topics, DOI/PMID enrichment, and dedupe support.</li>
        <li><strong>medRxiv and bioRxiv:</strong> date-window preprint metadata for recent/custom preprint updates.</li>
        <li><strong>PsyArXiv / OSF:</strong> psychology preprint metadata where available.</li>
        <li><strong>ClinicalTrials.gov API v2:</strong> clinical trial registry records, stored as clinical trial records rather than published literature records.</li>
      </ul>
      <p>
        Source APIs receive server-side bibliographic queries, source-specific search terms, date
        windows, identifiers, and configured contact metadata when required or appropriate for
        responsible API use. Normal visitor search terms are not forwarded to these source APIs.
      </p>
      <p>
        CC BY 4.0 applies only to rights held by the compiler in the selection and arrangement,
        normalization, original annotations, validation outputs, and documentation. Third-party
        bibliographic fields remain subject to applicable upstream rights and terms. An abstract
        being publicly readable or available through an API is not treated as permission to
        redistribute it.
      </p>
    </article>

    <article class="about-panel about-panel-wide">
      <h2>Automated Updates</h2>
      <p>
        Production updates are performed server-side by cron. The regular job runs
        <code>php bin/update.php --daily</code> at 03:20 server time, which corresponds to 01:20 UTC
        during Central European Summer Time. The job imports a bounded recent date window, deduplicates
        by DOI, PubMed ID, and normalized title, updates existing records, classifies topics and study
        types, writes heartbeat files, and records public-safe update status.
      </p>
      <ul class="about-list">
        <li>Admin-only commands can run backfills, custom date windows, source-specific imports, reclassification, targeted PubMed ID imports, alert sending, push sending, and SQLite backups.</li>
        <li>Visitor-triggered public refresh is bounded to a recent seven-day window and protected by a lock and cooldown.</li>
        <li>Runtime logs are JSON lines with redaction for token, password, secret, and key-like fields.</li>
      </ul>
    </article>

    <article class="about-panel">
      <h2>Cookies And Local Browser Storage</h2>
      <p>
        The app does not require advertising cookies or cross-site tracking cookies. JavaScript may
        use local browser storage for interface preferences such as sidebar collapse state and results
        panel state. The service worker and Cache Storage may store static local app assets for offline
        fallback and faster repeat visits.
      </p>
    </article>

    <article class="about-panel">
      <h2>Security Measures</h2>
      <ul class="about-list">
        <li>HTTPS protects browser traffic on the live site.</li>
        <li>Runtime data is kept under <code>data/</code> with web-deny rules for sensitive files.</li>
        <li>Sensitive alert and push fields are encrypted at rest where present.</li>
        <li>Admin actions are protected by server-side token checks and POST request handling.</li>
        <li>Generated public health output is designed not to expose secrets, traces, or private runtime data.</li>
      </ul>
    </article>

    <article class="about-panel">
      <h2>Retention</h2>
      <p>
        Publication metadata is retained as part of the public research index unless corrected,
        deduplicated, hidden as a false positive, or removed during maintenance. Alert data is retained
        while the alert exists and can be deleted from the secure manage link. Operational logs,
        heartbeat files, update logs, and backups are retained for reliability, auditing, debugging,
        and recovery according to server maintenance practice.
      </p>
    </article>

    <article class="about-panel">
      <h2>User Rights</h2>
      <p>
        Depending on applicable law, users may have rights to access, correction, deletion,
        restriction, objection, portability, and complaint to a supervisory authority. For alert
        subscriptions, the fastest self-service route is the secure manage link in confirmation and
        digest emails, which supports updating preferences, pausing, unsubscribing, and deleting alert
        data.
      </p>
    </article>

    <article class="about-panel about-panel-wide">
      <h2>Important Limits</h2>
      <p>
        The tracker is a bibliographic discovery and monitoring tool. Source metadata can be
        incomplete or contain errors, source APIs can change, and deterministic classification is a
        navigation aid rather than a scientific conclusion. Users should verify records at the DOI,
        publisher, PubMed, registry, or source database before relying on them for citation,
        clinical interpretation, reporting, or policy work.
      </p>
    </article>
  </section>
</main>

<footer class="footer about-footer">
  <span>Application created by Dr. Christopher B. Germann · <a href="/">Open tracker</a> · <a href="about.php">About</a> · <a href="api.php" target="_blank" rel="noopener">API</a>.</span>
  <?= funding_acknowledgement() ?>
</footer>

<?= detail_scroll_top() ?>
<script src="assets/app.min.js?v=<?= h($assetVersion) ?>" defer></script>
</body>
</html>
