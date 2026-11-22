<?php
declare(strict_types=1);

/** Short-lived private HTML cache for anonymous, canonical MSCA GET requests. */
final class MscaPageCache
{
    public const TTL = 60;

    private function __construct(
        private Database $db,
        private string $path,
        private string $key,
        private string $revision,
    ) {}

    public static function forRequest(Database $db, string $locale): ?self
    {
        if ((PHP_SAPI === 'cli' && getenv('MSCA_PAGE_CACHE_CLI') !== '1')
            || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
            || (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY) !== ''
            || $_GET !== [] || $_POST !== [] || $_COOKIE !== []
            || !empty($_SERVER['HTTP_AUTHORIZATION']) || !empty($_SERVER['REMOTE_USER'])
            || !isset(msca_locales()[$locale])) return null;
        $route = '/msca-impact/' . ($locale === 'en' ? '' : $locale . '/');
        if (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) !== $route) return null;
        try {
            $pdo = $db->pdo();
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') return null;
            $database = (string)($pdo->query('PRAGMA database_list')->fetch()['file'] ?? '');
            if ($database === '' || !is_file($database)) return null;
            $revision = self::revision($db);
            $context = hash_init('sha256');
            hash_update($context, $database . ':' . fileinode($database) . ':' . $revision . ':' . $locale . ':' . Config::publicBaseUrl() . ':' . gmdate('Y-m-d') . ':' . msca_asset_hash());
            $root = dirname(__DIR__);
            $files = array_merge(glob($root . '/msca-impact/*.php') ?: [], glob($root . '/config/msca-*.php') ?: [], [
                $root . '/msca-impact/locales/' . $locale . '.json',
                $root . '/msca-impact/seo.json', $root . '/msca-impact/research-provenance.jsonld',
                MscaCitations::path(),
            ]);
            foreach (['MscaPageCache', 'MscaCitations', 'MscaStatistics', 'MscaPageLayout', 'MscaAssets', 'Config', 'Database', 'PublicationRepository', 'PublicAggregateCache', 'ResearcherIdentity', 'helpers', 'ViewHelpers'] as $source) $files[] = __DIR__ . '/' . $source . '.php';
            foreach ($files as $file) {
                hash_update($context, $file . ':');
                if (is_file($file)) hash_update_file($context, $file);
            }
            $path = Config::dataDir() . '/msca-page-' . $locale . '.json';
            return new self($db, $path, hash_final($context), $revision);
        } catch (Throwable) {
            // Rendering remains available if the optional cache cannot be used.
            return null;
        }
    }

    public function read(): ?string
    {
        if (!is_file($this->path) || filesize($this->path) > 2000000) return null;
        $entry = json_decode((string)@file_get_contents($this->path), true);
        $age = time() - (int)($entry['created_at'] ?? 0);
        return is_array($entry) && ($entry['key'] ?? '') === $this->key && $age >= 0 && $age < self::TTL
            && is_string($entry['html'] ?? null) ? $entry['html'] : null;
    }

    public function write(string $html): void
    {
        if (strlen($html) > 1000000 || http_response_code() >= 400) return;
        $temporary = $this->path . '.tmp-' . bin2hex(random_bytes(8));
        try {
            if (self::revision($this->db) !== $this->revision) return;
            $payload = json_encode(['key' => $this->key, 'created_at' => time(), 'html' => $html], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $handle = @fopen($temporary, 'x');
            if ($handle === false) return;
            try {
                @chmod($temporary, 0600);
                $written = fwrite($handle, $payload);
            } finally { fclose($handle); }
            if ($written === strlen($payload)) @rename($temporary, $this->path);
        } catch (Throwable) {
            // A failed cache write must not fail an otherwise valid page.
        } finally { if (is_file($temporary)) @unlink($temporary); }
    }

    private static function revision(Database $db): string
    {
        return (string)$db->pdo()->query('SELECT revision FROM app_cache_revision WHERE id = 1')->fetchColumn();
    }
}
