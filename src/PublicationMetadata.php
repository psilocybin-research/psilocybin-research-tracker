<?php
declare(strict_types=1);

/** Bibliographic presentation and review signals; never invents missing source facts. */
final class PublicationMetadata
{
    /** A concise browser/search label; never alters the bibliographic title. */
    public static function searchTitle(string $title): string
    {
        $suffix = ' | Psilocybin Research';
        $title = trim(preg_replace('/\s+/u', ' ', clean_scientific_text($title)) ?? $title);
        if ($title === '') $title = 'Publication record';
        if (mb_strlen($title . $suffix, 'UTF-8') <= 72) return $title . $suffix;
        $prefix = mb_substr($title, 0, 72 - mb_strlen($suffix, 'UTF-8') - 1, 'UTF-8');
        $prefix = preg_replace('/\s+\S*$/u', '', $prefix) ?: $prefix;
        return (preg_replace('/[\s:;,–—-]+$/u', '', $prefix) ?: $prefix) . '…' . $suffix;
    }

    public static function venue(array $paper): array
    {
        $journal = trim((string)($paper['journal'] ?? ''));
        if ($journal !== '') {
            foreach (['ClinicalTrials.gov' => 'registry', 'PsyArXiv' => 'repository', 'bioRxiv' => 'repository', 'medRxiv' => 'repository', 'ResearchGate' => 'repository', 'Zenodo' => 'repository', 'arXiv' => 'repository'] as $name => $kind) {
                if (strcasecmp($journal, $name) === 0) return ['label' => $name, 'kind' => $kind];
            }
            return ['label' => $journal, 'kind' => 'journal'];
        }
        $doi = strtolower((string)(normalize_doi($paper['doi'] ?? null) ?? ''));
        $host = strtolower((string)parse_url((string)($paper['source_url'] ?? ''), PHP_URL_HOST));
        $repositories = [
            ['ResearchGate', 'researchgate.net', '10.13140/rg.'],
            ['Zenodo', 'zenodo.org', '10.5281/zenodo.'],
            ['Heidelberg University repository (HeiDOK)', 'archiv.ub.uni-heidelberg.de', '10.11588/heidok.'],
            ['arXiv', 'arxiv.org', '10.48550/arxiv.'],
            ['bioRxiv', 'biorxiv.org', ''],
            ['medRxiv', 'medrxiv.org', ''],
            ['PsyArXiv', 'psyarxiv.com', ''],
            ['OSF', 'osf.io', '10.31219/osf.io/'],
        ];
        foreach ($repositories as [$label, $domain, $prefix]) {
            if ($host === $domain || str_ends_with($host, '.' . $domain) || ($prefix !== '' && str_starts_with($doi, $prefix))) {
                return ['label' => $label, 'kind' => 'repository'];
            }
        }
        $source = trim((string)($paper['source_name'] ?? ''));
        if ($source === 'ClinicalTrials.gov' || $host === 'clinicaltrials.gov' || str_ends_with($host, '.clinicaltrials.gov')) {
            return ['label' => 'ClinicalTrials.gov', 'kind' => 'registry'];
        }
        // These importer names identify the repository even when the URL is a DOI.
        foreach (['bioRxiv', 'medRxiv', 'PsyArXiv'] as $repository) {
            if (strcasecmp($source, $repository) === 0) return ['label' => $repository, 'kind' => 'repository'];
        }
        if ($host !== '' && !in_array($host, ['doi.org', 'dx.doi.org', 'openalex.org', 'api.openalex.org'], true)) {
            return ['label' => $host, 'kind' => 'source'];
        }
        return ['label' => $source !== '' ? $source : 'Source not supplied', 'kind' => $source !== '' ? 'source' : 'unknown'];
    }

    public static function issues(array $paper): array
    {
        $issues = [];
        foreach (['title', 'authors', 'publication_date', 'source_name', 'source_url'] as $field) {
            if (trim((string)($paper[$field] ?? '')) === '') $issues[] = 'missing_' . $field;
        }
        $date = trim((string)($paper['publication_date'] ?? ''));
        if ($date !== '' && (parse_date_or_null($date) === null || (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) && parse_date_or_null($date) !== $date))) $issues[] = 'invalid_publication_date';
        if (trim((string)($paper['doi'] ?? '')) === '' && trim((string)($paper['pubmed_id'] ?? '')) === '' && trim((string)($paper['openalex_id'] ?? '')) === '') {
            $url = (string)($paper['source_url'] ?? '');
            $host = strtolower((string)parse_url($url, PHP_URL_HOST));
            $path = (string)parse_url($url, PHP_URL_PATH);
            $sourceIdentifier = ($host === 'clinicaltrials.gov' && preg_match('~/(?:study|show)/NCT\d{8}(?:/|$)~i', $path))
                || (in_array($host, ['osf.io', 'psyarxiv.com'], true) && preg_match('~/(?:preprints/(?:psyarxiv/)?)?[a-z0-9]{5,}(?:_v\d+)?/?$~i', $path))
                || ($host === 'europepmc.org' && preg_match('~/(?:article|articles)/[A-Z]+/[A-Z0-9]+/?$~i', $path))
                || (in_array($host, ['pmc.ncbi.nlm.nih.gov', 'www.ncbi.nlm.nih.gov'], true) && preg_match('~/articles/PMC\d+/?$~i', $path));
            if (!$sourceIdentifier) $issues[] = 'missing_bibliographic_identifier';
        }
        $venue = self::venue($paper);
        if (trim((string)($paper['journal'] ?? '')) === '' && !in_array($venue['kind'], ['registry', 'repository'], true)) $issues[] = 'missing_venue';
        if ($venue['kind'] === 'repository' && ($paper['publication_status'] ?? '') === 'published') $issues[] = 'repository_publication_status_needs_review';
        if ($venue['kind'] === 'repository' && ($paper['publication_status'] ?? '') === 'published' && in_array($paper['study_type'] ?? '', ['Clinical Trial', 'Randomized Controlled Trial'], true)) $issues[] = 'repository_trial_classification_needs_review';
        // Registry investigator credentials are source facts, not corrupted article authors.
        // PubMed's all-capital MSC can be personal initials (e.g. Marcio S C Longo).
        if ($venue['kind'] !== 'registry' && preg_match('/\b(?:Msc|MSc|PhD|phd|in business administration)\b/', (string)($paper['authors'] ?? ''))) $issues[] = 'author_name_credentials_need_review';
        return array_values(array_unique($issues));
    }

    public static function fingerprint(array $paper): string
    {
        $facts = [];
        foreach (['id', 'title', 'authors', 'journal', 'publication_date', 'doi', 'pubmed_id', 'openalex_id', 'source_name', 'source_url', 'publication_status', 'study_type'] as $field) $facts[$field] = (string)($paper[$field] ?? '');
        return hash('sha256', json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public static function report(iterable $papers, array $reviews = []): array
    {
        $counts = []; $records = []; $total = 0; $reviewed = []; $sourceGaps = [];
        foreach ($papers as $paper) {
            $total++;
            $issues = self::issues($paper);
            $review = $reviews[(string)$paper['id']] ?? null;
            if (is_array($review) && hash_equals(self::fingerprint($paper), (string)($review['metadata_sha256'] ?? ''))
                && preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string)($review['reviewed_on'] ?? ''))
                && ($review['reviewed_on'] ?? '') <= gmdate('Y-m-d')
                && in_array($review['resolution'] ?? '', ['verified_source_metadata', 'known_source_gap'], true)) {
                $resolved = array_intersect($issues, (array)($review['resolved_issues'] ?? []));
                $issues = array_values(array_diff($issues, $resolved));
                $reviewed[] = ['id' => (int)$paper['id'], 'reviewed_on' => $review['reviewed_on'], 'resolution' => $review['resolution']];
                if ($review['resolution'] === 'known_source_gap') $sourceGaps[] = ['id' => (int)$paper['id'], 'issues' => array_values($resolved)];
            }
            foreach ($issues as $issue) $counts[$issue] = ($counts[$issue] ?? 0) + 1;
            if ($issues) $records[] = ['id' => (int)$paper['id'], 'title' => (string)$paper['title'], 'doi' => $paper['doi'] ?? null, 'venue' => self::venue($paper), 'issues' => $issues];
        }
        ksort($counts);
        return ['generated_at_utc' => current_utc(), 'public_records_checked' => $total, 'records_needing_review' => count($records), 'issue_counts' => $counts, 'records' => $records, 'reviewed_records' => $reviewed, 'known_source_gaps' => $sourceGaps, 'interpretation' => 'Missing journal fields are expected for repositories and registries. Verified review dispositions apply only to the exact metadata fingerprint; changed facts reopen review. Known source gaps remain explicit. Review signals never invent source facts or automatically change publication status, authors or study design. Source text is excluded.'];
    }
}
