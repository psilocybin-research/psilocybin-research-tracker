<?php
declare(strict_types=1);
final class OfflineSnapshot
{
    public static function build(Database $db): array
    {
        return PublicAggregateCache::remember($db, 'offline-library-v1', static function () use ($db): array {
            $pdo=$db->pdo();$pdo->beginTransaction();
            try {
                $rows=$pdo->query('SELECT id,title,authors,journal,publication_date,publication_year,doi,pubmed_id,openalex_id,source_url,substance_tags,topic_tags,study_type,source_name,publication_status,date_added,last_checked,CASE WHEN abstract IS NOT NULL AND TRIM(abstract) != "" THEN 1 ELSE 0 END abstract_available FROM publications WHERE hidden=0 AND false_positive=0 AND merged_into_id IS NULL ORDER BY publication_date DESC,id DESC')->fetchAll();
                $records=public_papers($rows);
                // Reuse the public citation projection; unrestricted source JSON never leaves PHP.
                $references=[];$workMap=CitationIndex::workMap($db);
                $statement=$pdo->query('SELECT id,raw_json FROM publications WHERE hidden=0 AND false_positive=0 AND merged_into_id IS NULL');
                while($row=$statement->fetch())$references[(int)$row['id']]=array_values(array_filter(CitationIndex::referenceDois($row,$workMap),static fn(string $doi):bool=>strlen($doi)<=2048&&preg_match('~^10\\.\\d{4,9}/[^\\s<>]+$~u',$doi)===1));
                foreach($records as &$record)$record['reference_dois']=$references[(int)$record['id']]??[];
                unset($record);$pdo->commit();
            } catch(Throwable $error) {if($pdo->inTransaction())$pdo->rollBack();throw $error;}
            $encoded=json_encode($records,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            return ['format'=>'public-offline-library-v1','revision'=>hash('sha256',$encoded),'generated_at_utc'=>(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP'),'record_count'=>count($records),'approximate_bytes'=>strlen($encoded),'scope'=>'Public bibliographic metadata only; no source abstracts, private data or external full texts.','records'=>$records];
        },hash('sha256',(string)file_get_contents(__FILE__).(string)file_get_contents(__DIR__.'/helpers.php').(string)file_get_contents(__DIR__.'/CitationIndex.php')));
    }
}
