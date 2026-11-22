<?php
declare(strict_types=1);
final class CachePrewarmer
{
    public static function run(Database $db): array
    {
        if (PHP_SAPI !== 'cli') throw new RuntimeException('Cache prewarming is CLI only.');
        $path=Config::dataDir().'/cache-prewarm.lock';
        $lock=fopen($path,'c');
        if ($lock===false) throw new RuntimeException('Cannot open prewarm lock.');
        chmod($path,0600);
        if (!flock($lock,LOCK_EX | LOCK_NB)) { fclose($lock); return ['skipped'=>true,'elapsed_ms'=>0]; }
        try { return self::build($db); }
        finally { flock($lock,LOCK_UN); fclose($lock); }
    }

    private static function build(Database $db): array
    {
        $start=hrtime(true);
        $revision=(string)$db->pdo()->query('SELECT revision FROM app_cache_revision WHERE id=1')->fetchColumn();
        $index=CitationIndex::rebuild($db);
        $repo=new PublicationRepository($db);
        foreach (['stats','journals','years','topics','studyTypes','sources','publicationStatuses'] as $method) $repo->$method();
        $repo->analytics(false);
        ResearchOverview::summarize($db);
        PublicationStatistics::summary($db);
        StudyRelationships::catalogue($db);
        MscaStatistics::yearDetails($db);
        $snapshot=OfflineSnapshot::build($db);
        if ((string)$db->pdo()->query('SELECT revision FROM app_cache_revision WHERE id=1')->fetchColumn() !== $revision) throw new RuntimeException('Catalogue changed during prewarming.');
        $dataset = DatasetArtifacts::refresh($db);
        return ['revision'=>$revision,'citation_index'=>$index,'offline_records'=>$snapshot['record_count'],'dataset_snapshot'=>$dataset,'elapsed_ms'=>round((hrtime(true)-$start)/1e6,3)];
    }
}
