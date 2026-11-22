<?php
declare(strict_types=1);

final class AccountAuth
{
    public static function origin(): string { return rtrim(Config::publicBaseUrl(),'/'); }
    public static function cookieName(): string { return str_starts_with(self::origin(),'https://')?'__Host-tracker-account':'tracker_account_dev'; }
    public static function start(): void
    {
        if(session_status()===PHP_SESSION_ACTIVE)return;
        $dir=Config::dataDir().'/accounts/sessions';if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Sessions unavailable.');
        ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');ini_set('session.use_trans_sid','0');ini_set('session.gc_maxlifetime','604800');
        session_save_path($dir);session_name(self::cookieName());
        session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>str_starts_with(self::origin(),'https://'),'httponly'=>true,'samesite'=>'Lax']);
        session_cache_limiter('nocache');if(!session_start())throw new RuntimeException('Sessions unavailable.');
        $_SESSION['account_csrf']??=bin2hex(random_bytes(32));$_SESSION['link_browser']??=bin2hex(random_bytes(32));
    }
    public static function csrf(): string { self::start();return $_SESSION['account_csrf']; }
    public static function requireCsrf(?string $value): void
    {
        self::start();if(!is_string($value)||!hash_equals((string)$_SESSION['account_csrf'],$value))throw new InvalidArgumentException('Your session changed. Reload the page and try again.');
    }
    public static function current(?AccountStore $store=null): ?array
    {
        if(session_status()!==PHP_SESSION_ACTIVE&&!isset($_COOKIE[self::cookieName()]))return null;
        self::start();$key=$_SESSION['account_session']??null;if(!is_string($key))return null;
        $store??=new AccountStore();$q=$store->pdo()->prepare('SELECT s.*,a.role,a.handle,a.email_cipher,a.verified_at FROM account_sessions s JOIN accounts a ON a.id=s.account_id WHERE s.id_hash=?');$q->execute([hash('sha256',$key)]);$row=$q->fetch();
        if(!$row || (int)$row['expires_at']<time() || (int)$row['last_seen_at']<time()-($row['role']==='admin'?1800:7200)) { unset($_SESSION['account_session']);return null; }
        if((int)$row['last_seen_at']<time()-60)$store->pdo()->prepare('UPDATE account_sessions SET last_seen_at=? WHERE id_hash=?')->execute([time(),$row['id_hash']]);
        $row['id']=(int)$row['account_id'];return $row;
    }
    public static function login(AccountStore $store,array $account,string $method): void
    {
        self::start();if($account['role']==='admin'&&!in_array($method,['passkey','recovery'],true))throw new RuntimeException('Admin verification required.');
        if(isset($_SESSION['account_session']))$store->pdo()->prepare('DELETE FROM account_sessions WHERE id_hash=?')->execute([hash('sha256',$_SESSION['account_session'])]);
        session_regenerate_id(true);$key=bin2hex(random_bytes(32));$now=time();
        $_SESSION['account_session']=$key;$_SESSION['account_csrf']=bin2hex(random_bytes(32));unset($_SESSION['account_challenge'],$_SESSION['account_pending_admin']);
        $ua=(string)($_SERVER['HTTP_USER_AGENT']??'Browser');$label=str_contains($ua,'Android')?'Android':(str_contains($ua,'iPhone')||str_contains($ua,'iPad')?'iPhone / iPad':(str_contains($ua,'Windows')?'Windows':(str_contains($ua,'Macintosh')?'Mac':'Browser')));
        $q=$store->pdo()->prepare('INSERT INTO account_sessions(id_hash,account_id,method,label,created_at,last_seen_at,expires_at) VALUES(?,?,?,?,?,?,?)');$q->execute([hash('sha256',$key),$account['id'],$method,$label,$now,$now,$now+($account['role']==='admin'?28800:604800)]);
        $store->pdo()->prepare('UPDATE accounts SET last_login_at=? WHERE id=?')->execute([$now,$account['id']]);$store->audit((int)$account['id'],'signed_in_'.$method);
    }
    public static function logout(AccountStore $store): void
    {
        self::start();if(isset($_SESSION['account_session']))$store->pdo()->prepare('DELETE FROM account_sessions WHERE id_hash=?')->execute([hash('sha256',$_SESSION['account_session'])]);
        $_SESSION=[];session_regenerate_id(true);$_SESSION['account_csrf']=bin2hex(random_bytes(32));$_SESSION['link_browser']=bin2hex(random_bytes(32));
    }
    public static function requireUser(AccountStore $store,bool $recent=false): array
    {
        $user=self::current($store);if(!$user)throw new DomainException('Sign in to continue.');
        if($recent&&(int)$user['created_at']<time()-600)throw new DomainException('Please sign in again before changing account security.');return $user;
    }
    public static function legacyAllowed(): bool
    {
        if (!is_file(Config::dataDir().'/accounts/accounts.sqlite')) return true;
        $store=new AccountStore();
        return $store->pdo()->query("SELECT value FROM account_settings WHERE name='legacy_admin_disabled'")->fetchColumn() !== '1';
    }
    public static function isAdmin(): bool { $user=self::current();return $user && $user['role']==='admin' && in_array($user['method'],['passkey','recovery'],true); }
    public static function challenge(string $kind,array $options,?int $accountId=null): void { self::start();$_SESSION['account_challenge']=['kind'=>$kind,'options'=>$options,'account_id'=>$accountId,'expires'=>time()+300]; }
    public static function takeChallenge(string $kind): array
    {
        self::start();$value=$_SESSION['account_challenge']??null;unset($_SESSION['account_challenge']);
        if(!is_array($value)||$value['kind']!==$kind||$value['expires']<time())throw new InvalidArgumentException('This passkey request expired. Please try again.');return $value;
    }
    public static function clientData(array $credential,string $kind): void
    {
        $encoded=$credential['response']['clientDataJSON']??null;if(!is_string($encoded)||strlen($encoded)>24000)throw new InvalidArgumentException('Invalid passkey response.');
        $raw=Config::base64UrlDecode($encoded);$data=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($data)||($data['type']??null)!==$kind||($data['origin']??null)!==self::origin()||($data['crossOrigin']??false)!==false||isset($data['topOrigin']))throw new InvalidArgumentException('The passkey response belongs to another website or browser context.');
    }
}
