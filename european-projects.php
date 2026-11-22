<?php
declare(strict_types=1);
require_once __DIR__ . '/src/helpers.php';
$baseUrl = rtrim(Config::publicBaseUrl(), '/') . '/';
$path = 'european-psilocybin-research-projects/';
$title = 'European Psilocybin Research Projects | EU Grants & CORDIS';
$description = 'Explore EU-funded psilocybin and psilocin research projects with CORDIS grant records, coordinators, funding, scheduled dates and links to research outputs.';
$input = static fn(string $key): string => is_string($_GET[$key] ?? null) ? mb_substr(trim($_GET[$key]), 0, 200) : '';
$filters = array_map($input, array_combine(['q','country','programme','schedule'], ['q','country','programme','schedule']));
$snapshot = EuropeanProjects::snapshot(); $all = $snapshot['projects'];
$projects = EuropeanProjects::select($all, $filters);
$notes = require __DIR__ . '/config/european-project-notes.php';
$format = $input('format');
header('Cache-Control: no-store');
if ($_GET) header('X-Robots-Tag: noindex, follow');
if (in_array($format, ['json', 'csv'], true)) {
    header('X-Robots-Tag: noindex, follow');
    header('Content-Disposition: attachment; filename="european-psilocybin-projects.' . $format . '"');
    $export = array_map(static fn(array $p): array => $p + ['schedule' => EuropeanProjects::schedule($p)], $projects);
    if ($format === 'json') {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['source' => $snapshot['source'] ?? 'CORDIS', 'query' => EuropeanProjects::QUERY, 'checked_at' => $snapshot['checked_at'],
            'scope' => 'Grant metadata, not publications or trial recruitment status.', 'count' => count($export), 'projects' => $export], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } else {
        header('Content-Type: text/csv; charset=UTF-8'); $out = fopen('php://output', 'w');
        $columns = ['id','acronym','title','coordinator','country','programme','framework','start_date','end_date','cordis_status','eu_contribution_eur','source_updated','source_url','project_doi','schedule','checked_at'];
        fputcsv($out, $columns, ',', '"', '');
        foreach ($export as $p) { $p['checked_at'] = $snapshot['checked_at']; fputcsv($out, array_map(static function (string $k) use ($p): string {
            $v = (string)($p[$k] ?? ''); return preg_match('/^[=+@\-\t\r]/', $v) ? "'" . $v : $v;
        }, $columns), ',', '"', ''); }
    }
    exit;
}
$robots = $_GET ? 'noindex, follow' : 'index, follow, max-image-preview:large, max-snippet:-1';
$list = [];
foreach ($projects as $i => $p) $list[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $p['acronym'] . ': ' . $p['title'], 'url' => $baseUrl . $path . '#project-' . $p['id']];
$schema = ['@context' => 'https://schema.org', '@graph' => [
    ['@type' => 'CollectionPage', '@id' => $baseUrl . $path, 'url' => $baseUrl . $path, 'name' => $title, 'description' => $description, 'inLanguage' => 'en-GB', 'mainEntity' => ['@type' => 'ItemList', 'numberOfItems' => count($list), 'itemListElement' => $list]],
    ['@type' => 'BreadcrumbList', 'itemListElement' => [['@type' => 'ListItem', 'position' => 1, 'name' => 'Research database', 'item' => $baseUrl], ['@type' => 'ListItem', 'position' => 2, 'name' => 'European research projects', 'item' => $baseUrl . $path]]],
]];
$countries = ['AT'=>'Austria','BE'=>'Belgium','CZ'=>'Czechia','DE'=>'Germany','DK'=>'Denmark','ES'=>'Spain','FI'=>'Finland','FR'=>'France','GR'=>'Greece','IE'=>'Ireland','IT'=>'Italy','LT'=>'Lithuania','NL'=>'Netherlands','NO'=>'Norway','PL'=>'Poland','PT'=>'Portugal','SE'=>'Sweden','CH'=>'Switzerland','UK'=>'United Kingdom','GB'=>'United Kingdom'];
$countryName = static fn(string $code): string => $countries[$code] ?? $code;
$choices = [];
foreach (['country','programme'] as $key) { $choices[$key] = array_values(array_unique(array_column($all, $key))); sort($choices[$key]); }
$choices['schedule'] = ['Upcoming','Within scheduled dates','Past scheduled end'];
$checked = $snapshot['checked_at'] ? new DateTimeImmutable($snapshot['checked_at']) : null;
$stale = !$checked || $checked->getTimestamp() < time() - 3 * 86400;
$downloadQuery = array_filter($filters, static fn(string $v): bool => $v !== '');
$dateLabel = static fn(string $date): string => (new DateTimeImmutable($date))->format('j M Y');
?>
<!doctype html><html lang="en-GB"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<base href="<?= h($baseUrl) ?>"><title><?= h($title) ?></title>
<?= detail_page_seo($title, $description, $path, ['robots' => $robots]) ?>
<?= json_ld_script($schema) ?>
<link rel="stylesheet" href="<?= h(FrontendAssets::url('assets/styles.min.css')) ?>">
<script src="<?= h(FrontendAssets::url('assets/app.min.js')) ?>" defer></script>
</head><body class="detail-page explorer-page">
<?= detail_page_header('european-projects') ?>
<main class="detail-shell" id="main">
<section class="panel detail-hero">
<span class="eyebrow">European research · CORDIS · Grants</span>
<h1>European psilocybin research projects</h1>
<p>Discover EU-funded research on psilocybin and psilocin: from laboratory mechanisms and clinical programmes to social science. Follow each grant to the European Commission’s CORDIS record, its coordinator and its reported outputs.</p>
<p>Project records describe funded research plans. They are kept separate from <a href="./?range=all#papers">publications</a> and <a href="trial-pipeline.php">clinical-trial registrations</a>; funding does not establish a successful result or an approved treatment.</p>
<p><strong><?= count($all) ?> projects in the current snapshot.</strong> <?= $checked ? 'Last successful CORDIS check: ' : 'The first source snapshot is not yet available.' ?><?php if ($checked): ?><time datetime="<?= h($snapshot['checked_at']) ?>"><?= h($checked->setTimezone(new DateTimeZone('Europe/Berlin'))->format('j F Y, H:i T')) ?></time>.<?php endif; ?> Automatic checks run daily. <a href="<?= h($path) ?>#coverage">Coverage and update method</a></p>
<?php if ($stale): ?><p role="status">Source refresh is overdue or unavailable. Any entries below are from the last successful check; verify current details in CORDIS.</p><?php endif; ?>
</section>
<section class="panel detail-card" aria-labelledby="project-search-title">
<h2 id="project-search-title">Find a research project</h2>
<form method="get" action="<?= h($path) ?>#projects" class="dataset-filter-grid" role="search" aria-label="European research projects">
<label>Project, institution or grant ID<input type="search" name="q" value="<?= h($filters['q']) ?>" placeholder="psiloNOVA, gut, Vilnius…" maxlength="200"></label>
<?php foreach (['country'=>'Coordinator country','programme'=>'Funding programme','schedule'=>'Scheduled period'] as $key=>$label): ?>
<label><?= h($label) ?><select name="<?= h($key) ?>"><option value="">All</option><?php foreach ($choices[$key] as $value): ?><option value="<?= h($value) ?>" <?= $filters[$key] === $value ? 'selected' : '' ?>><?= h($key === 'country' ? $countryName($value) : $value) ?></option><?php endforeach; ?></select></label>
<?php endforeach; ?>
<button class="primary" type="submit">Find projects</button><a href="<?= h($path) ?>">Reset filters</a>
</form>
</section>
<section class="panel detail-card" id="projects" aria-labelledby="project-results-title">
<h2 id="project-results-title"><?= count($projects) ?> <?= count($projects) === 1 ? 'project' : 'projects' ?><?= array_filter($filters) ? ' matching your filters' : ' in the directory' ?></h2>
<p>Ordered by scheduled start, newest first. Country refers to the coordinator; collaborations and research locations may span several countries.</p>
<div class="detail-actions"><a class="secondary" href="<?= h($path . '?' . http_build_query($downloadQuery + ['format'=>'csv'])) ?>">Download CSV</a><a class="secondary" href="<?= h($path . '?' . http_build_query($downloadQuery + ['format'=>'json'])) ?>">Download JSON</a></div>
<?php if (!$projects): ?><p>No projects match these filters. <a href="<?= h($path) ?>">View all projects</a> or <a href="./?range=all#papers">search publications</a>.</p><?php endif; ?>
</section>
<?php foreach ($projects as $p): $note = $notes[$p['id']] ?? null; ?>
<article class="panel detail-card" id="project-<?= h($p['id']) ?>">
<span class="eyebrow"><?= h($note['scope'] ?? 'CORDIS research project') ?> · <?= h(EuropeanProjects::schedule($p)) ?></span>
<h2><?= h($note['label'] ?? $p['acronym']) ?></h2>
<p><strong><?= h($p['title']) ?></strong></p>
<?php if ($note): ?><p><?= h($note['summary']) ?></p><?php endif; ?>
<div class="detail-grid">
<div><h3>Coordinator and funding</h3><p><?= h($p['coordinator']) ?><br><?= h($countryName($p['country'])) ?></p><p><?= h($p['programme']) ?> · <?= h($p['framework'] === 'HORIZON' ? 'Horizon Europe' : $p['framework']) ?><br><strong>EU contribution: <?= $p['eu_contribution_eur'] === null ? 'Not reported' : '€' . h(number_format((float)$p['eu_contribution_eur'], 2, '.', ',')) ?></strong></p></div>
<div><h3>Grant record</h3><p>Grant agreement: <a href="<?= h($p['source_url']) ?>"><?= h($p['id']) ?></a><br>Scheduled: <time datetime="<?= h($p['start_date']) ?>"><?= h($dateLabel($p['start_date'])) ?></time> – <time datetime="<?= h($p['end_date']) ?>"><?= h($dateLabel($p['end_date'])) ?></time><br>CORDIS status: <?= h($p['cordis_status']) ?><br>CORDIS record updated: <?= h($p['source_updated'] ?: 'Not reported') ?></p></div>
</div>
<?php if ($note): ?><p><?= h($note['limit']) ?></p><?php endif; ?>
<div class="detail-actions"><a class="primary" href="<?= h($p['source_url']) ?>">View CORDIS project</a><a class="secondary" href="<?= h($p['source_url'] . '/results') ?>">CORDIS reported outputs</a><a class="secondary" href="<?= h('./?search=' . rawurlencode($p['acronym']) . '#papers') ?>">Search publications for <?= h($note['label'] ?? $p['acronym']) ?></a></div>
<p>Publication search matches the project acronym; matches require source checking and may be absent. CORDIS outputs may include protocols, reviews or other work, rather than trial results.</p>
</article>
<?php endforeach; ?>
<section class="panel detail-card" id="coverage">
<h2>Coverage, provenance and automatic updates</h2>
<p>This directory uses the European Commission’s <a href="https://cordis.europa.eu/about/services">public CORDIS data service</a>. Daily checks discover and refresh project records matching <code>psilocybin OR psilocin</code>; a further check requires one of these terms in the project title, objective or keywords. This is a keyword-defined directory, not a complete inventory of European psychedelic research or all European funding sources.</p>
<p>Grant identity, title, coordinator, programme, dates, EU contribution and source-update date come from CORDIS XML. Short research summaries were reviewed on 16 October 2026 and are maintained separately. Newly discovered projects can appear with source metadata before a summary is prepared. Downloads contain grant metadata and the successful source-check timestamp; source objectives and publication abstracts are not redistributed.</p>
<p>The refresh validates every page, the total number of records, project identities and required fields before replacing the snapshot. Failed, incomplete or unexpectedly reduced responses retain the previous complete copy for review. A successful check date means the source was checked, not that every project changed. Sources may lag current project activity.</p>
<p>Scheduled-period labels are calculated from CORDIS start and end dates. They do not establish that a project is running, completed, recruiting or publishing results. EU contribution is the grant amount reported by CORDIS, not verified spending, total project cost or a market valuation.</p>
<p><a href="https://cordis.europa.eu/search?<?= h(http_build_query(['q'=>EuropeanProjects::QUERY])) ?>">Run this search on CORDIS</a> · <a href="editorial-policy.php">Editorial and correction policy</a> · <a href="for-stakeholders/">Research planning for stakeholders</a> · <a href="trial-pipeline.php">Clinical-trial pipeline</a></p>
</section>
</main><?= detail_footer() ?><?= detail_scroll_top() ?></body></html>
