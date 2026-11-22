<?php
declare(strict_types=1);
final class SeoQueryWatch
{
    public const QUERIES=[
        'psilocybin research'=>'psilocybin-research-guide/',
        'psilocybin research papers'=>'psilocybin-papers/',
        'free psilocybin research database'=>'',
        'free psilocybin research api'=>'research-api.php',
        'psilocybin dataset'=>'research-dataset.php',
        'psilocybin csv'=>'research-api.php',
        'psilocybin clinical trials'=>'trials.php',
        'recruiting psilocybin clinical trials'=>'recruiting-psilocybin-clinical-trials/',
        'psilocybin depression research'=>'psilocybin-depression-research/',
        'psilocybin alcohol research'=>'psilocybin-alcohol-use-disorder-research/',

        'psilocybin studies'=>'psilocybin-studies/','psilocybin study'=>'psilocybin-studies/','studies on psilocybin'=>'psilocybin-studies/',
        'psilocybin paper'=>'psilocybin-papers/','psilocybin papers'=>'psilocybin-papers/','psilocybin research articles'=>'psilocybin-papers/',
        'psilocybin research database'=>'','psilocin studies'=>'',
        'psilocybin anxiety research'=>'psilocybin-cancer-anxiety-research/','psilocybin cancer research'=>'psilocybin-cancer-anxiety-research/',
        'psilocybin smoking cessation'=>'psilocybin-smoking-cessation-research/','psilocybin research api'=>'research-api.php',
    ];
    public static function build(?array $performance, DateTimeImmutable $now): array
    {
        $targets=['site'=>Config::publicBaseUrl(),'pages'=>[],'query_clusters'=>[]];
        $review=SeoReview::build($targets,null,$performance,[],$now);
        $valid=$review['performance_observation_available'];$rows=[];
        foreach(self::QUERIES as $query=>$path){$observation=$valid?($performance['current']['tracked_queries'][$query]??null):null;
            $rows[]=['query'=>$query,'intended_page'=>Config::publicBaseUrl().$path,'status'=>$observation?'observed in export':($valid?'not present in exported query rows':'owner-access performance required'),'metrics'=>$observation,'previous_metrics'=>$valid?($performance['previous']['tracked_queries'][$query]??null):null];
        }
        return ['generated_at_utc'=>$now->format(DateTimeInterface::ATOM),'measurement_available'=>$valid,'period'=>$valid?$performance['current']['period']:null,'queries'=>$rows,'limitations'=>'Absence from exported query rows is not proof of zero searches, zero impressions or non-indexing. Intended pages are editorial targets; separate query and page exports do not establish query-to-page attribution. Inspect canonical selection and Page indexing separately.'];
    }
    public static function markdown(array $report): string
    {
        $text="# Private priority-query watch\n\nGenerated: ".$report['generated_at_utc']."\n\n".$report['limitations']."\n\n| Query | Intended page | Observation | Clicks | Impressions | Position |\n|---|---|---|---:|---:|---:|\n";
        foreach($report['queries'] as $row){$m=$row['metrics'];$text.='| '.$row['query'].' | '.$row['intended_page'].' | '.$row['status'].' | '.($m['clicks']??'—').' | '.($m['impressions']??'—').' | '.($m['position']??'—')." |\n";}
        return $text;
    }
}
