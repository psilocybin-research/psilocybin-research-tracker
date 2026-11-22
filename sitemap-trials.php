<?php
declare(strict_types=1);

require_once __DIR__ . '/src/helpers.php';
header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=3600, stale-while-revalidate=86400');
$baseUrl = rtrim(Config::publicBaseUrl(), '/') . '/';
$landings = require __DIR__ . '/config/trial-seo-landings.php';
$paths = ['trials.php', 'recruiting-psilocybin-clinical-trials/', 'trials-map.php'];
foreach ($landings as $landing) $paths[] = (string)$landing['path'];
$xml = static fn(string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($paths as $path): ?>
  <url><loc><?= $xml($baseUrl . $path) ?></loc></url>
<?php endforeach; ?>
</urlset>
