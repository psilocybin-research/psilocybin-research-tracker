<?php
declare(strict_types=1);

/** Browser write protection; public read-only APIs keep their existing CORS policy. */
final class WebRequestSecurity
{
    public static function originAllowed(array $server, string $base): bool
    {
        if (strtolower((string)($server['HTTP_SEC_FETCH_SITE'] ?? '')) === 'cross-site') return false;
        $origin = (string)($server['HTTP_ORIGIN'] ?? '');
        return $origin === '' || rtrim($origin, '/') === rtrim($base, '/');
    }

    public static function protectPost(bool $json = false, int $maximum = 32768): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;
        if (!self::originAllowed($_SERVER, Config::publicBaseUrl())) self::reject(403, 'Please submit this request from the research website.', $json);
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maximum) self::reject(413, 'Request too large.', $json);
        if ($json && strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0])) !== 'application/json') self::reject(415, 'JSON required.', true);
    }

    public static function reject(int $status, string $message, bool $json = false, int $retry = 0): never
    {
        http_response_code($status);
        header('Cache-Control: no-store');
        header('Content-Type: ' . ($json ? 'application/json' : 'text/plain') . '; charset=utf-8');
        if ($retry) header('Retry-After: ' . $retry);
        echo $json ? json_encode(['ok' => false, 'message' => $message]) : $message;
        exit;
    }

    /** A single bounded private ledger contains only keyed identities and counters. */
    public static function rateLimit(array $rules, bool $json = false): void
    {
        $result = self::reserve($rules, Config::dataDir() . '/security-request-limits.json', Config::sensitiveDataKey(), time());
        if ($result > 0) self::reject(429, 'Too many requests. Please try again later.', $json, $result);
    }

    public static function reserve(array $rules, string $path, string $secret, int $now): int
    {
        $file = @fopen($path, 'c+');
        if (!$file) throw new RuntimeException('Request protection unavailable.');
        try {
            if (!flock($file, LOCK_EX)) throw new RuntimeException('Request protection unavailable.');
            chmod($path, 0600);
            if (fstat($file)['size'] > 1048576) throw new RuntimeException('Request protection unavailable.');
            $raw = stream_get_contents($file);
            $entries = $raw === '' ? [] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($entries)) throw new RuntimeException('Request protection unavailable.');
            $entries = array_filter($entries, static fn($row): bool => is_array($row) && (int)($row['reset'] ?? 0) > $now);
            $pending = [];
            foreach ($rules as [$scope, $identity, $maximum, $seconds]) {
                if ($maximum < 1 || $seconds < 1 || $seconds > 86400) throw new InvalidArgumentException('Invalid request limit.');
                $key = hash_hmac('sha256', $scope . "\0" . $identity, $secret);
                $entry = $entries[$key] ?? ['count' => 0, 'reset' => $now + $seconds];
                if ($entry['count'] >= $maximum) return max(1, $entry['reset'] - $now);
                $entry['count']++; $pending[$key] = $entry;
            }
            if (count($entries + $pending) > 5000) return 60;
            $bytes = json_encode(array_replace($entries, $pending), JSON_THROW_ON_ERROR) . "\n";
            rewind($file);
            if (!ftruncate($file, 0) || fwrite($file, $bytes) !== strlen($bytes) || !fflush($file)) throw new RuntimeException('Request protection unavailable.');
            return 0;
        } finally { flock($file, LOCK_UN); fclose($file); }
    }
}
