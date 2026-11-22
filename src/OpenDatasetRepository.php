<?php
declare(strict_types=1);

final class OpenDatasetRepository
{
    public function __construct(private Database $db)
    {
    }

    public function save(array $dataset): int
    {
        $now = current_utc();
        $stmt = $this->db->pdo()->prepare("INSERT INTO publication_datasets
            (publication_id, dataset_url, normalized_url, dataset_doi, dataset_title, repository, relation_type,
             access_status, license_name, license_url, resource_type, confidence, review_status, discovery_source,
             topic_tags, first_seen_at, last_checked_at, http_status, metadata_json)
            VALUES
            (:publication_id, :dataset_url, :normalized_url, :dataset_doi, :dataset_title, :repository, :relation_type,
             :access_status, :license_name, :license_url, :resource_type, :confidence, :review_status, :discovery_source,
             :topic_tags, :first_seen_at, :last_checked_at, :http_status, :metadata_json)
            ON CONFLICT(publication_id, normalized_url) DO UPDATE SET
              dataset_url = excluded.dataset_url,
              dataset_doi = COALESCE(excluded.dataset_doi, publication_datasets.dataset_doi),
              dataset_title = COALESCE(excluded.dataset_title, publication_datasets.dataset_title),
              repository = excluded.repository,
              relation_type = excluded.relation_type,
              access_status = CASE WHEN publication_datasets.review_status = 'verified' AND excluded.review_status = 'candidate' THEN publication_datasets.access_status ELSE excluded.access_status END,
              license_name = COALESCE(excluded.license_name, publication_datasets.license_name),
              license_url = COALESCE(excluded.license_url, publication_datasets.license_url),
              resource_type = CASE WHEN publication_datasets.review_status = 'verified' AND excluded.review_status = 'candidate' THEN publication_datasets.resource_type ELSE excluded.resource_type END,
              confidence = MAX(publication_datasets.confidence, excluded.confidence),
              review_status = CASE
                WHEN publication_datasets.review_status = 'rejected' THEN 'rejected'
                WHEN publication_datasets.review_status = 'verified' AND excluded.review_status = 'candidate' THEN 'verified'
                ELSE excluded.review_status
              END,
              discovery_source = excluded.discovery_source,
              topic_tags = excluded.topic_tags,
              last_checked_at = excluded.last_checked_at,
              http_status = excluded.http_status,
              metadata_json = excluded.metadata_json");
        $stmt->execute([
            ':publication_id' => (int)$dataset['publication_id'],
            ':dataset_url' => (string)$dataset['dataset_url'],
            ':normalized_url' => (string)$dataset['normalized_url'],
            ':dataset_doi' => self::nullable($dataset['dataset_doi'] ?? null),
            ':dataset_title' => self::nullable($dataset['dataset_title'] ?? null),
            ':repository' => (string)$dataset['repository'],
            ':relation_type' => (string)($dataset['relation_type'] ?? 'associated'),
            ':access_status' => (string)($dataset['access_status'] ?? 'unknown'),
            ':license_name' => self::nullable($dataset['license_name'] ?? null),
            ':license_url' => self::nullable($dataset['license_url'] ?? null),
            ':resource_type' => (string)($dataset['resource_type'] ?? 'Dataset'),
            ':confidence' => max(0, min(100, (int)($dataset['confidence'] ?? 0))),
            ':review_status' => (string)($dataset['review_status'] ?? 'candidate'),
            ':discovery_source' => (string)($dataset['discovery_source'] ?? 'metadata'),
            ':topic_tags' => (string)($dataset['topic_tags'] ?? ''),
            ':first_seen_at' => (string)($dataset['first_seen_at'] ?? $now),
            ':last_checked_at' => (string)($dataset['last_checked_at'] ?? $now),
            ':http_status' => isset($dataset['http_status']) ? (int)$dataset['http_status'] : null,
            ':metadata_json' => isset($dataset['metadata']) ? json_encode($dataset['metadata'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
        ]);
        $id = $this->db->pdo()->lastInsertId();
        if ($id !== '0') {
            return (int)$id;
        }
        $lookup = $this->db->pdo()->prepare('SELECT id FROM publication_datasets WHERE publication_id = :publication_id AND normalized_url = :normalized_url');
        $lookup->execute([':publication_id' => (int)$dataset['publication_id'], ':normalized_url' => (string)$dataset['normalized_url']]);
        return (int)$lookup->fetchColumn();
    }

    public function markScanned(int $publicationId, string $fingerprint, int $candidatesFound): void
    {
        $stmt = $this->db->pdo()->prepare("INSERT INTO publication_dataset_scans
            (publication_id, last_scanned_at, source_fingerprint, candidates_found)
            VALUES (:publication_id, :last_scanned_at, :source_fingerprint, :candidates_found)
            ON CONFLICT(publication_id) DO UPDATE SET
              last_scanned_at = excluded.last_scanned_at,
              source_fingerprint = excluded.source_fingerprint,
              candidates_found = excluded.candidates_found");
        $stmt->execute([
            ':publication_id' => $publicationId,
            ':last_scanned_at' => current_utc(),
            ':source_fingerprint' => $fingerprint,
            ':candidates_found' => $candidatesFound,
        ]);
    }

    public function scanQueue(int $limit = 100, bool $rescanAll = false, int $afterId = 0): array
    {
        $where = $rescanAll
            ? 'p.id > :after_id'
            : '(s.publication_id IS NULL OR COALESCE(p.last_checked, p.date_added, "") > s.last_scanned_at)';
        $sql = "SELECT p.id, p.title, p.doi, p.abstract, p.raw_json, p.source_url, p.topic_tags, p.substance_tags
                FROM publications p
                LEFT JOIN publication_dataset_scans s ON s.publication_id = p.id
                WHERE p.hidden = 0 AND p.false_positive = 0 AND {$where}
                ORDER BY p.id ASC LIMIT :limit";
        $stmt = $this->db->pdo()->prepare($sql);
        if ($rescanAll) {
            $stmt->bindValue(':after_id', $afterId, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', max(1, min($limit, 5000)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function search(array $filters = []): array
    {
        $where = ["d.review_status = 'verified'", "d.access_status IN ('open', 'public')", 'p.hidden = 0', 'p.false_positive = 0'];
        $params = [];
        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(d.dataset_title LIKE :q OR d.dataset_doi LIKE :q OR d.repository LIKE :q OR p.title LIKE :q OR p.authors LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }
        $topic = trim((string)($filters['topic'] ?? ''));
        if ($topic !== '') {
            $where[] = 'lower(d.topic_tags) LIKE :topic';
            $params[':topic'] = '%' . mb_strtolower($topic, 'UTF-8') . '%';
        }
        $repository = trim((string)($filters['repository'] ?? ''));
        if ($repository !== '') {
            $where[] = 'd.repository = :repository';
            $params[':repository'] = $repository;
        }
        $access = trim((string)($filters['access'] ?? ''));
        if (in_array($access, ['open', 'public'], true)) {
            $where[] = 'd.access_status = :access';
            $params[':access'] = $access;
        }
        $whereSql = implode(' AND ', $where);
        $count = $this->db->pdo()->prepare("SELECT COUNT(*) FROM publication_datasets d JOIN publications p ON p.id = d.publication_id WHERE {$whereSql}");
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $perPageRaw = (string)($filters['per_page'] ?? '24');
        $perPage = $perPageRaw === 'all' ? max(1, $total) : max(12, min(96, (int)$perPageRaw));
        $pages = max(1, (int)ceil($total / max(1, $perPage)));
        $page = max(1, min($pages, (int)($filters['page'] ?? 1)));
        $sort = (string)($filters['sort'] ?? 'newest');
        $order = match ($sort) {
            'topic' => 'd.topic_tags COLLATE NOCASE ASC, d.dataset_title COLLATE NOCASE ASC',
            'repository' => 'd.repository COLLATE NOCASE ASC, d.dataset_title COLLATE NOCASE ASC',
            'confidence' => 'd.confidence DESC, p.publication_date DESC, d.id DESC',
            default => 'p.publication_date DESC, d.id DESC',
        };
        $sql = "SELECT d.id, d.publication_id, d.dataset_url, d.dataset_doi, d.dataset_title, d.repository,
                       d.relation_type, d.access_status, d.license_name, d.license_url, d.resource_type,
                       d.confidence, d.topic_tags, d.last_checked_at,
                       p.title AS paper_title, p.authors AS paper_authors, p.publication_date, p.doi AS paper_doi
                FROM publication_datasets d
                JOIN publications p ON p.id = d.publication_id
                WHERE {$whereSql}
                ORDER BY {$order}
                LIMIT :limit OFFSET :offset";
        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();
        return ['rows' => array_map([$this, 'publicRow'], $stmt->fetchAll()), 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $perPageRaw, 'sort' => $sort];
    }

    public function forPublication(int $publicationId): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT d.*, p.title AS paper_title, p.authors AS paper_authors, p.publication_date, p.doi AS paper_doi
            FROM publication_datasets d JOIN publications p ON p.id = d.publication_id
            WHERE d.publication_id = :publication_id AND d.review_status = 'verified' AND d.access_status IN ('open', 'public')
            ORDER BY d.confidence DESC, d.id DESC");
        $stmt->execute([':publication_id' => $publicationId]);
        return array_map([$this, 'publicRow'], $stmt->fetchAll());
    }

    public function stats(): array
    {
        $row = $this->db->pdo()->query("SELECT
            SUM(CASE WHEN review_status = 'verified' AND access_status IN ('open', 'public') THEN 1 ELSE 0 END) AS datasets,
            COUNT(DISTINCT CASE WHEN review_status = 'verified' AND access_status IN ('open', 'public') THEN publication_id END) AS papers,
            COUNT(DISTINCT CASE WHEN review_status = 'verified' AND access_status IN ('open', 'public') THEN repository END) AS repositories,
            SUM(CASE WHEN review_status = 'candidate' THEN 1 ELSE 0 END) AS candidates,
            SUM(CASE WHEN review_status = 'verified' AND access_status = 'open' THEN 1 ELSE 0 END) AS licensed_open
            FROM publication_datasets")->fetch() ?: [];
        return array_map('intval', array_merge(['datasets' => 0, 'papers' => 0, 'repositories' => 0, 'candidates' => 0, 'licensed_open' => 0], $row));
    }

    public function lastVerifiedAt(): ?string
    {
        $value = $this->db->pdo()->query("SELECT MAX(last_checked_at) FROM publication_datasets WHERE review_status = 'verified' AND access_status IN ('open', 'public')")->fetchColumn();
        return is_string($value) && $value !== '' ? $value : null;
    }

    public function repositories(): array
    {
        return $this->db->pdo()->query("SELECT repository AS name, COUNT(*) AS count FROM publication_datasets
            WHERE review_status = 'verified' AND access_status IN ('open', 'public')
            GROUP BY repository ORDER BY count DESC, repository ASC")->fetchAll();
    }

    public function topics(): array
    {
        $rows = $this->db->pdo()->query("SELECT topic_tags FROM publication_datasets WHERE review_status = 'verified' AND access_status IN ('open', 'public')")->fetchAll();
        $counts = [];
        foreach ($rows as $row) {
            foreach (split_tag_values((string)$row['topic_tags']) as $topic) {
                $counts[$topic] = ($counts[$topic] ?? 0) + 1;
            }
        }
        arsort($counts);
        return array_map(static fn(string $name, int $count): array => ['name' => $name, 'count' => $count], array_keys($counts), array_values($counts));
    }

    public function review(int $id, string $status): bool
    {
        if (!in_array($status, ['candidate', 'verified', 'rejected'], true)) {
            throw new InvalidArgumentException('Invalid dataset review status.');
        }
        $stmt = $this->db->pdo()->prepare("UPDATE publication_datasets SET
            review_status = :status,
            access_status = CASE WHEN :status = 'verified' AND access_status = 'unknown' THEN 'public' ELSE access_status END,
            resource_type = CASE WHEN :status = 'verified' AND resource_type = 'Dataset candidate' THEN 'Dataset' ELSE resource_type END,
            last_checked_at = :checked
            WHERE id = :id");
        $stmt->execute([':status' => $status, ':checked' => current_utc(), ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function rejectUrlForPublication(int $publicationId, string $normalizedUrl): void
    {
        $stmt = $this->db->pdo()->prepare("UPDATE publication_datasets SET review_status = 'rejected', last_checked_at = :checked WHERE publication_id = :publication_id AND normalized_url = :url");
        $stmt->execute([':checked' => current_utc(), ':publication_id' => $publicationId, ':url' => $normalizedUrl]);
    }

    private function publicRow(array $row): array
    {
        return array_intersect_key($row, array_flip([
            'id', 'publication_id', 'dataset_url', 'dataset_doi', 'dataset_title', 'repository', 'relation_type',
            'access_status', 'license_name', 'license_url', 'resource_type', 'confidence', 'topic_tags', 'last_checked_at',
            'paper_title', 'paper_authors', 'publication_date', 'paper_doi',
        ]));
    }

    private static function nullable(mixed $value): ?string
    {
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }
}
