<?php
declare(strict_types=1);
require_once __DIR__.'/src/AppRelease.php';
require_once __DIR__.'/src/FrontendAssets.php';
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Service-Worker-Allowed: /');
header('X-Content-Type-Options: nosniff');
$release=AppRelease::id();
$source=file_get_contents(__DIR__.'/assets/service-worker-runtime.js');
$source=preg_replace('/const CACHE_VERSION = "[^"]+";/', 'const CACHE_VERSION = "publication-tracker-pwa-release-'.$release.'";', $source,1);
$source=preg_replace('/const ASSET_VERSION = "[^"]+";/', 'const ASSET_VERSION = "'.FrontendAssets::VERSION.'&r='.$release.'";', $source,1);
echo $source;
