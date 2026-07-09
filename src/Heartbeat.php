<?php
declare(strict_types=1);

final class Heartbeat
{
    public static function beat(string $name, string $status, array $context = []): void
    {
        $name = self::normalizeName($name);
        $payload = [
            'name' => $name,
            'status' => $status,
            'updated_at' => current_utc(),
            'context' => self::cleanContext($context),
        ];
        $path = self::path($name);
        $tmp = $path . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
        rename($tmp, $path);
    }

    public static function read(string $name): ?array
    {
        $path = self::path(self::normalizeName($name));
        if (!is_file($path)) {
            return null;
        }
        $json = file_get_contents($path);
        if ($json === false) {
            return null;
        }
        $payload = json_decode($json, true);
        return is_array($payload) ? $payload : null;
    }

    public static function all(): array
    {
        $items = [];
        foreach (glob(Config::heartbeatDir() . '/*.json') ?: [] as $path) {
            $json = file_get_contents($path);
            $payload = $json === false ? null : json_decode($json, true);
            if (is_array($payload)) {
                $items[] = $payload;
            }
        }
        usort($items, fn (array $a, array $b): int => strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
        return $items;
    }

    private static function path(string $name): string
    {
        return Config::heartbeatDir() . '/' . $name . '.json';
    }

    private static function normalizeName(string $name): string
    {
        $name = preg_replace('/[^a-zA-Z0-9_.:-]+/', '-', trim($name)) ?? 'heartbeat';
        return trim($name, '-') ?: 'heartbeat';
    }

    private static function cleanContext(array $context): array
    {
        $clean = [];
        foreach ($context as $key => $value) {
            if (preg_match('/token|password|secret|key/i', (string)$key)) {
                $clean[$key] = '[redacted]';
                continue;
            }
            $clean[$key] = is_scalar($value) || $value === null ? $value : '[complex]';
        }
        return $clean;
    }
}
