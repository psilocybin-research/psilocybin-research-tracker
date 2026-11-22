<?php
declare(strict_types=1);

require_once __DIR__ . '/src/helpers.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new InvalidArgumentException('Method not allowed.');
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 2048) throw new InvalidArgumentException('Request too large.');
    $body = file_get_contents('php://input', false, null, 0, 2049);
    if ($body === false || strlen($body) > 2048) throw new InvalidArgumentException('Request too large.');
    $input = json_decode($body, true);
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && rtrim($origin, '/') !== rtrim(Config::publicBaseUrl(), '/')) throw new InvalidArgumentException('Invalid origin.');
    if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') throw new InvalidArgumentException('Invalid origin.');
    if (!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) throw new InvalidArgumentException('JSON required.');
    if (!is_array($input) || array_is_list($input)) throw new InvalidArgumentException('Invalid input.');
    if (InternalTraffic::requestExcluded()) { echo json_encode(['ok' => true, 'excluded' => true]); exit; }
    $db = new Database();
    $db->initialize();
    PwaMetrics::record($db->pdo(), $input);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code($e instanceof InvalidArgumentException ? 400 : 500);
    if (!$e instanceof InvalidArgumentException) OperationalLogger::exception('engagement.endpoint_failed', $e);
    echo json_encode(['ok' => false]);
}
