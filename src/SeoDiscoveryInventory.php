<?php
declare(strict_types=1);
/** Private discovery/change inventory built from allowlisted public bibliographic facts. */
final class SeoDiscoveryInventory
{
    public static function build(PDO $pdo, array $targets, string $root): array
    {
        $base=rtrim((string)$targets['site'],'/').'/'; $entries=[];
        foreach($targets['pages'] as $page){
            $path=(string)$page['path'];
            if(empty($page['index'])||str_contains($path,'?'))continue;
            if(str_starts_with($path,'/')||str_contains($path,'..')||preg_match('/[^a-z0-9_\/.\-]/i',$path))throw new RuntimeException('Invalid canonical inventory path');
            if(str_starts_with($path,'msca-impact/')){
                // Only published canonical editions, never build materials or URL aliases.
                $metadataPath=$root.'/msca-impact/seo.json';
                if(!is_file($metadataPath))continue;
                $metadata=json_decode((string)file_get_contents($metadataPath),true,512,JSON_THROW_ON_ERROR);
                $locale=$path==='msca-impact/'?'en':(preg_match('~^msca-impact/([a-z]{2}(?:-[a-z]+)?)/$~D',$path,$match)?$match[1]:'');
                if(!isset($metadata[$locale])||($locale==='en'&&$path!=='msca-impact/'))continue;
                $source='';
                foreach(['msca-impact/index.php','msca-impact/i18n.php'] as $dependency){
                    if(!is_file($root.'/'.$dependency))throw new RuntimeException('Missing MSCA canonical dependency');
                    $source.=preg_replace('/2026\d{4}-[a-z0-9-]+-v\d+/','ASSET_VERSION',(string)file_get_contents($root.'/'.$dependency));
                }
                $source.=json_encode($metadata[$locale],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
                if($locale!=='en'){
                    $localePath=$root.'/msca-impact/locales/'.$locale.'.json';
                    if(!is_file($localePath))throw new RuntimeException('Missing published MSCA translation');
                    $source.=json_encode(json_decode((string)file_get_contents($localePath),true,512,JSON_THROW_ON_ERROR),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
                }
                $entries['https://ukri.psilocybin-research.com/'.$path]=['template'=>'editorial','hash'=>hash('sha256',$source)];
                continue;
            }
            $file=$path===''?'index.php':(str_ends_with($path,'/')?rtrim($path,'/').'.php':$path);
            if($path==='european-psilocybin-research-projects/')$file='european-projects.php';
            if(!is_file($root.'/'.$file))$file=str_contains($path,'clinical-trials')?'clinical-trials-topic.php':$file;
            $source=is_file($root.'/'.$file)?file_get_contents($root.'/'.$file):'';
            // Cache rebuilds alone are not substantive changes.
            $source=preg_replace('/2026\d{4}-[a-z0-9-]+-v\d+/', 'ASSET_VERSION', (string)$source);
            $dependencies=['src/ViewHelpers.php'];
            if($path==='european-psilocybin-research-projects/'){
                $dependencies[]='config/european-project-notes.php';
                $snapshotPath=$root.'/data/european-projects/snapshot.json';
                if(is_file($snapshotPath)){
                    $snapshot=json_decode((string)file_get_contents($snapshotPath),true,64,JSON_THROW_ON_ERROR);
                    $source.=json_encode($snapshot['projects']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
                }
            }
            if($path==='psilocybin-research-guide/')$dependencies[]='config/research-orientation.php';
            if(in_array($path,['research-api.php','research-hub.php','psilocybin-studies/','psilocybin-papers/'],true))$dependencies[]='src/ResearchExplorerPage.php';
            foreach($dependencies as $dependency){$text=is_file($root.'/'.$dependency)?(string)file_get_contents($root.'/'.$dependency):'';$source.=preg_replace('/2026\d{4}-[a-z0-9-]+-v\d+/','ASSET_VERSION',$text);}
            $entries[$base.$path]=['template'=>'editorial','hash'=>hash('sha256',$source)];
        }
        $titles=[];$gaps=[];$types=[];
        $rows=$pdo->query('SELECT id,title,authors,journal,publication_date,doi,pubmed_id,openalex_id,source_name,source_url,publication_status,study_type FROM publications WHERE hidden=0 AND false_positive=0 AND merged_into_id IS NULL ORDER BY id');
        foreach($rows as $paper){
            $registry=PublicationMetadata::venue($paper)['kind']==='registry';$template=$registry?'registry':'publication';
            $entries[$base.'publication.php?id='.(int)$paper['id']]=['template'=>$template,'hash'=>PublicationMetadata::fingerprint($paper)];
            $types[$template]=($types[$template]??0)+1;
            $titles[mb_strtolower(trim((string)$paper['title']))][]= (int)$paper['id'];
            $issues=PublicationMetadata::issues($paper);
            if(($paper['publication_date']??'')>gmdate('Y-m-d'))$issues[]='future_date_semantics_need_review';
            if($issues)$gaps[]=['id'=>(int)$paper['id'],'issues'=>$issues];
        }
        $duplicates=array_values(array_filter($titles,static fn($ids)=>count($ids)>1));ksort($entries);
        return ['generated_at_utc'=>gmdate('c'),'base_url'=>$base,'canonical_entries'=>$entries,'template_counts'=>array_merge(['editorial'=>count($entries)-array_sum($types)],$types),'metadata_signals'=>$gaps,'duplicate_title_groups'=>$duplicates,'interpretation'=>'Full allowlisted canonical inventory, not an indexed-page count or independent quality grade. Future publication dates and duplicate titles require source review; no records are automatically hidden or redirected. Private source text, credentials and volatile importer timestamps are excluded.'];
    }
    public static function changes(array $before,array $after): array
    {
        $changed=[];
        foreach($after as $url=>$entry)if(!isset($before[$url])||$before[$url]['hash']!==$entry['hash'])$changed[$url]='added_or_updated';
        foreach($before as $url=>$entry)if(!isset($after[$url]))$changed[$url]='deleted';
        return $changed;
    }
}
