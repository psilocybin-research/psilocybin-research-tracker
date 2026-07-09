<?php
declare(strict_types=1);

require_once __DIR__ . '/src/helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$db = new Database();
$db->initialize();
$push = new PushService($db, new PublicationRepository($db));
$action = request_value('action', 'public-key');

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'public-key') {
        echo json_encode(['publicKey' => $push->publicKey()], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
        exit;
    }

    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new InvalidArgumentException('Invalid JSON payload.');
    }

    if ($action === 'subscribe') {
        echo json_encode($push->subscribe($input, $_SERVER['HTTP_USER_AGENT'] ?? null), JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'unsubscribe') {
        echo json_encode(['ok' => $push->unsubscribe((string)($input['endpoint'] ?? ''))], JSON_UNESCAPED_SLASHES);
        exit;
    }

    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Unknown push action']);
} catch (Throwable $e) {
    http_response_code(400);
    OperationalLogger::exception('push.endpoint_failed', $e, ['action' => $action]);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
}
