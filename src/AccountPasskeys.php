<?php
declare(strict_types=1);

use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\CredentialRecord;

final class AccountPasskeys
{
    private $serializer;
    private CeremonyStepManagerFactory $factory;
    public function __construct(private AccountStore $store)
    {
        require_once Config::baseDir().'/vendor/autoload.php';
        $manager=new AttestationStatementSupportManager([new NoneAttestationStatementSupport()]);
        $this->serializer=(new WebauthnSerializerFactory($manager))->create();
        $this->factory=new CeremonyStepManagerFactory();$this->factory->setAllowedOrigins([AccountAuth::origin()],false);$this->factory->setAttestationStatementSupportManager($manager);
    }
    private function host(): string { return (string)parse_url(AccountAuth::origin(),PHP_URL_HOST); }
    private function arrayOptions(object $value): array { return json_decode($this->serializer->serialize($value,'json'),true,32,JSON_THROW_ON_ERROR); }
    public function registrationOptions(array $user): array
    {
        if(count($this->store->credentials($user['id']))>=10)throw new InvalidArgumentException('You can register up to 10 passkeys.');
        $descriptors=array_map(fn($row)=>new PublicKeyCredentialDescriptor('public-key',Config::base64UrlDecode($row['id'])),$this->store->credentials($user['id']));
        $options=new PublicKeyCredentialCreationOptions(new PublicKeyCredentialRpEntity('Psilocybin Research', $this->host()),new PublicKeyCredentialUserEntity($this->store->email($user),Config::base64UrlDecode($user['handle']),'Psilocybin Research account'),random_bytes(32),[new PublicKeyCredentialParameters('public-key',-7),new PublicKeyCredentialParameters('public-key',-257)],new AuthenticatorSelectionCriteria(null,'required','required'),'none',$descriptors,60000);
        $value=$this->arrayOptions($options);AccountAuth::challenge('register',$value,$user['id']);return $value;
    }
    public function register(array $user,array $credential,string $label): void
    {
        $challenge=AccountAuth::takeChallenge('register');if($challenge['account_id']!==$user['id'])throw new InvalidArgumentException('Account changed during registration.');
        AccountAuth::clientData($credential,'webauthn.create');
        $data=$this->serializer->deserialize(json_encode($credential,JSON_THROW_ON_ERROR),PublicKeyCredential::class,'json');
        if(!$data->response instanceof AuthenticatorAttestationResponse)throw new InvalidArgumentException('Invalid registration response.');
        $options=$this->serializer->deserialize(json_encode($challenge['options'],JSON_THROW_ON_ERROR),PublicKeyCredentialCreationOptions::class,'json');
        $record=(new AuthenticatorAttestationResponseValidator($this->factory->creationCeremony()))->check($data->response,$options,$this->host());
        if(!hash_equals($data->rawId,$record->publicKeyCredentialId)||!hash_equals(Config::base64UrlDecode($user['handle']),$record->userHandle))throw new InvalidArgumentException('Invalid credential identity.');
        $q=$this->store->pdo()->prepare('INSERT INTO account_credentials(id,account_id,record_cipher,label,created_at) VALUES(?,?,?,?,?)');
        $q->execute([Config::base64UrlEncode($record->publicKeyCredentialId),$user['id'],SensitiveData::encrypt($this->serializer->serialize($record,'json')),mb_substr(trim($label)?:'Passkey',0,60),time()]);$this->store->audit($user['id'],'passkey_added');
    }
    public function loginOptions(): array
    {
        $options=new PublicKeyCredentialRequestOptions(random_bytes(32),$this->host(),[],'required',60000);$value=$this->arrayOptions($options);AccountAuth::challenge('login',$value);return $value;
    }
    public function login(array $credential): array
    {
        $challenge=AccountAuth::takeChallenge('login');AccountAuth::clientData($credential,'webauthn.get');
        $data=$this->serializer->deserialize(json_encode($credential,JSON_THROW_ON_ERROR),PublicKeyCredential::class,'json');
        if(!$data->response instanceof AuthenticatorAssertionResponse)throw new InvalidArgumentException('Invalid sign-in response.');
        $id=Config::base64UrlEncode($data->rawId);$saved=$this->store->credential($id);if(!$saved)throw new InvalidArgumentException('This passkey is not registered. Use an email link to sign in.');
        $account=$this->store->byId((int)$saved['account_id']);if(!$account||!$account['verified_at'])throw new InvalidArgumentException('Account unavailable.');
        $record=$this->serializer->deserialize((string)SensitiveData::decrypt($saved['record_cipher']),CredentialRecord::class,'json');
        $options=$this->serializer->deserialize(json_encode($challenge['options'],JSON_THROW_ON_ERROR),PublicKeyCredentialRequestOptions::class,'json');
        $record=(new AuthenticatorAssertionResponseValidator($this->factory->requestCeremony()))->check($record,$data->response,$options,$this->host(),$data->response->userHandle);
        if(!hash_equals(Config::base64UrlDecode($account['handle']),$record->userHandle))throw new InvalidArgumentException('Credential account mismatch.');
        $q=$this->store->pdo()->prepare('UPDATE account_credentials SET record_cipher=?,last_used_at=? WHERE id=? AND record_cipher=?');
        $q->execute([SensitiveData::encrypt($this->serializer->serialize($record,'json')),time(),$id,$saved['record_cipher']]);
        if($q->rowCount()!==1)throw new InvalidArgumentException('Another sign-in completed. Please try again.');
        AccountAuth::login($this->store,$account,'passkey');
        if ($account['role']==='admin' && count($this->store->credentials((int)$account['id']))>=2) $this->store->pdo()->exec("INSERT OR REPLACE INTO account_settings(name,value) VALUES('legacy_admin_disabled','1')");
        return $account;
    }
}
