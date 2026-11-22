<?php
declare(strict_types=1);

/**
 * Builds a deliberately allowlisted, rights-safe projection of registry facts
 * for the public clinical-trials map. Source descriptions and unrestricted
 * importer payloads never leave the private runtime database.
 */
final class TrialMapService
{
    private const STATUS_LABELS = [
        'NOT_YET_RECRUITING' => 'Not yet recruiting',
        'RECRUITING' => 'Recruiting',
        'ENROLLING_BY_INVITATION' => 'Enrolling by invitation',
        'ACTIVE_NOT_RECRUITING' => 'Active, not recruiting',
        'COMPLETED' => 'Completed',
        'SUSPENDED' => 'Suspended',
        'TERMINATED' => 'Terminated',
        'WITHDRAWN' => 'Withdrawn',
        'UNKNOWN' => 'Unknown status',
    ];

    private ?array $evidenceReports = null;

    public function __construct(private Database $db)
    {
    }

    public function dataset(): array
    {
        $rows = $this->db->pdo()->query(
            "SELECT id, title, authors, publication_date, source_url, substance_tags, last_checked, raw_json
             FROM publications
             WHERE hidden = 0
               AND false_positive = 0
               AND publication_status = 'clinical trial'
             ORDER BY publication_date DESC, id DESC"
        )->fetchAll();

        $trials = [];
        $countries = [];
        $sites = [];
        $statuses = [];
        $phases = [];
        $conditions = [];
        $interventions = [];
        $latestChecked = null;

        foreach ($rows as $row) {
            $trial = $this->trialFromRow($row);
            if ($trial === null) {
                continue;
            }
            $trials[] = $trial;
            $statuses[$trial['status']] = ($statuses[$trial['status']] ?? 0) + 1;
            foreach ($trial['phases'] as $phase) {
                $phases[$phase] = ($phases[$phase] ?? 0) + 1;
            }
            foreach ($trial['conditions'] as $condition) {
                $conditions[$condition] = ($conditions[$condition] ?? 0) + 1;
            }
            foreach ($trial['interventions'] as $intervention) {
                $interventions[$intervention['name']] = ($interventions[$intervention['name']] ?? 0) + 1;
            }
            foreach ($trial['countries'] as $country) {
                $countries[$country] = ($countries[$country] ?? 0) + 1;
            }
            foreach ($trial['sites'] as $site) {
                $sites[$site['key']] = true;
            }
            $checked = trim((string)($row['last_checked'] ?? ''));
            if ($checked !== '' && ($latestChecked === null || $checked > $latestChecked)) {
                $latestChecked = $checked;
            }
        }

        arsort($countries);
        arsort($statuses);
        arsort($phases);
        arsort($conditions);
        arsort($interventions);

        return [
            'schema_version' => 1,
            'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'registry_checked_at_utc' => $latestChecked,
            'source' => [
                'name' => 'ClinicalTrials.gov',
                'url' => 'https://clinicaltrials.gov/',
                'scope' => 'Psilocybin, psilocin, and 4-HO-DMT registry records indexed by this database.',
            ],
            'summary' => [
                'trials' => count($trials),
                'sites' => count($sites),
                'countries' => count($countries),
                'recruiting' => array_sum(array_intersect_key($statuses, array_flip(['RECRUITING', 'NOT_YET_RECRUITING', 'ENROLLING_BY_INVITATION']))),
                'mapped_trials' => count(array_filter($trials, static fn(array $trial): bool => $trial['sites'] !== [])),
            ],
            'facets' => [
                'countries' => $countries,
                'statuses' => $statuses,
                'status_labels' => self::STATUS_LABELS,
                'phases' => $phases,
                'conditions' => $conditions,
                'interventions' => $interventions,
            ],
            'trials' => $trials,
        ];
    }

    /** Return the same allowlisted registry facts used by the map for one public record. */
    public function forPublication(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare("SELECT id, title, authors, publication_date, source_url, substance_tags, last_checked, raw_json FROM publications WHERE id = :id AND hidden = 0 AND false_positive = 0 AND publication_status = 'clinical trial'");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->trialFromRow($row) : null;
    }

    /**
     * Return the exact ClinicalTrials.gov RECRUITING subset used by the
     * canonical recruiting-trials landing page. Counts are recomputed from
     * the same rights-safe projection as the interactive map.
     */
    public function recruitingDataset(): array
    {
        $dataset = $this->dataset();
        $trials = array_values(array_filter(
            $dataset['trials'],
            static fn(array $trial): bool => ($trial['status'] ?? '') === 'RECRUITING'
        ));
        $sites = [];
        $countries = [];
        $conditions = [];
        $phases = [];

        foreach ($trials as $trial) {
            $trialCountries = [];
            foreach ((array)($trial['sites'] ?? []) as $site) {
                $key = trim((string)($site['key'] ?? ''));
                if ($key !== '') {
                    $sites[$key] = true;
                }
                $country = trim((string)($site['country'] ?? ''));
                if ($country !== '') {
                    $trialCountries[$country] = true;
                }
            }
            foreach (array_keys($trialCountries) as $country) {
                $countries[$country] = ($countries[$country] ?? 0) + 1;
            }
            foreach ((array)($trial['conditions'] ?? []) as $condition) {
                $condition = trim((string)$condition);
                if ($condition !== '') {
                    $conditions[$condition] = ($conditions[$condition] ?? 0) + 1;
                }
            }
            foreach ((array)($trial['phases'] ?? []) as $phase) {
                $phase = trim((string)$phase);
                if ($phase !== '') {
                    $phases[$phase] = ($phases[$phase] ?? 0) + 1;
                }
            }
        }

        arsort($countries);
        arsort($conditions);
        arsort($phases);

        return [
            'schema_version' => 1,
            'generated_at_utc' => $dataset['generated_at_utc'],
            'registry_checked_at_utc' => $dataset['registry_checked_at_utc'],
            'source' => $dataset['source'],
            'summary' => [
                'trials' => count($trials),
                'sites' => count($sites),
                'countries' => count($countries),
            ],
            'facets' => [
                'countries' => $countries,
                'conditions' => $conditions,
                'phases' => $phases,
            ],
            'trials' => $trials,
        ];
    }

    /**
     * Build one of the explicitly curated clinical-trial landing datasets.
     * Filters come from config/trial-seo-landings.php, never directly from a
     * request, so the public surface cannot produce arbitrary thin pages.
     */
    public function curatedDataset(array $filter): array
    {
        $dataset = $this->dataset();
        $type = (string)($filter['type'] ?? '');
        $values = array_values(array_filter(array_map(
            static fn(mixed $value): string => mb_strtolower(trim((string)$value), 'UTF-8'),
            (array)($filter['values'] ?? [])
        )));
        if (!in_array($type, ['condition', 'country', 'phase'], true) || $values === []) {
            throw new InvalidArgumentException('Invalid curated trial filter.');
        }

        $trials = array_values(array_filter($dataset['trials'], static function (array $trial) use ($type, $values): bool {
            if ($type === 'condition') {
                foreach ((array)($trial['conditions'] ?? []) as $condition) {
                    $condition = mb_strtolower((string)$condition, 'UTF-8');
                    foreach ($values as $value) {
                        if (str_contains($condition, $value)) {
                            return true;
                        }
                    }
                }
                return false;
            }
            if ($type === 'country') {
                foreach ((array)($trial['sites'] ?? []) as $site) {
                    if (in_array(mb_strtolower(trim((string)($site['country'] ?? '')), 'UTF-8'), $values, true)) {
                        return true;
                    }
                }
                return false;
            }
            foreach ((array)($trial['phases'] ?? []) as $phase) {
                if (in_array(mb_strtolower(trim((string)$phase), 'UTF-8'), $values, true)) {
                    return true;
                }
            }
            return false;
        }));

        $sites = [];
        $countries = [];
        $statuses = [];
        $phases = [];
        $conditions = [];
        foreach ($trials as $trial) {
            $statuses[(string)$trial['status']] = ($statuses[(string)$trial['status']] ?? 0) + 1;
            $trialCountries = [];
            foreach ((array)$trial['sites'] as $site) {
                $key = trim((string)($site['key'] ?? ''));
                if ($key !== '') {
                    $sites[$key] = true;
                }
                $country = trim((string)($site['country'] ?? ''));
                if ($country !== '') {
                    $trialCountries[$country] = true;
                }
            }
            foreach (array_keys($trialCountries) as $country) {
                $countries[$country] = ($countries[$country] ?? 0) + 1;
            }
            foreach ((array)$trial['conditions'] as $condition) {
                $condition = trim((string)$condition);
                if ($condition !== '') {
                    $conditions[$condition] = ($conditions[$condition] ?? 0) + 1;
                }
            }
            foreach ((array)$trial['phases'] as $phase) {
                $phase = trim((string)$phase);
                if ($phase !== '') {
                    $phases[$phase] = ($phases[$phase] ?? 0) + 1;
                }
            }
        }
        arsort($countries);
        arsort($statuses);
        arsort($phases);
        arsort($conditions);

        return [
            'schema_version' => 1,
            'generated_at_utc' => $dataset['generated_at_utc'],
            'registry_checked_at_utc' => $dataset['registry_checked_at_utc'],
            'source' => $dataset['source'],
            'summary' => [
                'trials' => count($trials),
                'recruiting' => (int)($statuses['RECRUITING'] ?? 0),
                'open_or_upcoming' => array_sum(array_intersect_key($statuses, array_flip(['RECRUITING', 'NOT_YET_RECRUITING', 'ENROLLING_BY_INVITATION']))),
                'sites' => count($sites),
                'countries' => count($countries),
            ],
            'facets' => [
                'countries' => $countries,
                'statuses' => $statuses,
                'status_labels' => self::STATUS_LABELS,
                'phases' => $phases,
                'conditions' => $conditions,
            ],
            'trials' => $trials,
        ];
    }

    private function trialFromRow(array $row): ?array
    {
        $raw = json_decode((string)($row['raw_json'] ?? ''), true);
        if (!is_array($raw)) {
            $raw = [];
        }
        $nctId = strtoupper(trim((string)($raw['nct_id'] ?? '')));
        if (!preg_match('/^NCT\d{8}$/', $nctId)) {
            if (preg_match('/\bNCT\d{8}\b/i', (string)($row['source_url'] ?? ''), $match)) {
                $nctId = strtoupper($match[0]);
            } else {
                return null;
            }
        }

        $status = strtoupper(trim((string)($raw['overall_status'] ?? 'UNKNOWN')));
        if (!isset(self::STATUS_LABELS[$status])) {
            $status = 'UNKNOWN';
        }
        $phases = $this->stringList($raw['phase'] ?? [], 8, 80);
        $conditions = $this->stringList($raw['conditions'] ?? [], 30, 180);
        $sites = [];
        foreach (array_slice((array)($raw['locations'] ?? []), 0, 500) as $location) {
            $site = $this->siteFromRaw(is_array($location) ? $location : []);
            if ($site !== null) {
                $sites[$site['key']] = $site;
            }
        }
        $interventions = [];
        foreach (array_slice((array)($raw['interventions'] ?? []), 0, 30) as $intervention) {
            if (!is_array($intervention)) {
                continue;
            }
            $name = $this->text($intervention['name'] ?? '', 180);
            if ($name === '') {
                continue;
            }
            $key = mb_strtolower($name, 'UTF-8');
            $interventions[$key] = [
                'name' => $name,
                'type' => $this->text($intervention['type'] ?? '', 80),
            ];
        }
        $enrollment = is_array($raw['enrollment'] ?? null) ? $raw['enrollment'] : [];
        $design = is_array($raw['design'] ?? null) ? $raw['design'] : [];

        return [
            'id' => (int)$row['id'],
            'nct_id' => $nctId,
            'title' => $this->text($row['title'] ?? '', 500),
            'sponsor' => $this->text($row['authors'] ?? '', 240),
            'registry_url' => 'https://clinicaltrials.gov/study/' . rawurlencode($nctId),
            'tracker_url' => 'publication.php?id=' . (int)$row['id'],
            'status' => $status,
            'status_label' => self::STATUS_LABELS[$status],
            'status_verified_date' => $this->date($raw['status_verified_date'] ?? null),
            'last_update_date' => $this->date($raw['last_update_date'] ?? ($row['publication_date'] ?? null)),
            'start_date' => $this->date($raw['start_date'] ?? null),
            'primary_completion_date' => $this->date($raw['primary_completion_date'] ?? null),
            'completion_date' => $this->date($raw['completion_date'] ?? null),
            'study_type' => $this->text($raw['study_type'] ?? 'INTERVENTIONAL', 80),
            'phases' => $phases,
            'enrollment' => [
                'count' => isset($enrollment['count']) && is_numeric($enrollment['count']) ? max(0, (int)$enrollment['count']) : null,
                'type' => $this->text($enrollment['type'] ?? '', 40),
            ],
            'design' => [
                'allocation' => $this->text($design['allocation'] ?? '', 80),
                'intervention_model' => $this->text($design['intervention_model'] ?? '', 100),
                'primary_purpose' => $this->text($design['primary_purpose'] ?? '', 100),
                'masking' => $this->text($design['masking'] ?? '', 80),
            ],
            'conditions' => $conditions,
            'interventions' => array_values($interventions),
            'substances' => $this->stringList(explode(',', (string)($row['substance_tags'] ?? '')), 8, 80),
            'sites' => array_values($sites),
            'facilities' => $this->facilitiesFromRaw((array)($raw['locations'] ?? [])),
            'selected_reports' => $this->selectedReports($nctId),
            'result_references' => $this->resultReferences((array)($raw['result_references'] ?? [])),
            'results_posted' => array_key_exists('results_posted',$raw)?(bool)$raw['results_posted']:null,
            'result_references_checked' => array_key_exists('result_references',$raw),
            'countries' => $this->stringList(array_map(static fn($location) => is_array($location) ? ($location['country'] ?? '') : '', array_slice((array)($raw['locations'] ?? []), 0, 500)), 100, 120),
        ];
    }

    private function selectedReports(string $nctId): array
    {
        if($this->evidenceReports===null){$this->evidenceReports=[];
            foreach(['depression','alcohol-use-disorder','cancer-anxiety','tobacco'] as $topic)foreach(ResearchEvidence::collection($this->db,$topic)['studies'] as $study){
                if(empty($study['nct'])||!preg_match('/^NCT[0-9]{8}$/D',$study['nct']))continue;
                $this->evidenceReports[$study['nct']][]=['label'=>$study['label'],'url'=>$study['work_id'],'paper_id'=>$study['paper']?(int)$study['paper']['id']:null,'basis'=>'Selected source-checked evidence guide'];
            }
        }return $this->evidenceReports[$nctId]??[];
    }

    private function facilitiesFromRaw(array $locations): array
    {
        $out=[];
        foreach(array_slice($locations,0,500) as $location){
            if(!is_array($location))continue;$f=[];
            foreach(['facility'=>220,'city'=>120,'region'=>120,'country'=>120] as $field=>$length)$f[$field]=$this->text($location[$field]??'', $length);
            if(!implode('', $f))continue;
            $f['key']=hash('sha256',mb_strtolower(implode('|',$f)));$f['mapped']=$this->siteFromRaw($location)!==null;$out[$f['key']]=$f;
        }
        return array_values($out);
    }
    private function resultReferences(array $references): array
    {
        $out=[];foreach(array_slice($references,0,100) as $ref){
            if(!is_array($ref)||strtoupper((string)($ref['type']??''))!=='RESULT'||!preg_match('/^[1-9]\d{0,9}$/D',(string)($ref['pmid']??'')))continue;
            $pmid=(string)$ref['pmid'];$out[$pmid]=['pmid'=>$pmid,'type'=>'RESULT','url'=>'https://pubmed.ncbi.nlm.nih.gov/'.$pmid.'/'];
        }return array_values($out);
    }

    private function siteFromRaw(array $location): ?array
    {
        $lat = filter_var($location['lat'] ?? null, FILTER_VALIDATE_FLOAT);
        $lon = filter_var($location['lon'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($lat === false || $lon === false || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            return null;
        }
        $facility = $this->text($location['facility'] ?? '', 220);
        $city = $this->text($location['city'] ?? '', 120);
        $region = $this->text($location['region'] ?? '', 120);
        $country = $this->text($location['country'] ?? '', 120);
        $key = implode('|', [number_format((float)$lat, 4, '.', ''), number_format((float)$lon, 4, '.', ''), mb_strtolower($facility . '|' . $city . '|' . $country, 'UTF-8')]);
        return [
            'key' => hash('sha256', $key),
            'facility' => $facility,
            'city' => $city,
            'region' => $region,
            'country' => $country,
            'lat' => round((float)$lat, 5),
            'lon' => round((float)$lon, 5),
        ];
    }

    private function stringList(mixed $values, int $limit, int $maxLength): array
    {
        if (!is_array($values)) {
            $values = [$values];
        }
        $safe = [];
        foreach (array_slice($values, 0, $limit) as $value) {
            $text = $this->text(is_scalar($value) ? (string)$value : '', $maxLength);
            if ($text !== '') {
                $safe[mb_strtolower($text, 'UTF-8')] = $text;
            }
        }
        return array_values($safe);
    }

    private function text(mixed $value, int $maxLength): string
    {
        return mb_substr(clean_scientific_text(is_scalar($value) ? (string)$value : ''), 0, $maxLength, 'UTF-8');
    }

    private function date(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}$/', $value)) {
            return $value;
        }
        return parse_date_or_null($value);
    }
}
