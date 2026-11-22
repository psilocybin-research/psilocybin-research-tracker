<?php
declare(strict_types=1);

/** Research discovery from visible bibliographic metadata and allowlisted registry facts. */
final class ResearchExplorer
{
    public function __construct(private Database $db) {}

    public function network(string $query = '', string $topic = '', int $limit = 80, bool $full = false): array
    {
        $version = substr(hash('sha256', hash_file('sha256', __FILE__) . hash_file('sha256', __DIR__ . '/ResearcherIdentity.php') . hash_file('sha256', __DIR__ . '/helpers.php')), 0, 16);
        // Only four fixed, unfiltered visual views are cached. Searches, topic
        // filters and full exports retain their existing projection path.
        if (!$full && $query === '' && $topic === '' && in_array($limit, [40, 80, 150, 300], true)) {
            return PublicAggregateCache::remember($this->db, 'research-network-view-' . $limit . '-' . $version,
                fn(): array => $this->buildNetwork($query, $topic, $limit, $full, $version));
        }
        return $this->buildNetwork($query, $topic, $limit, $full, $version);
    }

    private function buildNetwork(string $query, string $topic, int $limit, bool $full, string $version): array
    {
        $all = PublicAggregateCache::remember($this->db, 'research-network-' . $version, function (): array {
            $nodes = []; $edges = []; $papers = []; $identities = []; $omitted = 0;
            [$authorMetadata,$authorBridges]=ResearcherIdentity::metadata($this->db);
            $rows = $this->db->pdo()->query("SELECT id, title, authors, journal, publication_date, publication_year, doi, source_name, publication_status, topic_tags FROM publications WHERE hidden = 0 AND false_positive = 0 AND publication_status != 'clinical trial' ORDER BY id");
            foreach ($rows as $row) {
                $paper = public_paper($row);
                $names = preg_split(str_contains((string)$paper['authors'], ';') ? '/;\s*/u' : '/,\s*/u', (string)$paper['authors']);
                $names = array_values(array_unique(array_filter(array_map('trim', $names ?: []), static fn(string $name): bool => mb_strlen($name) >= 3 && !preg_match('/et\s+al\.?/i', $name))));
                // Avoid a pathological consortium record producing quadratic public workloads.
                if (count($names) > 100) { $omitted++; continue; }
                $ids = [];
                foreach ($names as $name) {
                    [$entityId,$identityUri,$basis]=ResearcherIdentity::label($name,(int)$paper['id'],$authorMetadata,$authorBridges);
                    if (!isset($identities[$entityId])) $identities[$entityId] = count($identities) + 1;
                    $id = $identities[$entityId];
                    if (!isset($nodes[$id])) $nodes[$id] = ['id' => $id, 'entity_id' => $entityId, 'name' => $name, 'identity_uri'=>$identityUri, 'identity_basis'=>$basis, 'aliases'=>[], 'papers' => [], 'connections' => 0];
                    $nodes[$id]['aliases'][$name]=true;
                    if(mb_strlen($name)>mb_strlen($nodes[$id]['name']))$nodes[$id]['name']=$name;
                    $nodes[$id]['papers'][(int)$paper['id']]=(int)$paper['id'];
                    $ids[$id] = $id;
                }
                if (!$ids) continue;
                $ids=array_values($ids);
                $papers[(int)$paper['id']] = array_intersect_key($paper, array_flip(['id','title','authors','journal','publication_date','publication_year','doi','source_name','publication_status','topic_tags','abstract_redistributed','text_rights_status']));
                for ($i = 0; $i < count($ids); $i++) for ($j = $i + 1; $j < count($ids); $j++) {
                    $pair = [$ids[$i], $ids[$j]]; sort($pair, SORT_NUMERIC);
                    $key = implode('|', $pair);
                    if (!isset($edges[$key])) $edges[$key] = [$pair[0], $pair[1], []];
                    $edges[$key][2][] = (int)$paper['id'];
                }
            }
            foreach($nodes as &$node){$node['aliases']=array_keys($node['aliases']);$node['papers']=array_values($node['papers']);}unset($node);
            unset($authorMetadata,$authorBridges);
            foreach ($edges as $edge) { $nodes[$edge[0]]['connections']++; $nodes[$edge[1]]['connections']++; }
            uasort($nodes, static fn(array $a, array $b): int => ($b['connections'] <=> $a['connections']) ?: strcmp($a['name'], $b['name']));
            return ['nodes' => array_values($nodes), 'edges' => array_values($edges), 'papers' => $papers, 'omitted_large_author_lists' => $omitted];
        });
        $papers = array_filter($all['papers'], static fn(array $p): bool => $topic === '' || in_array($topic, array_map('trim', explode(',', (string)$p['topic_tags'])), true));
        $paperIds = array_fill_keys(array_keys($papers), true);
        $nodes = [];
        foreach ($all['nodes'] as $node) {
            $node['papers'] = array_values(array_filter($node['papers'], static fn(int $id): bool => isset($paperIds[$id])));
            if (!$node['papers']) continue;
            $node['connections'] = 0;
            $nodes[$node['id']] = $node;
        }
        $edgeCount = 0;
        foreach ($all['edges'] as $edge) {
            if (!array_intersect_key(array_fill_keys($edge[2], true), $paperIds)) continue;
            $nodes[$edge[0]]['connections']++; $nodes[$edge[1]]['connections']++; $edgeCount++;
        }
        uasort($nodes, static fn(array $a, array $b): int => ($b['connections'] <=> $a['connections']) ?: strcmp($a['name'], $b['name']));
        $matching = array_filter($nodes, static fn(array $n): bool => $query === '' || str_contains(mb_strtolower(implode(' ', $n['aliases'])), mb_strtolower($query)));
        $selected = $full ? $matching : array_slice($matching, 0, max(10, min(300, $limit)), true);
        // A name search returns that person's neighbourhood, not an isolated node.
        if ($query !== '') {
            foreach ($all['edges'] as $edge) {
                if (!array_intersect_key(array_fill_keys($edge[2], true), $paperIds)) continue;
                if (isset($matching[$edge[0]]) || isset($matching[$edge[1]])) {
                    foreach ([$edge[0], $edge[1]] as $id) if ($full || count($selected) < max(10, min(300, $limit))) $selected[$id] = $nodes[$id];
                }
            }
        }
        $visibleEdges = $full && $topic === '' && $query === '' ? $all['edges'] : [];
        if (!($full && $topic === '' && $query === '')) foreach ($all['edges'] as $edge) {
            if (!isset($selected[$edge[0]], $selected[$edge[1]])) continue;
            $ids = array_values(array_filter($edge[2], static fn(int $id): bool => isset($paperIds[$id])));
            if ($ids) $visibleEdges[] = $full ? [$edge[0], $edge[1], $ids] : ['source' => $edge[0], 'target' => $edge[1], 'papers' => $ids, 'weight' => count($ids)];
        }
        $used = [];
        foreach ($selected as $node) foreach ($node['papers'] as $id) $used[$id] = $papers[$id];
        return ['schema_version' => 2, 'summary' => ['researcher_names' => count($nodes), 'connections' => $edgeCount, 'papers' => count($papers), 'matching_names' => count($matching), 'displayed_names' => count($selected), 'omitted_large_author_lists' => $all['omitted_large_author_lists']], 'nodes' => array_values($selected), 'edges' => $visibleEdges, 'edge_encoding' => $full ? ['source_node_id', 'target_node_id', 'shared_record_ids'] : 'named fields', 'papers' => array_values($used), 'limitations' => 'Source-associated valid ORCID/OpenAlex identifiers group aliases; missing identifiers remain name-only labels. Identifier syntax and ORCID checksums do not independently verify identity; names alone can still conflate people. Co-authorship is not evidence of influence or research quality. Registry sponsors are excluded; lists above 100 authors are omitted. Visual views are bounded for readability; all matching names and connections are available through the free full JSON export.'];
    }

    public function trials(array $filters = []): array
    {
        $dataset = (new TrialMapService($this->db))->dataset();
        $trials = array_values(array_filter($dataset['trials'], static function (array $t) use ($filters): bool {
            foreach (['country' => 'countries', 'phase' => 'phases', 'condition' => 'conditions'] as $filter => $field) if (!empty($filters[$filter]) && !in_array($filters[$filter], $t[$field] ?? [], true)) return false;
            foreach (['sponsor', 'status'] as $field) if (!empty($filters[$field]) && $filters[$field] !== $t[$field]) return false;
            return empty($filters['q']) || str_contains(mb_strtolower(implode(' ', [$t['title'], $t['sponsor'], $t['nct_id'], implode(' ', $t['conditions'])])), mb_strtolower($filters['q']));
        }));
        $facets = ['countries' => [], 'sponsors' => [], 'phases' => [], 'conditions' => [], 'statuses' => []];
        foreach ($dataset['trials'] as $t) {
            foreach (['countries', 'phases', 'conditions'] as $field) foreach (array_unique($t[$field] ?? []) as $value) $facets[$field][$value] = ($facets[$field][$value] ?? 0) + 1;
            foreach (['sponsors' => 'sponsor', 'statuses' => 'status'] as $field => $source) if ($t[$source] !== '') $facets[$field][$t[$source]] = ($facets[$field][$t[$source]] ?? 0) + 1;
        }
        foreach ($facets as &$facet) arsort($facet); unset($facet);
        $years = []; $statuses = []; $conditions = []; $sites = []; $facilities=[]; $enrollment = 0; $enrollmentKnown = 0;
        foreach ($trials as $t) {
            if ($t['start_date']) { $year = substr($t['start_date'], 0, 4); $years[$year] = ($years[$year] ?? 0) + 1; }
            $statuses[$t['status_label']] = ($statuses[$t['status_label']] ?? 0) + 1;
            foreach ($t['conditions'] as $c) $conditions[$c] = ($conditions[$c] ?? 0) + 1;
            foreach ($t['sites'] as $site) $sites[$site['key']] = $site;
            foreach($t['facilities'] as $facility)$facilities[$facility['key']]=$facility;
            if ($t['enrollment']['count'] !== null) { $enrollment += $t['enrollment']['count']; $enrollmentKnown++; }
        }
        $facilities=array_values($facilities);usort($facilities,static function($a,$b)use($filters){$country=$filters['country']??'';if($country!==''&&($a['country']===$country)!==($b['country']===$country))return $a['country']===$country?-1:1;return strcmp($a['country'].'|'.$a['facility'],$b['country'].'|'.$b['facility']);});
        ksort($years); arsort($statuses); arsort($conditions);
        return ['trials' => $trials, 'facets' => $facets, 'years' => $years, 'statuses' => $statuses, 'conditions' => $conditions, 'sites' => array_values($sites), 'facilities'=>$facilities, 'enrollment' => ['sum' => $enrollment, 'known_trials' => $enrollmentKnown], 'registry_checked_at_utc' => $dataset['registry_checked_at_utc']];
    }

    public function results(string $topic = ''): array
    {
        $out = [];
        foreach (['depression', 'alcohol-use-disorder','cancer-anxiety','tobacco'] as $key) {
            if ($topic !== '' && $topic !== $key) continue;
            $collection = ResearchEvidence::collection($this->db, $key);
            foreach ($collection['studies'] as $study) $out[] = $study + ['topic' => $key, 'source_checked' => $collection['checked']];
        }
        return $out;
    }

    public function comparison(array $ids): array
    {
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0))), 0, 50);
        $papers = array_map('public_paper', (new PublicationRepository($this->db))->publicByIds($ids));
        $trials = [];
        $service = new TrialMapService($this->db);
        foreach ($papers as $paper) if ($paper['publication_status'] === 'clinical trial' && ($trial = $service->forPublication((int)$paper['id']))) $trials[] = $trial;
        return ['papers' => $papers, 'trials' => $trials];
    }
}
