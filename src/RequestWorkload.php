<?php
declare(strict_types=1);

/** Bound expensive web requests without queues or per-address runtime files. */
final class RequestWorkload
{
    private static array $leases = [];

    public static function acquire(string $pool, int $slots): bool
    {
        if (!preg_match('/^[a-z-]+$/D', $pool)) throw new InvalidArgumentException('Invalid workload pool');
        for ($slot = 0; $slot < $slots; ++$slot) {
            $handle = @fopen(Config::dataDir() . '/workload-' . $pool . '-' . $slot . '.lock', 'c');
            if ($handle === false) continue;
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                self::$leases[] = $handle;
                return true;
            }
            fclose($handle);
        }
        return false;
    }

    public static function protect(string $pool = 'downloads', int $slots = 2): void
    {
        if (PHP_SAPI === 'cli' || self::acquire($pool, $slots)) return;
        http_response_code(429);
        header('Retry-After: 10');
        header('Cache-Control: no-store');
        header('Content-Type: text/plain; charset=utf-8');
        echo 'This service is busy. Please retry in 10 seconds.';
        exit;
    }

    public static function release(): void
    {
        foreach (self::$leases as $handle) { flock($handle, LOCK_UN); fclose($handle); }
        self::$leases = [];
    }
}
