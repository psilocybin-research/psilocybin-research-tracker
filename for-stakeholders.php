<?php
declare(strict_types=1);
require_once __DIR__ . '/src/helpers.php';
$baseUrl = rtrim(Config::publicBaseUrl(), '/') . '/';
$path = 'for-stakeholders/';
$title = 'Psilocybin Market Research & Planning | For Stakeholders';
$description = 'Use psilocybin research, trial pipelines and evidence tools for market intelligence, trend analysis, strategic planning and investment due diligence.';
$robots = $_GET ? 'noindex, follow' : 'index, follow, max-image-preview:large, max-snippet:-1';
if ($_GET) header('X-Robots-Tag: noindex, follow');
$schema = ['@context' => 'https://schema.org', '@graph' => [
    ['@type' => 'WebPage', '@id' => $baseUrl . $path, 'url' => $baseUrl . $path, 'name' => $title, 'description' => $description, 'inLanguage' => 'en-GB'],
    ['@type' => 'BreadcrumbList', 'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Research database', 'item' => $baseUrl],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'For stakeholders', 'item' => $baseUrl . $path]]],
]];
?>
<!doctype html>
<html lang="en-GB"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<base href="<?= h($baseUrl) ?>">
<title><?= h($title) ?></title>
<?= detail_page_seo($title, $description, $path, ['robots' => $robots]) ?>
<?= json_ld_script($schema) ?>
<link rel="stylesheet" href="<?= h(FrontendAssets::url('assets/styles.min.css')) ?>">
<script src="<?= h(FrontendAssets::url('assets/app.min.js')) ?>" defer></script>
</head><body class="detail-page">
<?= detail_page_header('for-stakeholders') ?>
<main class="detail-shell" id="main">
<section class="panel detail-hero">
<span class="eyebrow">Market intelligence · Evidence · Strategic planning</span>
<h1>For stakeholders</h1>
<p>Build a clearer picture of psilocybin research before committing resources. Psilocybin-Research.com connects source-linked literature, clinical trial registrations and analytical tools to support market research, research partnerships and investment due diligence.</p>
<p>For funders, investors, development teams, service planners and policy analysts: turn a broad question into a documented evidence review, a programme watchlist and a set of explicit planning assumptions.</p>
<div class="detail-actions"><a class="primary" href="research-questions.php">Define your research question</a><a class="secondary" href="trial-pipeline.php">Explore the trial pipeline</a></div>
<p><a href="for-stakeholders/#landscape">Research landscape</a> · <a href="for-stakeholders/#trends">Trends and milestones</a> · <a href="for-stakeholders/#planning">Investment planning</a> · <a href="for-stakeholders/#briefing">Your review brief</a></p>
</section>
<section class="panel detail-card" id="landscape">
<h2>Map the research landscape</h2>
<p>Start with a defined indication, population or research problem. Search psilocybin and psilocin records, then narrow by topic, year, source, publication status and study type. Read the original work before treating a database tag as evidence.</p>
<div class="detail-grid">
<div><h3>Find relevant programmes</h3><p>Use the <a href="trial-pipeline.php">trial pipeline</a> to inspect available phase, recruitment status, condition and sponsor fields. Follow each NCT identifier to its original registration. Keep multiple publications from the same trial together in your review.</p></div>
<div><h3>Find potential partners</h3><p>Explore the <a href="research-network.php">researcher network</a> and <a href="research-countries.php">countries and facilities</a> to identify research activity and collaboration leads. Verify institutional affiliations and current capabilities directly; a co-authorship link does not establish a commercial partnership.</p></div>
</div>
<p><strong>Useful output:</strong> a landscape table with the research question, programme or institution, linked records, evidence stage and questions requiring follow-up. Indexed activity can help identify areas to investigate; it does not measure market size or unmet demand.</p>
<p><a href="./?range=all#papers">Search the full database</a> · <a href="research-library.php">Explore topics</a> · <a href="trials-map.php">Open the trial map</a></p>
</section>
<section class="panel detail-card" id="trends">
<h2>Track trends and upcoming research milestones</h2>
<p>Use <a href="research-trends.php">research trends</a> to examine recorded publication years and source coverage. Define the same filters and record types for each comparison. Separate published findings, preprints and trial registrations, and compare complete years when assessing annual changes.</p>
<p>Build a programme watchlist from the trial pipeline. Verify planned completion dates and study updates in the original registry, then record what would change your assessment: recruitment progress, a results posting, a primary report or an independently verified regulatory decision. A planned completion date is not a promised results announcement.</p>
<p>Backfilled records, indexing delays and changing source coverage can change historical counts. More records do not by themselves demonstrate stronger evidence, commercial growth or a higher probability of approval.</p>
<p><strong>Useful output:</strong> a dated trend chart and milestone ledger, with a source link, last-check date and next review trigger for each programme.</p>
<p><a href="research-updates.php">Monitor new research and changes</a> · <a href="research-in-motion/">View the research visualisation</a></p>
</section>
<section class="panel detail-card">
<h2>Assess evidence maturity before interpreting opportunity</h2>
<p>The <a href="research-results.php">findings comparison</a>, <a href="evidence.php">evidence map</a> and <a href="psilocybin-research-guide/">critical-reading guide</a> help structure an initial review. Examine study design, comparators, participant selection, endpoints, follow-up and safety reporting. Check whether different reports describe the same participants.</p>
<div class="research-table-wrap" role="region" aria-label="Evidence questions for stakeholder reviews" tabindex="0"><table class="research-table">
<caption>Questions to carry into due diligence</caption>
<thead><tr><th scope="col">Question</th><th scope="col">What to examine</th><th scope="col">Planning implication</th></tr></thead><tbody>
<tr><th scope="row">How robust is the finding?</th><td>Original reports, controls, sample size, uncertainty and replication.</td><td>Document what is established and what still needs confirmation.</td></tr>
<tr><th scope="row">Who was studied?</th><td>Eligibility, exclusions, setting and follow-up.</td><td>Check whether the proposed population or service resembles the research setting.</td></tr>
<tr><th scope="row">What resources were required?</th><td>Published protocols and descriptions of preparation, staffing, monitoring and follow-up.</td><td>Identify delivery assumptions to verify locally.</td></tr>
<tr><th scope="row">What remains unresolved?</th><td>Safety reporting, durability, comparator choice and missing results.</td><td>Define additional evidence needed before the next commitment.</td></tr>
</tbody></table></div>
<p>Registration, clinical development and marketing authorisation are distinct. Verify regulatory status with the relevant authority; the <a href="https://www.fda.gov/patients/learn-about-drug-and-device-approvals/drug-development-process">FDA drug-development overview</a> explains the separate research and review stages in the United States.</p>
</section>
<section class="panel detail-card" id="planning">
<h2>Support investment and project planning with explicit scenarios</h2>
<p>Use the research review to define decision gates: what evidence would justify further diligence, a partnership discussion, a grant application or a staged resource commitment? Record the assumptions behind each gate, who will verify them and when they will be revisited.</p>
<p>The <a href="research-economics.php">economic assumptions tool</a> supports transparent cost and access scenarios. Combine it with independently sourced local staffing, capacity and reimbursement information. Keep research observations separate from financial assumptions.</p>
<div class="detail-grid">
<div><h3>Test the assumptions</h3><p>Write a base case, a more favourable case and a constrained case. Vary development timing, staffing, utilisation and funding requirements. Explain why each range was chosen and which external source supports it.</p></div>
<div><h3>Complete the commercial picture</h3><p>Verify financing, ownership, intellectual property, competition and commercial claims using original documents. For US reporting companies, <a href="https://www.sec.gov/search-filings">SEC EDGAR filings</a> provide a separate source for company disclosures. Use the relevant official sources for other jurisdictions.</p></div>
</div>
<p><strong>Scope:</strong> this database supports research discovery and evidence-informed planning. It does not supply market valuations, revenue forecasts, approval probabilities or investment recommendations. Publication counts cannot be converted directly into sales, patient demand or investment returns.</p>
</section>
<section class="panel detail-card" id="briefing">
<h2>Build a reproducible stakeholder brief</h2>
<ol>
<li><strong>Define the decision.</strong> State the research question, audience, geographical scope and review horizon.</li>
<li><strong>Archive the evidence set.</strong> Save your filters, retrieval date and <a href="research-dataset.php">metadata export</a>. Retain the exact file and its checksum so a later database update does not silently change your analysis.</li>
<li><strong>Separate observation from interpretation.</strong> Link findings to DOI or PubMed records and programmes to NCT registrations. Label incomplete information and unresolved claims.</li>
<li><strong>Summarise the decision gates.</strong> Present evidence strengths, uncertainties, scenario assumptions and the events that would prompt reassessment.</li>
<li><strong>Keep the review current.</strong> Use <a href="research-updates.php">research updates</a> and the database’s alert controls, with a scheduled review of original sources.</li>
</ol>
<p>A useful one-page brief answers: <em>What has changed? Why does it matter to this decision? What remains uncertain? What should we check next?</em> Attach the source ledger and analytical input rather than presenting an unsupported confidence score.</p>
<p><a href="research-api.php">Use the free API</a> for repeatable metadata workflows, or use the <a href="research-library.php">saved reading list</a> to organise a focused review. Reading-list storage is local to your browser; it is not a shared team workspace.</p>
<p>See <a href="editorial-policy.php">selection and correction methods</a> and <a href="research-dataset.php">dataset provenance and coverage</a> before treating the index as exhaustive. Selected topic tags are discovery aids and can overlap.</p>
</section>
</main>
<?= detail_footer() ?><?= detail_scroll_top() ?>
</body></html>
