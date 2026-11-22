<?php
declare(strict_types=1);

require_once __DIR__ . '/src/helpers.php';

$assetVersion = '20261011-evidence-performance-v198';
$baseUrl = rtrim(Config::publicBaseUrl(), '/') . '/';
$canonicalUrl = $baseUrl . 'editorial-policy.php';
$contactEmail = Config::contactEmail();
$pageTitle = 'Editorial Policy and Research Methodology | Psilocybin Research';
$description = 'Authorship, affiliation, source selection, automated updates, manual review, corrections, rights, and scientific limitations for Psilocybin Research.';
$author = [
    '@type' => 'Person',
    '@id' => $canonicalUrl . '#author',
    'name' => 'Dr. Christopher B. Germann',
    'url' => 'https://orcid.org/0000-0002-1573-4651',
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
            '@type' => 'WebPage', '@id' => $canonicalUrl . '#page', 'name' => $pageTitle,
            'headline' => 'Editorial policy and research methodology', 'description' => $description,
            'url' => $canonicalUrl, 'dateModified' => '2026-08-09', 'author' => $author,
            'reviewedBy' => $author, 'mainEntity' => ['@id' => $canonicalUrl . '#author'],
            'isPartOf' => ['@type' => 'WebSite', 'name' => 'Psilocybin Research', 'url' => $baseUrl],
        ],
        $author,
    ],
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($pageTitle) ?></title>
  <?= detail_page_seo($pageTitle, $description, 'editorial-policy.php') ?>
  <?= json_ld_script($structuredData) ?>
  <link rel="stylesheet" href="<?= h(FrontendAssets::url('assets/styles.min.css')) ?>">
  <script src="<?= h(FrontendAssets::url('assets/app.min.js')) ?>" defer></script>
</head>
<body class="detail-page editorial-policy-page">
<?= detail_page_header('about') ?>
<main class="detail-shell editorial-policy-shell">
  <section class="panel detail-hero editorial-hero">
    <div class="detail-hero-intro"><div><span class="eyebrow">Accountability and provenance</span><h1>Editorial policy and research methodology</h1><p>This page identifies who maintains Psilocybin Research, how records and research views are produced, what is reviewed, and how errors can be corrected.</p></div></div>
    <p class="editorial-reviewed">Policy last reviewed <time datetime="2026-08-09">9 August 2026</time></p>
  </section>

  <section class="panel editorial-author" aria-labelledby="editorial-author-heading">
    <div><span class="eyebrow">Author and maintainer</span><h2 id="editorial-author-heading">Dr. Christopher B. Germann</h2></div>
    <div>
      <p>Psilocybin Research is created and maintained by Dr. Christopher B. Germann, postdoctoral researcher at the <a href="https://www.uni-wh.de/en/about-us/faculty-of-health/school-of-medicine" target="_blank" rel="noopener">Faculty of Health, School of Medicine, University of Witten/Herdecke</a>.</p>
      <p><a class="secondary iconed" href="https://orcid.org/0000-0002-1573-4651" target="_blank" rel="me noopener"><i data-icon="external-link" aria-hidden="true"></i><span>ORCID 0000-0002-1573-4651</span></a></p>
      <p class="editorial-independence"><strong>Independence:</strong> the affiliation identifies the maintainer’s academic context. It does not imply that University of Witten/Herdecke operates, endorses, verifies, or is responsible for this independent website.</p>
    </div>
  </section>

  <section class="editorial-grid" aria-label="Editorial standards">
    <article class="panel"><h2>Scope and inclusion</h2><p>The database indexes bibliographic and registry records related to psilocybin, psilocin, and explicit synonyms. Sources include PubMed, Crossref, Europe PMC, OpenAlex, selected preprint services, and ClinicalTrials.gov. Automated acquisition is followed by normalization, duplicate checks, relevance rules, publication-status classification, and source tracking. Broad coverage is the goal; exhaustive recall is not claimed.</p></article>
    <article class="panel"><h2>Automation and human review</h2><p>Scheduled source refreshes identify and update records. Deterministic code performs normalization, classification, linking, and deduplication. Manual review is prioritized for ambiguous relevance, conflicting identifiers, unresolved duplicates, incomplete metadata, external comparison candidates, and reported errors. Automatically derived tags are navigation aids and should not be treated as expert clinical judgments.</p></article>
    <article class="panel"><h2>Clinical-trial interpretation</h2><p>Trial status, phase, enrollment, sponsor, dates, conditions, interventions, and sites reproduce factual registry fields. Status can lag changes at an individual site. The database is for research discovery, not recruitment advice, diagnosis, treatment, or an assessment of safety or efficacy. ClinicalTrials.gov remains the authoritative record for eligibility and contacts.</p></article>
    <article class="panel"><h2>Source and rights boundary</h2><p>Public pages, APIs, exports, widgets, analytics, and database downloads use a rights-safe projection. They exclude unverified abstracts, registry descriptions, source-derived keywords, contacts, and unrestricted raw payloads. MIT covers the software; it does not relicense third-party bibliographic content. Dataset selection, arrangement, normalization, original annotations, and validation outputs are documented separately.</p></article>
    <article class="panel"><h2>Corrections and quarantine</h2><p>Verified corrections are applied to the living database with source context preserved. Entries that cannot be validated, are irrelevant, or present unresolved identity conflicts may be quarantined and excluded from public results until they can be resolved. Corrections to fixed Zenodo snapshots are documented through a new version or an explicit record note rather than silently changing an immutable deposit.</p></article>
    <article class="panel"><h2>Review and citation</h2><p>Users should open DOI, PubMed, publisher, or registry links before citation, reporting, policy analysis, or clinical interpretation. The live database changes over time; reproducible work should cite a fixed Zenodo dataset version and the associated software release. The <a href="about.php">about page</a> explains architecture and sources, while the <a href="data-protection.php#public-data-rights">data-rights section</a> documents public redistribution limits.</p></article>
  </section>

  <section class="panel editorial-corrections" aria-labelledby="corrections-heading">
    <div><span class="eyebrow">Report an issue</span><h2 id="corrections-heading">Corrections are welcome</h2></div>
    <p>When reporting a problem, include the database URL, DOI, PMID or NCT identifier, the field that appears incorrect, and a direct authoritative source. Contact <a href="mailto:<?= h($contactEmail) ?>"><?= h($contactEmail) ?></a>. Substantive corrections are checked against source records before publication.</p>
  </section>
</main>
<?= detail_footer() ?>
<?= detail_scroll_top() ?>
</body>
</html>
