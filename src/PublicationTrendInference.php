<?php
declare(strict_types=1);

/** Read only inference computed for exactly the current complete-year series. */
final class PublicationTrendInference
{
    public const METHOD = 'bai-perron-hac-qs-v1';

    public static function rows(array $rows): array
    {
        $rows = array_values(array_map(static fn(array $r): array => ['year' => (int)$r['year'], 'count' => (int)$r['count']],
            array_filter($rows, static fn(array $r): bool => (int)$r['year'] < (int)gmdate('Y') && (int)$r['count'] > 0)));
        usort($rows, static fn(array $a, array $b): int => $a['year'] <=> $b['year']);
        return $rows;
    }

    public static function inputHash(array $rows): string
    {
        return hash('sha256', json_encode(self::rows($rows), JSON_THROW_ON_ERROR));
    }

    public static function path(): string
    {
        return Config::dataDir() . '/publication-break-inference.json';
    }

    public static function matching(array $rows, ?string $path = null): ?array
    {
        $file = $path ?? self::path();
        if (!is_readable($file)) return null;
        try {
            $result = json_decode((string)file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            return null;
        }
        if (!is_array($result) || ($result['schema'] ?? null) !== 1
            || ($result['method'] ?? '') !== self::METHOD
            || !hash_equals(self::inputHash($rows), (string)($result['input_hash'] ?? ''))
            || ($result['observations'] ?? null) !== count(self::rows($rows))
            || ($result['trim'] ?? null) !== 0.2 || ($result['q'] ?? null) !== 2
            || ($result['max_breaks'] ?? null) !== 3) return null;
        if (!is_array($result['udmax'] ?? null) || !is_array($result['supf'] ?? null) || !is_array($result['sequential'] ?? null)) return null;
        $tests = array_merge([$result['udmax']], $result['supf'], $result['sequential']);
        if (count($result['supf'] ?? []) !== 3 || count($result['sequential'] ?? []) !== 3) return null;
        foreach ($tests as $test) {
            if (!is_array($test)) return null;
            $value = $test['statistic'] ?? null;
            $bound = $test['p_bound'] ?? [];
            if ($value === null && ($test['available'] ?? null) === false && isset($test['null_breaks'])) continue;
            if (!is_numeric($value) || !is_finite((float)$value) || $value < 0
                || !in_array($bound['operator'] ?? '', ['<', '≥'], true)
                || !in_array($bound['value'] ?? null, [0.01, 0.025, 0.05, 0.1], true)) return null;
        }
        return $result;
    }

    public static function pLabel(array $test): string
    {
        return 'p ' . $test['p_bound']['operator'] . ' ' . rtrim(rtrim(number_format((float)$test['p_bound']['value'], 3, '.', ''), '0'), '.');
    }
}
