<?php
declare(strict_types=1);

require_once __DIR__ . '/src/helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $db = new Database();
    $db->initialize();
    $repo = new PublicationRepository($db);
    $runs = new FetchRunRepository($db);
    $latest = $runs->latestSuccessful();
    $lastUpdated = $latest['finished_at'] ?? null;
    $updatedAt = $lastUpdated ? DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string)$lastUpdated, new DateTimeZone('UTC')) : null;
    $ageSeconds = $updatedAt ? (time() - $updatedAt->getTimestamp()) : null;
    $pending = $ageSeconds === null || $ageSeconds > 32 * 3600;

    echo json_encode([
        'ok' => true,
        'last_updated' => $lastUpdated,
        'last_updated_display' => format_utc_display($lastUpdated),
        'pending' => $pending,
        'message' => $pending ? 'Latest automated update is pending.' : 'Latest automated update has run.',
        'stats' => $repo->stats(),
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    OperationalLogger::exception('status.failed', $e);
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Unable to check update status right now.',
    ], JSON_UNESCAPED_SLASHES);
}
