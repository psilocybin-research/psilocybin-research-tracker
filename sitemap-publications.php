<?php
declare(strict_types=1);

require_once __DIR__ . '/src/helpers.php';
header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=3600, stale-while-revalidate=86400');
$db = new Database();
$db->initialize();
$baseUrl = rtrim(Config::publicBaseUrl(), '/') . '/';
$rows = $db->pdo()->query(
    'SELECT id FROM publications WHERE hidden = 0 AND false_positive = 0 ORDER BY id'
)->fetchAll();
$xml = static fn(string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($rows as $row): ?>
  <url><loc><?= $xml($baseUrl . 'publication.php?id=' . (int)$row['id']) ?></loc></url>
<?php endforeach; ?>
</urlset>
