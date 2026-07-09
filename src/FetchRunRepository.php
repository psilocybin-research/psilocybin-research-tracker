<?php
declare(strict_types=1);

final class FetchRunRepository
{
    public function __construct(private Database $db)
    {
    }

    public function start(string $source): int
    {
        $stmt = $this->db->pdo()->prepare('INSERT INTO fetch_runs (started_at, status, source) VALUES (:started_at, :status, :source)');
        $stmt->execute(['started_at' => current_utc(), 'status' => 'running', 'source' => $source]);
        return (int)$this->db->pdo()->lastInsertId();
    }

    public function finish(int $id, string $status, int $imported, int $updated, int $skipped, int $errors, ?string $message = null): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE fetch_runs SET finished_at = :finished_at, status = :status, imported_count = :imported, updated_count = :updated, skipped_count = :skipped, error_count = :errors, message = :message WHERE id = :id');
        $stmt->execute([
            'finished_at' => current_utc(),
            'status' => $status,
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
            'message' => $message,
            'id' => $id,
        ]);
    }

    public function error(?int $runId, string $source, string $message): void
    {
        $stmt = $this->db->pdo()->prepare('INSERT INTO fetch_errors (fetch_run_id, source, message, created_at) VALUES (:run, :source, :message, :created)');
        $stmt->execute(['run' => $runId, 'source' => $source, 'message' => mb_substr($message, 0, 2000), 'created' => current_utc()]);
    }

    public function latestRuns(int $limit = 8): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM fetch_runs ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function latestErrors(int $limit = 8): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM fetch_errors ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function latestSuccessful(): ?array
    {
        $stmt = $this->db->pdo()->prepare("SELECT * FROM fetch_runs WHERE status = 'ok' ORDER BY finished_at DESC, id DESC LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function latestSuccessfulBatchWindow(int $lookbackHours = 6): ?array
    {
        $latest = $this->latestSuccessful();
        if (!$latest || empty($latest['finished_at'])) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT
                MIN(started_at) started_at,
                MAX(finished_at) finished_at,
                SUM(imported_count) imported_count,
                SUM(updated_count) updated_count,
                COUNT(*) source_runs
             FROM fetch_runs
             WHERE status = 'ok'
               AND finished_at IS NOT NULL
               AND finished_at <= :finished_at
               AND started_at >= datetime(:finished_at, :lookback)"
        );
        $stmt->execute([
            'finished_at' => (string)$latest['finished_at'],
            'lookback' => '-' . max(1, min($lookbackHours, 24)) . ' hours',
        ]);
        $row = $stmt->fetch();
        return $row && !empty($row['started_at']) && !empty($row['finished_at']) ? $row : null;
    }
}
