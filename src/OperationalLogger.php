<?php
declare(strict_types=1);

final class OperationalLogger
{
    private const MAX_VALUE_LENGTH = 700;

    public static function info(string $event, array $context = []): void
    {
        self::write('info', $event, $context);
    }

    public static function warning(string $event, array $context = []): void
    {
        self::write('warning', $event, $context);
    }

    public static function error(string $event, array $context = []): void
    {
        self::write('error', $event, $context);
    }

    public static function exception(string $event, Throwable $e, array $context = []): void
    {
        self::error($event, array_merge($context, [
            'exception_class' => $e::class,
            'message' => $e->getMessage(),
        ]));
    }

    private static function write(string $level, string $event, array $context): void
    {
        $record = [
            'ts' => current_utc(),
            'level' => $level,
            'event' => self::sanitizeKey($event),
            'context' => self::sanitizeContext($context),
        ];
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        $file = Config::logFile();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    private static function sanitizeContext(array $context): array
    {
        $clean = [];
        foreach ($context as $key => $value) {
            $key = self::sanitizeKey((string)$key);
            if (preg_match('/token|password|secret|key/i', $key)) {
                $clean[$key] = '[redacted]';
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $clean[$key] = self::sanitizeValue($value);
                continue;
            }
            if (is_array($value)) {
                $clean[$key] = self::sanitizeArray($value);
                continue;
            }
            $clean[$key] = self::sanitizeValue((string)$value);
        }
        return $clean;
    }

    private static function sanitizeArray(array $items): array
    {
        $clean = [];
        foreach (array_slice($items, 0, 20, true) as $key => $value) {
            $clean[(string)$key] = is_scalar($value) || $value === null ? self::sanitizeValue($value) : '[complex]';
        }
        return $clean;
    }

    private static function sanitizeKey(string $key): string
    {
        $key = preg_replace('/[^a-zA-Z0-9_.:-]+/', '_', trim($key)) ?? 'event';
        return trim($key, '_') ?: 'event';
    }

    private static function sanitizeValue(mixed $value): string|int|float|bool|null
    {
        if ($value === null || is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }
        $value = preg_replace('/\s+/', ' ', (string)$value) ?? '';
        return mb_substr($value, 0, self::MAX_VALUE_LENGTH);
    }
}
