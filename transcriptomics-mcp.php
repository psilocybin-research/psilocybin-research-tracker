<?php
declare(strict_types=1);
require_once __DIR__ . '/src/helpers.php';
// A public MCP endpoint: no cookies, login or caller-controlled outbound URLs.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ResearchMcpHttp::originAllowed((string)($_SERVER['HTTP_ORIGIN'] ?? ''))) {
    header('Cache-Control: no-store');
    WebRequestSecurity::rateLimit([['mcp-client', (string)($_SERVER['REMOTE_ADDR'] ?? ''), 120, 60], ['mcp-global', 'public', 1200, 60]], true);
    RequestWorkload::protect('api', 4);
}
$body = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? (string)file_get_contents('php://input', false, null, 0, ResearchMcpHttp::MAX_BODY + 1) : '';
$result = ResearchMcpHttp::handle($_SERVER, $body, new ResearchMcpServer(null, 'transcriptomics'));
http_response_code($result['status']);
foreach ($result['headers'] as $name => $value) header($name . ': ' . $value);
echo $result['body'];
