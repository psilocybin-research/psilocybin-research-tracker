<?php
declare(strict_types=1);
require_once __DIR__ . '/src/helpers.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300, must-revalidate');
header('Access-Control-Allow-Origin: *');
header('X-Content-Type-Options: nosniff');
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET','HEAD'], true)) {
    http_response_code(405); header('Allow: GET, HEAD'); echo '{"error":"Use GET or HEAD."}'; exit;
}
try {
    $snapshot = new TranscriptomicsSnapshot();
    $body = json_encode($snapshot->manifest(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    header('X-Publication-Tracker-SHA256: ' . hash('sha256', $body));
    header('Access-Control-Expose-Headers: X-Publication-Tracker-SHA256');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') echo $body;
} catch (Throwable $error) {
    error_log('Transcriptomics manifest unavailable: ' . get_class($error));
    http_response_code(503); header('Cache-Control: no-store'); echo '{"error":"Transcriptomics snapshot temporarily unavailable."}';
}
