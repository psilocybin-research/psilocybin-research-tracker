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
        $designModule = $protocol['designModule'] ?? [];
        $locations = $protocol['contactsLocationsModule']['locations'] ?? [];
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

        $safeInterventions = [];
        foreach ((array)$interventions as $intervention) {
            if (!is_array($intervention) || empty($intervention['name'])) {
                continue;
            }
            $safeInterventions[] = [
                'type' => clean_scientific_text((string)($intervention['type'] ?? '')),
                'name' => clean_scientific_text((string)$intervention['name']),
            ];
        }
        $safeLocations = [];
        foreach ((array)$locations as $location) {
            if (!is_array($location) || !is_array($location['geoPoint'] ?? null)) {
                continue;
            }
            $lat = filter_var($location['geoPoint']['lat'] ?? null, FILTER_VALIDATE_FLOAT);
            $lon = filter_var($location['geoPoint']['lon'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($lat === false || $lon === false || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
                continue;
            }
            $safeLocations[] = [
                'facility' => clean_scientific_text((string)($location['facility'] ?? '')),
                'city' => clean_scientific_text((string)($location['city'] ?? '')),
                'region' => clean_scientific_text((string)($location['state'] ?? '')),
                'country' => clean_scientific_text((string)($location['country'] ?? '')),
                'lat' => round((float)$lat, 5),
                'lon' => round((float)$lon, 5),
            ];
        }
        $designInfo = is_array($designModule['designInfo'] ?? null) ? $designModule['designInfo'] : [];
        $maskingInfo = is_array($designInfo['maskingInfo'] ?? null) ? $designInfo['maskingInfo'] : [];
        $enrollmentInfo = is_array($designModule['enrollmentInfo'] ?? null) ? $designModule['enrollmentInfo'] : [];

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
                'results_posted' => !empty($study['hasResults']),
                'result_references' => array_values(array_map(static fn(array $r):array=>['pmid'=>(string)($r['pmid']??''),'type'=>'RESULT'],array_filter((array)($protocol['referencesModule']['references']??[]),static fn($r):bool=>is_array($r)&&($r['type']??'')==='RESULT'&&preg_match('/^[1-9]\d{0,9}$/D',(string)($r['pmid']??''))))),
                'overall_status' => $statusModule['overallStatus'] ?? null,
                'status_verified_date' => $statusModule['statusVerifiedDate'] ?? null,
                'phase' => $designModule['phases'] ?? [],
                'study_type' => $designModule['studyType'] ?? null,
                'enrollment' => [
                    'count' => isset($enrollmentInfo['count']) ? (int)$enrollmentInfo['count'] : null,
                    'type' => $enrollmentInfo['type'] ?? null,
                ],
                'design' => [
                    'allocation' => $designInfo['allocation'] ?? null,
                    'intervention_model' => $designInfo['interventionModel'] ?? null,
                    'primary_purpose' => $designInfo['primaryPurpose'] ?? null,
                    'masking' => $maskingInfo['masking'] ?? null,
                ],
                'conditions' => array_values(array_filter(array_map('clean_scientific_text', (array)$conditions))),
                'interventions' => $safeInterventions,
                'locations' => $safeLocations,
                'start_date' => $statusModule['startDateStruct']['date'] ?? null,
                'primary_completion_date' => $statusModule['primaryCompletionDateStruct']['date'] ?? null,
                'completion_date' => $statusModule['completionDateStruct']['date'] ?? null,
                'last_update_date' => $statusModule['lastUpdatePostDateStruct']['date'] ?? null,
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
