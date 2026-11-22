<?php
declare(strict_types=1);
/** Aggregate existing server log requests without retaining identifiers or query text. */
final class ResearchUsage {
 public static function event(string $line):?array {
  if(!preg_match('~^\S+ \S+ \S+ \[([^]]+)\] "(GET|HEAD|POST) ([^ ]+) HTTP/[^\"]+" (\d{3}) \S+ "[^\"]*" "([^\"]*)"~',$line,$m))return null;
  if(InternalTraffic::addressExcluded((string)strtok($line,' '))||InternalTraffic::testAgent($m[5]))return null;
  $time=strtotime($m[1]);if($time===false||$m[2]!=='GET')return null;
  if(InternalTraffic::logRequestExcluded((string)strtok($line,' '),$time,$m[2],$m[3]))return null;
  $ua=$m[5];if(preg_match('/bot|crawler|spider|slurp|inspectiontool|headless|lighthouse|monitor|audit/i',$ua))return null;
  $url=parse_url($m[3]);$path=$url['path']??'/';parse_str($url['query']??'',$query);
  $metric=null;
  if($path==='/export.php')$metric='reference_export';
  elseif($path==='/database.php')$metric='sqlite_download';
  elseif($path==='/api.php'||str_starts_with($path,'/api/v1/'))$metric=($query['format']??'')==='csv'?'api_csv':'api_json';
  elseif(in_array($path,['/','/index.php','/psilocybin-papers/','/psilocybin-studies/'],true)&&is_string($query['q']??$query['search']??null)&&trim($query['q']??$query['search'])!=='')$metric='search_response';
  elseif(in_array($path,['/tools/research_csv.py','/tools/research_csv.R'],true))$metric='research_client_download';
  if(!$metric)return null;
  $status=(int)$m[4];return ['date'=>gmdate('Y-m-d',$time),'metric'=>$metric,'outcome'=>$status>=200&&$status<300?'successful_response':($status===429?'rate_limited':'other_response')];
 }
 public static function report(iterable $lines,DateTimeImmutable $now):array {
  $start=$now->modify('-27 days')->format('Y-m-d');$end=$now->format('Y-m-d');$daily=[];$totals=[];$coverage=[];
  foreach($lines as $line){if(preg_match('/\[([^]]+)\]/',$line,$match)&&($stamp=strtotime($match[1]))!==false){$date=gmdate('Y-m-d',$stamp);if($date>=$start&&$date<=$end)$coverage[$date]=true;}$e=self::event($line);if(!$e||$e['date']<$start||$e['date']>$end)continue;$key=$e['metric'].':'.$e['outcome'];$daily[$e['date']][$key]=($daily[$e['date']][$key]??0)+1;$totals[$key]=($totals[$key]??0)+1;}
  ksort($daily);ksort($totals);
  return ['generated_at_utc'=>$now->format(DateTimeInterface::ATOM),'window'=>['start'=>$start,'end'=>$end],'observed_dates'=>count($coverage),'metric_dates'=>count($daily),'covered_dates'=>array_keys($coverage),'totals'=>$totals,'daily'=>$daily,'interpretation'=>'Aggregate requests from available host logs, not unique people, completed downloads, clinical use, conversions or a funnel. Configured owner/internal networks and recognizable test browsers are excluded. Known crawler user-agents are excluded heuristically; residual automation and repeated requests remain. API pagination counts as multiple requests. No IP, user-agent, referrer, record ID, query text, reading list or user identifier is retained. Missing dates mean unavailable coverage, not zero. No additional browser tracking is installed.'];
 }
}
