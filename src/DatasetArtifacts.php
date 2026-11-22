<?php
declare(strict_types=1);

/** Atomically published public downloads. Only CLI jobs build; HTTP reads completed files. */
final class DatasetArtifacts
{
    public const RETAIN = 3;
    private const ADDED_FORMATS = ['sqlite-xz', 'rds', 'study-links', 'parquet', 'feather'];

    public static function formats(): array
    {
        return [
            'sqlite' => ['file' => 'publications.sqlite', 'mime' => 'application/vnd.sqlite3', 'label' => 'SQLite database'],
            'sqlite-xz' => ['file' => 'publications.sqlite.xz', 'mime' => 'application/x-xz', 'label' => 'SQLite database · XZ compressed'],
            'rds' => ['file' => 'publications.rds', 'mime' => 'application/octet-stream', 'label' => 'R data frames · RDS with XZ'],
            'parquet' => ['file' => 'publications.parquet', 'mime' => 'application/vnd.apache.parquet', 'label' => 'Parquet'],
            'feather' => ['file' => 'publications.feather', 'mime' => 'application/vnd.apache.arrow.file', 'label' => 'Feather'],
            'csv' => ['file' => 'publications.csv', 'mime' => 'text/csv', 'label' => 'Full metadata CSV'],
            'json' => ['file' => 'publications.json', 'mime' => 'application/json', 'label' => 'Full metadata JSON'],
            'bibtex' => ['file' => 'references.bib', 'mime' => 'application/x-bibtex', 'label' => 'BibTeX'],
            'ris' => ['file' => 'references.ris', 'mime' => 'application/x-research-info-systems', 'label' => 'RIS'],
            'latex' => ['file' => 'references.tex', 'mime' => 'application/x-tex', 'label' => 'LaTeX'],
            'bundle' => ['file' => 'research-bundle.zip', 'mime' => 'application/zip', 'label' => 'Reproducible ZIP bundle'],
            'compact-json' => ['file' => 'catalogue.json', 'mime' => 'application/json', 'label' => 'Compact catalogue JSON'],
            'txt' => ['file' => 'titles-and-years.txt', 'mime' => 'text/plain', 'label' => 'Titles and years TXT'],
            'study-links' => ['file' => 'study-links.json', 'mime' => 'application/json', 'label' => 'Reviewed study relationships JSON'],
            'statistics-json' => ['file' => 'publications-by-year.json', 'mime' => 'application/json', 'label' => 'Annual statistics JSON'],
            'statistics-csv' => ['file' => 'publications-by-year.csv', 'mime' => 'text/csv', 'label' => 'Annual statistics CSV'],
        ];
    }

    private static function root(): string { return Config::dataDir() . '/dataset-artifacts'; }
    private static function pointer(): string { return Config::dataDir() . '/dataset-manifest.private.json'; }
    private static function validId(string $id): bool { return preg_match('/^[a-f0-9]{64}$/D', $id) === 1; }

    private static function sourceKey(Database $db): string
    {
        $dependencies = '';
        foreach (['DatasetArtifacts', 'CatalogueRevision', 'PublicationRepository', 'PublicDatabaseExport', 'ExportService', 'ResearchBundle', 'CatalogueDownload', 'PublicationStatistics', 'ResearchOverview', 'LinkedData', 'helpers'] as $name) {
            $dependencies .= hash_file('sha256', __DIR__ . '/' . $name . '.php');
        }
        $dependencies .= hash_file('sha256', __DIR__ . '/../bin/build-parquet-dataset.py') . hash_file('sha256', __DIR__ . '/../bin/build-r-dataset.py') . hash_file('sha256', __DIR__ . '/StudyRelationships.php') . hash_file('sha256', __DIR__ . '/../config/study-families.json');
        return hash('sha256', CatalogueRevision::read($db) . $dependencies . Config::publicBaseUrl() . gmdate('Y'));
    }

    private static function readJson(string $path): ?array
    {
        if (!is_file($path) || is_link($path) || filesize($path) > 1048576) return null;
        $raw = @file_get_contents($path);
        if ($raw === false) return null;
        $value = json_decode($raw, true);
        return is_array($value) ? $value : null;
    }

    public static function manifestFor(string $id): ?array
    {
        if (!self::validId($id) || is_link(self::root() . '/' . $id)) return null;
        $manifest = self::readJson(self::root() . '/' . $id . '/manifest.json');
        if (($manifest['schema_version'] ?? '') !== 'public-dataset-manifest-v1' || ($manifest['snapshot_id'] ?? '') !== $id || ($manifest['complete'] ?? null) !== true || !is_int($manifest['record_count'] ?? null)) return null;
        foreach (self::formats() as $key => $format) {
            $entry = $manifest['artifacts'][$key] ?? null;
            // Completed older snapshots remain valid after adding formats.
            if ($entry === null && in_array($key, self::ADDED_FORMATS, true)) continue;
            if (!is_array($entry) || ($entry['filename'] ?? '') !== $format['file'] || ($entry['mime_type'] ?? '') !== $format['mime'] || !is_int($entry['bytes'] ?? null) || $entry['bytes'] < 0 || !self::validId((string)($entry['sha256'] ?? '')) || ($entry['record_count'] ?? null) !== $manifest['record_count']) return null;
        }
        return $manifest;
    }

    public static function current(Database $db): ?array
    {
        $state = self::readJson(self::pointer());
        $manifest = self::manifestFor((string)($state['snapshot_id'] ?? ''));
        if ($manifest === null) return null;
        $manifest['is_current'] = hash_equals((string)($state['source_key'] ?? ''), self::sourceKey($db));
        return $manifest;
    }

    public static function artifact(string $id, string $format): ?array
    {
        $manifest = self::manifestFor($id);
        if ($manifest === null) return null;
        if ($format === 'manifest') {
            $file = self::root() . '/' . $id . '/manifest.json';
            return ['path' => $file, 'filename' => 'dataset-manifest.json', 'mime_type' => 'application/json', 'bytes' => filesize($file), 'sha256' => hash_file('sha256', $file), 'record_count' => $manifest['record_count']];
        }
        $definition = self::formats()[$format] ?? null;
        if ($definition === null) return null;
        $entry = $manifest['artifacts'][$format] ?? null;
        if ($entry === null) return null;
        $file = self::root() . '/' . $id . '/' . $definition['file'];
        if (!is_file($file) || is_link($file) || filesize($file) !== $entry['bytes']) return null;
        return ['path' => $file] + $entry;
    }

    private static function healthy(array $manifest): bool
    {
        foreach ($manifest['artifacts'] as $format => $expected) {
            $entry = self::artifact($manifest['snapshot_id'], $format);
            if ($entry === null || !hash_equals($expected['sha256'], (string)hash_file('sha256', $entry['path']))) return false;
        }
        return true;
    }

    public static function refresh(Database $db): array
    {
        if (PHP_SAPI !== 'cli') throw new RuntimeException('Dataset publication is CLI only.');
        $lock = fopen(Config::dataDir() . '/dataset-artifacts.lock', 'c');
        if ($lock === false) throw new RuntimeException('Cannot open dataset publication lock.');
        chmod(Config::dataDir() . '/dataset-artifacts.lock', 0600);
        if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return ['skipped' => true]; }
        try {
            // A process that died mid-build no longer holds this exclusive lock.
            foreach (glob(self::root() . '/.build-*', GLOB_ONLYDIR) ?: [] as $directory) {
                if (preg_match('/^\.build-[a-f0-9]{64}$/D', basename($directory)) === 1 && !is_link($directory)) self::removeDirectory($directory);
            }
            $old = self::current($db);
            if ($old !== null && $old['is_current'] && self::healthy($old)) {
                return ['unchanged' => true, 'snapshot_id' => $old['snapshot_id'], 'record_count' => $old['record_count']];
            }
            return self::build($db);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private static function write(string $path, string $content): void
    {
        if (file_put_contents($path, $content) !== strlen($content) || !chmod($path, 0600)) throw new RuntimeException('Cannot write dataset artifact.');
    }

    private static function compress(string $staging): void
    {
        // No shell expansion, package install, R runtime or network request.
        $process = proc_open(['/usr/bin/python3', __DIR__ . '/../bin/build-r-dataset.py',
            '--sqlite', $staging . '/publications.sqlite', '--rds', $staging . '/publications.rds',
            '--sqlite-xz', $staging . '/publications.sqlite.xz'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start public dataset compression.');
        fclose($pipes[0]);
        $deadline = microtime(true) + 120;
        do {
            $state = proc_get_status($process);
            if (!$state['running']) break;
            if (microtime(true) >= $deadline) { proc_terminate($process, 9); proc_close($process); throw new RuntimeException('Public dataset compression timed out.'); }
            usleep(20000);
        } while (true);
        $closed = proc_close($process);
        if (($state['exitcode'] >= 0 ? $state['exitcode'] : $closed) !== 0) throw new RuntimeException('Public dataset compression failed; previous completed downloads retained.');
        foreach (['sqlite-xz', 'rds'] as $format) {
            $file = $staging . '/' . self::formats()[$format]['file'];
            if (!is_file($file) || filesize($file) === 0 || !chmod($file, 0600)) throw new RuntimeException('Compressed public dataset missing.');
        }
    }

    private static function parquet(string $staging, string $id): void
    {
        $runtime = __DIR__ . '/../data/parquet-runtime/bin/python3';
        $python = is_executable($runtime) ? $runtime : '/usr/bin/python3';
        $process = proc_open([$python, __DIR__ . '/../bin/build-parquet-dataset.py',
            '--sqlite', $staging . '/publications.sqlite', '--parquet', $staging . '/publications.parquet', '--snapshot-id', $id, '--feather', $staging . '/publications.feather'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start Parquet publication.');
        fclose($pipes[0]);
        $deadline = microtime(true) + 120;
        do {
            $state = proc_get_status($process);
            if (!$state['running']) break;
            if (microtime(true) >= $deadline) { proc_terminate($process, 9); proc_close($process); throw new RuntimeException('Parquet publication timed out.'); }
            usleep(20000);
        } while (true);
        $closed = proc_close($process);
        if (($state['exitcode'] >= 0 ? $state['exitcode'] : $closed) !== 0 || (!is_file($staging . '/publications.parquet') || !is_file($staging . '/publications.feather'))) throw new RuntimeException('Parquet publication failed; previous downloads retained.');
    }

    private static function build(Database $db): array
    {
        $started = hrtime(true);
        if (!is_dir(self::root()) && !mkdir(self::root(), 0700, true)) throw new RuntimeException('Cannot create dataset artifact directory.');
        chmod(self::root(), 0700);
        $id = bin2hex(random_bytes(32));
        $staging = self::root() . '/.build-' . $id;
        if (!mkdir($staging, 0700)) throw new RuntimeException('Cannot stage dataset artifacts.');
        $pdo = $db->pdo();
        if ($pdo->inTransaction()) { self::removeDirectory($staging); throw new RuntimeException('Dataset publication requires its own read transaction.'); }
        $temporaryPointer = null;
        try {
            $pdo->beginTransaction();
            $sourceKey = self::sourceKey($db);
            $revision = CatalogueRevision::read($db);
            $generated = gmdate('Y-m-d\TH:i:s\Z');
            $count = (int)$pdo->query('SELECT COUNT(*) FROM publications WHERE hidden=0 AND false_positive=0')->fetchColumn();
            if ($count > CatalogueDownload::MAX_RECORDS) throw new OverflowException('Dataset publication capacity exceeded; use the live public SQLite export.');
            $papers = (new PublicationRepository($db))->allForExport(['sort' => 'oldest'], CatalogueDownload::MAX_RECORDS);
            if (count($papers) !== $count) throw new RuntimeException('Dataset record count mismatch.');
            $database = PublicDatabaseExport::build($db, $staging . '/publications.sqlite', $generated);
            if ($database['record_count'] !== $count) throw new RuntimeException('Public database count mismatch.');
            self::compress($staging);
            self::parquet($staging, $id);
            $compact = CatalogueDownload::fromPapers($papers, $generated);
            $compact['scope'] = 'Complete visible public metadata directory. This compact projection has fewer fields than the full SQLite/CSV/JSON exports. Records are not necessarily independent studies.';
            $statistics = PublicationStatistics::summary($db);
            if ($statistics['record_count'] !== $count || $statistics['catalogue_revision'] !== $revision) throw new RuntimeException('Statistics snapshot mismatch.');
            $statistics['generated_at_utc'] = $generated;
            $artifacts = [];
            foreach (self::formats() as $format => $definition) {
                if ($format !== 'sqlite' && !in_array($format, ['sqlite-xz', 'rds', 'parquet', 'feather'], true)) {
                    $content = match ($format) {
                        'csv' => ExportService::csv($papers),
                        'json' => ExportService::json($papers, $generated),
                        'bibtex' => ExportService::bibtex($papers),
                        'ris' => ExportService::ris($papers),
                        'latex' => ExportService::latex($papers),
                        'bundle' => ResearchBundle::build($papers, ['range' => 'all'], CatalogueDownload::MAX_RECORDS, false, $generated),
                        'compact-json' => CatalogueDownload::encode($compact),
                        'txt' => CatalogueDownload::text($compact),
                        'study-links' => CatalogueDownload::encode(array_replace(StudyRelationships::catalogue($db), ['generated_at_utc' => $generated, 'record_count' => $count])),
                        'statistics-json' => CatalogueDownload::encode($statistics),
                        'statistics-csv' => PublicationStatistics::csv($statistics),
                    };
                    self::write($staging . '/' . $definition['file'], $content);
                    unset($content);
                }
                $path = $staging . '/' . $definition['file'];
                $artifacts[$format] = [
                    'label' => $definition['label'], 'filename' => $definition['file'], 'mime_type' => $definition['mime'],
                    'bytes' => filesize($path), 'sha256' => hash_file('sha256', $path), 'record_count' => $count,
                    'url' => rtrim(Config::publicBaseUrl(), '/') . '/dataset-download.php?' . http_build_query(['snapshot' => $id, 'format' => $format]),
                ];
                if ($format === 'sqlite-xz') $artifacts[$format] += ['compression' => 'xz', 'uncompressed_filename' => 'publications.sqlite', 'uncompressed_bytes' => $artifacts['sqlite']['bytes'], 'uncompressed_sha256' => $artifacts['sqlite']['sha256']];
                if ($format === 'rds') $artifacts[$format] += ['compression' => 'xz', 'r_serialization_version' => 2, 'r_object' => 'list(publications=data.frame, metadata=data.frame)', 'source_format' => 'sqlite', 'source_sha256' => $artifacts['sqlite']['sha256'], 'scope' => 'All public SQLite table values and metadata; no SQLite indexes. Text stays UTF-8, SQL NULL becomes R NA, and dates remain source strings.'];
                if (in_array($format, ['parquet', 'feather'], true)) $artifacts[$format] += ['compression' => 'zstd', 'source_format' => 'sqlite', 'source_sha256' => $artifacts['sqlite']['sha256'], 'scope' => 'All public publications columns; metadata table embedded as Arrow schema provenance. Typed nullable integers and UTF-8 strings; dates remain recorded strings.'];
                if ($format === 'study-links') {
                    $links = StudyRelationships::catalogue($db);
                    $artifacts[$format] += ['linked_record_count' => $links['indexed_member_count'], 'family_count' => $links['family_count'], 'scope' => 'Selected reviewed families only. record_count identifies the parent catalogue size; linked_record_count is the number of visible linked records, not an independent-study or participant count.'];
                }
            }
            $manifest = [
                'schema_version' => 'public-dataset-manifest-v1', 'snapshot_id' => $id, 'catalogue_revision' => $revision,
                'generated_at_utc' => $generated, 'source_last_checked_utc' => $database['last_checked_utc'],
                'record_count' => $count, 'complete' => true, 'metadata_schema' => 'rights-safe-core-v1',
                'scope' => 'All visible public indexed records, excluding hidden records and curated false positives. Public metadata only; no private runtime state, raw importer payloads, source abstracts or full texts.',
                'byte_representation' => 'Sizes and SHA-256 describe the exact downloaded file bytes after HTTP transfer decoding. For XZ/RDS files, verify the compressed file before decompressing or reading it. SQLite-XZ also includes the underlying SQLite size and hash.',
                'retained_snapshots' => self::RETAIN,
                'retention_note' => 'The latest three completed snapshots are retained. Preserve downloaded files for reproducibility; these URLs are not a permanent Zenodo deposit.',
                'manifest_url' => rtrim(Config::publicBaseUrl(), '/') . '/dataset-download.php?' . http_build_query(['snapshot' => $id, 'format' => 'manifest']),
                'artifacts' => $artifacts,
            ];
            self::write($staging . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
            $pdo->commit();
            // A new source revision is never presented as a current completed build.
            if (!hash_equals($sourceKey, self::sourceKey($db))) throw new RuntimeException('Catalogue changed during dataset publication.');
            if (!rename($staging, self::root() . '/' . $id)) throw new RuntimeException('Cannot publish dataset directory.');
            $previous = self::readJson(self::pointer());
            $history = array_values(array_unique(array_merge([$id], (array)($previous['snapshots'] ?? []), [(string)($previous['snapshot_id'] ?? '')])));
            $history = array_slice(array_values(array_filter($history, static fn($value): bool => is_string($value) && self::validId($value))), 0, self::RETAIN);
            $temporaryPointer = self::pointer() . '.tmp-' . bin2hex(random_bytes(6));
            self::write($temporaryPointer, json_encode(['source_key' => $sourceKey, 'snapshot_id' => $id, 'snapshots' => $history], JSON_THROW_ON_ERROR));
            if (!rename($temporaryPointer, self::pointer())) throw new RuntimeException('Cannot publish dataset manifest.');
            $temporaryPointer = null;
            foreach (glob(self::root() . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
                $name = basename($directory);
                if (self::validId($name) && !is_link($directory) && !in_array($name, $history, true)) self::removeDirectory($directory);
            }
            return ['snapshot_id' => $id, 'record_count' => $count, 'artifact_bytes' => array_sum(array_column($artifacts, 'bytes')), 'elapsed_ms' => round((hrtime(true) - $started) / 1e6, 3)];
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($temporaryPointer !== null && is_file($temporaryPointer)) unlink($temporaryPointer);
            if (is_dir($staging)) self::removeDirectory($staging);
        }
    }

    private static function removeDirectory(string $directory): void
    {
        if (is_link($directory)) return;
        foreach (array_merge(array_column(self::formats(), 'file'), ['manifest.json']) as $name) {
            $path = $directory . '/' . $name;
            if (is_file($path) || is_link($path)) @unlink($path);
        }
        @rmdir($directory);
    }
}
