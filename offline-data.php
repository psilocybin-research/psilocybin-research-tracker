<?php
declare(strict_types=1);
require_once __DIR__.'/src/helpers.php';
header('Content-Type: application/json; charset=utf-8');header('X-Robots-Tag: noindex, follow');header('Cache-Control: no-store');
if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');echo '{"error":"Method not allowed"}';exit;}
RequestWorkload::protect('offline',2);
try {
    $db=new Database();$db->initialize();$snapshot=OfflineSnapshot::build($db);header('ETag: "'.$snapshot['revision'].'"');
    if(($_GET['manifest']??'')==='1')unset($snapshot['records']);
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(Throwable $error){http_response_code(503);OperationalLogger::exception('offline.snapshot_failed',$error);echo '{"error":"Offline catalogue temporarily unavailable"}';}
