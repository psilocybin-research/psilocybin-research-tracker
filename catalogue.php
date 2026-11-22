<?php
declare(strict_types=1);
require_once __DIR__ . '/src/helpers.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Expose-Headers: X-Publication-Tracker-Export-Count, X-Publication-Tracker-Total, X-Publication-Tracker-Complete, X-Publication-Tracker-Generated-At, X-Publication-Tracker-SHA256');
header('X-Robots-Tag: noindex, follow');
header('Cache-Control: no-store');
header('Content-Type: application/json; charset=utf-8');
header('Link: <https://psilocybin-research.com/for-ai.txt>; rel="describedby"');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    echo '{"error":"Use GET or HEAD."}';
    exit;
}
$format = is_string($_GET['format'] ?? 'json') ? strtolower($_GET['format'] ?? 'json') : '';
$unknown = array_filter(array_keys($_GET), static fn($key): bool => $key !== 'format' && !str_starts_with((string)$key, 'utm_'));
if (!in_array($format, ['json', 'txt'], true) || $unknown) {
    http_response_code(400);
    if ($method !== 'HEAD') echo '{"error":"Use format=json or format=txt. This complete catalogue does not accept filters or pagination; use /api/v1/papers for searches."}';
    exit;
}
RequestWorkload::protect();
try {
    $db = new Database();
    $db->initialize();
    $snapshot = CatalogueDownload::snapshot($db);
    $content = $format === 'txt' ? CatalogueDownload::text($snapshot) : CatalogueDownload::encode($snapshot);
} catch (OverflowException $error) {
    http_response_code(413);
    if ($method !== 'HEAD') echo '{"error":"Catalogue exceeds this endpoint capacity; use /database.php for the complete public SQLite download."}';
    exit;
} catch (Throwable $error) {
    http_response_code(503);
    header('Retry-After: 10');
    if ($method !== 'HEAD') echo '{"error":"Complete catalogue temporarily unavailable. Retry later; no partial result was returned."}';
    exit;
}
header('Content-Type: ' . ($format === 'txt' ? 'text/plain' : 'application/json') . '; charset=utf-8');
header('Content-Disposition: inline; filename="psilocybin_year_title.' . $format . '"');
header('Content-Length: ' . strlen($content));
header('X-Publication-Tracker-Export-Count: ' . $snapshot['record_count']);
header('X-Publication-Tracker-Total: ' . $snapshot['total_records']);
header('X-Publication-Tracker-Complete: true');
header('X-Publication-Tracker-Generated-At: ' . $snapshot['generated_at_utc']);
header('X-Publication-Tracker-SHA256: ' . hash('sha256', $content));
if ($method !== 'HEAD') echo $content;
