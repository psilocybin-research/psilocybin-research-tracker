<?php
declare(strict_types=1);

final class PublicationRepository
{
    private ?bool $hasFts = null;

    public function __construct(private Database $db)
    {
    }

    public function upsert(array $paper): string
    {
        $now = current_utc();
        $doi = normalize_doi($paper['doi'] ?? null);
        $pubmedId = isset($paper['pubmed_id']) && $paper['pubmed_id'] !== '' ? (string)$paper['pubmed_id'] : null;
        $openAlexId = self::normalizeOpenAlexId($paper['openalex_id'] ?? ($paper['raw']['openalex_id'] ?? null));
        $title = clean_scientific_text((string)($paper['title'] ?? ''));
        if ($title === '') {
            return 'skipped';
        }
        if (self::isNonScholarlyDownloadArtifact($title, (string)($paper['abstract'] ?? ''))) {
            return 'skipped';
        }
        $normalizedTitle = normalize_title($title);
        $publicationDate = parse_date_or_null($paper['publication_date'] ?? null);
        $publicationYear = $publicationDate ? (int)substr($publicationDate, 0, 4) : null;
        $substanceTags = self::detectSubstances($title . ' ' . ($paper['abstract'] ?? '') . ' ' . ($paper['keywords'] ?? ''));
        if ($substanceTags === '') {
            return 'skipped';
        }
        $classificationText = $title . ' ' . ($paper['abstract'] ?? '') . ' ' . ($paper['keywords'] ?? '');
        $studyType = self::classifyStudyType($classificationText);

        $existing = $this->findDuplicate($doi, $pubmedId, $normalizedTitle, $openAlexId);
        $isOpenAlexSource = ($paper['source_name'] ?? null) === 'OpenAlex';
        if ($isOpenAlexSource && $openAlexId !== null) {
            $review = $this->openAlexReviewDecision($openAlexId);
            if (($review['decision'] ?? null) === 'rejected') {
                return 'skipped';
            }
        }
        if (!$existing && $isOpenAlexSource && !self::hasExplicitPsilocybinEvidence([
            'title' => $title,
            'abstract' => $paper['abstract'] ?? '',
            'keywords' => $paper['keywords'] ?? '',
            'substance_tags' => '',
            'raw_json' => isset($paper['raw']) ? json_encode($paper['raw'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '',
        ])) {
            if ($openAlexId !== null) {
                $this->recordOpenAlexQualityReview($openAlexId, null, 'rejected', 'standalone row lacks explicit psilocybin/psilocin evidence');
            }
            return 'skipped';
        }
        $payload = [
            'title' => $title,
            'normalized_title' => $normalizedTitle,
            'authors' => $paper['authors'] ?? null,
            'abstract' => $paper['abstract'] ?? null,
            'journal' => $paper['journal'] ?? null,
            'publication_date' => $publicationDate,
            'publication_year' => $publicationYear,
            'doi' => $doi,
            'pubmed_id' => $pubmedId,
            'openalex_id' => $openAlexId,
            'source_url' => $paper['source_url'] ?? null,
            'keywords' => $paper['keywords'] ?? null,
            'substance_tags' => $substanceTags,
            'topic_tags' => self::classifyTopics($classificationText),
            'study_type' => $studyType,
            'source_name' => $paper['source_name'] ?? null,
            'publication_status' => self::normalizePublicationStatus($paper['publication_status'] ?? null, $paper['source_name'] ?? null, $studyType),
            'last_checked' => $now,
            'raw_json' => isset($paper['raw']) ? json_encode($paper['raw'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
        ];

        if ($existing) {
            if (($payload['source_name'] ?? null) === 'OpenAlex' && trim((string)($existing['source_name'] ?? '')) !== '' && $existing['source_name'] !== 'OpenAlex') {
                $payload = $this->preservePrimaryRecordDuringOpenAlexEnrichment($payload, $existing);
            }
            if (!empty($existing['curation_locked'])) {
                foreach (['substance_tags', 'topic_tags', 'study_type'] as $curatedField) {
                    $payload[$curatedField] = $existing[$curatedField] ?? $payload[$curatedField];
                }
            }
            if ($doi !== null) {
                $doiOwner = $this->one('SELECT id FROM publications WHERE doi = :doi', ['doi' => $doi]);
                if ($doiOwner && (int)$doiOwner['id'] !== (int)$existing['id']) {
                    $payload['doi'] = $existing['doi'] ?: null;
                }
            }
            if ($pubmedId !== null) {
                $pubmedOwner = $this->one('SELECT id FROM publications WHERE pubmed_id = :pubmed_id', ['pubmed_id' => $pubmedId]);
                if ($pubmedOwner && (int)$pubmedOwner['id'] !== (int)$existing['id']) {
                    $payload['pubmed_id'] = $existing['pubmed_id'] ?: null;
                }
            }
            if ($openAlexId !== null) {
                $openAlexOwner = $this->one('SELECT id FROM publications WHERE openalex_id = :openalex_id', ['openalex_id' => $openAlexId]);
                if ($openAlexOwner && (int)$openAlexOwner['id'] !== (int)$existing['id']) {
                    $payload['openalex_id'] = $existing['openalex_id'] ?: null;
                }
            }
            $sets = [];
            foreach ($payload as $key => $_) {
                $sets[] = $key . ' = :' . $key;
            }
            $payload['id'] = $existing['id'];
            $sql = 'UPDATE publications SET ' . implode(', ', $sets) . ' WHERE id = :id';
            $this->db->pdo()->prepare($sql)->execute($payload);
            $this->syncNormalizedMetadata((int)$existing['id'], $payload);
            if ($isOpenAlexSource && $openAlexId !== null) {
                $this->recordOpenAlexQualityReview($openAlexId, (int)$existing['id'], 'approved', 'matched existing indexed publication');
            }
            return 'updated';
        }

        $payload['date_added'] = $now;
        $columns = array_keys($payload);
        $sql = 'INSERT INTO publications (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')';
        $this->db->pdo()->prepare($sql)->execute($payload);
        $id = (int)$this->db->pdo()->lastInsertId();
        $this->syncNormalizedMetadata($id, $payload);
        if ($isOpenAlexSource && $openAlexId !== null) {
            $this->recordOpenAlexQualityReview($openAlexId, $id, 'approved', 'standalone title contains direct psilocybin/psilocin signal');
        }
        return 'inserted';
    }

    public function openAlexReviewDecision(?string $openAlexId): ?array
    {
        $openAlexId = self::normalizeOpenAlexId($openAlexId);
        if ($openAlexId === null) {
            return null;
        }
        return $this->one('SELECT * FROM openalex_quality_reviews WHERE openalex_id = :openalex_id', ['openalex_id' => $openAlexId]);
    }

    public function recordOpenAlexQualityReview(string $openAlexId, ?int $publicationId, string $decision, ?string $reason, string $reviewer = 'system'): void
    {
        $openAlexId = self::normalizeOpenAlexId($openAlexId);
        if ($openAlexId === null || !in_array($decision, ['approved', 'rejected', 'needs_review'], true)) {
            return;
        }
        $this->db->pdo()->prepare(
            'INSERT INTO openalex_quality_reviews (openalex_id, publication_id, decision, reason, reviewed_at, reviewer)
             VALUES (:openalex_id, :publication_id, :decision, :reason, :reviewed_at, :reviewer)
             ON CONFLICT(openalex_id) DO UPDATE SET
                publication_id = excluded.publication_id,
                decision = excluded.decision,
                reason = excluded.reason,
                reviewed_at = excluded.reviewed_at,
                reviewer = excluded.reviewer'
        )->execute([
            'openalex_id' => $openAlexId,
            'publication_id' => $publicationId,
            'decision' => $decision,
            'reason' => $reason,
            'reviewed_at' => current_utc(),
            'reviewer' => $reviewer,
        ]);
    }

    public function backfillNormalizedMetadata(int $limit = 0): array
    {
        $limit = max(0, $limit);
        $sql = 'SELECT * FROM publications ORDER BY id';
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
        }
        $processed = 0;
        foreach ($this->db->pdo()->query($sql)->fetchAll() as $row) {
            $this->syncNormalizedMetadata((int)$row['id'], $row);
            $processed++;
        }
        return [
            'publications' => $processed,
            'authors' => (int)$this->db->pdo()->query('SELECT COUNT(*) FROM publication_authors')->fetchColumn(),
            'topics' => (int)$this->db->pdo()->query('SELECT COUNT(*) FROM publication_topics')->fetchColumn(),
            'openalex_reviews' => $this->backfillOpenAlexQualityReviews($limit),
        ];
    }

    public function backfillOpenAlexQualityReviews(int $limit = 0): array
    {
        $limit = max(0, $limit);
        $sql = 'SELECT id, title, source_name, openalex_id, hidden, false_positive
                FROM publications
                WHERE openalex_id IS NOT NULL
                  AND openalex_id != ""
                  AND openalex_id NOT IN (SELECT openalex_id FROM openalex_quality_reviews)
                ORDER BY id';
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
        }

        $stmt = $this->db->pdo()->prepare(
            'INSERT OR IGNORE INTO openalex_quality_reviews (openalex_id, publication_id, decision, reason, reviewed_at, reviewer)
             VALUES (:openalex_id, :publication_id, :decision, :reason, :reviewed_at, :reviewer)'
        );
        $counts = ['approved' => 0, 'rejected' => 0, 'needs_review' => 0];
        foreach ($this->db->pdo()->query($sql)->fetchAll() as $row) {
            $openAlexId = self::normalizeOpenAlexId($row['openalex_id'] ?? null);
            if ($openAlexId === null) {
                continue;
            }
            $hidden = (int)($row['hidden'] ?? 0) === 1 || (int)($row['false_positive'] ?? 0) === 1;
            if ($hidden) {
                $decision = 'needs_review';
                $reason = 'existing OpenAlex-linked row is hidden or marked false positive';
            } elseif (($row['source_name'] ?? null) !== 'OpenAlex') {
                $decision = 'approved';
                $reason = 'OpenAlex enrichment attached to primary source record';
            } elseif (self::hasStandaloneOpenAlexTitleSignal((string)($row['title'] ?? ''))) {
                $decision = 'approved';
                $reason = 'existing visible OpenAlex row has direct title signal';
            } else {
                $decision = 'needs_review';
                $reason = 'existing OpenAlex row needs manual relevance review';
            }
            $stmt->execute([
                'openalex_id' => $openAlexId,
                'publication_id' => (int)$row['id'],
                'decision' => $decision,
                'reason' => $reason,
                'reviewed_at' => current_utc(),
                'reviewer' => 'system-backfill',
            ]);
            $counts[$decision]++;
        }
        return $counts;
    }

    private function syncNormalizedMetadata(int $publicationId, array $payload): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM publication_authors WHERE publication_id = :publication_id')->execute(['publication_id' => $publicationId]);
        $pdo->prepare('DELETE FROM publication_topics WHERE publication_id = :publication_id')->execute(['publication_id' => $publicationId]);

        $authorStmt = $pdo->prepare(
            'INSERT OR IGNORE INTO publication_authors (publication_id, author_name, author_key, position, orcid, openalex_id)
             VALUES (:publication_id, :author_name, :author_key, :position, :orcid, :openalex_id)'
        );
        foreach (self::authorNamesFromText((string)($payload['authors'] ?? '')) as $position => $authorName) {
            $identifiers = self::authorIdentifiersFromRaw((string)($payload['raw_json'] ?? ''), $authorName);
            $authorStmt->execute([
                'publication_id' => $publicationId,
                'author_name' => $authorName,
                'author_key' => self::metadataKey($authorName),
                'position' => $position + 1,
                'orcid' => $identifiers['orcid'],
                'openalex_id' => $identifiers['openalex_id'],
            ]);
        }

        $topicStmt = $pdo->prepare(
            'INSERT OR IGNORE INTO publication_topics (publication_id, topic, topic_key, source)
             VALUES (:publication_id, :topic, :topic_key, :source)'
        );
        foreach (self::splitMetadataList((string)($payload['topic_tags'] ?? '')) as $topic) {
            $topicStmt->execute([
                'publication_id' => $publicationId,
                'topic' => $topic,
                'topic_key' => self::metadataKey($topic),
                'source' => 'classifier',
            ]);
        }
    }

    private static function hasStandaloneOpenAlexTitleSignal(string $title): bool
    {
        $lower = mb_strtolower($title, 'UTF-8');
        return preg_match('/\b(psilocybin|psilocin|psilocybe|magic mushroom|psychedelic mushroom|4[\s-]?ho[\s-]?dmt|4[\s-]?hydroxy[\s-]?dmt)\b/u', $lower) === 1;
    }

    private static function isNonScholarlyDownloadArtifact(string $title, string $abstract): bool
    {
        $text = mb_strtolower($title . ' ' . $abstract, 'UTF-8');
        return str_contains($text, '#downloadbook')
            || str_contains($text, 'read online =>')
            || str_contains($text, 'download book =>')
            || preg_match('/\b(best\\s*\\[pdf\\]|epub download|full pdf online|free download pdf)\\b/u', $text) === 1;
    }

    private static function hasBroadPsychedelicTitleSignal(string $title): bool
    {
        $lower = mb_strtolower($title, 'UTF-8');
        return preg_match('/\b(psychedelic|psychedelics|hallucinogen|hallucinogens|entheogen|entheogens|tryptamine|tryptamines|serotonergic|5[\s-]?ht2a|microdosing|brain circuit|brain entropy|fmri|neuroimaging)\b/u', $lower) === 1;
    }

    private static function hasExplicitPsilocybinEvidence(array $row): bool
    {
        $title = (string)($row['title'] ?? '');
        if (self::hasStandaloneOpenAlexTitleSignal($title)) {
            return true;
        }
        if (!self::hasBroadPsychedelicTitleSignal($title)) {
            return false;
        }
        $metadataText = mb_strtolower(implode(' ', [
            (string)($row['abstract'] ?? ''),
            (string)($row['keywords'] ?? ''),
            (string)($row['substance_tags'] ?? ''),
            (string)($row['raw_json'] ?? ''),
        ]), 'UTF-8');
        return preg_match('/\b(psilocybin|psilocin|4[\s-]?ho[\s-]?dmt|4[\s-]?hydroxy[\s-]?dmt)\b/u', $metadataText) === 1;
    }

    public function restoreOverQuarantinedOpenAlexRows(int $limit = 5000, bool $dryRun = false): array
    {
        $limit = max(1, min($limit, 20000));
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, title, abstract, keywords, substance_tags, raw_json, doi, pubmed_id,
                    openalex_id, normalized_title, source_name, publication_date, curation_notes
             FROM publications
             WHERE (hidden = 1 OR false_positive = 1)
               AND curation_notes LIKE "OpenAlex %"
             ORDER BY publication_date DESC, id DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $update = $this->db->pdo()->prepare(
            'UPDATE publications
             SET hidden = 0,
                 false_positive = 0,
                 curation_locked = 0,
                 curation_notes = NULL
             WHERE id = :id'
        );
        $restored = [];
        $reviewed = 0;
        $keptNoExplicitEvidence = 0;
        $keptVisibleDuplicate = 0;
        foreach ($stmt->fetchAll() as $row) {
            $reviewed++;
            if (!self::hasExplicitPsilocybinEvidence($row)) {
                $keptNoExplicitEvidence++;
                continue;
            }
            if ($this->hasVisibleDuplicateForStoredRow($row)) {
                $keptVisibleDuplicate++;
                continue;
            }
            if (!$dryRun) {
                $update->execute(['id' => (int)$row['id']]);
            }
            if (!empty($row['openalex_id'])) {
                if (!$dryRun) {
                    $this->recordOpenAlexQualityReview((string)$row['openalex_id'], (int)$row['id'], 'approved', 'restored after quarantine visibility review');
                }
            }
            $restored[] = [
                'id' => (int)$row['id'],
                'title' => (string)$row['title'],
                'doi' => (string)($row['doi'] ?? ''),
                'source_name' => (string)($row['source_name'] ?? ''),
            ];
        }

        return [
            'reviewed' => $reviewed,
            'restored' => count($restored),
            'kept_no_explicit_evidence' => $keptNoExplicitEvidence,
            'kept_visible_duplicate' => $keptVisibleDuplicate,
            'dry_run' => $dryRun,
            'sample' => array_slice($restored, 0, 20),
        ];
    }

    private function hasVisibleDuplicateForStoredRow(array $row): bool
    {
        $parts = [];
        $params = ['id' => (int)$row['id']];
        foreach (['doi', 'pubmed_id', 'normalized_title'] as $field) {
            $value = trim((string)($row[$field] ?? ''));
            if ($value === '') {
                continue;
            }
            $parts[] = $field . ' = :' . $field;
            $params[$field] = $value;
        }
        if (!$parts) {
            return false;
        }
        return $this->one(
            'SELECT id FROM publications
             WHERE id != :id
               AND hidden = 0
               AND false_positive = 0
               AND (' . implode(' OR ', $parts) . ')
             LIMIT 1',
            $params
        ) !== null;
    }

    private function preservePrimaryRecordDuringOpenAlexEnrichment(array $payload, array $existing): array
    {
        foreach ([
            'title',
            'normalized_title',
            'authors',
            'abstract',
            'journal',
            'publication_date',
            'publication_year',
            'doi',
            'pubmed_id',
            'source_url',
            'keywords',
            'substance_tags',
            'topic_tags',
            'study_type',
            'source_name',
            'publication_status',
        ] as $field) {
            $payload[$field] = $existing[$field] ?? $payload[$field] ?? null;
        }

        if (!empty($existing['openalex_id'])) {
            $payload['openalex_id'] = $existing['openalex_id'];
        }

        $existingRaw = json_decode((string)($existing['raw_json'] ?? ''), true);
        $incomingRaw = json_decode((string)($payload['raw_json'] ?? ''), true);
        if (!is_array($existingRaw)) {
            $existingRaw = [];
        }
        if (is_array($incomingRaw)) {
            $existingRaw['openalex_enrichment'] = $incomingRaw;
        }
        $payload['raw_json'] = $existingRaw
            ? json_encode($existingRaw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : ($existing['raw_json'] ?? null);

        return $payload;
    }

    public function findDuplicate(?string $doi, ?string $pubmedId, string $normalizedTitle, ?string $openAlexId = null): ?array
    {
        if ($doi !== null) {
            $row = $this->bestDuplicate('doi = :doi', ['doi' => $doi]);
            if ($row) {
                return $row;
            }
        }
        if ($pubmedId !== null) {
            $row = $this->bestDuplicate('pubmed_id = :pubmed_id', ['pubmed_id' => $pubmedId]);
            if ($row) {
                return $row;
            }
        }
        if ($openAlexId !== null) {
            $row = $this->bestDuplicate('openalex_id = :openalex_id', ['openalex_id' => $openAlexId]);
            if ($row) {
                return $row;
            }
        }
        if ($normalizedTitle !== '') {
            return $this->bestDuplicate('normalized_title = :title', ['title' => $normalizedTitle]);
        }
        return null;
    }

    private function bestDuplicate(string $where, array $params): ?array
    {
        return $this->one(
            'SELECT * FROM publications WHERE ' . $where . '
             ORDER BY hidden ASC,
                      false_positive ASC,
                      CASE WHEN source_name = "OpenAlex" THEN 1 ELSE 0 END ASC,
                      id ASC
             LIMIT 1',
            $params
        );
    }

    public static function normalizeOpenAlexId(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (preg_match('~(?:https?://openalex\.org/)?(W\d+)~i', $value, $match)) {
            return 'https://openalex.org/' . strtoupper($match[1]);
        }
        return null;
    }

    public function search(array $filters): array
    {
        [$where, $params] = $this->whereClause($filters);
        $sortKey = (string)($filters['sort'] ?? 'newest');
        $sort = $sortKey === 'oldest' ? 'ASC' : 'DESC';
        $orderColumn = $sortKey === 'newly_added' ? 'date_added' : 'publication_date';
        $perPage = (string)($filters['per_page'] ?? '20');
        $showAll = mb_strtolower($perPage, 'UTF-8') === 'all';

        $countStmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM publications ' . $where);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $limit = $showAll ? max($total, 1) : min(max((int)$perPage, 5), 200);
        $page = $showAll ? 1 : max((int)($filters['page'] ?? 1), 1);
        $offset = $showAll ? 0 : (($page - 1) * $limit);

        $sql = 'SELECT * FROM publications ' . $where . ' ORDER BY ' . $orderColumn . ' ' . $sort . ', id ' . $sort . ' LIMIT :limit OFFSET :offset';
        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'rows' => array_map('clean_paper', $stmt->fetchAll()),
            'total' => $total,
            'page' => $page,
            'per_page' => $showAll ? 'all' : $limit,
            'pages' => max(1, (int)ceil($total / $limit)),
        ];
    }

    public function stats(): array
    {
        $pdo = $this->db->pdo();
        $journalWhere = $this->formalJournalWhere();
        return [
            'total' => (int)$pdo->query('SELECT COUNT(*) FROM publications WHERE hidden = 0 AND false_positive = 0')->fetchColumn(),
            'psilocybin' => (int)$pdo->query("SELECT COUNT(*) FROM publications WHERE substance_tags LIKE '%psilocybin%' AND hidden = 0 AND false_positive = 0")->fetchColumn(),
            'psilocin' => (int)$pdo->query("SELECT COUNT(*) FROM publications WHERE substance_tags LIKE '%psilocin%' AND hidden = 0 AND false_positive = 0")->fetchColumn(),
            'journals' => (int)$pdo->query('SELECT COUNT(DISTINCT journal) FROM publications WHERE ' . $journalWhere)->fetchColumn(),
            'last_checked' => $pdo->query("SELECT MAX(last_checked) FROM publications")->fetchColumn() ?: null,
        ];
    }

    public function latest(int $limit = 5, int $offset = 0): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM publications WHERE publication_date IS NOT NULL AND hidden = 0 AND false_positive = 0 ORDER BY publication_date DESC, id DESC LIMIT :limit OFFSET :offset');
        $stmt->bindValue(':limit', max(1, min($limit, 20)), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();
        return array_map('clean_paper', $stmt->fetchAll());
    }

    public function journals(): array
    {
        return $this->db->pdo()->query('SELECT journal, COUNT(*) count FROM publications WHERE ' . $this->formalJournalWhere() . ' GROUP BY journal ORDER BY journal LIMIT 500')->fetchAll();
    }

    public function authors(?string $query = null, int $limit = 100): array
    {
        return $this->authorCounts(max(1, min($limit, 500)), $query);
    }

    public function years(): array
    {
        return $this->db->pdo()->query("SELECT publication_year year, COUNT(*) count FROM publications WHERE publication_year IS NOT NULL GROUP BY publication_year ORDER BY publication_year DESC")->fetchAll();
    }

    public function recentForAlert(array $subscription, string $since): array
    {
        $filters = [
            'from' => $since,
            'substances' => array_filter(explode(',', (string)$subscription['substances'])),
            'q' => $subscription['keywords'] ?: null,
            'author' => $subscription['author'] ?: null,
            'journal' => $subscription['journal'] ?: null,
            'topic' => $subscription['topic'] ?: null,
            'cited_doi' => $subscription['cited_doi'] ?: null,
            'per_page' => 100,
            'page' => 1,
        ];
        $result = $this->search($filters);
        return $result['rows'];
    }

    public static function detectSubstances(string $text): string
    {
        $lower = mb_strtolower($text, 'UTF-8');
        $tags = [];
        $hasMagicMushroomContext = preg_match('/\bmagic mushroom(s)?\b/', $lower)
            && preg_match('/\b(psychedelic|hallucinogenic|psilocybe|psilocybin|psilocin|tryptamine)\b/', $lower);
        if (preg_match('/\bpsilocybin(?:s|e|en)?\b/', $lower) || $hasMagicMushroomContext) {
            $tags[] = 'psilocybin';
        }
        if (preg_match('/\bpsilocin(?:s|e|en)?\b|\b4[\s-]?ho[\s-]?dmt\b/', $lower)) {
            $tags[] = 'psilocin';
        }
        return implode(',', array_values(array_unique($tags)));
    }

    private function whereClause(array $filters): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['q'])) {
            $queryParts = [];
            if ($this->hasFts()) {
                $ftsQuery = $this->ftsQuery((string)$filters['q']);
                if ($ftsQuery !== null) {
                    $queryParts[] = 'id IN (SELECT rowid FROM publications_fts WHERE publications_fts MATCH :q_fts)';
                    $params['q_fts'] = $ftsQuery;
                }
            } else {
                $queryParts[] = '(title LIKE :q OR abstract LIKE :q OR authors LIKE :q OR keywords LIKE :q OR journal LIKE :q)';
                $params['q'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string)$filters['q']) . '%';
            }
            $this->appendIdentifierSearchParts($queryParts, $params, (string)$filters['q'], 'q');
            $this->appendAuthorSearchParts($queryParts, $params, (string)$filters['q'], 'q_author');
            if ($queryParts) {
                $where[] = '(' . implode(' OR ', $queryParts) . ')';
            } else {
                $where[] = 'id = -1';
            }
        }
        if (!empty($filters['author'])) {
            $authorParts = [];
            $this->appendAuthorSearchParts($authorParts, $params, (string)$filters['author'], 'author');
            if ($authorParts) {
                $where[] = '(' . implode(' OR ', $authorParts) . ')';
            } else {
                $where[] = 'id = -1';
            }
        }
        if (!empty($filters['topic'])) {
            $where[] = '(topic_tags LIKE :topic OR id IN (SELECT publication_id FROM publication_topics WHERE topic LIKE :topic OR topic_key LIKE :topic_key))';
            $params['topic'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string)$filters['topic']) . '%';
            $params['topic_key'] = '%' . self::metadataKey((string)$filters['topic']) . '%';
        }
        if (!empty($filters['cited_doi'])) {
            $citedDoi = normalize_doi((string)$filters['cited_doi']);
            if ($citedDoi === null) {
                $where[] = 'id = -1';
            } else {
                $where[] = 'raw_json LIKE :cited_doi';
                $params['cited_doi'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $citedDoi) . '%';
            }
        }
        if (!empty($filters['study_type'])) {
            $where[] = 'study_type = :study_type';
            $params['study_type'] = (string)$filters['study_type'];
        }
        if (!empty($filters['sources'])) {
            $sourceParts = [];
            foreach (array_values((array)$filters['sources']) as $i => $source) {
                $source = trim((string)$source);
                if ($source === '') {
                    continue;
                }
                if ($source === 'Unknown') {
                    $sourceParts[] = "(source_name IS NULL OR source_name = '')";
                    continue;
                }
                $key = 'source' . $i;
                $sourceParts[] = 'source_name = :' . $key;
                $params[$key] = $source;
            }
            if ($sourceParts) {
                $where[] = '(' . implode(' OR ', $sourceParts) . ')';
            }
        }
        if (!empty($filters['publication_statuses'])) {
            $statusParts = [];
            foreach (array_values((array)$filters['publication_statuses']) as $i => $status) {
                $status = self::normalizePublicationStatus((string)$status);
                if (!array_key_exists($status, self::publicationStatusOptions())) {
                    continue;
                }
                $key = 'publication_status' . $i;
                $statusParts[] = 'publication_status = :' . $key;
                $params[$key] = $status;
            }
            if ($statusParts) {
                $where[] = '(' . implode(' OR ', $statusParts) . ')';
            }
        }
        if (!empty($filters['substances'])) {
            $parts = [];
            foreach (array_values((array)$filters['substances']) as $i => $tag) {
                if (!in_array($tag, ['psilocybin', 'psilocin'], true)) {
                    continue;
                }
                $key = 'substance' . $i;
                $parts[] = 'substance_tags LIKE :' . $key;
                $params[$key] = '%' . $tag . '%';
            }
            if ($parts) {
                $where[] = '(' . implode(' OR ', $parts) . ')';
            }
        }
        if (!empty($filters['year']) && ctype_digit((string)$filters['year'])) {
            $where[] = 'publication_year = :year';
            $params['year'] = (int)$filters['year'];
        }
        if (!empty($filters['journal'])) {
            $where[] = 'journal = :journal';
            $params['journal'] = (string)$filters['journal'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'publication_date >= :from_date';
            $params['from_date'] = parse_date_or_null((string)$filters['from']);
        }
        if (!empty($filters['to'])) {
            $where[] = 'publication_date <= :to_date';
            $params['to_date'] = parse_date_or_null((string)$filters['to']);
        }
        $addedFrom = self::normalizeDateAddedFilter($filters['added_from'] ?? null, false);
        if ($addedFrom !== null) {
            $where[] = 'date_added >= :added_from';
            $params['added_from'] = $addedFrom;
        }
        $addedTo = self::normalizeDateAddedFilter($filters['added_to'] ?? null, true);
        if ($addedTo !== null) {
            $where[] = 'date_added <= :added_to';
            $params['added_to'] = $addedTo;
        }
        if (empty($filters['include_hidden'])) {
            $where[] = 'hidden = 0';
            $where[] = 'false_positive = 0';
        }

        return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
    }

    private function one(string $sql, array $params): ?array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function formalJournalWhere(): string
    {
        return "journal IS NOT NULL
            AND journal != ''
            AND hidden = 0
            AND false_positive = 0
            AND COALESCE(publication_status, 'published') NOT IN ('preprint', 'clinical trial')
            AND LOWER(TRIM(journal)) NOT IN ('clinicaltrials.gov', 'psyarxiv', 'biorxiv', 'medrxiv', 'research square')";
    }

    private static function normalizeDateAddedFilter(mixed $value, bool $endOfDay): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2})?$/', $value)) {
            $normalized = str_replace('T', ' ', $value);
            return strlen($normalized) === 16 ? $normalized . ':00' : substr($normalized, 0, 19);
        }
        return null;
    }

    private function appendIdentifierSearchParts(array &$parts, array &$params, string $query, string $prefix): void
    {
        $query = trim($query);
        if ($query === '') {
            return;
        }
        $doi = normalize_doi($query);
        if ($doi !== null) {
            $key = $prefix . '_doi';
            $parts[] = 'doi = :' . $key;
            $params[$key] = $doi;
        }
        if (preg_match('/\b\d{5,}\b/', $query, $match)) {
            $key = $prefix . '_pmid';
            $parts[] = 'pubmed_id = :' . $key;
            $params[$key] = $match[0];
        }
        if (preg_match('/\b\d{4}-\d{4}-\d{4}-\d{3}[\dX]\b/i', $query, $match)) {
            $key = $prefix . '_orcid';
            $parts[] = 'raw_json LIKE :' . $key;
            $params[$key] = '%' . $match[0] . '%';
        }
        $openAlexId = self::normalizeOpenAlexId($query);
        if ($openAlexId !== null) {
            $key = $prefix . '_openalex_id';
            $parts[] = 'openalex_id = :' . $key;
            $params[$key] = $openAlexId;
        }
    }

    private function appendAuthorSearchParts(array &$parts, array &$params, string $query, string $prefix): void
    {
        foreach (self::authorSearchVariants($query) as $i => $variant) {
            $key = $prefix . $i;
            $keyKey = $key . '_key';
            $parts[] = '(authors LIKE :' . $key . ' OR raw_json LIKE :' . $key . ' OR id IN (SELECT publication_id FROM publication_authors WHERE author_name LIKE :' . $key . ' OR author_key LIKE :' . $keyKey . '))';
            $params[$key] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $variant) . '%';
            $params[$keyKey] = '%' . self::metadataKey($variant) . '%';
        }
        foreach (self::authorInitialKeyPatterns($query) as $i => $pattern) {
            $key = $prefix . '_initial_' . $i;
            $parts[] = 'id IN (SELECT publication_id FROM publication_authors WHERE author_key LIKE :' . $key . ')';
            $params[$key] = $pattern;
        }
    }

    public static function authorSearchVariants(string $query): array
    {
        $clean = clean_scientific_text($query);
        $clean = preg_replace('/\bhttps?:\/\/orcid\.org\//i', '', $clean) ?? $clean;
        $clean = trim(preg_replace('/[^\p{L}\p{N}\-]+/u', ' ', $clean) ?? $clean);
        if ($clean === '') {
            return [];
        }
        $variants = [$clean];
        preg_match_all('/[\p{L}\p{N}\-]+/u', $clean, $matches);
        $tokens = array_values(array_filter($matches[0] ?? [], static fn(string $token): bool => $token !== ''));
        if (count($tokens) >= 2) {
            $last = array_pop($tokens);
            $initials = implode('', array_map(static fn(string $token): string => mb_substr($token, 0, 1, 'UTF-8'), $tokens));
            $spacedInitials = implode(' ', array_map(static fn(string $token): string => mb_substr($token, 0, 1, 'UTF-8'), $tokens));
            $first = $tokens[0] ?? '';
            $candidateVariants = mb_strlen($last, 'UTF-8') <= 2
                ? [
                    trim($first . ' ' . $last),
                    trim($first . ' ' . $last . '.'),
                    trim($first . ', ' . $last),
                ]
                : [
                    $last,
                    trim($last . ' ' . $initials),
                    trim($last . ' ' . $spacedInitials),
                    trim($last . ' ' . mb_substr($first, 0, 1, 'UTF-8')),
                    trim($first . ' ' . $last),
                    trim(mb_substr($first, 0, 1, 'UTF-8') . ' ' . $last),
                ];
            foreach ($candidateVariants as $variant) {
                if ($variant !== '') {
                    $variants[] = $variant;
                }
            }
        }
        return array_values(array_unique($variants));
    }

    private static function authorInitialKeyPatterns(string $query): array
    {
        $tokens = self::authorNameTokens($query);
        if (count($tokens) < 2) {
            return [];
        }
        $patterns = [];
        $addPatterns = static function (array $surnameTokens, string $initial) use (&$patterns): void {
            $surnameKey = self::metadataKey(implode(' ', $surnameTokens));
            $initialKey = self::metadataKey($initial);
            if ($surnameKey === '' || $initialKey === '') {
                return;
            }
            $patterns[] = $surnameKey . ' ' . $initialKey . '%';
            $patterns[] = $initialKey . '% ' . $surnameKey;
        };

        $last = $tokens[count($tokens) - 1];
        if (mb_strlen($last, 'UTF-8') <= 2) {
            $addPatterns(array_slice($tokens, 0, -1), $last);
        }
        $first = $tokens[0];
        if (mb_strlen($first, 'UTF-8') <= 2) {
            $addPatterns(array_slice($tokens, 1), $first);
        }

        return array_values(array_unique($patterns));
    }

    public function allForExport(array $filters, int $limit = 5000): array
    {
        [$where, $params] = $this->whereClause($filters);
        $sort = ($filters['sort'] ?? 'newest') === 'oldest' ? 'ASC' : 'DESC';
        $limit = min(max($limit, 1), 50000);
        $sql = 'SELECT * FROM publications ' . $where . ' ORDER BY publication_date ' . $sort . ', id ' . $sort . ' LIMIT :limit';
        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map('clean_paper', $stmt->fetchAll());
    }

    public function topics(): array
    {
        return $this->splitCounts('topic_tags');
    }

    public function studyTypes(): array
    {
        return $this->db->pdo()->query("SELECT study_type, COUNT(*) count FROM publications WHERE study_type IS NOT NULL AND study_type != '' AND hidden = 0 AND false_positive = 0 GROUP BY study_type ORDER BY count DESC, study_type")->fetchAll();
    }

    public function sources(): array
    {
        return $this->db->pdo()->query("SELECT COALESCE(NULLIF(source_name, ''), 'Unknown') source_name, COUNT(*) count FROM publications WHERE hidden = 0 AND false_positive = 0 GROUP BY COALESCE(NULLIF(source_name, ''), 'Unknown') ORDER BY CASE WHEN COALESCE(NULLIF(source_name, ''), 'Unknown') = 'PubMed' THEN 0 ELSE 1 END, count DESC, source_name")->fetchAll();
    }

    public function publicationStatuses(): array
    {
        return $this->db->pdo()->query("SELECT publication_status, COUNT(*) count FROM publications WHERE publication_status IS NOT NULL AND publication_status != '' AND hidden = 0 AND false_positive = 0 GROUP BY publication_status ORDER BY count DESC, publication_status")->fetchAll();
    }

    public static function publicationStatusOptions(): array
    {
        return [
            'published' => 'Published / peer reviewed',
            'preprint' => 'Preprint (not peer reviewed)',
            'clinical trial' => 'Clinical trial',
            'protocol' => 'Protocol',
            'review' => 'Review',
        ];
    }

    public static function normalizePublicationStatus(?string $status, ?string $sourceName = null, ?string $studyType = null): string
    {
        $value = mb_strtolower(trim((string)$status), 'UTF-8');
        $source = mb_strtolower(trim((string)$sourceName), 'UTF-8');
        $study = mb_strtolower(trim((string)$studyType), 'UTF-8');

        if (str_contains($source, 'biorxiv') || str_contains($source, 'medrxiv') || str_contains($source, 'psyarxiv') || str_contains($source, 'preprint')) {
            return 'preprint';
        }
        if (str_contains($source, 'clinicaltrials') || str_contains($source, 'clinical trials')) {
            return 'clinical trial';
        }
        if (in_array($value, ['preprint', 'published', 'protocol', 'review'], true)) {
            return $value;
        }
        if (in_array($value, ['clinical trial', 'clinical_trial', 'trial'], true)) {
            return 'clinical trial';
        }
        if (str_contains($study, 'protocol')) {
            return 'protocol';
        }
        if ($study === 'review') {
            return 'review';
        }
        return 'published';
    }

    public function analytics(): array
    {
        $pdo = $this->db->pdo();
        $journalWhere = $this->formalJournalWhere();
        return [
            'trends' => $pdo->query("SELECT publication_year year, COUNT(*) count FROM publications WHERE publication_year IS NOT NULL AND hidden = 0 AND false_positive = 0 GROUP BY publication_year ORDER BY publication_year")->fetchAll(),
            'timeline' => $pdo->query("SELECT publication_date date, COUNT(*) count FROM publications WHERE publication_date IS NOT NULL AND hidden = 0 AND false_positive = 0 GROUP BY publication_date ORDER BY publication_date")->fetchAll(),
            'timeline_papers' => $pdo->query("SELECT id, title, authors, journal, publication_date, publication_year, doi, pubmed_id, openalex_id, source_url, source_name, publication_status, substance_tags, topic_tags, study_type, date_added, last_checked, CASE WHEN abstract IS NOT NULL AND TRIM(abstract) != '' THEN 1 ELSE 0 END abstract_available FROM publications WHERE publication_date IS NOT NULL AND hidden = 0 AND false_positive = 0 ORDER BY publication_date DESC, title")->fetchAll(),
            'top_journals' => $pdo->query('SELECT journal, COUNT(*) count FROM publications WHERE ' . $journalWhere . ' GROUP BY journal ORDER BY count DESC, journal LIMIT 10')->fetchAll(),
            'study_types' => $this->studyTypes(),
            'topics' => array_slice($this->topics(), 0, 12),
            'top_authors' => $this->topAuthors(10),
        ];
    }

    public function findById(int $id): ?array
    {
        $paper = $this->one('SELECT * FROM publications WHERE id = :id', ['id' => $id]);
        return $paper ? clean_paper($paper) : null;
    }

    public function publicById(int $id): ?array
    {
        $paper = $this->one('SELECT * FROM publications WHERE id = :id AND hidden = 0 AND false_positive = 0', ['id' => $id]);
        return $paper ? clean_paper($paper) : null;
    }

    public function publicByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        if (!$ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare('SELECT * FROM publications WHERE hidden = 0 AND false_positive = 0 AND id IN (' . $placeholders . ') ORDER BY publication_date DESC, id DESC');
        $stmt->execute($ids);
        return array_map('clean_paper', $stmt->fetchAll());
    }

    public function authorProfile(string $name): ?array
    {
        $name = trim(clean_scientific_text($name));
        if ($name === '') {
            return null;
        }
        $papers = $this->search([
            'author' => $name,
            'substances' => ['psilocybin', 'psilocin'],
            'range' => 'all',
            'per_page' => 200,
        ]);
        if ((int)$papers['total'] === 0) {
            return null;
        }
        $topics = [];
        $journals = [];
        $years = [];
        $orcid = null;
        $openAlexId = null;
        foreach ($papers['rows'] as $paper) {
            foreach (array_filter(array_map('trim', explode(',', (string)($paper['topic_tags'] ?? '')))) as $topic) {
                $topics[$topic] = ($topics[$topic] ?? 0) + 1;
            }
            $journal = trim((string)($paper['journal'] ?? ''));
            if ($journal !== '') {
                $journals[$journal] = ($journals[$journal] ?? 0) + 1;
            }
            $year = (string)($paper['publication_year'] ?? '');
            if ($year !== '') {
                $years[$year] = ($years[$year] ?? 0) + 1;
            }
            $identifiers = self::authorIdentifiersFromRaw((string)($paper['raw_json'] ?? ''), $name);
            if ($orcid === null && $identifiers['orcid'] !== null) {
                $orcid = $identifiers['orcid'];
            }
            if ($openAlexId === null && $identifiers['openalex_id'] !== null) {
                $openAlexId = $identifiers['openalex_id'];
            }
        }
        arsort($topics);
        arsort($journals);
        krsort($years);
        return [
            'name' => $name,
            'count' => (int)$papers['total'],
            'orcid' => $orcid,
            'openalex_id' => $openAlexId,
            'topics' => array_slice($topics, 0, 12, true),
            'journals' => array_slice($journals, 0, 8, true),
            'years' => $years,
            'papers' => $papers['rows'],
        ];
    }

    private static function authorIdentifiersFromRaw(string $rawJson, string $name): array
    {
        $out = ['orcid' => null, 'openalex_id' => null];
        $raw = json_decode($rawJson, true);
        if (!is_array($raw)) {
            return $out;
        }
        $authorshipGroups = [(array)($raw['authorships'] ?? [])];
        if (isset($raw['openalex_enrichment']) && is_array($raw['openalex_enrichment'])) {
            $authorshipGroups[] = (array)($raw['openalex_enrichment']['authorships'] ?? []);
        }
        foreach ($authorshipGroups as $authorships) {
            foreach ($authorships as $authorship) {
            if (!is_array($authorship)) {
                continue;
            }
            $displayName = clean_scientific_text((string)($authorship['display_name'] ?? $authorship['author']['display_name'] ?? ''));
            if ($displayName === '' || !self::authorNamesCompatible($name, $displayName)) {
                continue;
            }
            $orcid = $authorship['orcid'] ?? $authorship['author']['orcid'] ?? null;
            if (is_string($orcid) && preg_match('/\b\d{4}-\d{4}-\d{4}-\d{3}[\dX]\b/i', $orcid, $match)) {
                $out['orcid'] = strtoupper($match[0]);
            }
            $openAlexId = $authorship['id'] ?? $authorship['author']['id'] ?? null;
            if (is_string($openAlexId) && preg_match('~https://openalex\.org/A\d+~i', $openAlexId, $match)) {
                $out['openalex_id'] = $match[0];
            }
            return $out;
            }
        }
        return $out;
    }

    private static function authorNamesCompatible(string $queryName, string $metadataName): bool
    {
        $queryTokens = self::authorNameTokens($queryName);
        $metadataTokens = self::authorNameTokens($metadataName);
        if (!$queryTokens || !$metadataTokens) {
            return false;
        }
        $queryLower = implode(' ', $queryTokens);
        $metadataLower = implode(' ', $metadataTokens);
        if ($queryLower === $metadataLower || str_contains($metadataLower, $queryLower)) {
            return true;
        }
        $querySurname = $queryTokens[0];
        $queryLast = $queryTokens[count($queryTokens) - 1];
        $metadataSurname = $metadataTokens[count($metadataTokens) - 1];
        $metadataFirst = $metadataTokens[0];
        if (mb_strlen($queryLast, 'UTF-8') <= 2) {
            return $querySurname === $metadataSurname && str_starts_with($metadataFirst, $queryLast);
        }
        return $queryLast === $metadataSurname && str_starts_with($metadataFirst, mb_substr($queryTokens[0], 0, 1, 'UTF-8'));
    }

    private static function authorNameTokens(string $name): array
    {
        $name = clean_scientific_text($name);
        $name = mb_strtolower($name, 'UTF-8');
        preg_match_all('/[\p{L}\p{N}\-]+/u', $name, $matches);
        return array_values(array_filter($matches[0] ?? [], static fn(string $token): bool => $token !== ''));
    }

    public function relatedPapers(array $paper, int $limit = 8): array
    {
        $terms = array_values(array_unique(array_filter(array_merge(
            array_map('trim', explode(',', (string)($paper['topic_tags'] ?? ''))),
            array_map('trim', explode(',', (string)($paper['keywords'] ?? ''))),
            array_map('trim', explode(',', (string)($paper['substance_tags'] ?? '')))
        ), static fn(string $term): bool => mb_strlen($term, 'UTF-8') >= 3)));
        $author = trim((string)explode(',', (string)($paper['authors'] ?? ''))[0]);
        if ($author !== '') {
            $terms[] = $author;
        }
        if (!$terms) {
            return [];
        }
        $candidate = $this->search([
            'q' => implode(' ', array_slice($terms, 0, 6)),
            'substances' => ['psilocybin', 'psilocin'],
            'range' => 'all',
            'per_page' => 80,
        ]);
        $sourceId = (int)($paper['id'] ?? 0);
        $scores = [];
        foreach ($candidate['rows'] as $row) {
            if ((int)$row['id'] === $sourceId) {
                continue;
            }
            $haystack = mb_strtolower(implode(' ', [
                $row['title'] ?? '',
                $row['abstract'] ?? '',
                $row['authors'] ?? '',
                $row['keywords'] ?? '',
                $row['topic_tags'] ?? '',
                $row['substance_tags'] ?? '',
            ]), 'UTF-8');
            $score = 0;
            foreach ($terms as $term) {
                if (str_contains($haystack, mb_strtolower($term, 'UTF-8'))) {
                    $score++;
                }
            }
            if ($score > 0) {
                $scores[] = ['score' => $score, 'paper' => $row];
            }
        }
        usort($scores, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        return array_map(static fn(array $row): array => $row['paper'], array_slice($scores, 0, max(1, min($limit, 20))));
    }

    public function citedReferences(array $paper, int $limit = 30): array
    {
        $raw = json_decode((string)($paper['raw_json'] ?? ''), true);
        if (!is_array($raw)) {
            return [];
        }
        $dois = [];
        foreach (['reference_dois', 'references'] as $key) {
            foreach ((array)($raw[$key] ?? []) as $item) {
                $doi = is_array($item) ? ($item['doi'] ?? null) : $item;
                $doi = normalize_doi(is_string($doi) ? $doi : null);
                if ($doi !== null) {
                    $dois[$doi] = true;
                }
            }
        }
        return array_slice(array_keys($dois), 0, max(1, min($limit, 100)));
    }

    public function citingPapers(string $doi, int $limit = 20): array
    {
        $doi = normalize_doi($doi);
        if ($doi === null) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare('SELECT * FROM publications WHERE hidden = 0 AND false_positive = 0 AND raw_json LIKE :doi ORDER BY publication_date DESC, id DESC LIMIT :limit');
        $stmt->bindValue(':doi', '%' . str_replace(['%', '_'], ['\\%', '\\_'], $doi) . '%');
        $stmt->bindValue(':limit', max(1, min($limit, 100)), PDO::PARAM_INT);
        $stmt->execute();
        return array_map('clean_paper', $stmt->fetchAll());
    }

    public function citationNetwork(array $filters = [], int $paperId = 0, int $limit = 1): array
    {
        $limit = max(1, min($limit, 48));
        $seedRows = [];
        $focusPaper = null;

        if ($paperId > 0) {
            $focusPaper = $this->publicById($paperId);
            if ($focusPaper) {
                $seedRows[] = $focusPaper;
                if ($limit > 1) {
                    foreach ($this->relatedPapers($focusPaper, min(10, $limit - 1)) as $row) {
                        $seedRows[(int)$row['id']] = $row;
                    }
                }
                if ($limit > count($seedRows)) {
                    foreach ($this->citingPapers((string)($focusPaper['doi'] ?? ''), min(10, $limit - count($seedRows))) as $row) {
                        $seedRows[(int)$row['id']] = $row;
                    }
                }
            }
        }

        if (!$seedRows) {
            $filters['substances'] = $filters['substances'] ?? ['psilocybin', 'psilocin'];
            $filters['range'] = $filters['range'] ?? 'all';
            $filters['per_page'] = $limit;
            $filters['page'] = 1;
            $result = $this->search($filters);
            $seedRows = $result['rows'];
        }

        $seedRows = array_slice(array_values($seedRows), 0, $limit);
        $nodes = [];
        $edges = [];
        $internalByDoi = [];
        $internalByOpenAlex = [];
        $externalReferenceCount = 0;

        $addNode = static function (string $id, array $node) use (&$nodes): void {
            if (isset($nodes[$id])) {
                $nodes[$id]['weight'] = max((float)($nodes[$id]['weight'] ?? 1), (float)($node['weight'] ?? 1));
                foreach ($node as $key => $value) {
                    if ($key === 'weight' || $key === 'id') {
                        continue;
                    }
                    if ($value !== '' && $value !== [] && $value !== null && (empty($nodes[$id][$key]) || in_array($key, ['reference_match', 'matched_reference_doi', 'related'], true))) {
                        $nodes[$id][$key] = $value;
                    }
                }
                return;
            }
            $nodes[$id] = ['id' => $id] + $node;
        };
        $addEdge = static function (string $source, string $target, string $type, float $weight = 1.0) use (&$edges): void {
            if ($source === '' || $target === '' || $source === $target) {
                return;
            }
            $key = $source . '|' . $target . '|' . $type;
            if (isset($edges[$key])) {
                $edges[$key]['weight'] += $weight;
                return;
            }
            $edges[$key] = [
                'id' => $key,
                'source' => $source,
                'target' => $target,
                'type' => $type,
                'weight' => $weight,
            ];
        };
        $paperNodePayload = function (array $paper, float $weight, array $extra = []): array {
            $paperIdValue = (int)($paper['id'] ?? 0);
            $related = [];
            if ($paperIdValue > 0) {
                foreach ($this->relatedPapers($paper, 5) as $relatedPaper) {
                    $related[] = [
                        'id' => (int)$relatedPaper['id'],
                        'label' => (string)($relatedPaper['title'] ?? 'Untitled publication'),
                        'url' => 'publication.php?id=' . (int)$relatedPaper['id'],
                        'authors' => (string)($relatedPaper['authors'] ?? ''),
                        'date' => (string)($relatedPaper['publication_date'] ?? ''),
                        'journal' => (string)($relatedPaper['journal'] ?? ''),
                        'doi' => (string)($relatedPaper['doi'] ?? ''),
                        'source' => (string)($relatedPaper['source_name'] ?? ''),
                        'status' => (string)($relatedPaper['publication_status'] ?? 'published'),
                    ];
                }
            }
            return [
                'type' => 'paper',
                'label' => (string)($paper['title'] ?? 'Untitled publication'),
                'url' => 'publication.php?id=' . $paperIdValue,
                'authors' => (string)($paper['authors'] ?? ''),
                'date' => (string)($paper['publication_date'] ?? ''),
                'journal' => (string)($paper['journal'] ?? ''),
                'doi' => (string)(normalize_doi($paper['doi'] ?? null) ?? ''),
                'pubmed_id' => (string)($paper['pubmed_id'] ?? ''),
                'source' => (string)($paper['source_name'] ?? ''),
                'status' => (string)($paper['publication_status'] ?? 'published'),
                'weight' => $weight,
                'related' => $related,
            ] + $extra;
        };

        foreach ($seedRows as $paper) {
            $paperIdValue = (int)($paper['id'] ?? 0);
            if ($paperIdValue <= 0) {
                continue;
            }
            $nodeId = 'paper:' . $paperIdValue;
            $doi = normalize_doi($paper['doi'] ?? null);
            $openAlexId = self::normalizeOpenAlexId($paper['openalex_id'] ?? null);
            if ($doi !== null) {
                $internalByDoi[$doi] = $paper;
            }
            if ($openAlexId !== null) {
                $internalByOpenAlex[$openAlexId] = $paper;
            }
            $addNode($nodeId, $paperNodePayload($paper, 2 + min(8, max(0, (int)$this->rawCitationCount($paper))), ['seed' => true]));

            foreach (array_slice(self::splitMetadataList((string)($paper['authors'] ?? '')), 0, 4) as $author) {
                $authorNode = 'author:' . normalize_title($author);
                $addNode($authorNode, [
                    'type' => 'author',
                    'label' => $author,
                    'url' => 'authors.php?author=' . rawurlencode($author),
                    'weight' => 1.4,
                ]);
                $addEdge($nodeId, $authorNode, 'author', 0.7);
            }

            $journal = trim((string)($paper['journal'] ?? ''));
            if ($journal !== '') {
                $journalNode = 'journal:' . normalize_title($journal);
                $addNode($journalNode, [
                    'type' => 'journal',
                    'label' => $journal,
                    'url' => './?' . http_build_query(['journal' => $journal, 'range' => 'all', 'page' => 1]) . '#papers',
                    'weight' => 1.2,
                ]);
                $addEdge($nodeId, $journalNode, 'journal', 0.45);
            }

            foreach (array_slice(self::splitMetadataList((string)($paper['topic_tags'] ?? '')), 0, 5) as $topic) {
                $topicNode = 'topic:' . normalize_title($topic);
                $addNode($topicNode, [
                    'type' => 'topic',
                    'label' => $topic,
                    'url' => './?' . http_build_query(['topic' => $topic, 'range' => 'all', 'page' => 1]) . '#papers',
                    'weight' => 1.3,
                ]);
                $addEdge($nodeId, $topicNode, 'topic', 0.55);
            }
        }

        foreach ($seedRows as $paper) {
            $paperNode = 'paper:' . (int)($paper['id'] ?? 0);
            foreach ($this->citedReferences($paper, 18) as $doi) {
                $targetPaper = $internalByDoi[$doi] ?? $this->publicByDoi($doi);
                if ($targetPaper) {
                    $targetNode = 'paper:' . (int)$targetPaper['id'];
                    $addNode($targetNode, $paperNodePayload($targetPaper, 2.2, [
                        'reference_match' => true,
                        'matched_reference_doi' => $doi,
                    ]));
                    $addEdge($paperNode, $targetNode, 'cites', 1.35);
                    continue;
                }
                $referenceNode = 'doi:' . normalize_title($doi);
                $addNode($referenceNode, [
                    'type' => 'reference',
                    'label' => $doi,
                    'url' => 'https://doi.org/' . rawurlencode($doi),
                    'weight' => 0.9,
                ]);
                $addEdge($paperNode, $referenceNode, 'cites', 0.85);
                $externalReferenceCount++;
            }

            foreach ($this->openAlexReferencedWorks($paper, 18) as $workId) {
                $targetPaper = $internalByOpenAlex[$workId] ?? $this->publicByOpenAlexId($workId);
                if ($targetPaper) {
                    $targetNode = 'paper:' . (int)$targetPaper['id'];
                    $addNode($targetNode, $paperNodePayload($targetPaper, 2.2, ['reference_match' => true]));
                    $addEdge($paperNode, $targetNode, 'cites', 1.2);
                }
            }
        }

        $nodes = array_values(array_slice($nodes, 0, 160, true));
        $allowed = array_fill_keys(array_column($nodes, 'id'), true);
        $edges = array_values(array_filter($edges, static fn(array $edge): bool => isset($allowed[$edge['source']], $allowed[$edge['target']])));

        return [
            'nodes' => $nodes,
            'edges' => array_slice($edges, 0, 260),
            'stats' => [
                'seed_papers' => count($seedRows),
                'nodes' => count($nodes),
                'edges' => min(count($edges), 260),
                'external_references' => $externalReferenceCount,
                'focus_paper' => $focusPaper ? (int)$focusPaper['id'] : null,
            ],
        ];
    }

    private function publicByDoi(string $doi): ?array
    {
        $doi = normalize_doi($doi);
        if ($doi === null) {
            return null;
        }
        $paper = $this->one('SELECT * FROM publications WHERE doi = :doi AND hidden = 0 AND false_positive = 0', ['doi' => $doi]);
        return $paper ? clean_paper($paper) : null;
    }

    private function publicByOpenAlexId(string $openAlexId): ?array
    {
        $openAlexId = self::normalizeOpenAlexId($openAlexId);
        if ($openAlexId === null) {
            return null;
        }
        $paper = $this->one('SELECT * FROM publications WHERE openalex_id = :openalex_id AND hidden = 0 AND false_positive = 0', ['openalex_id' => $openAlexId]);
        return $paper ? clean_paper($paper) : null;
    }

    private function openAlexReferencedWorks(array $paper, int $limit = 30): array
    {
        $raw = json_decode((string)($paper['raw_json'] ?? ''), true);
        if (!is_array($raw)) {
            return [];
        }
        $values = [];
        foreach ((array)($raw['referenced_works'] ?? []) as $workId) {
            $workId = self::normalizeOpenAlexId(is_string($workId) ? $workId : null);
            if ($workId !== null) {
                $values[$workId] = true;
            }
        }
        return array_slice(array_keys($values), 0, max(1, min($limit, 100)));
    }

    private function rawCitationCount(array $paper): int
    {
        $raw = json_decode((string)($paper['raw_json'] ?? ''), true);
        if (!is_array($raw)) {
            return 0;
        }
        return max(0, (int)($raw['cited_by_count'] ?? 0));
    }

    public function trials(array $filters = [], int $limit = 100): array
    {
        $filters['publication_statuses'] = ['clinical trial'];
        $filters['substances'] = $filters['substances'] ?? ['psilocybin', 'psilocin'];
        $filters['per_page'] = max(10, min($limit, 200));
        $filters['page'] = 1;
        return $this->search($filters);
    }

    public function evidenceMap(): array
    {
        $rows = $this->db->pdo()->query("SELECT publication_year, topic_tags, study_type, substance_tags, COUNT(*) count FROM publications WHERE hidden = 0 AND false_positive = 0 AND publication_year IS NOT NULL GROUP BY publication_year, topic_tags, study_type, substance_tags ORDER BY publication_year DESC")->fetchAll();
        $map = [];
        foreach ($rows as $row) {
            foreach (array_filter(array_map('trim', explode(',', (string)$row['topic_tags']))) as $topic) {
                foreach (array_filter(array_map('trim', explode(',', (string)$row['substance_tags']))) as $substance) {
                    $key = $topic . '|' . (string)$row['study_type'] . '|' . $substance . '|' . (string)$row['publication_year'];
                    $map[$key] = [
                        'topic' => $topic,
                        'study_type' => (string)$row['study_type'],
                        'substance' => $substance,
                        'year' => (int)$row['publication_year'],
                        'count' => ($map[$key]['count'] ?? 0) + (int)$row['count'],
                    ];
                }
            }
        }
        usort($map, static fn(array $a, array $b): int => [$b['year'], $b['count']] <=> [$a['year'], $a['count']]);
        return $map;
    }

    public function curate(int $id, array $fields): void
    {
        $allowed = ['topic_tags', 'study_type', 'substance_tags', 'hidden', 'false_positive', 'curation_notes', 'merged_into_id'];
        $sets = [];
        $params = ['id' => $id];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $fields)) {
                $sets[] = $field . ' = :' . $field;
                $params[$field] = $fields[$field];
            }
        }
        if (!$sets) {
            return;
        }
        $sets[] = 'curation_locked = 1';
        $this->db->pdo()->prepare('UPDATE publications SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
    }

    public function merge(int $sourceId, int $targetId): void
    {
        if ($sourceId === $targetId) {
            throw new InvalidArgumentException('Source and target must differ.');
        }
        $this->curate($sourceId, [
            'hidden' => 1,
            'merged_into_id' => $targetId,
            'curation_notes' => 'Merged into publication #' . $targetId,
        ]);
    }

    public static function classifyTopics(string $text): string
    {
        $lower = mb_strtolower($text, 'UTF-8');
        $map = [
            'Depression' => ['depression', 'depressive', 'mdd', 'treatment-resistant depression'],
            'Anxiety' => ['anxiety', 'anxious'],
            'PTSD' => ['ptsd', 'post-traumatic stress', 'posttraumatic stress'],
            'Addiction' => ['addiction', 'alcohol use', 'substance use', 'tobacco', 'smoking', 'opioid', 'cocaine'],
            'OCD' => ['obsessive-compulsive', 'ocd'],
            'Eating Disorders' => ['eating disorder', 'anorexia', 'bulimia'],
            'End-of-Life Distress' => ['end-of-life', 'life-threatening', 'terminal', 'palliative'],
            'Chronic Pain' => ['chronic pain', 'pain'],
            'Headache / Migraine' => ['headache', 'migraine', 'cluster headache'],
            'Neuroplasticity' => ['neuroplasticity', 'plasticity', 'synaptogenesis', 'synaptic'],
            'Neurogenesis' => ['neurogenesis'],
            'Brain Imaging' => ['fmri', 'neuroimaging', 'brain imaging', 'imaging', 'eeg', 'pet scan'],
            'Pharmacology' => ['pharmacokinetic', 'pharmacodynamic', 'pharmacology', 'metabolism'],
            'Mechanism of Action' => ['mechanism of action', 'mechanisms', 'signaling', 'pathway'],
            'Receptor Pharmacology' => ['receptor', '5-ht', 'serotonin', '5ht2a', '5-ht2a'],
            'Default Mode Network' => ['default mode network', 'dmn'],
            'Consciousness' => ['consciousness', 'conscious state'],
            'Biomarkers' => ['biomarker', 'marker'],
            'Aging' => ['aging', 'ageing', 'older adult', 'geriatric'],
            'Telomeres' => ['telomere', 'telomerase'],
            'Epigenetics' => ['epigenetic', 'methylation'],
            'Cellular Senescence' => ['senescence', 'senescent'],
            'Longevity' => ['longevity', 'lifespan', 'healthspan'],
            'Mitochondrial Function' => ['mitochondria', 'mitochondrial'],
            'Inflammaging' => ['inflammaging'],
            'Biological Age' => ['biological age', 'epigenetic age'],
            'Oxidative Stress' => ['oxidative stress', 'reactive oxygen'],
            'Microdosing' => ['microdosing', 'microdose'],
            'Wellbeing' => ['wellbeing', 'well-being', 'wellness'],
            'Resilience' => ['resilience'],
            'Personality Change' => ['personality'],
            'Emotional Processing' => ['emotion', 'emotional processing'],
            'Creativity' => ['creativity', 'creative'],
            'Spirituality' => ['spirituality', 'spiritual'],
            'Mystical Experience' => ['mystical', 'mysticism'],
            'Psychological Flexibility' => ['psychological flexibility'],
            'Clinical Trial' => ['clinical trial', 'phase 1', 'phase 2', 'phase i', 'phase ii'],
            'Randomized Controlled Trial' => ['randomized controlled', 'randomised controlled', 'rct'],
            'Meta-Analysis' => ['meta-analysis', 'metaanalysis'],
            'Systematic Review' => ['systematic review'],
            'Review Article' => ['review'],
            'Case Report' => ['case report', 'case series'],
            'Observational Study' => ['observational', 'cohort', 'survey'],
            'Animal Study' => ['mouse', 'mice', 'rat ', 'animal model', 'preclinical'],
            'In Vitro Study' => ['in vitro', 'cell culture'],
            'Healthy Volunteers' => ['healthy volunteer', 'healthy participant'],
            'Older Adults' => ['older adult', 'elderly'],
            'Adolescents' => ['adolescent', 'youth'],
            'Cancer Patients' => ['cancer patient', 'oncology', 'cancer-related'],
            'Treatment-Resistant Depression' => ['treatment-resistant depression', 'trd'],
            'Veterans' => ['veteran'],
            'Healthcare Workers' => ['healthcare worker', 'health care worker', 'clinician'],
            'Safety' => ['safety', 'tolerability', 'risk'],
            'Adverse Events' => ['adverse event', 'side effect'],
            'Toxicity' => ['toxicity', 'toxicology'],
            'Drug Interactions' => ['drug interaction', 'interaction'],
            'Contraindications' => ['contraindication'],
            'Abuse Liability' => ['abuse liability', 'dependence potential'],
            'Genomics' => ['genomic', 'genome'],
            'Transcriptomics' => ['transcriptomic', 'transcriptome'],
            'Proteomics' => ['proteomic', 'proteome'],
            'Metabolomics' => ['metabolomic', 'metabolome'],
            'Microbiome' => ['microbiome', 'microbiota'],
            'Inflammation' => ['inflammation', 'inflammatory'],
            'Immune Function' => ['immune', 'immunological'],
        ];
        $tags = [];
        foreach ($map as $tag => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($lower, $needle)) {
                    $tags[] = $tag;
                    break;
                }
            }
        }
        return implode(',', array_values(array_unique($tags ?: ['General'])));
    }

    public static function classifyStudyType(string $text): string
    {
        $lower = mb_strtolower($text, 'UTF-8');
        if (str_contains($lower, 'randomized controlled') || str_contains($lower, 'randomised controlled') || str_contains($lower, ' rct')) {
            return 'Randomized Controlled Trial';
        }
        if (str_contains($lower, 'meta-analysis')) {
            return 'Meta-Analysis';
        }
        if (str_contains($lower, 'systematic review')) {
            return 'Systematic Review';
        }
        if (str_contains($lower, 'randomized') || str_contains($lower, 'clinical trial') || str_contains($lower, 'phase ')) {
            return 'Clinical Trial';
        }
        if (str_contains($lower, 'review')) {
            return 'Review Article';
        }
        if (str_contains($lower, 'case report') || str_contains($lower, 'case series')) {
            return 'Case Report';
        }
        if (str_contains($lower, 'survey') || str_contains($lower, 'cohort') || str_contains($lower, 'observational')) {
            return 'Observational Study';
        }
        if (str_contains($lower, 'mouse') || str_contains($lower, 'mice') || str_contains($lower, 'rat ') || str_contains($lower, 'preclinical')) {
            return 'Animal Study';
        }
        if (str_contains($lower, 'in vitro') || str_contains($lower, 'cell culture')) {
            return 'In Vitro Study';
        }
        if (str_contains($lower, 'qualitative')) {
            return 'Qualitative Study';
        }
        return 'Other';
    }

    private function splitCounts(string $column): array
    {
        if ($column === 'topic_tags' && $this->normalizedTableHasRows('publication_topics')) {
            return $this->db->pdo()->query(
                'SELECT topic name, COUNT(DISTINCT publication_id) count
                 FROM publication_topics
                 WHERE publication_id IN (SELECT id FROM publications WHERE hidden = 0 AND false_positive = 0)
                 GROUP BY topic_key
                 ORDER BY count DESC, topic
                 LIMIT 500'
            )->fetchAll();
        }

        $counts = [];
        $stmt = $this->db->pdo()->query("SELECT $column FROM publications WHERE $column IS NOT NULL AND $column != '' AND hidden = 0 AND false_positive = 0");
        foreach ($stmt->fetchAll() as $row) {
            foreach (self::splitMetadataList((string)$row[$column]) as $tag) {
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }
        arsort($counts);
        $out = [];
        foreach ($counts as $tag => $count) {
            $out[] = ['name' => $tag, 'count' => $count];
        }
        return $out;
    }

    private function topAuthors(int $limit): array
    {
        return $this->authorCounts($limit);
    }

    private function authorCounts(int $limit, ?string $query = null): array
    {
        if ($this->normalizedTableHasRows('publication_authors')) {
            $limit = max(1, min($limit, 500));
            return $this->canonicalAuthorCounts($limit, $query);
        }

        $counts = [];
        $stmt = $this->db->pdo()->query("SELECT authors FROM publications WHERE authors IS NOT NULL AND authors != '' AND hidden = 0 AND false_positive = 0");
        $needle = trim((string)$query);
        $needleLower = mb_strtolower($needle, 'UTF-8');
        foreach ($stmt->fetchAll() as $row) {
            foreach (self::authorNamesFromText((string)$row['authors']) as $author) {
                if ($needleLower !== '' && !str_contains(mb_strtolower($author, 'UTF-8'), $needleLower)) {
                    continue;
                }
                $counts[$author] = ($counts[$author] ?? 0) + 1;
            }
        }
        arsort($counts);
        $out = [];
        foreach (array_slice($counts, 0, $limit, true) as $name => $count) {
            $out[] = ['name' => $name, 'count' => $count];
        }
        return $out;
    }

    /**
     * Combine source-specific renderings such as "Carhart-Harris RL.",
     * "Carhart-Harris R" and "Robin Carhart-Harris" without rewriting the
     * source metadata. Abbreviations are attached only when one full-name
     * candidate is available, or when a stable author identifier resolves an
     * otherwise ambiguous match.
     */
    private function canonicalAuthorCounts(int $limit, ?string $query): array
    {
        $rows = $this->db->pdo()->query(
            'SELECT pa.publication_id, pa.author_name, pa.author_key, pa.orcid, pa.openalex_id
             FROM publication_authors pa
             INNER JOIN publications p ON p.id = pa.publication_id
             WHERE p.hidden = 0 AND p.false_positive = 0'
        )->fetchAll();

        $exact = [];
        foreach ($rows as $row) {
            $key = (string)$row['author_key'];
            if (!isset($exact[$key])) {
                $exact[$key] = [
                    'names' => [],
                    'publications' => [],
                    'identifiers' => [],
                    'identity' => self::authorDirectoryIdentity((string)$row['author_name']),
                ];
            }
            $name = (string)$row['author_name'];
            $exact[$key]['names'][$name] = ($exact[$key]['names'][$name] ?? 0) + 1;
            $exact[$key]['publications'][(int)$row['publication_id']] = true;
            foreach (['orcid', 'openalex_id'] as $field) {
                $identifier = trim((string)($row[$field] ?? ''));
                if ($identifier !== '') {
                    $exact[$key]['identifiers'][$identifier] = true;
                }
            }
        }

        $groups = [];
        $abbreviated = [];
        foreach ($exact as $key => $entry) {
            if (($entry['identity']['kind'] ?? '') !== 'full') {
                $abbreviated[$key] = $entry;
                continue;
            }
            $identity = $entry['identity'];
            $groupKey = $identity['first'] . '|' . $identity['surname'];
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = self::emptyAuthorDirectoryGroup();
                $groups[$groupKey]['identity'] = $identity;
            }
            $groups[$groupKey]['full_initials'][$identity['initials']] = true;
            foreach ($entry['names'] as $fullName => $nameCount) {
                $groups[$groupKey]['full_names'][$fullName] = ($groups[$groupKey]['full_names'][$fullName] ?? 0) + $nameCount;
            }
            self::mergeAuthorDirectoryEntry($groups[$groupKey], $entry);
        }

        $identifierGroups = [];
        $surnameInitialGroups = [];
        foreach ($groups as $groupKey => $group) {
            foreach (array_keys($group['identifiers']) as $identifier) {
                $identifierGroups[$identifier][$groupKey] = true;
            }
            $firstInitial = mb_substr((string)$group['identity']['initials'], 0, 1, 'UTF-8');
            foreach ($group['identity']['surname_aliases'] as $surnameAlias) {
                $surnameInitialGroups[$surnameAlias . '|' . $firstInitial][$groupKey] = true;
            }
        }

        foreach ($abbreviated as $key => $entry) {
            $identity = $entry['identity'];
            $candidates = [];
            foreach (array_keys($entry['identifiers']) as $identifier) {
                foreach ($identifierGroups[$identifier] ?? [] as $groupKey => $_) {
                    $candidates[$groupKey] = true;
                }
            }
            if (!$candidates && ($identity['surname'] ?? '') !== '' && ($identity['initials'] ?? '') !== '') {
                $lookupKey = $identity['surname'] . '|' . mb_substr($identity['initials'], 0, 1, 'UTF-8');
                $candidates = $surnameInitialGroups[$lookupKey] ?? [];
            }
            if (count($candidates) > 1 && mb_strlen((string)($identity['initials'] ?? ''), 'UTF-8') > 1) {
                $scores = [];
                foreach (array_keys($candidates) as $groupKey) {
                    $scores[$groupKey] = self::authorInitialMatchScore($identity['initials'], array_keys($groups[$groupKey]['full_initials']));
                }
                $bestScore = max($scores);
                $best = array_filter($scores, static fn(int $score): bool => $score === $bestScore);
                if ($bestScore > 1 && count($best) === 1) {
                    $candidates = [(string)array_key_first($best) => true];
                }
            }
            if (count($candidates) === 1) {
                $groupKey = (string)array_key_first($candidates);
                self::mergeAuthorDirectoryEntry($groups[$groupKey], $entry);
                continue;
            }
            $groupKey = 'unresolved|' . $key;
            $groups[$groupKey] = self::emptyAuthorDirectoryGroup();
            $groups[$groupKey]['identity'] = $identity;
            self::mergeAuthorDirectoryEntry($groups[$groupKey], $entry);
        }

        $needle = mb_strtolower(trim((string)$query), 'UTF-8');
        $needleKey = self::authorDirectoryKey((string)$query);
        $out = [];
        foreach ($groups as $group) {
            if ($needle !== '') {
                $matches = false;
                foreach (array_keys($group['names']) as $alias) {
                    if (str_contains(mb_strtolower($alias, 'UTF-8'), $needle)
                        || ($needleKey !== '' && str_contains(self::authorDirectoryKey($alias), $needleKey))) {
                        $matches = true;
                        break;
                    }
                }
                if (!$matches) {
                    continue;
                }
            }
            $names = $group['full_names'] ?: $group['names'];
            uksort($names, static function (string $a, string $b) use ($names): int {
                $aHasDiacritics = preg_match('/[^\x00-\x7F]/', $a) === 1;
                $bHasDiacritics = preg_match('/[^\x00-\x7F]/', $b) === 1;
                return ($bHasDiacritics <=> $aHasDiacritics)
                    ?: ($names[$b] <=> $names[$a])
                    ?: strcasecmp($a, $b);
            });
            $out[] = [
                'name' => (string)array_key_first($names),
                'count' => count($group['publications']),
            ];
        }
        usort($out, static fn(array $a, array $b): int => ($b['count'] <=> $a['count']) ?: strcasecmp($a['name'], $b['name']));
        return array_slice($out, 0, $limit);
    }

    private static function emptyAuthorDirectoryGroup(): array
    {
        return ['names' => [], 'full_names' => [], 'publications' => [], 'identifiers' => [], 'full_initials' => [], 'identity' => []];
    }

    private static function authorInitialMatchScore(string $abbreviated, array $fullInitialVariants): int
    {
        $best = 0;
        foreach ($fullInitialVariants as $full) {
            $length = min(mb_strlen($abbreviated, 'UTF-8'), mb_strlen($full, 'UTF-8'));
            $score = 0;
            for ($i = 0; $i < $length; $i++) {
                if (mb_substr($abbreviated, $i, 1, 'UTF-8') !== mb_substr($full, $i, 1, 'UTF-8')) {
                    break;
                }
                $score++;
            }
            $best = max($best, $score);
        }
        return $best;
    }

    private static function mergeAuthorDirectoryEntry(array &$group, array $entry): void
    {
        foreach (['names', 'publications', 'identifiers'] as $field) {
            foreach ($entry[$field] as $key => $value) {
                if ($field === 'names') {
                    $group[$field][$key] = ($group[$field][$key] ?? 0) + $value;
                } else {
                    $group[$field][$key] = true;
                }
            }
        }
    }

    private static function authorDirectoryIdentity(string $name): array
    {
        preg_match_all('/[\p{L}\p{N}\-]+\.?/u', clean_scientific_text($name), $matches);
        $rawTokens = array_values(array_filter($matches[0] ?? []));
        $tokens = array_map(static fn(string $token): string => self::authorDirectoryKey(rtrim($token, '.')), $rawTokens);
        if (count($tokens) < 2) {
            return ['kind' => 'other', 'surname' => self::authorDirectoryKey($name), 'surname_aliases' => [], 'initials' => '', 'first' => ''];
        }

        $isInitial = static function (string $rawToken): bool {
            $letters = preg_replace('/[^\p{L}]/u', '', $rawToken) ?? '';
            $length = mb_strlen($letters, 'UTF-8');
            return $length === 1
                || ($length <= 4 && ($rawToken !== rtrim($rawToken, '.') || $letters === strtoupper($letters)));
        };

        $lastIndex = count($tokens) - 1;
        if ($isInitial($rawTokens[$lastIndex])) {
            return [
                'kind' => 'abbreviated',
                'surname' => implode(' ', array_slice($tokens, 0, -1)),
                'surname_aliases' => [],
                'initials' => $tokens[$lastIndex],
                'first' => '',
            ];
        }

        $leadingInitials = [];
        $surnameStart = 0;
        while ($surnameStart < $lastIndex && $isInitial($rawTokens[$surnameStart])) {
            $leadingInitials[] = $tokens[$surnameStart];
            $surnameStart++;
        }
        if ($leadingInitials) {
            return [
                'kind' => 'abbreviated',
                'surname' => implode(' ', array_slice($tokens, $surnameStart)),
                'surname_aliases' => [],
                'initials' => implode('', $leadingInitials),
                'first' => '',
            ];
        }

        $particles = ['da', 'de', 'del', 'der', 'dos', 'la', 'van', 'von'];
        $fullSurnameStart = $lastIndex;
        while ($fullSurnameStart > 1 && in_array($tokens[$fullSurnameStart - 1], $particles, true)) {
            $fullSurnameStart--;
        }
        $surname = implode(' ', array_slice($tokens, $fullSurnameStart));
        $surnameAliases = array_values(array_unique([$tokens[$lastIndex], $surname]));
        $given = array_slice($tokens, 0, $fullSurnameStart);
        $initials = implode('', array_map(static fn(string $token): string => mb_substr($token, 0, 1, 'UTF-8'), $given));
        return [
            'kind' => 'full',
            'surname' => $surname,
            'surname_aliases' => $surnameAliases,
            'initials' => $initials,
            'first' => $given[0] ?? '',
        ];
    }

    private static function authorDirectoryKey(string $value): string
    {
        if (function_exists('iconv')) {
            $folded = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($folded) && $folded !== '') {
                $value = $folded;
            }
        }
        return self::metadataKey($value);
    }

    private function normalizedTableHasRows(string $table): bool
    {
        if (!in_array($table, ['publication_authors', 'publication_topics'], true)) {
            return false;
        }
        try {
            return (int)$this->db->pdo()->query('SELECT COUNT(*) FROM ' . $table . ' LIMIT 1')->fetchColumn() > 0;
        } catch (PDOException) {
            return false;
        }
    }

    private static function splitMetadataList(string $value): array
    {
        $items = array_map('trim', explode(',', $value));
        $items = array_filter($items, static fn(string $item): bool => $item !== '');
        return array_values(array_unique($items));
    }

    private static function authorNamesFromText(string $authors): array
    {
        $out = [];
        foreach (self::splitMetadataList($authors) as $author) {
            $author = clean_scientific_text($author);
            if (mb_strlen($author, 'UTF-8') < 3 || str_contains(mb_strtolower($author, 'UTF-8'), ' et al')) {
                continue;
            }
            $out[] = $author;
        }
        return array_values(array_unique($out));
    }

    private static function metadataKey(string $value): string
    {
        $key = normalize_title(clean_scientific_text($value));
        return $key !== '' ? $key : mb_strtolower(trim($value), 'UTF-8');
    }

    private function hasFts(): bool
    {
        if ($this->hasFts !== null) {
            return $this->hasFts;
        }
        try {
            $this->db->pdo()->query("SELECT rowid FROM publications_fts WHERE publications_fts MATCH 'psilocybin' LIMIT 1");
            $this->hasFts = true;
        } catch (PDOException) {
            $this->hasFts = false;
        }
        return $this->hasFts;
    }

    private function ftsQuery(string $query): ?string
    {
        preg_match_all('/[\p{L}\p{N}\-]+/u', mb_strtolower($query, 'UTF-8'), $matches);
        $terms = array_values(array_filter($matches[0] ?? [], static fn(string $term): bool => $term !== ''));
        if (!$terms) {
            return null;
        }
        return implode(' AND ', array_map(static function (string $term): string {
            return '"' . str_replace('"', '""', $term) . '"';
        }, array_slice($terms, 0, 8)));
    }
}
