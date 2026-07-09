<?php
declare(strict_types=1);

final class HealthService
{
    public function __construct(private Database $db, private PublicationRepository $publications, private FetchRunRepository $runs)
    {
    }

    public function report(): array
    {
        $checks = [
            'database' => $this->databaseCheck(),
            'storage_security' => $this->storageSecurityCheck(),
            'backup' => $this->backupCheck(),
            'runtime_paths' => $this->runtimePathCheck(),
            'updates' => $this->updateFreshnessCheck(),
            'heartbeat' => $this->heartbeatCheck(),
            'recent_errors' => $this->recentErrorsCheck(),
            'log' => $this->logCheck(),
        ];
        $status = 'ok';
        foreach ($checks as $check) {
            if (($check['status'] ?? 'ok') === 'fail') {
                $status = 'fail';
                break;
            }
            if (($check['status'] ?? 'ok') === 'warn') {
                $status = 'warn';
            }
        }
        return [
            'ok' => $status !== 'fail',
            'status' => $status,
            'generated_at' => current_utc(),
            'checks' => $checks,
        ];
    }

    private function databaseCheck(): array
    {
        try {
            $this->db->pdo()->query('SELECT 1')->fetchColumn();
            $stats = $this->publications->stats();
            return [
                'status' => ((int)($stats['total'] ?? 0)) > 0 ? 'ok' : 'warn',
                'message' => ((int)($stats['total'] ?? 0)) > 0 ? 'Database reachable.' : 'Database reachable but contains no visible publications.',
                'publications' => (int)($stats['total'] ?? 0),
                'journals' => (int)($stats['journals'] ?? 0),
            ];
        } catch (Throwable $e) {
            return ['status' => 'fail', 'message' => 'Database check failed.'];
        }
    }

    private function runtimePathCheck(): array
    {
        $paths = [
            'data_dir' => Config::dataDir(),
            'heartbeat_dir' => Config::heartbeatDir(),
            'log_file' => Config::logFile(),
        ];
        $details = [];
        $status = 'ok';
        foreach ($paths as $name => $path) {
            $target = $name === 'log_file' ? dirname($path) : $path;
            $writable = is_dir($target) && is_writable($target);
            $details[$name] = ['path' => $path, 'writable' => $writable];
            if (!$writable) {
                $status = 'fail';
            }
        }
        return ['status' => $status, 'message' => $status === 'ok' ? 'Runtime paths writable.' : 'A runtime path is not writable.', 'details' => $details];
    }

    private function storageSecurityCheck(): array
    {
        $dataDir = Config::dataDir();
        $htaccess = $dataDir . '/.htaccess';
        $details = [
            'data_dir' => $this->modeDetails($dataDir),
            'data_htaccess_present' => is_file($htaccess),
            'data_htaccess_denies_all' => $this->htaccessDeniesAll($htaccess),
            'sqlite' => null,
        ];

        $status = 'ok';
        $messages = [];
        if (!$details['data_htaccess_present'] || !$details['data_htaccess_denies_all']) {
            $status = 'fail';
            $messages[] = 'Runtime data directory is not protected by a deny-all .htaccess.';
        }

        $dataMode = $details['data_dir']['mode_octal'] ?? null;
        if (is_string($dataMode) && $this->hasWorldBits($dataMode)) {
            $status = $status === 'fail' ? 'fail' : 'warn';
            $messages[] = 'Runtime data directory is readable, writable, or executable by other users.';
        }

        $sqlitePath = $this->sqliteDatabasePath();
        if ($sqlitePath !== null) {
            $details['sqlite'] = $this->modeDetails($sqlitePath);
            $sqliteMode = $details['sqlite']['mode_octal'] ?? null;
            if (!is_file($sqlitePath)) {
                $status = 'warn';
                $messages[] = 'SQLite database file does not exist yet.';
            } elseif (is_string($sqliteMode) && $this->hasWorldBits($sqliteMode)) {
                $status = $status === 'fail' ? 'fail' : 'warn';
                $messages[] = 'SQLite database file is readable or writable by other users.';
            }
        }

        return [
            'status' => $status,
            'message' => $messages ? implode(' ', $messages) : 'Runtime data storage is protected and file permissions are not world-accessible.',
            'details' => $details,
        ];
    }

    private function backupCheck(): array
    {
        if ($this->sqliteDatabasePath() === null) {
            return ['status' => 'ok', 'message' => 'Backup freshness check skipped for non-SQLite DSN.'];
        }

        $backupDir = Config::backupDir();
        $backups = glob($backupDir . '/publications-*.sqlite') ?: [];
        if (!$backups) {
            return [
                'status' => 'warn',
                'message' => 'No SQLite backup file found.',
                'backup_dir' => $backupDir,
                'latest_backup' => null,
            ];
        }

        usort($backups, fn (string $a, string $b): int => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));
        $latest = $backups[0];
        $mtime = filemtime($latest) ?: 0;
        $age = $mtime > 0 ? max(0, time() - $mtime) : null;
        $status = $age !== null && $age <= 7 * 24 * 3600 ? 'ok' : 'warn';

        return [
            'status' => $status,
            'message' => $status === 'ok' ? 'Recent SQLite backup exists.' : 'Latest SQLite backup is older than 7 days.',
            'backup_dir' => $backupDir,
            'latest_backup' => basename($latest),
            'latest_backup_size_bytes' => filesize($latest) ?: 0,
            'latest_backup_age_seconds' => $age,
            'backup_count' => count($backups),
        ];
    }

    private function updateFreshnessCheck(): array
    {
        $latest = $this->runs->latestSuccessful();
        $finished = $latest['finished_at'] ?? null;
        $age = $this->ageSeconds($finished);
        if ($age === null) {
            return ['status' => 'warn', 'message' => 'No successful update run recorded.', 'last_successful_update' => null];
        }
        $status = $age > 36 * 3600 ? 'warn' : 'ok';
        return [
            'status' => $status,
            'message' => $status === 'ok' ? 'Latest successful update is fresh.' : 'Latest successful update is older than 36 hours.',
            'last_successful_update' => $finished,
            'age_seconds' => $age,
        ];
    }

    private function heartbeatCheck(): array
    {
        $heartbeats = Heartbeat::all();
        $update = Heartbeat::read('update-daily');
        $status = 'ok';
        $message = 'Heartbeat files readable.';
        if (!$update) {
            $status = 'warn';
            $message = 'No update-daily heartbeat recorded yet.';
        } else {
            $age = $this->ageSeconds((string)($update['updated_at'] ?? ''));
            if ($age !== null && $age > 36 * 3600) {
                $status = 'warn';
                $message = 'update-daily heartbeat is older than 36 hours.';
            }
            if (($update['status'] ?? '') === 'fail') {
                $status = 'fail';
                $message = 'Latest update-daily heartbeat reports failure.';
            }
        }
        return ['status' => $status, 'message' => $message, 'items' => $heartbeats];
    }

    private function recentErrorsCheck(): array
    {
        $errors = $this->runs->latestErrors(5);
        $recent = array_values(array_filter($errors, function (array $error): bool {
            $age = $this->ageSeconds((string)($error['created_at'] ?? ''));
            return $age !== null && $age <= 48 * 3600;
        }));
        return [
            'status' => $recent ? 'warn' : 'ok',
            'message' => $recent ? 'Recent fetch errors exist.' : 'No fetch errors in the last 48 hours.',
            'recent_count' => count($recent),
            'latest' => array_map(fn (array $row): array => [
                'source' => $row['source'] ?? '',
                'message' => mb_substr((string)($row['message'] ?? ''), 0, 240),
                'created_at' => $row['created_at'] ?? null,
            ], $recent),
        ];
    }

    private function logCheck(): array
    {
        $file = Config::logFile();
        if (!is_file($file)) {
            return ['status' => 'warn', 'message' => 'Application log has not been created yet.', 'path' => $file];
        }
        $size = filesize($file) ?: 0;
        $status = $size > 10 * 1024 * 1024 ? 'warn' : 'ok';
        return [
            'status' => $status,
            'message' => $status === 'ok' ? 'Application log exists.' : 'Application log is larger than 10 MB; consider rotation.',
            'path' => $file,
            'size_bytes' => $size,
            'latest' => $this->tailJsonLog($file, 5),
        ];
    }

    private function tailJsonLog(string $file, int $limit): array
    {
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) {
            return [];
        }
        $items = [];
        foreach (array_slice($lines, -$limit) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $items[] = $decoded;
            }
        }
        return $items;
    }

    private function sqliteDatabasePath(): ?string
    {
        $dsn = Config::databaseDsn();
        if (!str_starts_with($dsn, 'sqlite:')) {
            return null;
        }
        $path = substr($dsn, strlen('sqlite:'));
        return $path === '' || $path === ':memory:' ? null : $path;
    }

    private function modeDetails(string $path): array
    {
        $exists = file_exists($path);
        $mode = $exists ? (fileperms($path) & 0777) : null;
        return [
            'exists' => $exists,
            'mode_octal' => $mode === null ? null : sprintf('%04o', $mode),
            'owner_readable' => $exists && is_readable($path),
            'owner_writable' => $exists && is_writable($path),
        ];
    }

    private function htaccessDeniesAll(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }
        $contents = file_get_contents($path);
        return is_string($contents)
            && str_contains($contents, 'Require all denied')
            && str_contains($contents, 'Deny from all')
            && str_contains($contents, 'Options -Indexes');
    }

    private function hasWorldBits(string $modeOctal): bool
    {
        return ((int)substr($modeOctal, -1)) !== 0;
    }

    private function ageSeconds(?string $value): ?int
    {
        if (!$value) {
            return null;
        }
        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
            return max(0, time() - $date->getTimestamp());
        } catch (Throwable) {
            return null;
        }
    }
}
