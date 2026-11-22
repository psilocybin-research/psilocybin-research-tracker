<?php
declare(strict_types=1);

/** Private identity store, deliberately separate from downloadable research data. */
final class AccountStore
{
    private PDO $pdo;
    public function __construct(?PDO $pdo = null)
    {
        $dir = Config::dataDir() . '/accounts';
        if (!$pdo && !is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Account storage unavailable.');
        $this->pdo = $pdo ?? new PDO('sqlite:' . $dir . '/accounts.sqlite');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL;');
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS accounts(id INTEGER PRIMARY KEY AUTOINCREMENT, handle TEXT NOT NULL UNIQUE, email_cipher TEXT NOT NULL, email_index TEXT NOT NULL UNIQUE, role TEXT NOT NULL DEFAULT 'user' CHECK(role IN ('user','admin')), created_at INTEGER NOT NULL, verified_at INTEGER, last_login_at INTEGER);
CREATE UNIQUE INDEX IF NOT EXISTS accounts_single_admin ON accounts(role) WHERE role='admin';
CREATE TABLE IF NOT EXISTS account_credentials(id TEXT PRIMARY KEY, account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE, record_cipher TEXT NOT NULL, label TEXT NOT NULL, created_at INTEGER NOT NULL, last_used_at INTEGER);
CREATE INDEX IF NOT EXISTS account_credentials_owner ON account_credentials(account_id);
CREATE TABLE IF NOT EXISTS account_sessions(id_hash TEXT PRIMARY KEY, account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE, method TEXT NOT NULL, label TEXT NOT NULL, created_at INTEGER NOT NULL, last_seen_at INTEGER NOT NULL, expires_at INTEGER NOT NULL);
CREATE INDEX IF NOT EXISTS account_sessions_owner ON account_sessions(account_id);
CREATE TABLE IF NOT EXISTS account_links(token_hash TEXT PRIMARY KEY, email_cipher TEXT NOT NULL, email_index TEXT NOT NULL, browser_hash TEXT NOT NULL, created_at INTEGER NOT NULL, expires_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS account_recovery(code_hash TEXT PRIMARY KEY, account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE, created_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS account_workspaces(account_id INTEGER PRIMARY KEY REFERENCES accounts(id) ON DELETE CASCADE, value_cipher TEXT NOT NULL, revision INTEGER NOT NULL DEFAULT 0, updated_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS account_settings(name TEXT PRIMARY KEY,value TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS account_audit(id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER REFERENCES accounts(id) ON DELETE CASCADE, event TEXT NOT NULL, created_at INTEGER NOT NULL);
SQL);
        if (!$pdo) @chmod($dir . '/accounts.sqlite', 0600);
    }
    public function pdo(): PDO { return $this->pdo; }
    public function byId(int $id): ?array { $q=$this->pdo->prepare('SELECT * FROM accounts WHERE id=?');$q->execute([$id]);return $q->fetch() ?: null; }
    public function byEmail(string $email): ?array { $q=$this->pdo->prepare('SELECT * FROM accounts WHERE email_index=?');$q->execute([self::emailIndex($email)]);return $q->fetch() ?: null; }
    public static function emailIndex(string $email): string { return SensitiveData::blindIndex('account-email', SensitiveData::canonicalEmail($email)); }
    public function email(array $account): string { return (string)SensitiveData::decrypt($account['email_cipher']); }
    public function create(string $email, string $role='user', bool $verified=true): array
    {
        $email=SensitiveData::canonicalEmail($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email)>254 || !in_array($role,['user','admin'],true)) throw new InvalidArgumentException('Enter a valid email address.');
        $q=$this->pdo->prepare('INSERT INTO accounts(handle,email_cipher,email_index,role,created_at,verified_at) VALUES(?,?,?,?,?,?)');
        $q->execute([Config::base64UrlEncode(random_bytes(32)),SensitiveData::encrypt($email),self::emailIndex($email),$role,time(),$verified?time():null]);
        return $this->byId((int)$this->pdo->lastInsertId());
    }
    public function audit(int $id,string $event): void { $q=$this->pdo->prepare('INSERT INTO account_audit(account_id,event,created_at) VALUES(?,?,?)');$q->execute([$id,$event,time()]); }
    public function credentials(int $id): array { $q=$this->pdo->prepare('SELECT id,label,created_at,last_used_at FROM account_credentials WHERE account_id=? ORDER BY created_at');$q->execute([$id]);return $q->fetchAll(); }
    public function credential(string $id): ?array { $q=$this->pdo->prepare('SELECT * FROM account_credentials WHERE id=?');$q->execute([$id]);return $q->fetch() ?: null; }
    public function recoveryCodes(int $id): array
    {
        $codes=[];$this->pdo->prepare('DELETE FROM account_recovery WHERE account_id=?')->execute([$id]);
        $q=$this->pdo->prepare('INSERT INTO account_recovery(code_hash,account_id,created_at) VALUES(?,?,?)');
        for($i=0;$i<8;$i++) { $code=strtoupper(bin2hex(random_bytes(12)));$codes[]=implode('-',str_split($code,6));$q->execute([self::recoveryHash($code),$id,time()]); }
        return $codes;
    }
    public static function recoveryHash(string $code): string { return SensitiveData::blindIndex('account-recovery', strtoupper(str_replace(['-',' '],'',trim($code)))); }
    public function consumeRecovery(int $id,string $code): bool { $q=$this->pdo->prepare('DELETE FROM account_recovery WHERE account_id=? AND code_hash=?');$q->execute([$id,self::recoveryHash($code)]);return $q->rowCount()===1; }
    public function workspace(int $id): array
    {
        $q=$this->pdo->prepare('SELECT * FROM account_workspaces WHERE account_id=?');$q->execute([$id]);$row=$q->fetch();
        return ['workspace'=>$row ? self::cleanWorkspace(json_decode((string)SensitiveData::decrypt($row['value_cipher']),true,32,JSON_THROW_ON_ERROR)) : self::cleanWorkspace([]),'revision'=>(int)($row['revision']??0)];
    }
    public static function cleanWorkspace(array $data): array
    {
        $ids=[];foreach((array)($data['ids']??[]) as $id)if((is_int($id)||ctype_digit((string)$id))&&(int)$id>0&&count($ids)<50)$ids[(int)$id]=(int)$id;
        $searches=[];$paths=['/','/index.php','/research-hub.php','/research-questions.php','/research-network.php','/trial-pipeline.php','/research-countries.php','/research-results.php','/research-updates.php','/research-library.php','/germany-psilocybin-research.php','/research-economics.php','/citation-network.php','/trials-map.php','/trials.php'];
        foreach((array)($data['searches']??[]) as $s) {
            if(!is_array($s)||!is_string($s['url']??null)||!is_string($s['title']??null))continue;
            $url=$s['url'];$path=parse_url($url,PHP_URL_PATH);
            if(strlen($url)>2000||!str_starts_with($url,'/')||str_starts_with($url,'//')||preg_match('/[\\\\\x00-\x20]/',$url)||!in_array($path,$paths,true))continue;
            $searches[$url]=['title'=>mb_substr($s['title'],0,150),'url'=>$url];if(count($searches)>=30)break;
        }
        return ['version'=>1,'ids'=>array_values($ids),'searches'=>array_values($searches)];
    }
    public function changeWorkspace(int $id,array $changes): array
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $current=$this->workspace($id);$v=$current['workspace'];
            $remove=array_map('intval',array_slice((array)($changes['removeIds']??[]),0,50));
            $v['ids']=array_values(array_diff($v['ids'],$remove));
            foreach((array)($changes['addIds']??[]) as $value)if(is_int($value)&&$value>0&&!in_array($value,$v['ids'],true))$v['ids'][]=$value;
            if(count($v['ids'])>50)throw new InvalidArgumentException('Your reading list is limited to 50 publications.');
            $removeSearches=array_slice((array)($changes['removeSearches']??[]),0,30);$map=[];
            foreach($v['searches'] as $s)if(!in_array($s['url'],$removeSearches,true))$map[$s['url']]=$s;
            foreach(self::cleanWorkspace(['searches'=>$changes['addSearches']??[]])['searches'] as $s)$map[$s['url']]=$s;
            if(count($map)>30)throw new InvalidArgumentException('Keep up to 30 saved searches.');
            $v=self::cleanWorkspace(['ids'=>$v['ids'],'searches'=>array_values($map)]);$revision=$current['revision']+1;
            $q=$this->pdo->prepare('INSERT INTO account_workspaces(account_id,value_cipher,revision,updated_at) VALUES(?,?,?,?) ON CONFLICT(account_id) DO UPDATE SET value_cipher=excluded.value_cipher,revision=excluded.revision,updated_at=excluded.updated_at');
            $q->execute([$id,SensitiveData::encrypt(json_encode($v,JSON_THROW_ON_ERROR)), $revision,time()]);$this->pdo->exec('COMMIT');
            return ['workspace'=>$v,'revision'=>$revision];
        } catch(Throwable $e) { $this->pdo->exec('ROLLBACK');throw $e; }
    }
    public function prune(): void
    {
        $now=time();$this->pdo->prepare('DELETE FROM account_links WHERE expires_at<?')->execute([$now]);
        $this->pdo->prepare('DELETE FROM account_sessions WHERE expires_at<? OR last_seen_at<?')->execute([$now,$now-7200]);
        $this->pdo->prepare('DELETE FROM account_audit WHERE created_at<?')->execute([$now-90*86400]);
        foreach (glob(Config::dataDir().'/accounts/sessions/sess_*') ?: [] as $file) {
            if (!is_link($file) && is_file($file) && filemtime($file)<$now-7*86400) @unlink($file);
        }
    }
}
