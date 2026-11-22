<?php
declare(strict_types=1);

/** Reviewed study identities; never inferred from shared words or citations. */
final class StudyRelationships
{
    public static function catalogue(Database $db): array
    {
        $config = __DIR__ . '/../config/study-families.json';
        return PublicAggregateCache::remember($db, 'study-relationships', static function () use ($db, $config): array {
            $definition = json_decode((string)file_get_contents($config), true, 512, JSON_THROW_ON_ERROR);
            $families = $definition['families'];
            if (count($families) > 100) throw new RuntimeException('Reviewed study-family capacity exceeded.');
            $dois = []; $registrations = [];
            $rows = $db->pdo()->query('SELECT id,title,doi,pubmed_id,source_url,publication_year,publication_status FROM publications WHERE hidden=0 AND false_positive=0 AND merged_into_id IS NULL ORDER BY id');
            foreach ($rows as $row) {
                $doi = normalize_doi($row['doi']);
                if ($doi !== null) $dois[strtolower($doi)][] = $row;
                // Exact registry URL identities only, not NCT mentions in text.
                if (preg_match('~^https?://(?:www\.)?clinicaltrials\.gov/(?:study/(NCT[0-9]{8})|(?:ct2/)?show/(NCT[0-9]{8}))/?(?:\?.*)?$~iD', (string)$row['source_url'], $match)) {
                    $nct = strtoupper($match[1] ?: ($match[2] ?? ''));
                    if ($row['publication_status'] === 'clinical trial') $registrations[$nct][] = $row;
                }
            }
            $out = []; $identities = [];
            foreach ($families as $family) {
                if (isset($identities[$family['id']])) throw new RuntimeException('Duplicate study family identity.');
                $identities[$family['id']] = true;
                $members = []; $unindexed = [];
                foreach ($family['members'] as $member) {
                    if (!in_array($member['role'], ['primary_report', 'follow_up', 'secondary_analysis', 'protocol', 'protocol_preprint', 'preprint'], true) || !$member['evidence']) throw new RuntimeException('Study member role/evidence missing.');
                    $doi = strtolower((string)normalize_doi($member['doi']));
                    foreach ($member['evidence'] as $source) if (!str_starts_with($source['url'], 'https://') || !trim($source['basis'])) throw new RuntimeException('Study source missing.');
                    $matches = $dois[$doi] ?? [];
                    foreach ($matches as $row) $members[] = self::member($row, $member['role']) + ['evidence' => $member['evidence']];
                    if (!$matches) $unindexed[] = ['doi' => $doi, 'role' => $member['role'], 'source_url' => 'https://doi.org/' . $doi, 'evidence' => $member['evidence']];
                }
                $nct = $family['nct_id'];
                if ($nct !== null && !preg_match('/^NCT[0-9]{8}$/D', $nct)) throw new RuntimeException('Invalid study registry identity.');
                foreach ($registrations[$nct ?? ''] ?? [] as $row) $members[] = self::member($row, 'registration') + ['evidence' => [['url' => 'https://clinicaltrials.gov/study/' . $nct, 'basis' => 'Exact registry identity; reports linked by the reviewed evidence above.']]];
                if ($members) $out[] = ['family_id' => $family['id'], 'label' => $family['label'], 'nct_id' => $nct,
                    'registry_url' => $nct ? 'https://clinicaltrials.gov/study/' . $nct : null,
                    'checked_at' => $definition['checked_at'], 'note' => $family['note'], 'members' => $members, 'unindexed_reports' => $unindexed];
            }
            return ['schema_version' => 'reviewed-study-relationships-v1', 'catalogue_revision' => CatalogueRevision::read($db),
                'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'), 'checked_at' => $definition['checked_at'],
                'coverage' => 'selected reviewed families; not exhaustive', 'scope' => $definition['scope'],
                'family_count' => count($out), 'indexed_member_count' => count(array_unique(array_merge(...array_map(static fn($family): array => array_column($family['members'], 'id'), $out)))), 'families' => $out,
                'interpretation' => 'Preserve every record ID. Study-family membership is not a duplicate-record deletion rule, an independent participant count, or proof of efficacy. Missing links are unknown; citation links and similar titles do not establish shared study identity.'];
        }, hash_file('sha256', __FILE__) . hash_file('sha256', $config) . Config::publicBaseUrl());
    }

    private static function member(array $paper, string $role): array
    {
        return ['id' => (int)$paper['id'], 'title' => $paper['title'], 'doi' => $paper['doi'],
            'pubmed_id' => $paper['pubmed_id'], 'publication_year' => $paper['publication_year'] !== null ? (int)$paper['publication_year'] : null,
            'publication_status' => $paper['publication_status'], 'role' => $role,
            'record_url' => rtrim(Config::publicBaseUrl(), '/') . '/publication.php?id=' . (int)$paper['id']];
    }

    public static function forRecord(Database $db, int $id): array
    {
        if ($id < 1 || (new PublicationRepository($db))->publicById($id) === null) throw new OutOfBoundsException('Visible public record not found.');
        $data = self::catalogue($db);
        $data['families'] = array_values(array_filter($data['families'], static fn($family): bool => in_array($id, array_column($family['members'], 'id'), true)));
        $data['family_count'] = count($data['families']);
        $data['indexed_member_count'] = count(array_unique(array_merge(...array_map(static fn($family): array => array_column($family['members'], 'id'), $data['families']))));
        $data['record_id'] = $id;
        return $data;
    }
}
