<?php
declare(strict_types=1);
/** Content-based release identity; no account, catalogue or downloaded data enter it. */
final class AppRelease
{
    private static ?string $id = null;
    public static function id(): string
    {
        if (self::$id !== null) return self::$id;
        $root = dirname(__DIR__);
        $files = [];
        foreach (['/*.php', '/src/*.php', '/assets/*.js', '/assets/*.mjs', '/templates/research/*.php', '/assets/*.css', '/assets/features/*.js', '/assets/features/*.css'] as $pattern) {
            foreach (glob($root . $pattern) ?: [] as $path) if (is_file($path)) $files[] = $path;
        }
        foreach (['sw.js', 'manifest.webmanifest'] as $name) if (is_file($root.'/'.$name)) $files[]=$root.'/'.$name;
        sort($files, SORT_STRING);
        // Invalidate immediately when file metadata changes, and rehash at least every 30s.
        $stats=[];foreach($files as $path){$s=stat($path);$stats[]=[$path,$s['size'],$s['mtime'],$s['ctime']];}
        $signature=hash('sha256',json_encode($stats,JSON_THROW_ON_ERROR));
        $dir=$root.'/data/app-release';
        if(!is_dir($dir))@mkdir($dir,0700,true);
        $cache=$dir.'/fingerprint.json';$read=static function()use($cache,$signature):?string{
            $v=json_decode((string)@file_get_contents($cache),true);
            return is_array($v)&&($v['signature']??'')===$signature&&($v['checked']??0)>time()-30&&preg_match('/^[a-f0-9]{24}$/D',(string)($v['id']??''))?$v['id']:null;
        };
        if($hit=$read())return self::$id=$hit;
        $lock=@fopen($dir.'/fingerprint.lock','c');if($lock)flock($lock,LOCK_EX);
        try{
            if($hit=$read())return self::$id=$hit;
            $context=hash_init('sha256');foreach($files as $path){hash_update($context,substr($path,strlen($root))."\0");hash_update_file($context,$path);}
            $id=substr(hash_final($context),0,24);
            $tmp=@tempnam($dir,'release-');if($tmp!==false){file_put_contents($tmp,json_encode(['signature'=>$signature,'id'=>$id,'checked'=>time()],JSON_THROW_ON_ERROR));chmod($tmp,0600);rename($tmp,$cache);}
            return self::$id=$id;
        }finally{if($lock){flock($lock,LOCK_UN);fclose($lock);}}
    }
}
