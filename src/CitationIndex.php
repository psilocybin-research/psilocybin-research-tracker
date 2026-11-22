<?php
declare(strict_types=1);

/** Private, revision-matched DOI reference index; never indexes incidental mentions. */
final class CitationIndex
{
    private const VISIBLE = 'hidden=0 AND false_positive=0 AND merged_into_id IS NULL';

    public static function validDoi(?string $value): ?string
    {
        $doi = normalize_doi($value);
        return $doi !== null && strlen($doi) <= 2048 && preg_match('~^10\\.\\d{4,9}/[^\\s<>]+$~u', $doi) === 1 ? $doi : null;
    }

    private static function workId(mixed $value): ?string
    {
        return is_string($value) && preg_match('~^(?:https?://openalex\\.org/)?(W\\d+)$~i', trim($value), $match) ? 'https://openalex.org/' . strtoupper($match[1]) : null;
    }

    public static function directDois(array $paper): array
    {
        $raw = json_decode((string)($paper['raw_json'] ?? ''), true);
        if (!is_array($raw)) return [];
        $dois = [];
        foreach (['reference_dois','references'] as $key) {
            foreach ((array)($raw[$key] ?? []) as $item) {
                $value = is_array($item) ? ($item['doi'] ?? $item['DOI'] ?? null) : $item;
                $doi = self::validDoi(is_string($value) ? $value : null);
                if ($doi !== null && strlen($doi) <= 2048) $dois[$doi] = true;
            }
        }
        return array_keys($dois);
    }

    public static function workMap(Database $db): array
    {
        $map = [];
        foreach ($db->pdo()->query('SELECT openalex_id,doi FROM publications WHERE ' . self::VISIBLE) as $row) {
            $id = self::workId($row['openalex_id']);
            $doi = self::validDoi($row['doi']);
            if ($id === null || $doi === null) continue;
            if (array_key_exists($id,$map) && $map[$id] !== $doi) $map[$id] = null;
            else $map[$id] = $doi;
        }
        return $map;
    }

    public static function referenceDois(array $paper, array $workMap): array
    {
        $values = array_fill_keys(self::directDois($paper), true);
        $raw = json_decode((string)($paper['raw_json'] ?? ''), true);
        foreach ((array)($raw['referenced_works'] ?? []) as $work) {
            $id = self::workId($work);
            if ($id !== null && isset($workMap[$id])) $values[$workMap[$id]] = true;
        }
        return array_keys($values);
    }

    /** Unindexed fallback uses exactly the same structured-reference semantics. */
    public static function scanCitingIds(Database $db, string $doi): array
    {
        $map = self::workMap($db); $ids = [];
        foreach ($db->pdo()->query('SELECT id,raw_json FROM publications WHERE ' . self::VISIBLE) as $row) {
            if (in_array($doi,self::referenceDois($row,$map),true)) $ids[] = (int)$row['id'];
        }
        return $ids;
    }

    public static function citingIds(Database $db, string $doi): array
    {
        $doi = self::validDoi($doi);
        if ($doi === null) return [];
        $identity = self::identity($db);
        if ($identity !== null) {
            $path = self::path($identity);
            if (is_file($path)) {
                try {
                    $index = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, (defined('Pdo\\Sqlite::ATTR_OPEN_FLAGS') ? constant('Pdo\\Sqlite::ATTR_OPEN_FLAGS') : constant('PDO::SQLITE_ATTR_OPEN_FLAGS'))=>(defined('Pdo\\Sqlite::OPEN_READONLY') ? constant('Pdo\\Sqlite::OPEN_READONLY') : constant('PDO::SQLITE_OPEN_READONLY'))]);
                    if ($index->query("SELECT value FROM metadata WHERE name='source_key'")->fetchColumn() === self::key($db,$identity)) {
                        $statement = $index->prepare('SELECT citing_id FROM reference_edges WHERE doi=?');
                        $statement->execute([$doi]);
                        return array_map('intval',$statement->fetchAll(PDO::FETCH_COLUMN));
                    }
                } catch (Throwable) { /* Missing or invalid cache falls back to current source data. */ }
            }
        }
        return self::scanCitingIds($db,$doi);
    }

    public static function rebuild(Database $db): array
    {
        if (PHP_SAPI !== 'cli') throw new RuntimeException('Citation index rebuild is CLI only.');
        $identity = self::identity($db);
        if ($identity === null || $db->pdo()->inTransaction()) throw new RuntimeException('A persistent independent catalogue is required.');
        $path = self::path($identity);
        $lock = fopen($path . '.lock','c');
        if ($lock === false) throw new RuntimeException('Cannot lock citation index.');
        chmod($path . '.lock',0600);
        if (!flock($lock,LOCK_EX | LOCK_NB)) { fclose($lock); return ['skipped'=>true]; }
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
        try {
            $db->pdo()->beginTransaction();
            $revision = self::revision($db); $key = self::key($db,$identity);
            $index = new PDO('sqlite:' . $temporary,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            chmod($temporary,0600);
            $index->exec('CREATE TABLE reference_edges (doi TEXT NOT NULL,citing_id INTEGER NOT NULL,PRIMARY KEY(doi,citing_id)) WITHOUT ROWID; CREATE TABLE metadata (name TEXT PRIMARY KEY,value TEXT NOT NULL)');
            $index->beginTransaction();
            $insert = $index->prepare('INSERT OR IGNORE INTO reference_edges VALUES (?,?)');
            $map = self::workMap($db); $records = 0; $edges = 0;
            foreach ($db->pdo()->query('SELECT id,raw_json FROM publications WHERE ' . self::VISIBLE) as $row) {
                $records++;
                foreach (self::referenceDois($row,$map) as $doi) { $insert->execute([$doi,(int)$row['id']]); $edges += $insert->rowCount(); }
            }
            $meta = $index->prepare('INSERT INTO metadata VALUES (?,?)');
            $meta->execute(['source_key',$key]); $meta->execute(['built_at',gmdate('c')]);
            $index->commit();
            if ($index->query('PRAGMA quick_check')->fetchColumn() !== 'ok') throw new RuntimeException('Citation index integrity failed.');
            $insert = $meta = null; $index = null;
            $db->pdo()->commit();
            if (self::revision($db) !== $revision) throw new RuntimeException('Catalogue changed during index build; previous index retained.');
            if (!rename($temporary,$path)) throw new RuntimeException('Cannot publish citation index.');
            return ['records'=>$records,'edges'=>$edges,'revision'=>$revision];
        } finally {
            if (isset($index) && $index instanceof PDO && $index->inTransaction()) $index->rollBack();
            $insert = $meta = $index = null;
            if ($db->pdo()->inTransaction()) $db->pdo()->rollBack();
            foreach ([$temporary, $temporary . '-journal'] as $file) if (is_file($file)) unlink($file);
            flock($lock,LOCK_UN); fclose($lock);
        }
    }

    private static function identity(Database $db): ?string
    {
        if ($db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') return null;
        $row = $db->pdo()->query('PRAGMA database_list')->fetch(PDO::FETCH_ASSOC);
        $file = (string)($row['file'] ?? '');
        return $file !== '' && is_file($file) ? hash('sha256',$file . ':' . fileinode($file)) : null;
    }
    private static function path(string $identity): string { return Config::dataDir() . '/citation-index-' . $identity . '.sqlite'; }
    private static function revision(Database $db): string { return (string)$db->pdo()->query('SELECT revision FROM app_cache_revision WHERE id=1')->fetchColumn(); }
    private static function key(Database $db,string $identity): string
    {
        return hash('sha256',$identity . ':' . self::revision($db) . ':' . hash_file('sha256',__FILE__) . ':' . hash_file('sha256',Config::baseDir() . '/src/helpers.php'));
    }
}
