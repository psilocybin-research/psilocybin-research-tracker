<?php
declare(strict_types=1);

require_once __DIR__ . '/src/helpers.php';
require_once __DIR__ . '/msca-impact/i18n.php';
header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=3600, stale-while-revalidate=86400');
$baseUrl = rtrim(Config::publicBaseUrl(), '/') . '/';
$pages = [
    ['', 'index.php'],
    ['msca-impact/credits.php', 'msca-impact/credits.php'],
    ['msca-impact/official-sources.php', 'msca-impact/official-sources.php'],
    ['psilocybin-cancer-anxiety-research/', 'psilocybin-cancer-anxiety-research.php'],
    ['psilocybin-smoking-cessation-research/', 'psilocybin-smoking-cessation-research.php'],
    ['evidence.php', 'evidence.php'],
    ['psilocybin-studies/', 'psilocybin-studies.php'],
    ['psilocybin-papers/', 'psilocybin-papers.php'],
    ['research-api.php', 'research-api.php'],
    ['research-questions.php', 'research-questions.php'],
    ['research-library.php', 'research-library.php'],
    ['research-results.php', 'research-results.php'],
    ['germany-psilocybin-research.php', 'germany-psilocybin-research.php'],
    ['research-network.php', 'research-network.php'],
    ['trial-pipeline.php', 'trial-pipeline.php'],
    ['research-updates.php', 'research-updates.php'],
    ['research-economics.php', 'research-economics.php'],
    ['for-stakeholders/', 'for-stakeholders.php'],
    ['european-psilocybin-research-projects/', 'european-projects.php'],
    ['research-countries.php', 'research-countries.php'],
    ['research-hub.php', 'research-hub.php'],

    ['psilocybin-research-guide/', 'psilocybin-research-guide.php'],
    ['psilocybin-alcohol-use-disorder-research/', 'psilocybin-alcohol-use-disorder-research.php'],
    ['psilocybin-depression-research/', 'psilocybin-depression-research.php'],
    ['psilocybin-research-brief-2026-10/', 'research-brief-2026-10.php'],
    ['open-datasets.php', 'open-datasets.php'],
    ['authors.php', 'authors.php'],
    ['citation-network.php', 'citation-network.php'],
    ['about.php', 'about.php'],
    ['credits.php', 'credits.php'],
    ['fungi-gallery.php', 'fungi-gallery.php'],
    ['install.php', 'install.php'],
    ['research-dataset.php', 'research-dataset.php'],
    ['r-analysis/', 'r-analysis/index.php'],
    ['shinyapp-live', 'apps/research-explorer/index.php'],
    ['research-trends.php', 'research-trends.php'],
    ['editorial-policy.php', 'editorial-policy.php'],
    ['data-protection.php', 'data-protection.php'],
];
foreach ([1, 2] as $podcastEpisode) {
    foreach (['en', 'de'] as $podcastLanguage) {
        $path = 'msca-impact/' . ($podcastLanguage === 'de' ? 'de/' : '') . 'podcast-' . $podcastEpisode . '-transcript/';
        $pages[] = [$path, 'msca-impact/podcast-transcript.php'];
    }
}
foreach (msca_locales() as $locale => $config) {
    $pages[] = ['msca-impact/' . ($locale === 'en' ? '' : $locale . '/'), 'msca-impact/index.php'];
}
// Explicit substantive modification dates; asset/cache changes are not content freshness.
$modified = array_fill_keys(['', 'evidence.php', 'psilocybin-depression-research/', 'psilocybin-alcohol-use-disorder-research/', 'psilocybin-research-guide/', 'psilocybin-research-brief-2026-10/', 'research-dataset.php', 'research-trends.php'], '2026-10-07');
$modified['credits.php'] = '2026-11-04';
$modified['evidence.php'] = '2026-10-11';
$modified['research-trends.php'] = '2026-10-11';
$modified['psilocybin-smoking-cessation-research/'] = '2026-10-11';
$modified = array_replace($modified, array_fill_keys(['', 'research-library.php', 'research-results.php', 'germany-psilocybin-research.php', 'research-network.php', 'trial-pipeline.php', 'research-updates.php', 'research-economics.php', 'research-countries.php', 'research-hub.php'], '2026-10-09'));
$projectSnapshot = EuropeanProjects::snapshot();
$modified['european-psilocybin-research-projects/'] = max('2026-10-16', substr((string)($projectSnapshot['content_changed_at'] ?? ''), 0, 10));
$modified['for-stakeholders/'] = '2026-10-11';
$modified['research-hub.php'] = '2026-10-11';
$modified['psilocybin-research-guide/'] = '2026-10-11';
$modified[''] = '2026-10-11';
$modified['research-api.php'] = '2026-10-11';
$modified['data-protection.php'] = '2026-10-12';
$modified['msca-impact/credits.php'] = '2026-10-16';
$modified['psilocybin-studies/'] = '2026-10-11';
$modified['psilocybin-papers/'] = '2026-10-11';
$modified['research-questions.php'] = '2026-10-09';
$modified += array_fill_keys(['psilocybin-studies/','psilocybin-papers/','research-api.php','research-dataset.php','data-protection.php'], '2026-10-09');
$modified['research-dataset.php'] = '2026-10-09';
$modified += array_fill_keys(['psilocybin-cancer-anxiety-research/','psilocybin-smoking-cessation-research/'],'2026-10-09');
foreach (msca_locales() as $locale => $config) {
    $modified['msca-impact/' . ($locale === 'en' ? '' : $locale . '/')] = '2026-11-02';
}
foreach (['', 'about.php', 'psilocybin-research-guide/', 'research-trends.php', 'psilocybin-studies/'] as $path) $modified[$path] = '2026-10-12';
foreach ([1, 2] as $podcastEpisode) {
    foreach (['', 'de/'] as $podcastLanguage) {
        $modified['msca-impact/' . $podcastLanguage . 'podcast-' . $podcastEpisode . '-transcript/'] = '2026-10-26';
    }
}
$xml = static fn(string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
<?php foreach ($pages as [$path, $file]): ?>
  <url><loc><?= $xml($baseUrl . $path) ?></loc><?php if (isset($modified[$path])): ?><lastmod><?= $xml($modified[$path]) ?></lastmod><?php endif; ?><?php if ($path === 'msca-impact/' || preg_match('~^msca-impact/[a-z]{2}(?:-[a-z]+)?/$~D', $path)): ?>
<?php foreach (msca_locales() as $alternateLocale => $alternate): ?>
    <xhtml:link rel="alternate" hreflang="<?= $xml($alternate['lang']) ?>" href="<?= $xml(msca_locale_url($baseUrl, $alternateLocale)) ?>"/>
<?php endforeach; ?>
    <xhtml:link rel="alternate" hreflang="x-default" href="<?= $xml(msca_locale_url($baseUrl, 'en')) ?>"/>
<?php endif; ?><?php if (preg_match('~^msca-impact/(?:de/)?podcast-([12])-transcript/$~D', $path, $podcastMatch)): ?>
    <xhtml:link rel="alternate" hreflang="en" href="<?= $xml($baseUrl . 'msca-impact/podcast-' . $podcastMatch[1] . '-transcript/') ?>"/>
    <xhtml:link rel="alternate" hreflang="de" href="<?= $xml($baseUrl . 'msca-impact/de/podcast-' . $podcastMatch[1] . '-transcript/') ?>"/>
    <xhtml:link rel="alternate" hreflang="x-default" href="<?= $xml($baseUrl . 'msca-impact/podcast-' . $podcastMatch[1] . '-transcript/') ?>"/>
<?php endif; ?></url>
<?php endforeach; ?>
</urlset>
