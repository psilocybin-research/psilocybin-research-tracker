<?php
declare(strict_types=1);

require_once __DIR__ . '/src/helpers.php';

$db = new Database();
$db->initialize();
$source = $db->pdo();

$tmp = tempnam(sys_get_temp_dir(), 'publication-tracker-sqlite-');
if ($tmp === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Unable to create database export.';
    exit;
}
$sqlitePath = $tmp . '.sqlite';
rename($tmp, $sqlitePath);
register_shutdown_function(static function () use ($sqlitePath): void {
    if (is_file($sqlitePath)) {
        @unlink($sqlitePath);
    }
});

$export = new PDO('sqlite:' . $sqlitePath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$export->exec('PRAGMA journal_mode = OFF');
$export->exec('PRAGMA synchronous = OFF');
$export->exec('CREATE TABLE publications (
    id INTEGER PRIMARY KEY,
    title TEXT NOT NULL,
    authors TEXT,
    abstract TEXT,
    journal TEXT,
    publication_date TEXT,
    publication_year INTEGER,
    doi TEXT,
    pubmed_id TEXT,
    source_url TEXT,
    keywords TEXT,
    substance_tags TEXT NOT NULL DEFAULT "",
    topic_tags TEXT NOT NULL DEFAULT "",
    study_type TEXT,
    source_name TEXT,
    publication_status TEXT NOT NULL DEFAULT "published",
    date_added TEXT NOT NULL,
    last_checked TEXT NOT NULL,
    raw_json TEXT
)');
$export->exec('CREATE INDEX idx_publications_date ON publications(publication_date)');
$export->exec('CREATE INDEX idx_publications_year ON publications(publication_year)');
$export->exec('CREATE INDEX idx_publications_doi ON publications(doi)');
$export->exec('CREATE INDEX idx_publications_pubmed_id ON publications(pubmed_id)');
$export->exec('CREATE INDEX idx_publications_source ON publications(source_name)');
$export->exec('CREATE INDEX idx_publications_status ON publications(publication_status)');
$export->exec('CREATE INDEX idx_publications_topic ON publications(topic_tags)');
$export->exec('CREATE TABLE metadata (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
)');

$count = (int)$source->query('SELECT COUNT(*) FROM publications WHERE hidden = 0 AND false_positive = 0')->fetchColumn();
$lastChecked = (string)($source->query("SELECT MAX(last_checked) FROM publications WHERE hidden = 0 AND false_positive = 0")->fetchColumn() ?: '');

$export->beginTransaction();
$insert = $export->prepare('INSERT INTO publications (
    id, title, authors, abstract, journal, publication_date, publication_year, doi, pubmed_id,
    source_url, keywords, substance_tags, topic_tags, study_type, source_name, publication_status,
    date_added, last_checked, raw_json
) VALUES (
    :id, :title, :authors, :abstract, :journal, :publication_date, :publication_year, :doi, :pubmed_id,
    :source_url, :keywords, :substance_tags, :topic_tags, :study_type, :source_name, :publication_status,
    :date_added, :last_checked, :raw_json
)');
$select = $source->query('SELECT
    id, title, authors, abstract, journal, publication_date, publication_year, doi, pubmed_id,
    source_url, keywords, substance_tags, topic_tags, study_type, source_name, publication_status,
    date_added, last_checked, raw_json
    FROM publications
    WHERE hidden = 0 AND false_positive = 0
    ORDER BY publication_date DESC, id DESC');
while ($row = $select->fetch()) {
    $insert->execute([
        'id' => (int)$row['id'],
        'title' => (string)$row['title'],
        'authors' => $row['authors'],
        'abstract' => $row['abstract'],
        'journal' => $row['journal'],
        'publication_date' => $row['publication_date'],
        'publication_year' => $row['publication_year'] !== null ? (int)$row['publication_year'] : null,
        'doi' => $row['doi'],
        'pubmed_id' => $row['pubmed_id'],
        'source_url' => $row['source_url'],
        'keywords' => $row['keywords'],
        'substance_tags' => (string)$row['substance_tags'],
        'topic_tags' => (string)($row['topic_tags'] ?? ''),
        'study_type' => $row['study_type'],
        'source_name' => $row['source_name'],
        'publication_status' => (string)($row['publication_status'] ?: 'published'),
        'date_added' => (string)$row['date_added'],
        'last_checked' => (string)$row['last_checked'],
        'raw_json' => $row['raw_json'],
    ]);
}
$metadata = $export->prepare('INSERT INTO metadata (key, value) VALUES (:key, :value)');
foreach ([
    'title' => 'Psilocybin and Psilocin Research Publications',
    'description' => 'Public literature-only SQLite export from the Psilocybin Research Publication Tracker. Runtime state, private notification data, admin data, hidden records, and curated false positives are excluded.',
    'tracker_site_url' => ExportService::TRACKER_SITE_URL,
    'source_url' => Config::publicBaseUrl(),
    'generated_at_utc' => current_utc(),
    'record_count' => (string)$count,
    'last_checked_utc' => $lastChecked,
    'license_note' => 'Bibliographic metadata is aggregated from upstream public sources. Check source_url, DOI, PubMed, and registry links for upstream rights and authoritative records.',
] as $key => $value) {
    $metadata->execute(['key' => $key, 'value' => $value]);
}
$export->commit();
$export->exec('VACUUM');
$export = null;

$filename = download_filename('psilocybin-research-publications', 'sqlite');
header('Content-Type: application/vnd.sqlite3');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($sqlitePath));
header('X-Publication-Tracker-Export-Count: ' . $count);
header('X-Publication-Tracker-Export-Scope: public-literature-only');
header('X-Publication-Tracker-Filename: ' . $filename);
header('X-Publication-Tracker-Site: ' . ExportService::TRACKER_SITE_URL);
if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
    exit;
}
readfile($sqlitePath);
