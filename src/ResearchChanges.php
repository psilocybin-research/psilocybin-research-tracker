<?php
declare(strict_types=1);

/** Observed registry transitions. First observation is a baseline, not historical news. */
final class ResearchChanges
{
    public const TYPES=['recruiting'=>'Recruitment starts','status_changed'=>'Registry status changes','results_posted'=>'Registry results posted','result_publication'=>'Linked result publications'];
    public function __construct(private Database $db) {}
    public static function types(array $values): array { return array_values(array_intersect(array_keys(self::TYPES),array_filter($values,'is_string'))); }
    public function observe(): int
    {
        $pdo=$this->db->pdo();$own=!$pdo->inTransaction();if($own)$pdo->beginTransaction();$count=0;
        try {
            $initialized=(bool)$pdo->query("SELECT 1 FROM research_change_state WHERE nct_id='__baseline__'")->fetchColumn();
            $find=$pdo->prepare('SELECT snapshot_json FROM research_change_state WHERE nct_id=?');
            $save=$pdo->prepare('INSERT OR REPLACE INTO research_change_state(nct_id,snapshot_json,observed_at) VALUES(?,?,?)');
            $add=$pdo->prepare('INSERT INTO research_changes(publication_id,nct_id,event_type,facts_json,observed_at) VALUES(?,?,?,?,?)');
            foreach((new TrialMapService($this->db))->dataset()['trials'] as $trial){
                $id=$trial['nct_id'];$current=['status'=>$trial['status'],'results_posted'=>$trial['results_posted'],'result_pmids'=>$trial['result_references_checked']?array_column($trial['result_references'],'pmid'):null];if(is_array($current['result_pmids']))sort($current['result_pmids']);
                $find->execute([$id]);$old=$find->fetchColumn();$before=$old?json_decode($old,true,512,JSON_THROW_ON_ERROR):null;$events=[];
                if($initialized){
                    if(($before['status']??null)!==$current['status']){
                        if($current['status']==='RECRUITING')$events['recruiting']=['before'=>$before['status']??null,'after'=>'RECRUITING'];
                        elseif($before)$events['status_changed']=['before'=>$before['status'],'after'=>$current['status']];
                    }
                    if($before && $before['results_posted']===false && $current['results_posted']===true)$events['results_posted']=['registry_url'=>$trial['registry_url']];
                    if($before && is_array($current['result_pmids']) && is_array($before['result_pmids']))foreach(array_diff($current['result_pmids'],$before['result_pmids']) as $pmid){
                        $add->execute([$trial['id'],$id,'result_publication',json_encode(['pmid'=>$pmid]),current_utc()]);$count++;
                    }
                }
                foreach($events as $type=>$facts){$add->execute([$trial['id'],$id,$type,json_encode($facts),current_utc()]);$count++;}
                $save->execute([$id,json_encode($current),current_utc()]);
            }
            $save->execute(['__baseline__','{}',current_utc()]);if($own)$pdo->commit();return $count;
        } catch(Throwable $e){if($own&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    public function latest(array $types=[],int $limit=50, ?array $subscription=null, string $since=""): array
    {
        $conditions='';$params=[];if($subscription){$conditions=' AND c.observed_at>=? AND c.observed_at>=? AND NOT EXISTS(SELECT 1 FROM research_change_deliveries d WHERE d.change_id=c.id AND d.subscription_id=?)';$params=[$since,$subscription['events_since']??$subscription['created_at'],(int)$subscription['id']];}
        $rows=$this->db->pdo()->prepare("SELECT c.id,c.publication_id,c.nct_id,c.event_type,c.facts_json,c.observed_at,p.title,p.topic_tags,p.substance_tags,p.authors FROM research_changes c JOIN publications p ON p.id=c.publication_id WHERE p.hidden=0 AND p.false_positive=0 $conditions ORDER BY c.id DESC");$rows->execute($params);$out=[];
        foreach($rows as $row){if($types&&!in_array($row['event_type'],$types,true))continue;$facts=json_decode($row['facts_json'],true,512,JSON_THROW_ON_ERROR);unset($row['facts_json']);
            $paper=public_paper(array_merge($row,['id'=>$row['publication_id']]));foreach(['title','authors','topic_tags','substance_tags'] as $field)$row[$field]=$paper[$field];
            $row['label']=self::TYPES[$row['event_type']];$row['facts']=$facts;$row['url']=Config::publicBaseUrl().'publication.php?id='.(int)$row['publication_id'];
            if($row['event_type']==='result_publication'){$row['label']='Registry added a result-publication reference';$row['source_url']='https://pubmed.ncbi.nlm.nih.gov/'.$facts['pmid'].'/';}
            elseif($row['event_type']==='recruiting')$row['label']=($facts['before']===null?'First observed recruiting':'Recruitment status changed to recruiting');
            $out[]=$row;if(count($out)>=max(1,min(200,$limit)))break;
        }return $out;
    }
    public function forSubscription(array $subscription,string $since): array
    {
        $types=self::types(explode(',',(string)($subscription['event_types']??'')));if(!$types)return [];
        $out=[];$delivered=$this->db->pdo()->prepare('SELECT 1 FROM research_change_deliveries WHERE subscription_id=? AND change_id=?');
        foreach($this->latest($types,200,$subscription,$since) as $event){
            if($event['observed_at']<$since || $event['observed_at']<($subscription['events_since']??$subscription['created_at']))continue;
            if(!array_intersect(explode(',',(string)$subscription['substances']),array_map('trim',explode(',',$event['substance_tags']))))continue;
            if(!empty($subscription['topic'])&&!array_intersect(AlertFilters::values($subscription['topic']),array_map('trim',explode(',',$event['topic_tags']))))continue;
            foreach(['author'=>'authors','keywords'=>'title'] as $filter=>$field)if(!empty($subscription[$filter])&&!str_contains(mb_strtolower($event[$field]),mb_strtolower($subscription[$filter])))continue 2;
            // Journal and citation filters describe publications, not registry sponsor records.
            if(!empty($subscription['journal'])||!empty($subscription['cited_doi']))continue;
            $delivered->execute([(int)$subscription['id'],(int)$event['id']]);if(!$delivered->fetchColumn())$out[]=$event;
        }return $out;
    }
    public function mark(int $subscriptionId,array $events): void
    {
        $stmt=$this->db->pdo()->prepare('INSERT OR IGNORE INTO research_change_deliveries(subscription_id,change_id,delivered_at) VALUES(?,?,?)');
        foreach($events as $event)$stmt->execute([$subscriptionId,(int)$event['id'],current_utc()]);
    }
}
