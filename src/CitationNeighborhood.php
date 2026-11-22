<?php
declare(strict_types=1);

/** Direct structured citation links only; similarity never establishes a citation. */
final class CitationNeighborhood
{
    public static function build(Database $db, int $paperId, int $display = 12): ?array
    {
        $rows = $db->pdo()->query('SELECT id,title,authors,publication_date,journal,doi,openalex_id,publication_status,source_name,raw_json FROM publications WHERE hidden=0 AND false_positive=0 AND merged_into_id IS NULL')->fetchAll();
        $byId = $byDoi = $byWork = [];
        foreach ($rows as $row) {
            $byId[(int)$row['id']] = $row;
            if ($doi = CitationIndex::validDoi($row['doi'] ?? null)) $byDoi[$doi] = $row;
            if ($work = self::work($row['openalex_id'] ?? null)) $byWork[$work] = $row;
        }
        if (!isset($byId[$paperId])) return null;
        $focus = $byId[$paperId];
        $focusDoi = CitationIndex::validDoi($focus['doi'] ?? null);
        $focusWork = self::work($focus['openalex_id'] ?? null);
        $incoming = $outgoing = [];
        $references = static function(array $row): array {
            $raw = json_decode((string)($row['raw_json'] ?? ''), true);
            $works = [];
            foreach ((array)($raw['referenced_works'] ?? []) as $value) if ($work = self::work($value)) $works[$work] = true;
            return [CitationIndex::directDois($row), array_keys($works)];
        };
        foreach ($rows as $row) {
            if ((int)$row['id'] === $paperId) continue;
            [$dois, $works] = $references($row);
            if (($focusDoi && in_array($focusDoi, $dois, true)) || ($focusWork && in_array($focusWork, $works, true))) {
                $node = self::paper($row);
                $node['basis'] = $focusDoi && in_array($focusDoi, $dois, true) ? 'Stored DOI reference' : 'Stored OpenAlex work reference';
                $incoming[$node['id']] = $node;
            }
        }
        [$dois, $works] = $references($focus);
        foreach ($dois as $doi) {
            $node = isset($byDoi[$doi]) ? self::paper($byDoi[$doi]) : ['id'=>'doi:'.$doi, 'type'=>'reference','label'=>$doi,'doi'=>$doi,'url'=>'https://doi.org/'.$doi,'external'=>true];
            if ($node['id'] === 'paper:'.$paperId) continue;
            $node['basis'] = 'Stored DOI reference';
            $outgoing[$node['id']] = $node;
        }
        foreach ($works as $work) {
            $row = $byWork[$work] ?? null;
            $node = $row ? self::paper($row) : ['id'=>'work:'.$work,'type'=>'reference','label'=>'OpenAlex '.$work,'url'=>'https://openalex.org/'.$work,'external'=>true];
            if ($node['id'] === 'paper:'.$paperId || isset($outgoing[$node['id']])) continue;
            $node['basis'] = 'Stored OpenAlex work reference';
            $outgoing[$node['id']] = $node;
        }
        $sort = static fn(array $a,array $b): int => strcmp((string)($b['date']??''),(string)($a['date']??'')) ?: strcmp($a['label'],$b['label']);
        uasort($incoming,$sort); uasort($outgoing,$sort);
        $raw = json_decode((string)($focus['raw_json'] ?? ''),true);
        $reported = isset($raw['cited_by_count']) && is_numeric($raw['cited_by_count']) ? max(0,(int)$raw['cited_by_count']) : null;
        // Only attribute the count to OpenAlex when the stored work has an OpenAlex identity.
        if (!$focusWork) $reported = null;
        $display = max(1,min(96,$display));
        $center = self::paper($focus); $center['role']='focus';
        $nodes=[$center['id']=>$center];$edges=[];
        foreach (['references'=>$outgoing,'citing'=>$incoming] as $direction=>$items) {
            foreach (array_slice($items,0,$display,true) as $node) {
                $node['role'] = isset($nodes[$node['id']]) ? 'both' : $direction;
                $nodes[$node['id']]=$node;
                $source=$direction==='references'?$center['id']:$node['id'];$target=$direction==='references'?$node['id']:$center['id'];
                $edges[]=['id'=>$source.'|'.$target,'source'=>$source,'target'=>$target,'type'=>'cites','basis'=>$node['basis']];
            }
        }
        return ['mode'=>'paper','focus'=>$center,'references'=>array_values($outgoing),'citing'=>array_values($incoming),'nodes'=>array_values($nodes),'edges'=>$edges,'coverage'=>[
            'scope'=>'Public tracker catalogue and stored structured reference metadata. External references may have identifiers only. No live external citation lookup is performed.',
            'reported_citations'=>$reported,'reported_source'=>$reported!==null?'OpenAlex':null,'reported_url'=>$focusWork?'https://openalex.org/'.$focusWork:null,
            'record_updated_at'=>$focus['updated_at']??null,'display_per_direction'=>$display,
        ],'stats'=>['seed_papers'=>1,'nodes'=>count($nodes),'edges'=>count($edges),'focus_paper'=>$paperId,'external_references'=>count(array_filter($outgoing,static fn($n)=>!empty($n['external']))),'references'=>count($outgoing),'citing'=>count($incoming)]];
    }
    private static function work(mixed $value): ?string
    {
        return is_string($value)&&preg_match('~^(?:https?://openalex\.org/)?(W[0-9]+)$~i',trim($value),$m)?strtoupper($m[1]):null;
    }
    private static function paper(array $row): array
    {
        $id=(int)$row['id'];
        return ['id'=>'paper:'.$id,'paper_id'=>$id,'type'=>'paper','label'=>(string)$row['title'],'authors'=>(string)($row['authors']??''),'date'=>(string)($row['publication_date']??''),'journal'=>(string)($row['journal']??''),'doi'=>(string)($row['doi']??''),'url'=>'publication.php?id='.$id,'status'=>(string)($row['publication_status']??'published'),'source'=>(string)($row['source_name']??'')];
    }
}
