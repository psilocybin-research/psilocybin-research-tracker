<?php
declare(strict_types=1);

/** Conservative grouping from source-associated identifiers; never guess aliases from initials. */
final class ResearcherIdentity
{
    public static function orcid(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('~^(?:https://orcid.org/)?(\d{4}-\d{4}-\d{4}-\d{3}[\dX])$~i',trim($value),$m)) return null;
        $id=strtoupper($m[1]);$digits=str_replace('-','',$id);$total=0;
        for($i=0;$i<15;$i++)$total=($total+(int)$digits[$i])*2;
        $check=(12-$total%11)%11;
        return $digits[15]===($check===10?'X':(string)$check)?'https://orcid.org/'.$id:null;
    }
    public static function openAlex(mixed $value): ?string
    {
        return is_string($value)&&preg_match('~^https://openalex\.org/A[1-9]\d*$~D',trim($value))?trim($value):null;
    }
    public static function metadata(Database $db): array
    {
        $out=[];$bridges=[];
        $rows=$db->pdo()->query("SELECT a.publication_id,a.author_name,a.orcid,a.openalex_id FROM publication_authors a JOIN publications p ON p.id=a.publication_id WHERE p.hidden=0 AND p.false_positive=0 AND p.publication_status!='clinical trial'");
        foreach($rows as $row){$o=self::orcid($row['orcid']);$a=self::openAlex($row['openalex_id']);if(!$o&&!$a)continue;
            $out[(int)$row['publication_id']][mb_strtolower(trim($row['author_name']))]=[$o,$a];
            if($o&&$a)$bridges[$a][$o]=true;
        }
        $unique=[];foreach($bridges as $a=>$ids)if(count($ids)===1)$unique[$a]=array_key_first($ids);
        return [$out,$unique];
    }
    public static function label(string $name,int $paperId,array $metadata,array $bridges): array
    {
        [$orcid,$alex]=$metadata[$paperId][mb_strtolower(trim($name))]??[null,null];
        if(!$orcid&&$alex&&isset($bridges[$alex]))$orcid=$bridges[$alex];
        $uri=$orcid??$alex;
        return [$uri??LinkedData::person($name)['@id'],$uri,$orcid?'source-associated ORCID':($alex?'source-associated OpenAlex':'name only')];
    }
}
