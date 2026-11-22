<?php
declare(strict_types=1);

/** Encrypted user analyses and immutable rights-safe public dataset snapshots. */
final class AccountAnalyses
{
    private PDO $pdo;
    public function __construct(AccountStore $store)
    {
        $this->pdo=$store->pdo();
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS account_analysis_snapshots(hash TEXT PRIMARY KEY,metadata TEXT NOT NULL,csv_gzip BLOB NOT NULL,created_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS account_analyses(id TEXT PRIMARY KEY,account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,snapshot_hash TEXT NOT NULL REFERENCES account_analysis_snapshots(hash),value_cipher TEXT NOT NULL,revision INTEGER NOT NULL DEFAULT 1,created_at INTEGER NOT NULL,updated_at INTEGER NOT NULL);
CREATE INDEX IF NOT EXISTS account_analyses_owner ON account_analyses(account_id);");
    }
    public function snapshot(?string $hash=null): array
    {
        if($hash!==null){$q=$this->pdo->prepare('SELECT * FROM account_analysis_snapshots WHERE hash=?');$q->execute([$hash]);$row=$q->fetch();if(!$row)throw new InvalidArgumentException('Dataset snapshot not found.');return ['manifest'=>json_decode($row['metadata'],true,32,JSON_THROW_ON_ERROR),'csv'=>gzdecode($row['csv_gzip'])];}
        $db=new Database();$db->initialize();$repo=new PublicationRepository($db);
        $papers=$repo->allForExport(['substances'=>['psilocybin','psilocin'],'sort'=>'oldest']);
        usort($papers,static fn($a,$b)=>(int)$a['id']<=>(int)$b['id']);
        $csv=ExportService::csv($papers);if(strlen($csv)>30000000)throw new InvalidArgumentException('This dataset is too large for the browser workspace.');
        $hash=hash('sha256',$csv);$q=$this->pdo->prepare('SELECT metadata FROM account_analysis_snapshots WHERE hash=?');$q->execute([$hash]);$existing=$q->fetchColumn();
        $manifest=$existing?json_decode($existing,true,32,JSON_THROW_ON_ERROR):['format'=>'psilocybin-analysis-snapshot-v1','sha256'=>$hash,'record_count'=>count($papers),'bytes'=>strlen($csv),'generated_at_utc'=>current_utc(),'source'=>rtrim(Config::publicBaseUrl(),'/').'/research-dataset.php','schema'=>'rights-safe-core-v1','scope'=>'All visible public psilocybin/psilocin records; hidden and false-positive records excluded.','limitations'=>'Records are not participants or independent studies. Registry entries, preprints and articles can describe the same study. Topics and study types are derived labels; verify original sources. Missing dates are excluded only where stated. This is a living-index snapshot, not a Zenodo release.'];
        $q=$this->pdo->prepare('INSERT OR IGNORE INTO account_analysis_snapshots(hash,metadata,csv_gzip,created_at) VALUES(?,?,?,?)');$q->execute([$hash,json_encode($manifest,JSON_THROW_ON_ERROR),gzencode($csv,6),time()]);
        // Only unreferenced public snapshots expire; saved analyses keep theirs.
        $this->pdo->prepare('DELETE FROM account_analysis_snapshots WHERE created_at<? AND hash NOT IN (SELECT snapshot_hash FROM account_analyses)')->execute([time()-7*86400]);
        return ['manifest'=>$manifest,'csv'=>$csv];
    }
    public function list(int $owner,bool $full=false): array
    {
        $q=$this->pdo->prepare('SELECT * FROM account_analyses WHERE account_id=? ORDER BY updated_at DESC');$q->execute([$owner]);$items=[];
        foreach($q->fetchAll() as $row){$value=json_decode((string)SensitiveData::decrypt($row['value_cipher']),true,32,JSON_THROW_ON_ERROR);$item=['id'=>$row['id'],'title'=>$value['title'],'revision'=>(int)$row['revision'],'updated_at'=>(int)$row['updated_at'],'snapshot_hash'=>$row['snapshot_hash']];$items[]=$full?$item+['analysis'=>$value]:$item;}
        return $items;
    }
    public function get(int $owner,string $id): array
    {
        $q=$this->pdo->prepare('SELECT * FROM account_analyses WHERE id=? AND account_id=?');$q->execute([$id,$owner]);$row=$q->fetch();if(!$row)throw new InvalidArgumentException('Saved analysis not found.');
        return ['id'=>$row['id'],'revision'=>(int)$row['revision'],'updated_at'=>(int)$row['updated_at'],'analysis'=>json_decode((string)SensitiveData::decrypt($row['value_cipher']),true,32,JSON_THROW_ON_ERROR)];
    }
    public function normalise(array $value): array
    {
        $title=trim((string)($value['title']??''));$code=(string)($value['code']??'');
        if($title===''||mb_strlen($title)>120||strlen($code)>65536||$code==='')throw new InvalidArgumentException('Use a title up to 120 characters and R code up to 64 KB.');
        $hash=(string)($value['snapshot']['sha256']??'');if(!preg_match('/^[a-f0-9]{64}$/D',$hash))throw new InvalidArgumentException('Load a dataset before saving.');
        $snapshot=$this->snapshot($hash)['manifest'];$output=(string)($value['output']??'');$csv=(string)($value['results_csv']??'');$session=(string)($value['session_info']??'');
        if(strlen($output)>200000||strlen($csv)>300000||strlen($session)>30000)throw new InvalidArgumentException('Results are too large to save. Download them instead.');
        $plots=[];foreach((array)($value['plots']??[]) as $png){if(count($plots)>=4||!is_string($png)||!str_starts_with($png,'data:image/png;base64,'))throw new InvalidArgumentException('Save up to four PNG plots.');$bytes=base64_decode(substr($png,22),true);$size=$bytes===false?false:@getimagesizefromstring($bytes);if(!$size||$size[2]!==IMAGETYPE_PNG||strlen($bytes)>500000||$size[0]>2400||$size[1]>2400)throw new InvalidArgumentException('Plot exceeds the supported PNG dimensions or size.');$plots[]=$png;}
        $value=['title'=>$title,'code'=>$code,'output'=>$output,'plots'=>$plots,'results_csv'=>$csv,'session_info'=>$session,'snapshot'=>$snapshot,'runtime'=>['webR'=>'0.6.0'],'code_sha256'=>hash('sha256',$code),'execution'=>'Browser-side user code; saved outputs are not independently verified.','seed'=>20261108,'run_at_utc'=>mb_substr((string)($value['run_at_utc']??''),0,40)];
        $json=json_encode($value,JSON_THROW_ON_ERROR);if(strlen($json)>1500000)throw new InvalidArgumentException('This analysis is too large to save. Download the results instead.');
        return $value;
    }
    public function save(int $owner,array $input): array
    {
        $value=$this->normalise((array)($input['analysis']??[]));$hash=$value['snapshot']['sha256'];$json=json_encode($value,JSON_THROW_ON_ERROR);
        $id=(string)($input['id']??'');$revision=(int)($input['revision']??0);$this->pdo->exec('BEGIN IMMEDIATE');
        try {
            if($id!==''){$current=$this->get($owner,$id);if($revision!==$current['revision'])throw new InvalidArgumentException('This analysis changed on another device. Reopen it or save a new copy.');}
            $q=$this->pdo->prepare('SELECT COUNT(*) n,COALESCE(SUM(LENGTH(value_cipher)),0) bytes FROM account_analyses WHERE account_id=? AND id!=?');$q->execute([$owner,$id]);$quota=$q->fetch();if($quota['n']>=20||$quota['bytes']+strlen($json)*1.5>20000000)throw new InvalidArgumentException('Your saved analyses have reached their limit (20 analyses / 20 MB). Download or delete an older analysis.');
            if($id==='')$id=bin2hex(random_bytes(16));
            $q=$this->pdo->prepare('INSERT INTO account_analyses(id,account_id,snapshot_hash,value_cipher,revision,created_at,updated_at) VALUES(?,?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET snapshot_hash=excluded.snapshot_hash,value_cipher=excluded.value_cipher,revision=excluded.revision,updated_at=excluded.updated_at WHERE account_analyses.account_id=excluded.account_id');
            $q->execute([$id,$owner,$hash,SensitiveData::encrypt($json),$revision+1,time(),time()]);$this->pdo->exec('COMMIT');return $this->get($owner,$id);
        }catch(Throwable $e){$this->pdo->exec('ROLLBACK');throw $e;}
    }
    public function delete(int $owner,string $id,int $revision): void
    {
        $q=$this->pdo->prepare('DELETE FROM account_analyses WHERE account_id=? AND id=? AND revision=?');$q->execute([$owner,$id,$revision]);if(!$q->rowCount())throw new InvalidArgumentException('The analysis changed or was already removed. Refresh the saved list.');
    }
    public function bundle(int $owner,string $id): string
    {
        $saved=$this->get($owner,$id);$a=$saved['analysis'];$files=['user-code.R'=>$a['code'], 'analysis.R'=>"# Run from the extracted bundle directory.\nrecords <- read.csv('records.csv', check.names=FALSE, na.strings=c('', 'NA'), stringsAsFactors=FALSE)\nset.seed(20261108)\n".$a['code']."\n",'records.csv'=>$this->snapshot($a['snapshot']['sha256'])['csv'],'output.txt'=>$a['output'],'sessionInfo.txt'=>$a['session_info'],'provenance.json'=>json_encode($a['snapshot']+['code_sha256'=>$a['code_sha256'],'runtime'=>$a['runtime'],'run_at_utc'=>$a['run_at_utc'],'execution'=>$a['execution']],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)];
        if($a['results_csv']!=='')$files['results.csv']=$a['results_csv'];foreach($a['plots'] as $i=>$png)$files['plot-'.($i+1).'.png']=base64_decode(substr($png,22));
        $files['README.txt']="Psilocybin Research analysis bundle\n\nUser-authored exploratory analysis. Outputs are not independently verified.\nThe exact public metadata snapshot, code, output and runtime information are included.\nInstall any additional packages used by the code before running analysis.R.\nRecords are not independent studies or participants. Verify original sources.\nDataset citation and rights: https://psilocybin-research.com/research-dataset.php\nSource-derived abstracts and private account data are excluded from records.csv.\n";
        $sums='';foreach($files as $name=>$contents)$sums.=hash('sha256',$contents).'  '.$name."\n";$files['SHA256SUMS']=$sums;
        $temp=tempnam(sys_get_temp_dir(),'analysis-');try{$zip=new ZipArchive();if($zip->open($temp,ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Download unavailable.');foreach($files as $name=>$contents)$zip->addFromString($name,$contents);$zip->close();return (string)file_get_contents($temp);}finally{@unlink($temp);}
    }
}
