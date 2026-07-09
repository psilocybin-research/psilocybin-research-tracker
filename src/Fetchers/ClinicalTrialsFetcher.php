<?php
declare(strict_types=1);

final class ClinicalTrialsFetcher implements FetcherInterface
{
    private const BASE = 'https://clinicaltrials.gov/api/v2/studies';

    public function __construct(private HttpClient $http)
    {
    }

    public function name(): string
    {
        return 'ClinicalTrials.gov';
    }

    public function fetch(?string $from = null, ?string $to = null, int $limit = 0): array
    {
        $papers = [];
        $seen = [];
        $pageToken = null;
        do {
            $params = [
                'format' => 'json',
                'query.term' => 'psilocybin OR psilocin OR 4-HO-DMT',
                'pageSize' => (string)($limit > 0 ? min(100, max(1, $limit - count($papers))) : 100),
            ];
            if ($pageToken) {
                $params['pageToken'] = $pageToken;
            }
            $data = json_decode($this->http->get(self::BASE . '?' . http_build_query($params)), true);
            foreach (($data['studies'] ?? []) as $study) {
                $paper = self::paperFromStudy($study);
                if ($paper === null || !$this->dateInRange($paper['publication_date'] ?? null, $from, $to)) {
                    continue;
                }
                $key = strtolower((string)($paper['raw']['nct_id'] ?? normalize_title((string)$paper['title'])));
                if ($key !== '' && isset($seen[$key])) {
                    continue;
                }
                if ($key !== '') {
                    $seen[$key] = true;
                }
                $papers[] = $paper;
                if ($limit > 0 && count($papers) >= $limit) {
                    return $papers;
                }
            }
            $pageToken = (string)($data['nextPageToken'] ?? '');
            usleep(250000);
        } while ($pageToken !== '');

        return $papers;
    }

    public static function paperFromStudy(array $study): ?array
    {
        $protocol = $study['protocolSection'] ?? [];
        $idModule = $protocol['identificationModule'] ?? [];
        $statusModule = $protocol['statusModule'] ?? [];
        $description = $protocol['descriptionModule'] ?? [];
        $conditions = $protocol['conditionsModule']['conditions'] ?? [];
        $interventions = $protocol['armsInterventionsModule']['interventions'] ?? [];
        $sponsor = $protocol['sponsorCollaboratorsModule']['leadSponsor']['name'] ?? '';
        $nctId = (string)($idModule['nctId'] ?? '');
        $title = clean_scientific_text((string)($idModule['officialTitle'] ?? $idModule['briefTitle'] ?? ''));
        $abstract = clean_scientific_text(trim((string)($description['briefSummary'] ?? '') . "\n" . (string)($description['detailedDescription'] ?? '')));
        $interventionNames = [];
        foreach ((array)$interventions as $intervention) {
            if (!empty($intervention['name'])) {
                $interventionNames[] = (string)$intervention['name'];
            }
            foreach ((array)($intervention['otherNames'] ?? []) as $name) {
                $interventionNames[] = (string)$name;
            }
        }
        $keywords = implode(', ', array_values(array_unique(array_merge((array)$conditions, $interventionNames, [(string)($statusModule['overallStatus'] ?? '')]))));
        if ($nctId === '' || $title === '' || PublicationRepository::detectSubstances($title . ' ' . $abstract . ' ' . $keywords) === '') {
            return null;
        }
        $date = $statusModule['lastUpdatePostDateStruct']['date']
            ?? $statusModule['studyFirstPostDateStruct']['date']
            ?? $statusModule['startDateStruct']['date']
            ?? null;

        return [
            'title' => $title,
            'authors' => clean_scientific_text((string)$sponsor),
            'abstract' => $abstract,
            'journal' => 'ClinicalTrials.gov',
            'publication_date' => $date,
            'doi' => null,
            'pubmed_id' => null,
            'source_url' => 'https://clinicaltrials.gov/study/' . rawurlencode($nctId),
            'keywords' => $keywords,
            'source_name' => 'ClinicalTrials.gov',
            'publication_status' => 'clinical trial',
            'raw' => [
                'nct_id' => $nctId,
                'overall_status' => $statusModule['overallStatus'] ?? null,
                'phase' => $protocol['designModule']['phases'] ?? [],
            ],
        ];
    }

    private function dateInRange(?string $date, ?string $from, ?string $to): bool
    {
        $date = parse_date_or_null($date);
        if ($date === null) {
            return true;
        }
        return (!$from || $date >= $from) && (!$to || $date <= $to);
    }
}
