<?php
declare(strict_types=1);

final class AccountLinks
{
    public function __construct(private AccountStore $store) {}
    public function request(string $email): void
    {
        AccountAuth::start();$email=SensitiveData::canonicalEmail($email);
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>254)throw new InvalidArgumentException('Enter a valid email address.');
        $this->store->prune();$token=Config::base64UrlEncode(random_bytes(32));$now=time();
        $q=$this->store->pdo()->prepare('INSERT INTO account_links(token_hash,email_cipher,email_index,browser_hash,created_at,expires_at) VALUES(?,?,?,?,?,?)');
        $q->execute([hash('sha256',$token),SensitiveData::encrypt($email),AccountStore::emailIndex($email),hash('sha256',$_SESSION['link_browser']),$now,$now+900]);
        $url=AccountAuth::origin().'/account.php#link='.$token;
        $message=AccountEmail::signIn($email,$url);
        if(!@mail($message['to'],$message['subject'],$message['body'],implode("\r\n",$message['headers']))) {
            $this->store->pdo()->prepare('DELETE FROM account_links WHERE token_hash=?')->execute([hash('sha256',$token)]);
            OperationalLogger::warning('account.mail_failed',['recipient_hash'=>AccountStore::emailIndex($email)]);
            throw new RuntimeException('The sign-in email could not be sent. Please try again later.');
        }
    }
    /** Preview never consumes the link or signs a browser in. */
    public function preview(string $token): array
    {
        AccountAuth::start();
        if(!preg_match('/^[A-Za-z0-9_-]{43}$/D',$token))throw new InvalidArgumentException('This sign-in link is invalid or has expired. Request a new link.');
        $q=$this->store->pdo()->prepare('SELECT email_cipher FROM account_links WHERE token_hash=? AND expires_at>=?');$q->execute([hash('sha256',$token),time()]);$row=$q->fetch();
        if(!$row)throw new InvalidArgumentException('This sign-in link is invalid or has expired. Request a new link.');
        $_SESSION['account_link_preview']=['hash'=>hash('sha256',$token),'expires'=>time()+300];
        return ['email'=>(string)SensitiveData::decrypt($row['email_cipher'])];
    }
    public function consume(string $token): array
    {
        AccountAuth::start();if(!preg_match('/^[A-Za-z0-9_-]{43}$/D',$token))throw new InvalidArgumentException('This sign-in link is invalid or has expired.');
        $preview=$_SESSION['account_link_preview']??null;
        if(!is_array($preview)||($preview['expires']??0)<time()||!hash_equals((string)($preview['hash']??''),hash('sha256',$token)))throw new InvalidArgumentException('Review the account shown before continuing. Reopen the email link if needed.');
        $pdo=$this->store->pdo();$pdo->exec('BEGIN IMMEDIATE');
        try {
            $q=$pdo->prepare('SELECT * FROM account_links WHERE token_hash=? AND expires_at>=?');$q->execute([hash('sha256',$token),time()]);$row=$q->fetch();
            if(!$row)throw new InvalidArgumentException('This sign-in link is invalid or has expired. Request a new link.');
            $pdo->prepare('DELETE FROM account_links WHERE token_hash=?')->execute([$row['token_hash']]);
            $email=(string)SensitiveData::decrypt($row['email_cipher']);$account=$this->store->byEmail($email)??$this->store->create($email);
            if(!$account['verified_at']){$pdo->prepare('UPDATE accounts SET verified_at=? WHERE id=?')->execute([time(),$account['id']]);$account=$this->store->byId((int)$account['id']);}
            $pdo->exec('COMMIT');
        } catch(Throwable $e){$pdo->exec('ROLLBACK');throw $e;}
        unset($_SESSION['account_link_preview']);
        if($account['role']==='admin') { $_SESSION['account_pending_admin']=['id'=>(int)$account['id'],'expires'=>time()+300];return ['adminRecovery'=>true]; }
        AccountAuth::login($this->store,$account,'email');return ['signedIn'=>true];
    }
    public function recoverAdmin(string $code): void
    {
        AccountAuth::start();$pending=$_SESSION['account_pending_admin']??null;
        if(!is_array($pending)||$pending['expires']<time())throw new InvalidArgumentException('Request a new email link before using a recovery code.');
        if(!$this->store->consumeRecovery($pending['id'],$code))throw new InvalidArgumentException('Recovery code not recognised.');
        unset($_SESSION['account_pending_admin']);$account=$this->store->byId($pending['id']);
        // Revoke earlier sessions before granting recovered administrative access.
        $this->store->pdo()->prepare('DELETE FROM account_sessions WHERE account_id=?')->execute([$account['id']]);
        AccountAuth::login($this->store,$account,'recovery');$this->store->audit($account['id'],'admin_recovered');
    }
}
