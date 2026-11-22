<?php
declare(strict_types=1);

require_once __DIR__ . '/src/helpers.php';
WebRequestSecurity::protectPost(true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$db = new Database();
$db->initialize();
$push = new PushService($db, new PublicationRepository($db));
$action = request_value('action', 'public-key');

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'form') {
        header('Content-Type: text/html; charset=utf-8');
        $repo = new PublicationRepository($db);
        echo PushPreferencesView::render(array_column($repo->journals(), 'journal'), array_column($repo->topics(), 'name'));
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'public-key') {
        echo json_encode(['publicKey' => $push->publicKey()], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
        exit;
    }

    WebRequestSecurity::rateLimit([['push-ip', (string)($_SERVER['REMOTE_ADDR'] ?? ''), 30, 600]], true);
    $raw = file_get_contents('php://input', false, null, 0, 32769);
    if ($raw === false || strlen($raw) > 32768) throw new InvalidArgumentException('Request too large.');
    $input = json_decode($raw, true);
    if (!is_array($input)) {
        throw new InvalidArgumentException('Invalid JSON payload.');
    }

    if ($action === 'subscribe') {
        echo json_encode($push->subscribe($input, $_SERVER['HTTP_USER_AGENT'] ?? null), JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'preferences') {
        echo json_encode($push->preferences($input), JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'test') {
        $result = $push->testNotification($input);
        if (!$result['ok']) http_response_code(502);
        echo json_encode($result, JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'unsubscribe') {
        $push->preferences($input);
        echo json_encode(['ok' => $push->unsubscribe((string)($input['endpoint'] ?? ''))], JSON_UNESCAPED_SLASHES);
        exit;
    }

    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Unknown push action']);
} catch (Throwable $e) {
    http_response_code($e instanceof InvalidArgumentException ? 400 : 500);
    OperationalLogger::exception('push.endpoint_failed', $e, ['action' => $action]);
    echo json_encode(['ok' => false, 'message' => $e instanceof InvalidArgumentException ? $e->getMessage() : 'Notification service temporarily unavailable.'], JSON_UNESCAPED_SLASHES);
}
