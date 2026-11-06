<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/helpers.php';
(static function (): void {
    $check = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
    $server = class_exists('ResearchMcpServer') ? new ResearchMcpServer(null, 'transcriptomics') : null;
    $snapshot = new TranscriptomicsSnapshot();
    if ($server) {
        $tools=$server->dispatch(json_decode('{"jsonrpc":"2.0","id":1,"method":"tools/list"}'))['result']['tools'];
        $check(count($tools)===5, 'Transcriptomics endpoint must expose exactly its five tools');
        $rejected=$server->dispatch(json_decode('{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"search","arguments":{"query":"psilocybin"}}}'));
        $check(isset($rejected['error']), 'Transcriptomics endpoint must reject bibliographic search');
    }
    $call = static function (string $name, array $args = []) use ($server,$snapshot): array {
        if ($server === null) {
            try { $payload=$snapshot->execute($name,$args);return ['isError'=>false,'structuredContent'=>$payload,'content'=>[['type'=>'text','text'=>json_encode($payload)]]]; }
            catch (InvalidArgumentException | OutOfBoundsException $error) {return ['isError'=>true,'content'=>[['type'=>'text','text'=>$error->getMessage()]]];}
        }
        $reply = $server->dispatch(json_decode(json_encode(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>$name,'arguments'=>(object)$args]])), '2025-11-25');
        if (isset($reply['error'])) throw new RuntimeException(json_encode($reply));
        return $reply['result'];
    };
    $data = static function (array $reply) use ($check): array {
        $check(!$reply['isError'], 'Transcriptomics query failed: ' . json_encode($reply));
        $check(json_encode(json_decode($reply['content'][0]['text'],true)) === json_encode($reply['structuredContent']), 'Text and structured results differ');
        $check($reply['structuredContent']['provenance']['doi']==='10.5281/zenodo.23283595', 'Version DOI must be pinned');
        $check(count($reply['structuredContent']['limitations'])>=8, 'Scientific qualifications missing');
        $check(strlen(json_encode($reply)) < 2*1024*1024, 'Response exceeds transport check bound');
        return $reply['structuredContent']['data'];
    };
    $search = $data($call('search_transcriptomic_genes',['query'=>'GPX4','limit'=>1]));
    $check(count($search['genes'])===1 && $search['genes'][0]['symbol']==='GPX4','Exact gene should rank first');
    $check(str_contains($search['genes'][0]['atlas_url'],'#view=genes&gene=ENSG'), 'Search results need exact-ID atlas links');
    $gene = $data($call('get_transcriptomic_gene',['gene'=>'gpx4']));
    $check(count($gene['results'])===3,'All three contrasts required by default');
    $check(is_float($gene['results'][0]['log2FoldChange']) && isset($gene['results'][0]['source_table']), 'Typed estimates and provenance required');
    $check(count(array_unique(array_column($gene['results'],'contrast')))===3, 'Gene results must identify their contrasts');
    foreach ($gene['results'] as $row) $check(str_contains($row['atlas_url'],'gene='.$row['gene_id']), 'Gene atlas link must preserve source identity');
    $id = $search['genes'][0]['gene_id'];
    $stable = $data($call('get_transcriptomic_gene',['gene'=>explode('.',$id)[0],'contrast'=>'day1_vs_control']));
    $check(count($stable['results'])===1 && $stable['results'][0]['gene_id']===$id,'Stable ID must resolve exact source version');
    $ox = $data($call('get_transcriptomic_pathways',['query'=>'oxidative phosphorylation','contrast'=>'day1_vs_control']));
    $check($ox['total_pathways']===1,'Pathway search must normalize spaces');
    $atlasLinks = $ox['pathways'][0]['atlas_links_by_contrast'];
    $check(array_keys($atlasLinks)===['day1_vs_control'] && str_contains($atlasLinks['day1_vs_control']['pathway'],'view=pathways&pathway=HALLMARK_OXIDATIVE_PHOSPHORYLATION&contrast=day1_vs_control'), 'Pathway links must match the requested contrast');
    $cam = $ox['pathways'][0]['camera_both_settings'];
    $check(count($cam)===2 && array_column($cam,'mode')===['estimated','fixed_0.01'],'Both CAMERA assumptions required');
    $check(abs($cam[0]['family_fdr']-0.587168975061856)<1e-12 && abs($cam[1]['family_fdr']-5.84305966116762e-8)<1e-10,'CAMERA source values altered');
    $all = $data($call('get_transcriptomic_pathways',['limit'=>1]));
    $check($all['total_pathways']===14 && $all['next_offset']===1,'Bounded pathway pagination missing');
    $next = $data($call('get_transcriptomic_pathways',['limit'=>1,'offset'=>1]));
    $check($next['pathways'][0]['pathway']!==$all['pathways'][0]['pathway'],'Pages overlap');
    $rob = $data($call('get_transcriptomic_robustness'))['results'];
    $check(count($rob['camera'])===30,'Both complete 15-test CAMERA families required');
    $post = array_values(array_filter($rob['bayesian'],static fn($r)=>str_ends_with($r['source_table'],'posterior_summary.csv')));
    $check(count($post)===60 && count(array_unique(array_column($post,'fit')))===10,'All ten Bayesian models required');
    $dna = array_values(array_filter($rob['dna'],static fn($r)=>($r['module']??'')==='TELOMERE_MINUS_DNA_REPAIR' && ($r['variant']??'')==='primary'));
    $days = array_column($dna,'family_fdr','contrast');
    $check($days['day1_vs_control']<0.05 && $days['day3_vs_control']>0.05,'DNA-overlap uncertainty lost');
    $prov = $data($call('get_transcriptomic_provenance'));
    $check(count($prov['analysis_units'])===9 && count($prov['sample_metadata'])===23,'Analysis design counts changed');
    $check(str_contains($prov['license_and_attribution_notices'],'Anne Hoffrichter') && str_contains($prov['license_and_attribution_notices'],'MSigDB'),'Source attribution missing');
    $check(str_ends_with($prov['atlas_url'],'#view=methods'), 'Provenance must link to methods');
    $manifest = $prov['manifest']; $check(!isset($manifest['database']['path']),'Runtime location exposed');
    foreach ([['get_transcriptomic_gene',['gene'=>'UNKNOWN_NONEXISTENT']],['get_transcriptomic_gene',['gene'=>'GPX4','contrast'=>'bad']],['get_transcriptomic_gene',['gene'=>'GPX4','url'=>'file:///etc/passwd']],['get_transcriptomic_pathways',['limit'=>6]],['search_transcriptomic_genes',['query'=>' ']],['get_transcriptomic_robustness',['topic'=>'favorite']]] as [$name,$args]) $check($call($name,$args)['isError'],'Invalid input accepted');
    $empty = $data($call('search_transcriptomic_genes',['query'=>"%' OR 1=1 --"]));
    $check($empty['total']===0,'SQL input was not literal');
    if ($server !== null) {
    $resources = $server->dispatch(json_decode('{"jsonrpc":"2.0","id":1,"method":"resources/list"}'))['result']['resources'];
    $check(count($resources)===2,'Snapshot resource must be discoverable');
    }
    echo "Transcriptomics: source values, complete sensitivity families, design, attribution, bounds and invalid inputs passed.\n";
})();
