<?php
declare(strict_types=1);

/** Queries a checksum-pinned, public-results-only SQLite derivative of Zenodo. */
final class TranscriptomicsSnapshot
{
    private array $manifest;
    private PDO $pdo;
    public function __construct()
    {
        $root = dirname(__DIR__);
        $this->manifest = json_decode((string)file_get_contents($root . '/config/transcriptomics-snapshot.json'), true, 64, JSON_THROW_ON_ERROR);
        $file = $this->manifest['database'];
        if (!preg_match('~^data/transcriptomics/zenodo-[0-9]+-[a-f0-9]{12}/results-[a-f0-9]{16}\.sqlite$~D', $file['path'])) throw new RuntimeException('Invalid snapshot path.');
        $path = $root . '/' . $file['path'];
        if (!is_file($path) || filesize($path) !== $file['bytes'] || !hash_equals($file['sha256'], hash_file('sha256', $path))) throw new RuntimeException('Snapshot integrity check failed.');
        $this->pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, (class_exists('Pdo\\Sqlite') ? Pdo\Sqlite::ATTR_OPEN_FLAGS : PDO::SQLITE_ATTR_OPEN_FLAGS) => (class_exists('Pdo\\Sqlite') ? Pdo\Sqlite::OPEN_READONLY : PDO::SQLITE_OPEN_READONLY)]);
        $this->pdo->exec('PRAGMA query_only=ON');
    }
    private function query(string $sql, array $args = []): array
    {
        $q = $this->pdo->prepare($sql); $q->execute($args); return $q->fetchAll();
    }
    private function document(string $name): mixed
    {
        return json_decode($this->query('SELECT payload FROM documents WHERE name=?', [$name])[0]['payload'], true, 64, JSON_THROW_ON_ERROR);
    }
    private function rows(string $sql, array $args = []): array
    {
        return array_map(static fn($row) => json_decode($row['payload'], true, 64, JSON_THROW_ON_ERROR) + ['source_table' => $row['source']], $this->query($sql, $args));
    }
    private function envelope(array $data): array
    {
        $manifest = $this->manifest;
        return ['data' => $data, 'provenance' => [
            'snapshot_id' => $manifest['snapshot_id'], 'analysis_version' => $manifest['source']['analysis_version'], 'doi' => $manifest['source']['doi'],
            'analysis_commit' => $manifest['source']['analysis_commit'], 'query_database_sha256' => $manifest['database']['sha256'],
            'manifest_url' => rtrim(Config::publicBaseUrl(), '/') . '/transcriptomics-manifest.php',
            'source_tables' => $manifest['tables'], 'citations' => $manifest['citations'],
        ], 'field_definitions' => $manifest['field_definitions'], 'limitations' => $manifest['limitations']];
    }
    public function manifest(): array
    {
        $public = $this->manifest;
        unset($public['database']['path']); // Internal storage is not a downloadable runtime path.
        return $public;
    }
    public function execute(string $name, array $args): array
    {
        $allowed = match ($name) {
            'search_transcriptomic_genes' => ['query','limit','offset'],
            'get_transcriptomic_gene' => ['gene','contrast'],
            'get_transcriptomic_pathways' => ['query','contrast','limit','offset'],
            'get_transcriptomic_robustness' => ['topic'],
            'get_transcriptomic_provenance' => [],
            default => throw new InvalidArgumentException('Unknown transcriptomics tool.'),
        };
        if (array_diff(array_keys($args), $allowed)) throw new InvalidArgumentException('Unsupported transcriptomics argument.');
        foreach (['query','gene'] as $key) if (array_key_exists($key,$args) && (!is_string($args[$key]) || mb_strlen($args[$key]) > 160)) throw new InvalidArgumentException('Invalid query or gene.');
        $required = match ($name) { 'search_transcriptomic_genes' => 'query', 'get_transcriptomic_gene' => 'gene', default => null };
        if ($required !== null && (!isset($args[$required]) || trim($args[$required]) === '')) throw new InvalidArgumentException('A nonempty query or gene is required.');
        if (isset($args['contrast']) && !in_array($args['contrast'],$this->manifest['contrasts'],true)) throw new InvalidArgumentException('Unknown contrast.');
        if (isset($args['topic']) && !in_array($args['topic'],['all','oxphos','dna','redox'],true)) throw new InvalidArgumentException('Unknown robustness topic.');
        $maxLimit = $name === 'search_transcriptomic_genes' ? 50 : 5;
        if (array_key_exists('limit',$args) && (!is_int($args['limit']) || $args['limit'] < 1 || $args['limit'] > $maxLimit)) throw new InvalidArgumentException('Limit outside the supported range.');
        $maxOffset = $name === 'search_transcriptomic_genes' ? 50000 : 100;
        if (array_key_exists('offset',$args) && (!is_int($args['offset']) || $args['offset'] < 0 || $args['offset'] > $maxOffset)) throw new InvalidArgumentException('Offset outside the supported range.');
        if ($name === 'get_transcriptomic_provenance') return $this->envelope(['manifest' => $this->manifest(), 'analysis_units' => $this->document('design'), 'sample_metadata' => $this->document('samples'), 'license_and_attribution_notices' => $this->document('notices')]);
        if ($name === 'search_transcriptomic_genes') {
            $query = trim($args['query']);
            $escaped = str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $query);
            $where = "(symbol LIKE ? ESCAPE '\\' OR gene_id LIKE ? ESCAPE '\\')";
            $params = ['%' . $escaped . '%', '%' . $escaped . '%'];
            $total = (int)$this->query('SELECT COUNT(DISTINCT gene_id) AS n FROM genes WHERE ' . $where, $params)[0]['n'];
            $limit = $args['limit'] ?? 20; $offset = $args['offset'] ?? 0;
            $rows = $this->query('SELECT DISTINCT gene_id,symbol FROM genes WHERE ' . $where . ' ORDER BY CASE WHEN symbol = ? COLLATE NOCASE OR gene_id = ? THEN 0 ELSE 1 END,symbol COLLATE NOCASE,gene_id LIMIT ' . $limit . ' OFFSET ' . $offset, [...$params,$query,$query]);
            return $this->envelope(['genes' => $rows, 'total' => $total, 'offset' => $offset, 'limit' => $limit, 'next_offset' => $offset + count($rows) < $total ? $offset + count($rows) : null, 'scope' => 'Literal substring discovery among genes present in primary result tables; not semantic search or a complete genome catalogue.']);
        }
        if ($name === 'get_transcriptomic_gene') {
            $gene = trim($args['gene']);
            $where = '(symbol=? COLLATE NOCASE OR gene_id=? OR stable_id=?)'; $params = [$gene,$gene,strtoupper($gene)];
            if (isset($args['contrast'])) { $where .= ' AND contrast=?'; $params[] = $args['contrast']; }
            $rows = $this->rows('SELECT payload,source FROM genes WHERE ' . $where . ' ORDER BY gene_id,contrast', $params);
            if (!$rows) throw new OutOfBoundsException('No matching primary result. Search gene symbols or Ensembl IDs; absence here is not evidence of no expression or no biological effect.');
            if (count($rows) > 30) throw new InvalidArgumentException('Ambiguous symbol; select one Ensembl gene ID from search_transcriptomic_genes.');
            $membership = $this->document('membership');
            foreach ($rows as &$row) {
                $row['pathway_memberships'] = array_keys(array_filter($membership, static fn($genes) => in_array($row['symbol'], $genes, true)));
            }
            unset($row);
            return $this->envelope(['results' => $rows, 'matching' => 'Exact symbol (case-insensitive), exact versioned Ensembl ID, or unversioned Ensembl ID. Multiple mappings are retained. No automatic alias substitution.', 'membership_scope' => 'Frozen atlas memberships under the original symbol mapping; absence does not exclude other annotations.']);
        }
        if ($name === 'get_transcriptomic_pathways') {
            $available = array_column($this->query("SELECT DISTINCT name FROM records WHERE family='fgsea' ORDER BY name"), 'name');
            $needle = strtoupper(str_replace(' ', '_', trim($args['query'] ?? '')));
            $matches = array_values(array_filter($available, static fn($value) => $needle === '' || str_contains($value, $needle)));
            $offset = $args['offset'] ?? 0; $limit = $args['limit'] ?? 5; $selected = array_slice($matches,$offset,$limit); $results = [];
            foreach ($selected as $pathway) {
                $where = 'name=?'; $params = [$pathway];
                if (isset($args['contrast'])) { $where .= ' AND contrast=?'; $params[] = $args['contrast']; }
                $results[] = ['pathway' => $pathway,
                    'fgsea_all_variants' => $this->rows("SELECT payload,source FROM records WHERE family='fgsea' AND " . $where . ' ORDER BY contrast,variant', $params),
                    'camera_both_settings' => $this->rows("SELECT payload,source FROM records WHERE family='camera' AND " . $where . ' ORDER BY contrast,variant', $params)];
            }
            return $this->envelope(['pathways' => $results, 'total_pathways' => count($matches), 'offset' => $offset, 'limit' => $limit, 'next_offset' => $offset + count($selected) < count($matches) ? $offset + count($selected) : null, 'scope' => 'Frozen tested pathway identifiers; primary and sensitivity FGSEA rows are retained together. CAMERA covers five declared sets; an empty CAMERA array means not tested here, not a negative result. DNA-overlap modules and targeted redox panels are available through get_transcriptomic_robustness.']);
        }
        if ($name === 'get_transcriptomic_robustness') {
            $topic = $args['topic'] ?? 'all'; $families = match ($topic) { 'oxphos' => ['camera','bayesian','alias'], 'dna' => ['dna'], 'redox' => ['redox'], default => ['camera','bayesian','alias','dna','redox'] };
            $results = [];
            foreach ($families as $family) $results[$family] = $this->rows('SELECT payload,source FROM records WHERE family=? ORDER BY source,name,contrast,variant', [$family]);
            return $this->envelope(['results' => $results, 'scope' => 'Complete imported rows for each requested family, including null and nonsignificant results. No filtering by significance or preferred model. Not posterior draws or fitted model objects.', 'methods' => ['camera' => 'reports/camera_sensitivity_methods.md', 'bayesian' => 'reports/bayesian_oxphos_methods.md', 'dna' => 'reports/dna_maintenance_results.md', 'redox' => 'config/targeted_redox_v1.json', 'alias' => 'reports/oxphos_alias_sensitivity.md']]);
        }
        throw new InvalidArgumentException('Unknown transcriptomics tool.');
    }
}
