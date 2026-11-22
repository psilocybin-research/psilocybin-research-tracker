<?php
declare(strict_types=1);
require_once __DIR__ . '/src/helpers.php';
$path = 'psilocybin-research-guide/'; $url = LinkedData::base() . $path;
$orientation = require __DIR__ . '/config/research-orientation.php';
$citations=[];foreach($orientation['sections'] as $section)foreach($section['sources'] as $doi)$citations[]=['@id'=>LinkedData::recordId(['doi'=>$doi])];
$title = 'Psilocybin Research: Evidence, Studies and Clinical Trials';
$description = 'Explore psilocybin research findings and limitations, compare selected evidence by condition, find clinical trials and papers, and reuse free research data.';
$robots = $_GET ? 'noindex, follow' : 'index, follow, max-image-preview:large, max-snippet:-1';
if ($_GET) header('X-Robots-Tag: noindex, follow');
$schema = LinkedData::document([
    ['@type' => 'WebPage', '@id' => $url . '#page', 'url' => $url, 'name' => $title, 'description' => $description, 'mainEntity' => ['@id' => $url . '#guide'], 'isPartOf' => ['@id' => LinkedData::base() . '#website']],
    ['@type' => 'Article', '@id' => $url . '#guide', 'url' => $url, 'headline' => $title, 'description' => $description, 'datePublished' => '2026-10-07', 'dateModified' => '2026-10-12', 'citation' => $citations, 'inLanguage' => 'en', 'author' => LinkedData::maintainer(), 'publisher' => LinkedData::maintainer(), 'mainEntityOfPage' => ['@id' => $url . '#page'], 'about' => [LinkedData::term('substance', 'psilocybin'), LinkedData::term('substance', 'psilocin')]],
    LinkedData::website(), ['@type' => 'BreadcrumbList', 'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Research database', 'item' => LinkedData::base()],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Research guide', 'item' => $url],
    ]],
]);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><base href="/">
<title><?= h($title) ?></title><?= detail_page_seo($title, $description, $path, ['robots' => $robots, 'type' => 'article']) ?><?= json_ld_script($schema) ?>
<link rel="stylesheet" href="<?= h(FrontendAssets::url('assets/styles.min.css')) ?>"><script src="<?= h(FrontendAssets::url('assets/app.min.js')) ?>" defer></script>
</head><body class="detail-page evidence-guide-page">
<?= detail_page_header('research-guide') ?><main class="detail-shell" id="main"><article>
<header class="panel detail-hero evidence-guide-hero"><span class="eyebrow">Literature discovery and interpretation</span><h1>Psilocybin research: evidence and study guide</h1>
<p class="evidence-guide-lead">Research papers, preprints and clinical trial registrations answer different questions. This guide connects the literature database to evidence interpretation, trial discovery and reproducible analysis, with a checklist for reading results in their scientific context.</p>
<p class="evidence-guide-meta">Maintained by <a href="editorial-policy.php#author">Dr. Christopher B. Germann</a> · Source-checked <time datetime="<?= h($orientation['checked']) ?>">11 October 2026</time></p>
<div class="research-shortcuts"><a href="<?= h($path) ?>#evidence-overview">What studies show</a><a href="<?= h($path) ?>#find-research">Choose a research task</a><a href="<?= h($path) ?>#reading-results">Read a study critically</a><a href="<?= h($path) ?>#terms">Research terminology</a></div></header>
<section class="panel detail-card" id="evidence-overview"><h2>What does psilocybin research show?</h2>
<p>Human studies examine psilocybin alongside structured psychological support in selected participants. Results depend on the condition, study design and outcome. The examples below give a starting point for reading the evidence; they are a selected educational overview, not a systematic review or treatment recommendation.</p>
<div class="research-table-wrap" role="region" aria-label="Selected psilocybin research findings and limitations" tabindex="0"><table class="research-table"><caption>Selected primary reports · sources checked <?= h($orientation['checked']) ?></caption><thead><tr><th scope="col">Research area</th><th scope="col">Selected findings</th><th scope="col">Important limits</th></tr></thead><tbody>
<?php foreach ($orientation['sections'] as $section): ?><tr><th scope="row"><a href="<?= h($section['guide']) ?>"><?= h($section['label']) ?></a></th><td><?= h($section['finding']) ?><p><?php foreach ($section['sources'] as $label => $doi): ?><a href="<?= h(LinkedData::recordId(['doi'=>$doi])) ?>"><?= h($label) ?></a><br><?php endforeach; ?></p></td><td><?= h($section['limit']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<p>These trials do not establish the effects of unsupervised use. Recognizable subjective effects can weaken blinding; small studies cannot reliably measure uncommon harms. Read each report's adverse events and exclusions alongside efficacy results. The <a href="https://www.nccih.nih.gov/health/psilocybin-for-mental-health-and-addiction-what-you-need-to-know">NCCIH overview</a> explains broader research and safety context.</p></section>
<section class="panel detail-card" id="latest-research"><h2>How to find the latest psilocybin research</h2>
<p>Use <a href="./#hero-q">publication search</a> for a compound, condition, author or DOI. Compare publication status and study design, then open the original source. The <a href="research-updates.php">research updates</a> view helps distinguish recently indexed records from their publication dates: a newly discovered paper is not necessarily a new study.</p>
<p>For treatment-development questions, use the <a href="trials.php">clinical-trial tracker</a> and confirm registry status. A recruitment change, primary completion date or planned phase does not establish a positive result. For participation, start with <a href="recruiting-psilocybin-clinical-trials/">currently indexed recruiting trials</a> and verify eligibility with the study team.</p>
<p>For ongoing literature work, save a search or configure confirmed alerts. For analysis, use the <a href="research-api.php">free API and CSV workflows</a> or preserve a dated <a href="research-dataset.php">dataset download</a>. Keep preprints, registrations, protocols and published findings distinguishable in your selection.</p></section>
<section class="panel detail-card" id="find-research"><h2>Find the resource that fits your question</h2>
<div class="research-table-wrap" role="region" aria-label="Research tasks and resources" tabindex="0"><table class="research-table"><caption>Each resource has a different purpose and scope.</caption><thead><tr><th scope="col">Question</th><th scope="col">Start here</th><th scope="col">What to check</th></tr></thead><tbody>
<tr><th scope="row">Which papers discuss my topic?</th><td><a href="./#papers">Publication database</a></td><td>Search terms, source, publication status and original DOI or PubMed record.</td></tr>
<tr><th scope="row">What did depression studies find?</th><td><a href="psilocybin-depression-research/">Depression evidence guide</a></td><td>Population, comparator, primary outcome, follow-up and inconclusive results.</td></tr>
<tr><th scope="row">What is the alcohol use disorder evidence?</th><td><a href="psilocybin-alcohol-use-disorder-research/">Alcohol research guide</a></td><td>Heavy drinking outcomes versus relapse prevention, and controlled versus pilot designs.</td></tr>
<tr><th scope="row">What other conditions are being studied?</th><td><a href="evidence.php">Evidence map</a> and <a href="trials.php">trial tracker</a></td><td>Classified record counts indicate research activity, not proven effectiveness.</td></tr>
<tr><th scope="row">Which studies are recruiting?</th><td><a href="recruiting-psilocybin-clinical-trials/">Recruiting clinical trials</a></td><td>Registry status, site contacts and individual eligibility. Confirm with the study team.</td></tr>
<tr><th scope="row">Can I analyze or cite this literature corpus?</th><td><a href="research-dataset.php">Dataset and reuse guide</a></td><td>Live versus fixed data, provenance, rights, version and export format.</td></tr>
<tr><th scope="row">How is the indexed literature changing?</th><td><a href="research-trends.php">Research trends</a> and <a href="psilocybin-research-brief-2026-10/">October research brief</a></td><td>Coverage changes, incomplete years, record status and reproducible input.</td></tr>
<tr><th scope="row">How do papers and researchers connect?</th><td><a href="citation-network.php">Citation network</a> and <a href="authors.php">author index</a></td><td>Available reference coverage and name-based author identity limitations.</td></tr>
<tr><th scope="row">How can a hypothesis inspire later experiments?</th><td><a href="msca-impact/">MSCA research story: psilocybin and telomeres</a> · 20 languages</td><td>Original hypothesis, independent preclinical follow-up and limitations. Laboratory findings do not establish effects in humans.</td></tr>
</tbody></table></div></section>
<section class="panel detail-card" id="scope"><h2>Psilocybin, psilocin and the scope of this database</h2>
<p>Psilocybin is converted in the body to psilocin. Literature may therefore discuss the administered compound, the active compound, or both. The <a href="https://www.nccih.nih.gov/health/psilocybin-for-mental-health-and-addiction-what-you-need-to-know">NCCIH overview</a> provides a public introduction to the compounds and research context.</p>
<p>This database combines bibliographic records from multiple sources. It includes clinical, preclinical and other relevant literature, rather than only treatment trials. Source coverage, retrieval and relevance screening have limitations; inclusion does not certify study quality. Visit <a href="about.php">methods and provenance</a> before using the corpus as the basis for a review.</p>
<p>For a research search, try both compound names and the condition or mechanism of interest. Check status and design filters, then follow the original source. Broad matches can help discovery, but a systematic review requires a documented search strategy, screening criteria and a reproducible selection process beyond this interface.</p></section>
<section class="panel detail-card" id="reading-results"><h2>Seven questions to ask when reading a psilocybin study</h2>
<ol>
<li><strong>Who was studied?</strong> Record diagnosis, recruitment setting, eligibility and exclusions. Findings in selected volunteers may not transfer to another population.</li>
<li><strong>What was compared?</strong> Identify the control, dose schedule and psychological support in each group. An open-label or wait-list design answers a different question from an active-comparator randomized trial.</li>
<li><strong>What was the primary outcome?</strong> Separate prespecified primary endpoints from secondary measures and exploratory analyses. A positive secondary finding does not replace an unsuccessful primary comparison.</li>
<li><strong>How large and uncertain was the effect?</strong> Keep the scale, units, group difference and confidence interval together. Statistical significance alone does not establish clinical importance.</li>
<li><strong>How long did follow-up last?</strong> A result at two weeks is not evidence of a year-long benefit. Look for missing observations, withdrawals and the population included in each analysis.</li>
<li><strong>Could expectations influence results?</strong> Participants or assessors may infer allocation from noticeable subjective effects. Check blinding assessments and the report's discussion of this limitation.</li>
<li><strong>What happened beyond the efficacy score?</strong> Read adverse events, serious events, discontinuations, funding, conflicts and corrections. Small samples can miss uncommon harms.</li>
</ol>
<p>Apply the checklist to the <a href="psilocybin-depression-research/#study-comparison">depression comparison</a> or <a href="psilocybin-alcohol-use-disorder-research/#comparison">alcohol study comparison</a>. These selections demonstrate interpretation and do not replace a formal evidence synthesis.</p></section>
<section class="panel detail-card" id="terms"><h2>Research terms used across the tracker</h2>
<dl>
<dt><strong>Randomized controlled trial</strong></dt><dd>Assignment to study groups uses a random process. Randomization supports comparison, but still requires scrutiny of blinding, missing data and the analysis plan.</dd>
<dt><strong>Open-label study</strong></dt><dd>Participants or investigators know the intervention. Observed change can help establish feasibility and generate hypotheses; without an appropriate comparator it does not isolate a treatment effect.</dd>
<dt><strong>Preprint</strong></dt><dd>A manuscript shared before journal peer review. Check for a later version, correction or published article before relying on its findings.</dd>
<dt><strong>Protocol or registration</strong></dt><dd>A description of planned methods, eligibility and outcomes. It is not evidence that the intervention worked. Registration records can later link to posted results or publications.</dd>
<dt><strong>Review and meta-analysis</strong></dt><dd>A review synthesizes prior work; a meta-analysis statistically combines estimates. Conclusions depend on selection, study quality and whether the pooled studies address sufficiently comparable questions.</dd>
<dt><strong>Confidence interval</strong></dt><dd>An interval expressing uncertainty under the analysis assumptions. Examine its range alongside the point estimate and outcome units.</dd>
<dt><strong>Publication count</strong></dt><dd>A count of indexed records, not necessarily unique experiments or participants. Multiple papers may describe one study; registration records and published reports are distinct.</dd>
</dl>
<p>Tracker study labels and topic tags are discovery aids. They are not independently verified evidence grades or clinical diagnoses. See the <a href="editorial-policy.php">editorial policy</a> for classification and manual review.</p></section>
<section class="panel detail-card" id="reproduce"><h2>Keep discovery, citation and analysis reproducible</h2>
<p>When exporting a selection, record your query, filters and retrieval date. For an analysis of the whole corpus, preserve the downloaded data and checksum, document the source version and explain how you treated preprints, registrations and missing dates. Use a fixed dataset version when another reader needs to reproduce the same result.</p>
<p>The <a href="research-dataset.php">dataset guide</a> explains citation and download options. The <a href="psilocybin-research-brief-2026-10/">October brief</a> supplies frozen analytical input and scripts. The <a href="tools/psilocybin_bibliometrics_visnetwork.R">R toolkit</a> supports bibliometric exploration; inspect coverage and matching limitations before interpreting its network.</p>
<p>Public exports provide rights-safe bibliographic metadata and original derived annotations. An abstract-availability indicator does not grant permission to redistribute the underlying text. Follow the original source for full text and its licence.</p></section>
<section class="panel detail-card" id="sources"><h2>Sources, accountability and limits</h2>
<p>This is an original guide to using the database and interpreting research designs, with the selected evidence overview source-checked on 11 October 2026. It is educational and does not provide individualized treatment advice or claim independent clinical review.</p>
<ul><li><a href="https://www.nccih.nih.gov/health/psilocybin-for-mental-health-and-addiction-what-you-need-to-know">NCCIH: psilocybin research and safety context</a></li><li><a href="https://clinicaltrials.gov/study-basics/how-to-read-study-record">ClinicalTrials.gov: how to read a study record</a></li><li><a href="https://www.nih.gov/health-information/nih-clinical-research-trials-you/basics">NIH: clinical research basics</a></li><li><a href="editorial-policy.php">Tracker authorship, corrections and source policy</a></li></ul>
<p>New studies can change the evidence. Consult current source records and the live database alongside these dated guides. Reproducibility and explicit uncertainty remain necessary even when a finding is promising.</p></section>
</article></main><?= detail_footer() ?><?= detail_scroll_top() ?></body></html>
