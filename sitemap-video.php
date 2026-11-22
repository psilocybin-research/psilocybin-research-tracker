<?php
declare(strict_types=1);
require_once __DIR__ . '/src/helpers.php';
if (!headers_sent()) {
    header('Content-Type: application/xml; charset=UTF-8');
    header('Cache-Control: public, max-age=3600');
}
$xml = static fn(string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">
<url><loc><?= $xml(ResearchFilm::url()) ?></loc><lastmod><?= $xml(substr(ResearchFilm::uploaded(), 0, 10)) ?></lastmod><video:video>
<video:thumbnail_loc><?= $xml(ResearchFilm::url(ResearchFilm::ASSETS . 'desktop-poster.jpg')) ?></video:thumbnail_loc>
<video:title><?= $xml(ResearchFilm::NAME) ?></video:title>
<video:description><?= $xml(ResearchFilm::description()) ?></video:description>
<video:content_loc><?= $xml(ResearchFilm::url(ResearchFilm::ASSETS . 'psilocybin-research-desktop.mp4')) ?></video:content_loc>
<video:duration><?= (int)ResearchFilm::data()['film_duration_seconds'] ?></video:duration><video:publication_date><?= $xml(ResearchFilm::uploaded()) ?></video:publication_date><video:family_friendly>yes</video:family_friendly>
</video:video></url></urlset>
