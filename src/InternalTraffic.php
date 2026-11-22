<?php
declare(strict_types=1);

/** Owner/test exclusions shared by browser events and log-derived usage reports. */
final class InternalTraffic
{
    private static ?string $cachedKey = null;
    private static ?string $cachedKeyPath = null;
    private static function key(): string
    {
        $path = Config::dataDir();
        if (self::$cachedKey === null || self::$cachedKeyPath !== $path) {
            self::$cachedKeyPath = $path;
            self::$cachedKey = Config::sensitiveDataKey();
        }
        return self::$cachedKey;
    }
    private static ?array $fingerprints = null;
    private static ?string $fingerprintPath = null;
    public static function fingerprints(): array
    {
        $path = Config::dataDir() . '/visitor-internal-fingerprints.json';
        if (self::$fingerprints !== null && self::$fingerprintPath === $path) return self::$fingerprints;
        self::$fingerprintPath = $path;
        $data = is_file($path) ? json_decode((string)file_get_contents($path), true) : [];
        return self::$fingerprints = array_values(array_filter($data['fingerprints'] ?? [], static fn($v) => is_string($v) && preg_match('/^[a-f0-9]{64}$/D', $v)));
    }
    public static function addressExcluded(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
        $fingerprints = self::fingerprints();
        return $fingerprints && in_array(hash_hmac('sha256', $ip, Config::sensitiveDataKey()), $fingerprints, true);
    }
    public static function testAgent(string $agent): bool
    {
        return (bool)preg_match('/headless|playwright|selenium|lighthouse|TrackerAudit|TrackerTest/i', $agent);
    }
    public static function cookieValue(): string
    {
        return hash_hmac('sha256', 'tracker-internal-browser-v1', self::key());
    }
    public static function marked(array $cookies): bool
    {
        $value = $cookies['tracker_internal'] ?? null;
        return is_string($value) && strlen($value) === 64 && hash_equals(self::cookieValue(), $value);
    }
    public static function requestExcluded(?array $server = null, ?array $cookies = null): bool
    {
        $server ??= $_SERVER; $cookies ??= $_COOKIE;
        return self::marked($cookies) || self::addressExcluded((string)($server['REMOTE_ADDR'] ?? ''))
            || self::testAgent((string)($server['HTTP_USER_AGENT'] ?? ''))
            || ($server['HTTP_X_TRACKER_TEST'] ?? '') === '1';
    }
    public static function registerAddress(string $ip): void
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP) || self::addressExcluded($ip)) return;
        // Store only the existing keyed fingerprints; never raw network addresses.
        $lock = fopen(Config::dataDir() . '/visitor-statistics-refresh.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Cannot lock internal exclusions.');
        try {
            self::$fingerprints = null;
            $values = self::fingerprints();
            $values[] = hash_hmac('sha256', $ip, self::key());
            $values = array_values(array_unique($values));
            $path = Config::dataDir() . '/visitor-internal-fingerprints.json';
            $tmp = tempnam(Config::dataDir(), '.internal-');
            if (!$tmp) throw new RuntimeException('Cannot prepare internal exclusions.');
            try {
                if (file_put_contents($tmp, json_encode(['fingerprints' => $values, 'updated_at' => gmdate('c')], JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('Cannot save internal exclusions.');
                chmod($tmp, 0640);
                if (!rename($tmp, $path)) throw new RuntimeException('Cannot publish internal exclusions.');
            } finally { if (is_file($tmp)) unlink($tmp); }
            self::$fingerprints = $values;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
    private static array $requestMarkers = [];
    private static function requestSignature(string $ip, string $method, string $target): string
    {
        return hash_hmac('sha256', $ip . "\0" . $method . "\0" . $target, self::key());
    }
    public static function registerMarkedRequest(): void
    {
        if (PHP_SAPI === 'cli' || !self::marked($_COOKIE)) return;
        try {
            $dir = Config::dataDir() . '/internal-request-markers';
            if (!is_dir($dir)) mkdir($dir, 0700, true);
            $time = (int)($_SERVER['REQUEST_TIME'] ?? time());
            $signature = self::requestSignature((string)($_SERVER['REMOTE_ADDR'] ?? ''), (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'), (string)($_SERVER['REQUEST_URI'] ?? '/'));
            $path = $dir . '/' . gmdate('Y-m-d', $time) . '.jsonl';
            if (file_put_contents($path, json_encode([$time, $signature], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX) === false) throw new RuntimeException('Cannot record owner request exclusion.');
            chmod($path, 0600);
            unset(self::$requestMarkers[$path]);
        } catch (Throwable $e) { OperationalLogger::exception('internal.exclusion_failed', $e); }
    }
    public static function logRequestExcluded(string $ip, int $time, string $method, string $target, bool $includeNetworks = true): bool
    {
        if ($includeNetworks && self::addressExcluded($ip)) return true;
        $signature = null;
        foreach (array_unique([gmdate('Y-m-d', $time - 1), gmdate('Y-m-d', $time), gmdate('Y-m-d', $time + 1)]) as $date) {
            $path = Config::dataDir() . '/internal-request-markers/' . $date . '.jsonl';
            if (!array_key_exists($path, self::$requestMarkers)) {
                $markers = [];
                if (is_file($path) && ($handle = fopen($path, 'rb'))) {
                    while (($line = fgets($handle)) !== false) {
                        $entry = json_decode($line, true);
                        if (is_array($entry) && count($entry) === 2 && is_int($entry[0]) && is_string($entry[1])) $markers[$entry[0]][$entry[1]] = true;
                    }
                    fclose($handle);
                }
                self::$requestMarkers[$path] = $markers;
            }
            $markers = self::$requestMarkers[$path];
            if (!isset($markers[$time - 1]) && !isset($markers[$time]) && !isset($markers[$time + 1])) continue;
            $signature ??= self::requestSignature($ip, $method, $target);
            if (isset($markers[$time - 1][$signature]) || isset($markers[$time][$signature]) || isset($markers[$time + 1][$signature])) return true;
        }
        return false;
    }
    public static function link(int $until): string
    {
        return Config::publicBaseUrl() . 'internal-traffic.php?until=' . $until . '&key=' . hash_hmac('sha256', 'internal-link|' . $until, self::key());
    }
    public static function validLink(int $until, string $key): bool
    {
        return $until >= time() && $until <= time() + 2 * 86400 && strlen($key) === 64
            && hash_equals(hash_hmac('sha256', 'internal-link|' . $until, Config::sensitiveDataKey()), $key);
    }
}
