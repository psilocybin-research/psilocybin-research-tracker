<?php
declare(strict_types=1);

require_once __DIR__ . '/src/helpers.php';

header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=3600, stale-while-revalidate=86400');

$baseUrl = rtrim(Config::publicBaseUrl(), '/') . '/';
$staticLastmod = gmdate('Y-m-d', max(array_map(
    static fn(string $file): int => (int)(filemtime(__DIR__ . '/' . $file) ?: 0),
    ['index.php', 'r-analysis/index.php', 'about.php', 'editorial-policy.php', 'data-protection.php', 'evidence.php', 'authors.php', 'citation-network.php', 'msca-impact/index.php', 'msca-impact/i18n.php']
)));
$xml = static fn(string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <sitemap><loc><?= $xml($baseUrl . 'sitemap-static.xml') ?></loc><lastmod><?= $xml($staticLastmod) ?></lastmod></sitemap>
  <sitemap><loc><?= $xml($baseUrl . 'sitemap-video.xml') ?></loc><lastmod>2026-10-11</lastmod></sitemap>
  <sitemap><loc><?= $xml($baseUrl . 'sitemap-trials.xml') ?></loc></sitemap>
  <sitemap><loc><?= $xml($baseUrl . 'sitemap-publications.xml') ?></loc></sitemap>
</sitemapindex>
