<?php
declare(strict_types=1);

/** Fixed-key, private cache for bibliographic aggregates, never source payloads. */
final class PublicAggregateCache
{
    public static function remember(Database $db, string $name, callable $build, string $dependencyVersion = ''): array
    {
        $pdo = $db->pdo();
        $databases = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? $pdo->query('PRAGMA database_list')->fetchAll() : [];
        $file = (string)($databases[0]['file'] ?? '');
        // Disposable and in-memory corpora must never share a persistent cache.
        if ($file === '' || !is_file($file)) return $build();
        $revision = (string)$pdo->query('SELECT revision FROM app_cache_revision WHERE id = 1')->fetchColumn();
        $identity = hash('sha256', $file . ':' . fileinode($file));
        $version = hash_file('sha256', __DIR__ . '/PublicationRepository.php');
        $key = $identity . ':' . $version . ':' . $revision;
        if ($dependencyVersion !== '') $key .= ':' . $dependencyVersion;
        $path = Config::dataDir() . '/aggregate-' . $identity . '-' . $name . '.json';
        $read = static function () use ($path, $key): ?array {
            $entry = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
            return is_array($entry) && ($entry['key'] ?? '') === $key && is_array($entry['value'] ?? null) ? $entry['value'] : null;
        };
        if (($value = $read()) !== null) return $value;
        $lock = @fopen($path . '.lock', 'c');
        if ($lock === false) return $build();
        try {
            // One builder at a time; aggregate rebuilds are bounded to the corpus.
            if (!flock($lock, LOCK_EX)) return $build();
            if (($value = $read()) !== null) return $value;
            $value = $build();
            // Never cache a result if ingestion/curation changed during its build.
            if ((string)$pdo->query('SELECT revision FROM app_cache_revision WHERE id = 1')->fetchColumn() !== $revision) return $value;
            $temporary = $path . '.tmp-' . bin2hex(random_bytes(4));
            try {
                if (@file_put_contents($temporary, json_encode(['key' => $key, 'value' => $value], JSON_THROW_ON_ERROR)) !== false) {
                    @chmod($temporary, 0640);
                    @rename($temporary, $path);
                }
            } finally { if (is_file($temporary)) @unlink($temporary); }
            return $value;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}
