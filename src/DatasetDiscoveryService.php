<?php
declare(strict_types=1);

final class DatasetDiscoveryService
{
    private OpenDatasetRepository $datasets;

    public function __construct(private Database $db)
    {
        $this->datasets = new OpenDatasetRepository($db);
    }

    public function discover(int $limit = 100, bool $verify = true, bool $rescanAll = false, int $afterId = 0): array
    {
        $rows = $this->datasets->scanQueue($limit, $rescanAll, $afterId);
        $summary = ['scanned' => 0, 'candidates' => 0, 'verified' => 0, 'quarantined' => 0, 'rejected' => 0, 'last_id' => $afterId, 'errors' => []];
        foreach ($rows as $paper) {
            $summary['scanned']++;
            $summary['last_id'] = max($summary['last_id'], (int)$paper['id']);
            $candidates = $this->extractCandidates($paper);
            foreach ($candidates as $candidate) {
                try {
                    $record = $verify ? $this->verifyCandidate($candidate, $paper) : $candidate;
                    $this->datasets->save($record);
                    $summary['candidates']++;
                    if ($record['review_status'] === 'verified') {
                        $summary['verified']++;
                    } elseif ($record['review_status'] === 'rejected') {
                        $summary['rejected']++;
                    } else {
                        $summary['quarantined']++;
                    }
                } catch (Throwable $e) {
                    $summary['errors'][] = ['publication_id' => (int)$paper['id'], 'message' => $e->getMessage()];
                    OperationalLogger::exception('datasets.discovery_candidate_failed', $e, ['publication_id' => (int)$paper['id']]);
                }
            }
            $fingerprint = hash('sha256', (string)($paper['raw_json'] ?? '') . "\n" . (string)($paper['abstract'] ?? ''));
            $this->datasets->markScanned((int)$paper['id'], $fingerprint, count($candidates));
        }
        return $summary;
    }

    /**
     * Find repository datasets that point back to an indexed paper, including
     * records whose publication metadata omitted the dataset URL.
     */
    public function discoverCatalog(array $queries = ['psilocybin', 'psilocin']): array
    {
        $summary = ['records_checked' => 0, 'matched' => 0, 'verified' => 0, 'errors' => []];
        $seen = [];
        foreach ($queries as $query) {
            try {
                foreach ($this->dataCiteSearch((string)$query) as $item) {
                    $attributes = $item['attributes'] ?? [];
                    $resourceType = mb_strtolower((string)($attributes['types']['resourceTypeGeneral'] ?? ''), 'UTF-8');
                    if (!in_array($resourceType, ['dataset', 'collection'], true)) {
                        continue;
                    }
                    $doi = mb_strtolower(trim((string)($item['id'] ?? '')), 'UTF-8');
                    if ($doi === '' || isset($seen[$doi])) {
                        continue;
                    }
                    $seen[$doi] = true;
                    $summary['records_checked']++;
                    $paper = $this->matchCatalogPaper($attributes);
                    if ($paper === null) {
                        continue;
                    }
                    $conceptDoi = $this->conceptDoi($doi, $attributes['relatedIdentifiers'] ?? []);
                    $datasetTitle = clean_scientific_text((string)($attributes['titles'][0]['title'] ?? ''));
                    if (preg_match('/^(?:supplementary material|additional file|supporting information)\b/iu', $datasetTitle)) {
                        $this->datasets->rejectUrlForPublication((int)$paper['id'], 'https://doi.org/' . $doi);
                        $this->datasets->rejectUrlForPublication((int)$paper['id'], 'https://doi.org/' . $conceptDoi);
                        continue;
                    }
                    $repository = self::repositoryForUrl((string)($attributes['url'] ?? ''), $conceptDoi)
                        ?? $this->repositoryFromPublisher((string)($attributes['publisher'] ?? ''));
                    if ($repository === null) {
                        continue;
                    }
                    $rights = $attributes['rightsList'][0] ?? [];
                    $explicit = (bool)($paper['_explicit_relation'] ?? false);
                    $this->datasets->save([
                        'publication_id' => (int)$paper['id'],
                        'dataset_url' => 'https://doi.org/' . $conceptDoi,
                        'normalized_url' => 'https://doi.org/' . $conceptDoi,
                        'dataset_doi' => $conceptDoi,
                        'dataset_title' => $datasetTitle !== '' ? $datasetTitle : null,
                        'repository' => $repository,
                        'relation_type' => $explicit ? (string)($paper['_relation_label'] ?? 'repository-linked dataset') : 'title-matched dataset',
                        'access_status' => !empty($rights) ? 'open' : 'public',
                        'license_name' => trim((string)($rights['rights'] ?? $rights['rightsIdentifier'] ?? '')) ?: null,
                        'license_url' => trim((string)($rights['rightsUri'] ?? '')) ?: null,
                        'resource_type' => ucfirst($resourceType),
                        'confidence' => $explicit ? 100 : 94,
                        'review_status' => 'verified',
                        'discovery_source' => 'DataCite dataset catalog',
                        'topic_tags' => $this->paperTopics($paper),
                        'last_checked_at' => current_utc(),
                        'http_status' => 200,
                        'metadata' => ['provider' => 'DataCite', 'match' => $explicit ? 'related identifier' : 'exact normalized title'],
                    ]);
                    if ($conceptDoi !== $doi) {
                        $this->datasets->rejectUrlForPublication((int)$paper['id'], 'https://doi.org/' . $doi);
                    }
                    $summary['matched']++;
                    $summary['verified']++;
                }
            } catch (Throwable $e) {
                $summary['errors'][] = ['query' => (string)$query, 'message' => $e->getMessage()];
                OperationalLogger::exception('datasets.catalog_query_failed', $e, ['query' => (string)$query]);
            }
        }
        return $summary;
    }

    public function extractCandidates(array $paper): array
    {
        $found = [];
        $raw = (string)($paper['raw_json'] ?? '');
        $abstract = (string)($paper['abstract'] ?? '');
        $this->collectRepositoryUrls($raw, 'source metadata', $found);
        $this->collectRepositoryUrls($abstract, 'data availability text', $found);
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $this->walkStructuredMetadata($decoded, [], $found);
        }
        $records = [];
        foreach ($found as $key => $item) {
            $url = $item['url'];
            $doi = self::doiFromUrl($url);
            $repository = self::repositoryForUrl($url, $doi);
            if ($repository === null) {
                continue;
            }
            $normalized = self::normalizeUrl($url, $doi);
            if ($normalized === '') {
                continue;
            }
            $records[$normalized] = [
                'publication_id' => (int)$paper['id'],
                'dataset_url' => $doi ? 'https://doi.org/' . $doi : $url,
                'normalized_url' => $normalized,
                'dataset_doi' => $doi,
                'dataset_title' => null,
                'repository' => $repository,
                'relation_type' => $item['relation_type'] ?? 'associated',
                'access_status' => 'unknown',
                'license_name' => null,
                'license_url' => null,
                'resource_type' => 'Dataset candidate',
                'confidence' => (int)($item['confidence'] ?? 55),
                'review_status' => 'candidate',
                'discovery_source' => (string)$item['source'],
                'topic_tags' => $this->paperTopics($paper),
                'first_seen_at' => current_utc(),
                'last_checked_at' => current_utc(),
                'http_status' => null,
                'metadata' => ['paper_doi' => (string)($paper['doi'] ?? ''), 'structured_path' => $item['path'] ?? null],
            ];
        }
        return array_values($records);
    }

    public static function repositoryForUrl(string $url, ?string $doi = null): ?string
    {
        $doi = $doi ?: self::doiFromUrl($url);
        if ($doi !== null) {
            $lowerDoi = mb_strtolower($doi, 'UTF-8');
            return match (true) {
                str_starts_with($lowerDoi, '10.5281/zenodo.') => 'Zenodo',
                str_starts_with($lowerDoi, '10.6084/m9.figshare.') => 'Figshare',
                str_starts_with($lowerDoi, '10.5061/dryad.') => 'Dryad',
                str_starts_with($lowerDoi, '10.17605/osf.io/') => 'OSF',
                str_starts_with($lowerDoi, '10.7910/dvn/') => 'Dataverse',
                str_starts_with($lowerDoi, '10.17632/') => 'Mendeley Data',
                str_starts_with($lowerDoi, '10.5683/sp3/') => 'Borealis',
                str_starts_with($lowerDoi, '10.82901/nemar.') || str_starts_with($lowerDoi, '10.18112/openneuro.') => 'OpenNeuro / NEMAR',
                str_starts_with($lowerDoi, '10.25345/') => 'MassIVE',
                str_starts_with($lowerDoi, '10.15468/') => 'GBIF',
                default => null,
            };
        }
        $host = mb_strtolower((string)parse_url($url, PHP_URL_HOST), 'UTF-8');
        return match (true) {
            $host === 'zenodo.org' || str_ends_with($host, '.zenodo.org') => 'Zenodo',
            str_contains($host, 'figshare.com') => 'Figshare',
            str_contains($host, 'datadryad.org') || $host === 'dryad.org' => 'Dryad',
            $host === 'openneuro.org' || str_ends_with($host, '.openneuro.org') => 'OpenNeuro',
            $host === 'osf.io' || str_ends_with($host, '.osf.io') => 'OSF',
            str_contains($host, 'dataverse') => 'Dataverse',
            $host === 'neurovault.org' || str_ends_with($host, '.neurovault.org') => 'NeuroVault',
            $host === 'dandiarchive.org' || str_ends_with($host, '.dandiarchive.org') => 'DANDI',
            str_contains($host, 'openicpsr.org') || str_contains($host, 'icpsr.umich.edu') => 'ICPSR',
            str_contains($host, 'mendeley.com') => 'Mendeley Data',
            str_contains($host, 'borealisdata.ca') => 'Borealis',
            $host === 'nemar.org' || str_ends_with($host, '.nemar.org') => 'OpenNeuro / NEMAR',
            str_contains($host, 'massive.ucsd.edu') => 'MassIVE',
            str_contains($host, 'gbif.org') => 'GBIF',
            default => null,
        };
    }

    public static function normalizeUrl(string $url, ?string $doi = null): string
    {
        $doi = $doi ?: self::doiFromUrl($url);
        if ($doi !== null) {
            return 'https://doi.org/' . mb_strtolower($doi, 'UTF-8');
        }
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $url = preg_replace('/[\]\[(){}.,;:]+$/u', '', $url) ?? $url;
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }
        $scheme = 'https';
        $host = mb_strtolower((string)$parts['host'], 'UTF-8');
        $path = rtrim((string)($parts['path'] ?? ''), '/');
        return $scheme . '://' . $host . ($path !== '' ? $path : '');
    }

    public static function doiFromUrl(string $value): ?string
    {
        $value = rawurldecode(html_entity_decode(str_replace('\\/', '/', $value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (preg_match('~(?:https?://(?:dx\.)?doi\.org/)?(10\.\d{4,9}/[-._;()/:A-Z0-9]+)~i', $value, $match)) {
            return rtrim(mb_strtolower($match[1], 'UTF-8'), '.,;:)\]}');
        }
        if (preg_match('~zenodo\.org/(?:records?|record)/(\d+)~i', $value, $match)) {
            return '10.5281/zenodo.' . $match[1];
        }
        if (preg_match('~figshare\.com/(?:articles/[^/]+/[^/]+/|ndownloader/articles/)(\d+)~i', $value, $match)) {
            return '10.6084/m9.figshare.' . $match[1];
        }
        return null;
    }

    private function verifyCandidate(array $candidate, array $paper): array
    {
        $doi = (string)($candidate['dataset_doi'] ?? '');
        if ($doi !== '') {
            $metadata = $this->dataCiteMetadata($doi);
            if ($metadata !== null) {
                $attributes = $metadata['data']['attributes'] ?? [];
                $resourceType = (string)($attributes['types']['resourceTypeGeneral'] ?? $attributes['types']['resourceType'] ?? '');
                $candidate['resource_type'] = $resourceType !== '' ? $resourceType : 'Unknown';
                $candidate['dataset_title'] = trim((string)($attributes['titles'][0]['title'] ?? '')) ?: null;
                $candidate['dataset_url'] = trim((string)($attributes['url'] ?? '')) ?: 'https://doi.org/' . $doi;
                $rights = $attributes['rightsList'][0] ?? [];
                $candidate['license_name'] = trim((string)($rights['rights'] ?? $rights['rightsIdentifier'] ?? '')) ?: null;
                $candidate['license_url'] = trim((string)($rights['rightsUri'] ?? '')) ?: null;
                $candidate['access_status'] = $candidate['license_name'] || $candidate['license_url'] ? 'open' : 'public';
                $candidate['http_status'] = 200;
                $isDataset = in_array(mb_strtolower($resourceType, 'UTF-8'), ['dataset', 'collection'], true);
                $isExcluded = in_array(mb_strtolower($resourceType, 'UTF-8'), ['software', 'text', 'journalarticle', 'book', 'conferencepaper', 'preprint'], true);
                $related = $attributes['relatedIdentifiers'] ?? [];
                $paperDoi = mb_strtolower(trim((string)($paper['doi'] ?? '')), 'UTF-8');
                $explicitRelation = false;
                foreach ($related as $relation) {
                    $relatedDoi = mb_strtolower(trim((string)($relation['relatedIdentifier'] ?? '')), 'UTF-8');
                    if ($paperDoi !== '' && $relatedDoi === $paperDoi) {
                        $explicitRelation = true;
                        $candidate['relation_type'] = $this->relationLabel((string)($relation['relationType'] ?? 'associated'));
                        break;
                    }
                }
                $candidate['confidence'] = $explicitRelation ? 99 : ($isDataset ? 91 : 35);
                $candidate['review_status'] = $isDataset ? 'verified' : ($isExcluded ? 'rejected' : 'candidate');
                $candidate['metadata'] = [
                    'provider' => 'DataCite',
                    'resource_type' => $resourceType,
                    'explicit_paper_relation' => $explicitRelation,
                    'publisher' => (string)($attributes['publisher'] ?? ''),
                ];
                return $candidate;
            }
        }
        $url = (string)$candidate['dataset_url'];
        if ($candidate['repository'] === 'OpenNeuro' && preg_match('~/datasets/(ds\d+)~i', $url, $match)) {
            $candidate['dataset_title'] = 'OpenNeuro dataset ' . strtoupper($match[1]);
            $candidate['resource_type'] = 'Dataset';
            $candidate['access_status'] = 'public';
            $candidate['confidence'] = 90;
            $candidate['review_status'] = 'verified';
            $candidate['http_status'] = $this->urlStatus($url);
            if ($candidate['http_status'] !== null && $candidate['http_status'] >= 400) {
                $candidate['review_status'] = 'candidate';
                $candidate['confidence'] = 55;
            }
            return $candidate;
        }
        $candidate['http_status'] = $this->urlStatus($url);
        return $candidate;
    }

    private function collectRepositoryUrls(string $text, string $source, array &$found): void
    {
        $text = str_replace('\\/', '/', $text);
        if (!preg_match_all('~https?://[^\s<>"\']+~iu', $text, $matches)) {
            return;
        }
        foreach ($matches[0] as $url) {
            $url = rtrim((string)$url, '.,;:)\]}');
            $doi = self::doiFromUrl($url);
            if (self::repositoryForUrl($url, $doi) === null) {
                continue;
            }
            $key = self::normalizeUrl($url, $doi);
            $found[$key] = ['url' => $url, 'source' => $source, 'confidence' => $source === 'data availability text' ? 68 : 58];
        }
    }

    private function walkStructuredMetadata(mixed $value, array $path, array &$found): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $this->walkStructuredMetadata($child, [...$path, (string)$key], $found);
            }
            return;
        }
        if (!is_scalar($value)) {
            return;
        }
        $pathText = mb_strtolower(implode('.', $path), 'UTF-8');
        if (!preg_match('/data|supplement|relation|associated|availability|resource/i', $pathText)) {
            return;
        }
        $text = (string)$value;
        $this->collectRepositoryUrls($text, 'structured metadata', $found);
        if (preg_match_all('~10\.\d{4,9}/[-._;()/:A-Z0-9]+~i', $text, $matches)) {
            foreach ($matches[0] as $doi) {
                $doi = self::doiFromUrl($doi);
                if ($doi === null || self::repositoryForUrl('https://doi.org/' . $doi, $doi) === null) {
                    continue;
                }
                $url = 'https://doi.org/' . $doi;
                $found[self::normalizeUrl($url, $doi)] = [
                    'url' => $url,
                    'source' => 'structured metadata',
                    'confidence' => 76,
                    'path' => $pathText,
                    'relation_type' => str_contains($pathText, 'supplement') ? 'supplementary dataset' : 'associated dataset',
                ];
            }
        }
    }

    private function dataCiteMetadata(string $doi): ?array
    {
        return $this->fetchJson('https://api.datacite.org/dois/' . rawurlencode($doi));
    }

    private function dataCiteSearch(string $query): array
    {
        $items = [];
        for ($page = 1; $page <= 3; $page++) {
            $response = $this->fetchJson('https://api.datacite.org/dois?query=' . rawurlencode($query) . '&page%5Bsize%5D=100&page%5Bnumber%5D=' . $page);
            if ($response === null) {
                break;
            }
            $batch = $response['data'] ?? [];
            if (!is_array($batch) || !$batch) {
                break;
            }
            array_push($items, ...$batch);
            if (count($batch) < 100) {
                break;
            }
        }
        return $items;
    }

    private function matchCatalogPaper(array $attributes): ?array
    {
        foreach ($attributes['relatedIdentifiers'] ?? [] as $relation) {
            if (mb_strtolower((string)($relation['relatedIdentifierType'] ?? ''), 'UTF-8') !== 'doi') {
                continue;
            }
            $doi = normalize_doi((string)($relation['relatedIdentifier'] ?? ''));
            if ($doi === null) {
                continue;
            }
            $stmt = $this->db->pdo()->prepare('SELECT id, title, doi, topic_tags, substance_tags FROM publications WHERE hidden = 0 AND false_positive = 0 AND lower(doi) = :doi LIMIT 1');
            $stmt->execute([':doi' => $doi]);
            $paper = $stmt->fetch();
            if (is_array($paper)) {
                $paper['_explicit_relation'] = true;
                $paper['_relation_label'] = $this->relationLabel((string)($relation['relationType'] ?? 'associated'));
                return $paper;
            }
        }
        $title = trim((string)($attributes['titles'][0]['title'] ?? ''));
        $title = preg_replace('/^(?:dataset|data from|replication data for)\s*:\s*/iu', '', $title) ?? $title;
        $normalized = normalize_title($title);
        if (mb_strlen($normalized, 'UTF-8') < 35) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare('SELECT id, title, doi, topic_tags, substance_tags FROM publications WHERE hidden = 0 AND false_positive = 0 AND normalized_title = :title LIMIT 1');
        $stmt->execute([':title' => $normalized]);
        $paper = $stmt->fetch();
        if (is_array($paper)) {
            $paper['_explicit_relation'] = false;
            return $paper;
        }
        return null;
    }

    private function conceptDoi(string $doi, array $relations): string
    {
        if (preg_match('/^(10\.6084\/m9\.figshare\.\d+)\.v\d+$/i', $doi, $match)) {
            return mb_strtolower($match[1], 'UTF-8');
        }
        if (preg_match('/^(10\.82901\/nemar\.[^.]+)\.v\d+(?:\.\d+){1,3}$/i', $doi, $match)) {
            return mb_strtolower($match[1], 'UTF-8');
        }
        foreach ($relations as $relation) {
            if (mb_strtolower((string)($relation['relationType'] ?? ''), 'UTF-8') !== 'isversionof'
                || mb_strtolower((string)($relation['relatedIdentifierType'] ?? ''), 'UTF-8') !== 'doi') {
                continue;
            }
            $concept = normalize_doi((string)($relation['relatedIdentifier'] ?? ''));
            if ($concept !== null && self::repositoryForUrl('https://doi.org/' . $concept, $concept) !== null) {
                return $concept;
            }
        }
        return $doi;
    }

    private function repositoryFromPublisher(string $publisher): ?string
    {
        $publisher = mb_strtolower($publisher, 'UTF-8');
        return match (true) {
            str_contains($publisher, 'zenodo') => 'Zenodo',
            str_contains($publisher, 'figshare') => 'Figshare',
            str_contains($publisher, 'dryad') => 'Dryad',
            str_contains($publisher, 'mendeley') => 'Mendeley Data',
            str_contains($publisher, 'borealis') => 'Borealis',
            str_contains($publisher, 'nemar') || str_contains($publisher, 'openneuro') => 'OpenNeuro / NEMAR',
            str_contains($publisher, 'massive') => 'MassIVE',
            str_contains($publisher, 'gbif') => 'GBIF',
            default => null,
        };
    }

    private function fetchJson(string $url): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_USERAGENT => 'psilocybin-research-dataset-discovery/1.0 (https://psilocybin-research.com/)',
            CURLOPT_HTTPHEADER => ['Accept: application/vnd.api+json'],
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if (!is_string($body) || $status < 200 || $status >= 300) {
            return null;
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function urlStatus(string $url): ?int
    {
        if (!function_exists('curl_init') || self::repositoryForUrl($url) === null) {
            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_USERAGENT => 'psilocybin-research-dataset-discovery/1.0 (https://psilocybin-research.com/)',
        ]);
        curl_exec($ch);
        return (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: null;
    }

    private function paperTopics(array $paper): string
    {
        return implode(', ', array_values(array_unique(array_merge(
            split_tag_values((string)($paper['substance_tags'] ?? '')),
            split_tag_values((string)($paper['topic_tags'] ?? ''))
        ))));
    }

    private function relationLabel(string $relation): string
    {
        return match (mb_strtolower($relation, 'UTF-8')) {
            'issupplementto', 'issupplementedby' => 'supplementary dataset',
            'issourceof', 'isderivedfrom' => 'underlying or derived dataset',
            'isreferencedby', 'references', 'iscitedby', 'cites' => 'cited dataset',
            default => 'associated dataset',
        };
    }
}
