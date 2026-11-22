<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
$definitions = require __DIR__ . '/../config/research-explorer-pages.php';
if (!isset($definitions[$explorerPage ?? ''])) { http_response_code(404); exit; }
[$label, $title, $description] = $definitions[$explorerPage];
if (!in_array($definitions[$explorerPage]['view'], ['hub', 'api'], true)) {
    $db = new Database(); $db->initialize();
    $repo = new PublicationRepository($db); $explorer = new ResearchExplorer($db);
}
$path = in_array($explorerPage, ['psilocybin-studies','psilocybin-papers'], true) ? $explorerPage . '/' : $explorerPage . '.php'; $url = LinkedData::base() . $path;
$assetVersion = '20261013-offline-no-video-v267';
$filters = [];
foreach (['q', 'country', 'sponsor', 'phase', 'status', 'condition', 'topic', 'study_type'] as $key) $filters[$key] = mb_substr(is_scalar($_GET[$key] ?? '') ? trim((string)($_GET[$key] ?? '')) : '', 0, 240);
if ($explorerPage === 'germany-psilocybin-research') $filters['country'] = 'Germany';
$robots = $_GET || $explorerPage === 'research-workspace' ? 'noindex, follow' : 'index, follow, max-image-preview:large, max-snippet:-1';
if (str_contains($robots, 'noindex') && !headers_sent()) header('X-Robots-Tag: noindex, follow');
$schema = LinkedData::document([LinkedData::website(), ['@type' => 'CollectionPage', '@id' => $url . '#page', 'name' => $title, 'description' => $description, 'url' => $url, 'isPartOf' => ['@id' => LinkedData::base() . '#website']], ['@type' => 'BreadcrumbList', 'itemListElement' => [['@type' => 'ListItem', 'position' => 1, 'name' => 'Research database', 'item' => LinkedData::base()], ['@type' => 'ListItem', 'position' => 2, 'name' => $label, 'item' => $url]]]]);
require __DIR__ . '/../templates/research/_helpers.php';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><base href="/"><title><?= h($title) ?></title>
<?= detail_page_seo($title, $description, $path, ['robots' => $robots]) ?>
<?= json_ld_script($schema) ?>
<link rel="stylesheet" href="<?= h(FrontendAssets::url('assets/styles.min.css')) ?>"><script src="<?= h(FrontendAssets::url('assets/app.min.js')) ?>" defer></script><?php if (in_array($definitions[$explorerPage]['view'], ['network', 'trials', 'results', 'updates', 'library', 'workspace', 'economics'], true)): ?><script src="<?= h(FrontendAssets::url('assets/research-explorer.min.js')) ?>" defer></script><?php endif; ?><?php if ($definitions[$explorerPage]['view'] === 'trials'): ?><script src="<?= h(FrontendAssets::url('assets/features/trial-pipeline.min.js')) ?>" defer></script><?php endif; ?></head>
<body class="detail-page explorer-page"><?= detail_page_header($explorerPage) ?><main class="detail-shell" id="main">
<nav class="recruiting-breadcrumbs" aria-label="Breadcrumb"><a href="./">Research database</a><span aria-hidden="true">/</span><span aria-current="page"><?= h($label) ?></span></nav>
<header class="panel detail-hero explorer-hero"><span class="eyebrow">Open research · Psilocybin &amp; psilocin</span><h1><?= h($label) ?></h1><p><?= h($description) ?></p><p class="explorer-access">Free access · No subscription for research tools or data exports</p></header>
<nav class="explorer-tabs" aria-label="Research tasks"><a href="./#papers">Find research</a> <a href="research-results.php">Evaluate evidence</a> <a href="research-dataset.php">Reuse and cite</a></nav>
<?php require __DIR__ . '/../templates/research/' . $definitions[$explorerPage]['view'] . '.php'; ?>

</main><div class="explorer-toast" role="status" data-explorer-toast hidden></div><?= detail_footer() ?><?= detail_scroll_top() ?></body></html>
