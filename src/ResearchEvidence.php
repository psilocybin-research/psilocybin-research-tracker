<?php
declare(strict_types=1);

/** Resolve selected original annotations to visible, rights-safe bibliographic records. */
final class ResearchEvidence
{
    public static function guides(): array
    {
        return [
            'depression' => ['file' => 'depression-evidence.php', 'path' => 'psilocybin-depression-research/', 'label' => 'Psilocybin depression research'],
            'alcohol-use-disorder' => ['file' => 'alcohol-evidence.php', 'path' => 'psilocybin-alcohol-use-disorder-research/', 'label' => 'Psilocybin alcohol use disorder research'],
            'cancer-anxiety' => ['file' => 'cancer-anxiety-evidence.php', 'path' => 'psilocybin-cancer-anxiety-research/', 'label' => 'Psilocybin cancer-related anxiety research'],
            'tobacco' => ['file' => 'tobacco-evidence.php', 'path' => 'psilocybin-smoking-cessation-research/', 'label' => 'Psilocybin smoking cessation research'],
        ];
    }

    /** Exact selected-study DOI membership, never inferred clinical relevance. */
    public static function guidesForPublication(array $paper): array
    {
        if (!empty($paper['hidden']) || !empty($paper['false_positive']) || !empty($paper['merged_into_id'])) return [];
        $doi = normalize_doi($paper['doi'] ?? null);
        if ($doi === null) return [];
        $matches = [];
        foreach (self::guides() as $guide) {
            $definition = require __DIR__ . '/../config/' . $guide['file'];
            foreach ($definition['studies'] as $study) {
                if (mb_strtolower($doi, 'UTF-8') === mb_strtolower($study['doi'], 'UTF-8')) {
                    $matches[] = ['label' => $guide['label'], 'path' => $guide['path'] . '#' . $study['key']];
                }
            }
        }
        return $matches;
    }

    public static function collection(Database $db, string $topic): array
    {
        $guides = self::guides();
        if (!isset($guides[$topic])) throw new InvalidArgumentException('Unknown evidence collection');
        $definition = require __DIR__ . '/../config/' . $guides[$topic]['file'];
        $find = $db->pdo()->prepare('SELECT * FROM publications WHERE hidden = 0 AND false_positive = 0 AND LOWER(TRIM(doi)) = :doi ORDER BY id LIMIT 1');
        foreach ($definition['studies'] as &$study) {
            $find->execute(['doi' => strtolower($study['doi'])]);
            $row = $find->fetch();
            $study['paper'] = $row ? public_paper($row) : null;
            $study['work_id'] = LinkedData::recordId(['doi' => $study['doi']]);
        }
        unset($study);
        return $definition;
    }
}
