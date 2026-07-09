#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/helpers.php';

function backup_usage(): void
{
    fwrite(STDERR, "Usage: php bin/backup-sqlite.php [--keep=N]\n");
}

$keep = 14;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--keep=')) {
        $keep = max(1, (int)substr($arg, 7));
        continue;
    }
    if ($arg === '--help' || $arg === '-h') {
        backup_usage();
        exit(0);
    }
    backup_usage();
    exit(2);
}

$dsn = Config::databaseDsn();
if (!str_starts_with($dsn, 'sqlite:')) {
    fwrite(STDERR, "SQLite backup is only available for sqlite: DSNs.\n");
    exit(2);
}

$source = substr($dsn, strlen('sqlite:'));
if ($source === '' || $source === ':memory:' || !is_file($source)) {
    fwrite(STDERR, "SQLite database file does not exist.\n");
    exit(1);
}

$backupDir = Config::backupDir();
$timestamp = gmdate('Ymd-His');
$target = $backupDir . '/publications-' . $timestamp . '.sqlite';

$db = new Database();
$db->initialize();
$quotedTarget = $db->pdo()->quote($target);
if (!is_string($quotedTarget)) {
    fwrite(STDERR, "Unable to quote backup target path.\n");
    exit(1);
}

try {
    try {
        $db->pdo()->exec('VACUUM main INTO ' . $quotedTarget);
    } catch (PDOException $e) {
        if (!class_exists('SQLite3') || !str_contains($e->getMessage(), 'near "INTO"')) {
            throw $e;
        }
        $sourceDb = new SQLite3($source, SQLITE3_OPEN_READONLY);
        $targetDb = new SQLite3($target, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        try {
            if (!$sourceDb->backup($targetDb)) {
                throw new RuntimeException('SQLite3 backup API returned false.');
            }
        } finally {
            $sourceDb->close();
            $targetDb->close();
        }
    }
    chmod($target, 0640);
} catch (Throwable $e) {
    @unlink($target);
    OperationalLogger::exception('backup.failed', $e);
    fwrite(STDERR, "Backup failed.\n");
    exit(1);
}

$backups = glob($backupDir . '/publications-*.sqlite') ?: [];
rsort($backups, SORT_STRING);
foreach (array_slice($backups, $keep) as $oldBackup) {
    if (is_file($oldBackup)) {
        @unlink($oldBackup);
    }
}

OperationalLogger::info('backup.completed', [
    'file' => basename($target),
    'size_bytes' => filesize($target) ?: 0,
    'keep' => $keep,
]);

echo json_encode([
    'ok' => true,
    'backup' => basename($target),
    'size_bytes' => filesize($target) ?: 0,
    'kept' => min(count($backups), $keep),
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
