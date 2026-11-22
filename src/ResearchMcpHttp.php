<?php
declare(strict_types=1);

/** JSON-response Streamable HTTP transport; no sessions or long-lived PHP workers. */
final class ResearchMcpHttp
{
    public const MAX_BODY = 32768;
    public static function originAllowed(string $origin): bool
    {
        if ($origin === '') return true; // Server-to-server clients normally send no Origin.
        $url = parse_url(Config::publicBaseUrl());
        $site = ($url['scheme'] ?? 'https') . '://' . ($url['host'] ?? '') . (isset($url['port']) ? ':' . $url['port'] : '');
        return in_array($origin, [$site, 'https://chatgpt.com', 'https://claude.ai', 'https://claude.com'], true);
    }
    private static function acceptsJson(string $accept): bool
    {
        foreach (explode(',', strtolower($accept)) as $entry) {
            $parts = array_map('trim', explode(';', $entry)); $mime = array_shift($parts); $quality = 1.0;
            foreach ($parts as $part) if (str_starts_with($part, 'q=')) $quality = (float)substr($part, 2);
            if (in_array($mime, ['application/json', 'application/*', '*/*'], true) && $quality > 0) return true;
        }
        return false;
    }
    public static function handle(array $server, string $body, ResearchMcpServer $mcp): array
    {
        $headers = ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex, follow', 'Vary' => 'Origin, Accept', 'Link' => '<' . rtrim(Config::publicBaseUrl(), '/') . '/for-ai.html>; rel="describedby"'];
        $reply = static function (int $status, ?array $data = null, array $extra = []) use (&$headers): array { return ['status' => $status, 'headers' => $extra + $headers, 'body' => $data === null ? '' : json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]; };
        $origin = (string)($server['HTTP_ORIGIN'] ?? '');
        if (!self::originAllowed($origin)) return $reply(403, ResearchMcpServer::error(null, -32000, 'Origin is not allowed. Server-side MCP clients may connect without Origin.'));
        if ($origin !== '') $headers['Access-Control-Allow-Origin'] = $origin;
        $headers['Access-Control-Allow-Methods'] = 'POST, OPTIONS';
        $headers['Access-Control-Allow-Headers'] = 'Content-Type, Accept, MCP-Protocol-Version';
        $headers['Access-Control-Expose-Headers'] = 'MCP-Protocol-Version, Retry-After';
        $method = $server['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'OPTIONS') {
            $requested = array_filter(array_map('trim', explode(',', strtolower((string)($server['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? '')))));
            if (array_diff($requested, ['content-type', 'accept', 'mcp-protocol-version']) || !in_array($server['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] ?? 'POST', ['POST', 'OPTIONS'], true)) return $reply(403, ResearchMcpServer::error(null, -32000, 'Preflight method or headers are not allowed.'));
            return $reply(204);
        }
        if ($method !== 'POST') return $reply(405, ResearchMcpServer::error(null, -32000, 'Use MCP Streamable HTTP POST. No standalone SSE stream or session deletion is offered. Read /for-ai.html for setup.'), ['Allow' => 'POST, OPTIONS']);
        if (strlen($body) > self::MAX_BODY || (int)($server['CONTENT_LENGTH'] ?? 0) > self::MAX_BODY) return $reply(413, ResearchMcpServer::error(null, -32000, 'MCP request exceeds 32 KiB.'));
        if (strtolower(trim(explode(';', (string)($server['CONTENT_TYPE'] ?? ''))[0])) !== 'application/json') return $reply(415, ResearchMcpServer::error(null, -32000, 'Send application/json.'));
        if (!self::acceptsJson((string)($server['HTTP_ACCEPT'] ?? ''))) return $reply(406, ResearchMcpServer::error(null, -32000, 'Accept application/json and text/event-stream. This server returns JSON responses.'));
        $protocol = (string)($server['HTTP_MCP_PROTOCOL_VERSION'] ?? '2025-03-26');
        if (!in_array($protocol, ResearchMcpServer::PROTOCOLS, true)) return $reply(400, ResearchMcpServer::error(null, -32600, 'Unsupported MCP-Protocol-Version. Initialize to negotiate a supported version.'));
        try { $message = json_decode($body, false, 32, JSON_THROW_ON_ERROR); }
        catch (JsonException $error) { return $reply(400, ResearchMcpServer::error(null, -32700, 'Invalid JSON.')); }
        if (!($message instanceof stdClass)) return $reply(400, ResearchMcpServer::error(null, -32600, 'Send one JSON-RPC object, not a batch.'));
        // The server never sends requests, but valid client responses are accepted and ignored.
        if (!property_exists($message, 'method') && ($message->jsonrpc ?? '') === '2.0' && (is_string($message->id ?? null) || is_int($message->id ?? null)) && (property_exists($message, 'result') xor property_exists($message, 'error'))) return $reply(202);
        $response = $mcp->dispatch($message, $protocol);
        if ($response === null) return $reply(202);
        if (($response['error']['code'] ?? null) === -32600) return $reply(400, $response);
        if (($message->method ?? '') === 'initialize' && isset($response['result']['protocolVersion'])) $headers['MCP-Protocol-Version'] = $response['result']['protocolVersion'];
        else $headers['MCP-Protocol-Version'] = $protocol;
        return $reply(200, $response);
    }
}
