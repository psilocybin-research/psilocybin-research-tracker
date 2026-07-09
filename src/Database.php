<?php
declare(strict_types=1);

final class Database
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?: new PDO(
            Config::databaseDsn(),
            Config::databaseUser(),
            Config::databasePassword(),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );

        if (str_starts_with(Config::databaseDsn(), 'sqlite:')) {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA journal_mode = WAL');
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function initialize(): void
    {
        $schema = file_get_contents(Config::baseDir() . '/schema.sql');
        if ($schema === false) {
            throw new RuntimeException('Unable to load schema.sql');
        }
        $this->pdo->exec($schema);
        $this->migrate();
    }

    private function migrate(): void
    {
        if (!str_starts_with(Config::databaseDsn(), 'sqlite:')) {
            return;
        }
        $columns = $this->pdo->query('PRAGMA table_info(fetch_runs)')->fetchAll();
        $names = array_map(function (array $column): string {
            return (string)$column['name'];
        }, $columns);
        if (!in_array('skipped_count', $names, true)) {
            $this->pdo->exec('ALTER TABLE fetch_runs ADD COLUMN skipped_count INTEGER NOT NULL DEFAULT 0');
        }
        $publicationColumns = $this->pdo->query('PRAGMA table_info(publications)')->fetchAll();
        $publicationNames = array_map(function (array $column): string {
            return (string)$column['name'];
        }, $publicationColumns);
        $publicationAdds = [
            'topic_tags' => 'ALTER TABLE publications ADD COLUMN topic_tags TEXT NOT NULL DEFAULT ""',
            'study_type' => 'ALTER TABLE publications ADD COLUMN study_type TEXT',
            'hidden' => 'ALTER TABLE publications ADD COLUMN hidden INTEGER NOT NULL DEFAULT 0',
            'false_positive' => 'ALTER TABLE publications ADD COLUMN false_positive INTEGER NOT NULL DEFAULT 0',
            'curation_notes' => 'ALTER TABLE publications ADD COLUMN curation_notes TEXT',
            'curation_locked' => 'ALTER TABLE publications ADD COLUMN curation_locked INTEGER NOT NULL DEFAULT 0',
            'merged_into_id' => 'ALTER TABLE publications ADD COLUMN merged_into_id INTEGER',
            'publication_status' => 'ALTER TABLE publications ADD COLUMN publication_status TEXT NOT NULL DEFAULT "published"',
            'openalex_id' => 'ALTER TABLE publications ADD COLUMN openalex_id TEXT',
        ];
        foreach ($publicationAdds as $name => $sql) {
            if (!in_array($name, $publicationNames, true)) {
                try {
                    $this->pdo->exec($sql);
                } catch (PDOException $e) {
                    if (!str_contains($e->getMessage(), 'duplicate column name')) {
                        throw $e;
                    }
                }
            }
        }
        $alertColumns = $this->pdo->query('PRAGMA table_info(alert_subscriptions)')->fetchAll();
        $alertNames = array_map(function (array $column): string {
            return (string)$column['name'];
        }, $alertColumns);
        $alertAdds = [
            'public_uuid' => 'ALTER TABLE alert_subscriptions ADD COLUMN public_uuid TEXT',
            'author' => 'ALTER TABLE alert_subscriptions ADD COLUMN author TEXT',
            'journal' => 'ALTER TABLE alert_subscriptions ADD COLUMN journal TEXT',
            'topic' => 'ALTER TABLE alert_subscriptions ADD COLUMN topic TEXT',
            'cited_doi' => 'ALTER TABLE alert_subscriptions ADD COLUMN cited_doi TEXT',
            'confirmation_token' => 'ALTER TABLE alert_subscriptions ADD COLUMN confirmation_token TEXT',
            'confirmation_sent_at' => 'ALTER TABLE alert_subscriptions ADD COLUMN confirmation_sent_at TEXT',
            'confirmed_at' => 'ALTER TABLE alert_subscriptions ADD COLUMN confirmed_at TEXT',
        ];
        foreach ($alertAdds as $name => $sql) {
            if (!in_array($name, $alertNames, true)) {
                try {
                    $this->pdo->exec($sql);
                } catch (PDOException $e) {
                    if (!str_contains($e->getMessage(), 'duplicate column name')) {
                        throw $e;
                    }
                }
            }
        }
        $this->pdo->exec('UPDATE alert_subscriptions SET confirmed_at = COALESCE(confirmed_at, created_at) WHERE active = 1');
        $this->backfillAlertPublicUuids();
        $this->migrateAlertUniqueness();
        $this->migrateSensitiveTables();
        $this->dropLegacySensitiveIndexes();
        $this->pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_alert_subscriptions_token_blind ON alert_subscriptions(token_blind_index)');
        $this->pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_alert_subscriptions_confirmation_token_blind ON alert_subscriptions(confirmation_token_blind_index)');
        $this->pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_alert_subscriptions_public_uuid ON alert_subscriptions(public_uuid)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_publications_topic ON publications(topic_tags)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_publications_study_type ON publications(study_type)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_publications_source ON publications(source_name)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_publications_status ON publications(publication_status)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_publications_hidden ON publications(hidden, false_positive)');
        $this->pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_publications_openalex_id_unique ON publications(openalex_id) WHERE openalex_id IS NOT NULL AND openalex_id != ""');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_publications_visible_openalex ON publications(hidden, false_positive, openalex_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_publications_visible_date ON publications(hidden, false_positive, publication_date DESC, id DESC)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_publications_visible_year_date ON publications(hidden, false_positive, publication_year, publication_date DESC, id DESC)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_publications_visible_source_date ON publications(hidden, false_positive, source_name, publication_date DESC, id DESC)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_publications_visible_status_date ON publications(hidden, false_positive, publication_status, publication_date DESC, id DESC)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_publications_visible_journal_date ON publications(hidden, false_positive, journal, publication_date DESC, id DESC)');
        $this->migratePublicationMetadataIndexes();
        $this->migratePublicationFts();
    }

    private function migratePublicationMetadataIndexes(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS publication_authors (
            publication_id INTEGER NOT NULL,
            author_name TEXT NOT NULL,
            author_key TEXT NOT NULL,
            position INTEGER NOT NULL DEFAULT 0,
            orcid TEXT,
            openalex_id TEXT,
            FOREIGN KEY(publication_id) REFERENCES publications(id) ON DELETE CASCADE,
            UNIQUE(publication_id, author_key)
        )");
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_publication_authors_key ON publication_authors(author_key)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_publication_authors_name ON publication_authors(author_name)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_publication_authors_publication ON publication_authors(publication_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_publication_authors_openalex ON publication_authors(openalex_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_publication_authors_orcid ON publication_authors(orcid)');

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS publication_topics (
            publication_id INTEGER NOT NULL,
            topic TEXT NOT NULL,
            topic_key TEXT NOT NULL,
            source TEXT NOT NULL DEFAULT 'classifier',
            FOREIGN KEY(publication_id) REFERENCES publications(id) ON DELETE CASCADE,
            UNIQUE(publication_id, topic_key, source)
        )");
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_publication_topics_key ON publication_topics(topic_key)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_publication_topics_topic ON publication_topics(topic)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_publication_topics_publication ON publication_topics(publication_id)');

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS openalex_quality_reviews (
            openalex_id TEXT PRIMARY KEY,
            publication_id INTEGER,
            decision TEXT NOT NULL CHECK(decision IN ('approved', 'rejected', 'needs_review')),
            reason TEXT,
            reviewed_at TEXT NOT NULL,
            reviewer TEXT NOT NULL DEFAULT 'system',
            FOREIGN KEY(publication_id) REFERENCES publications(id) ON DELETE SET NULL
        )");
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_openalex_quality_decision ON openalex_quality_reviews(decision, reviewed_at)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_openalex_quality_publication ON openalex_quality_reviews(publication_id)');
    }

    private function migratePublicationFts(): void
    {
        try {
            $this->pdo->exec("CREATE VIRTUAL TABLE IF NOT EXISTS publications_fts USING fts5(
                title,
                abstract,
                authors,
                keywords,
                journal,
                content='publications',
                content_rowid='id'
            )");
            $this->pdo->exec("CREATE TRIGGER IF NOT EXISTS publications_fts_ai AFTER INSERT ON publications BEGIN
                INSERT INTO publications_fts(rowid, title, abstract, authors, keywords, journal)
                VALUES (new.id, new.title, new.abstract, new.authors, new.keywords, new.journal);
            END");
            $this->pdo->exec("CREATE TRIGGER IF NOT EXISTS publications_fts_ad AFTER DELETE ON publications BEGIN
                INSERT INTO publications_fts(publications_fts, rowid, title, abstract, authors, keywords, journal)
                VALUES ('delete', old.id, old.title, old.abstract, old.authors, old.keywords, old.journal);
            END");
            $this->pdo->exec("CREATE TRIGGER IF NOT EXISTS publications_fts_au AFTER UPDATE OF title, abstract, authors, keywords, journal ON publications BEGIN
                INSERT INTO publications_fts(publications_fts, rowid, title, abstract, authors, keywords, journal)
                VALUES ('delete', old.id, old.title, old.abstract, old.authors, old.keywords, old.journal);
                INSERT INTO publications_fts(rowid, title, abstract, authors, keywords, journal)
                VALUES (new.id, new.title, new.abstract, new.authors, new.keywords, new.journal);
            END");
            if ($this->publicationFtsNeedsRebuild()) {
                $this->pdo->exec("INSERT INTO publications_fts(publications_fts) VALUES ('rebuild')");
            }
        } catch (PDOException $e) {
            if (!str_contains($e->getMessage(), 'no such module: fts5')) {
                throw $e;
            }
        }
    }

    private function publicationFtsNeedsRebuild(): bool
    {
        try {
            $publicationCount = (int)$this->pdo->query('SELECT COUNT(*) FROM publications')->fetchColumn();
            $ftsCount = (int)$this->pdo->query('SELECT COUNT(*) FROM publications_fts')->fetchColumn();
            return $publicationCount !== $ftsCount;
        } catch (PDOException) {
            return true;
        }
    }

    private function backfillAlertPublicUuids(): void
    {
        $columns = $this->pdo->query('PRAGMA table_info(alert_subscriptions)')->fetchAll();
        $names = array_map(fn (array $column): string => (string)$column['name'], $columns);
        if (!in_array('public_uuid', $names, true)) {
            return;
        }
        $stmt = $this->pdo->query('SELECT id FROM alert_subscriptions WHERE public_uuid IS NULL OR TRIM(public_uuid) = ""');
        $update = $this->pdo->prepare('UPDATE alert_subscriptions SET public_uuid = :uuid WHERE id = :id');
        foreach ($stmt->fetchAll() as $row) {
            do {
                $uuid = self::uuidV4();
                $exists = $this->pdo->prepare('SELECT 1 FROM alert_subscriptions WHERE public_uuid = :uuid LIMIT 1');
                $exists->execute(['uuid' => $uuid]);
            } while ($exists->fetchColumn());
            $update->execute(['uuid' => $uuid, 'id' => (int)$row['id']]);
        }
    }

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private function dropLegacySensitiveIndexes(): void
    {
        foreach ([
            'idx_alert_subscriptions_confirmation_token',
        ] as $index) {
            $this->pdo->exec('DROP INDEX IF EXISTS ' . $index);
        }
    }

    private function migrateAlertUniqueness(): void
    {
        $needsRebuild = false;
        foreach ($this->pdo->query('PRAGMA index_list(alert_subscriptions)')->fetchAll() as $index) {
            if (empty($index['unique'])) {
                continue;
            }
            $cols = [];
            foreach ($this->pdo->query('PRAGMA index_info(' . $index['name'] . ')')->fetchAll() as $info) {
                $cols[] = $info['name'];
            }
            if ($cols === ['email', 'frequency', 'substances', 'keywords'] || $cols === ['email', 'frequency', 'substances', 'keywords', 'author', 'journal', 'topic']) {
                $needsRebuild = true;
            }
        }
        if (!$needsRebuild) {
            return;
        }
        $this->pdo->exec('PRAGMA foreign_keys = OFF');
        $this->pdo->exec('BEGIN IMMEDIATE');
        $this->pdo->exec('CREATE TABLE alert_subscriptions_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_uuid TEXT UNIQUE,
            email TEXT NOT NULL,
            frequency TEXT NOT NULL CHECK(frequency IN ("daily", "weekly", "monthly")),
            substances TEXT NOT NULL DEFAULT "psilocybin,psilocin",
            keywords TEXT,
            author TEXT,
            journal TEXT,
            topic TEXT,
            cited_doi TEXT,
            active INTEGER NOT NULL DEFAULT 0,
            token TEXT NOT NULL UNIQUE,
            confirmation_token TEXT UNIQUE,
            confirmation_sent_at TEXT,
            confirmed_at TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            UNIQUE(email, frequency, substances, keywords, author, journal, topic, cited_doi)
        )');
        $this->pdo->exec('INSERT OR IGNORE INTO alert_subscriptions_new (id,public_uuid,email,frequency,substances,keywords,author,journal,topic,cited_doi,active,token,confirmation_token,confirmation_sent_at,confirmed_at,created_at,updated_at)
            SELECT id,public_uuid,email,frequency,substances,keywords,author,journal,topic,cited_doi,active,token,confirmation_token,confirmation_sent_at,confirmed_at,created_at,updated_at FROM alert_subscriptions');
        $this->pdo->exec('DROP TABLE alert_subscriptions');
        $this->pdo->exec('ALTER TABLE alert_subscriptions_new RENAME TO alert_subscriptions');
        $this->pdo->exec('COMMIT');
        $this->pdo->exec('PRAGMA foreign_keys = ON');
    }

    private function migrateSensitiveTables(): void
    {
        $this->migrateAlertSensitiveColumns();
        $this->migratePushSensitiveColumns();
    }

    private function migrateAlertSensitiveColumns(): void
    {
        $columns = $this->pdo->query('PRAGMA table_info(alert_subscriptions)')->fetchAll();
        $names = array_map(fn (array $column): string => (string)$column['name'], $columns);
        $needsRebuild = !in_array('email_cipher', $names, true) || !in_array('email_blind_index', $names, true) || !in_array('token_cipher', $names, true) || !in_array('token_blind_index', $names, true) || !in_array('confirmation_token_cipher', $names, true) || !in_array('confirmation_token_blind_index', $names, true);
        foreach ($this->pdo->query('PRAGMA index_list(alert_subscriptions)')->fetchAll() as $index) {
            if (empty($index['unique'])) {
                continue;
            }
            $cols = [];
            foreach ($this->pdo->query('PRAGMA index_info(' . $index['name'] . ')')->fetchAll() as $info) {
                $cols[] = $info['name'];
            }
            if (in_array('email', $cols, true) || $cols === ['token'] || $cols === ['confirmation_token']) {
                $needsRebuild = true;
            }
        }
        if (!$needsRebuild) {
            return;
        }

        $rows = $this->pdo->query('SELECT * FROM alert_subscriptions')->fetchAll();
        $this->pdo->exec('PRAGMA foreign_keys = OFF');
        $this->pdo->exec('BEGIN IMMEDIATE');
        $this->pdo->exec('CREATE TABLE alert_subscriptions_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_uuid TEXT UNIQUE,
            email TEXT NOT NULL DEFAULT "[encrypted]",
            email_cipher TEXT,
            email_blind_index TEXT,
            frequency TEXT NOT NULL CHECK(frequency IN ("daily", "weekly", "monthly")),
            substances TEXT NOT NULL DEFAULT "psilocybin,psilocin",
            keywords TEXT,
            author TEXT,
            journal TEXT,
            topic TEXT,
            cited_doi TEXT,
            active INTEGER NOT NULL DEFAULT 0,
            token TEXT NOT NULL DEFAULT "[encrypted]",
            token_cipher TEXT,
            token_blind_index TEXT UNIQUE,
            confirmation_token TEXT,
            confirmation_token_cipher TEXT,
            confirmation_token_blind_index TEXT UNIQUE,
            confirmation_sent_at TEXT,
            confirmed_at TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            UNIQUE(email_blind_index, frequency, substances, keywords, author, journal, topic, cited_doi)
        )');
        $stmt = $this->pdo->prepare('INSERT OR IGNORE INTO alert_subscriptions_new
            (id,public_uuid,email,email_cipher,email_blind_index,frequency,substances,keywords,author,journal,topic,cited_doi,active,token,token_cipher,token_blind_index,confirmation_token,confirmation_token_cipher,confirmation_token_blind_index,confirmation_sent_at,confirmed_at,created_at,updated_at)
            VALUES (:id,:public_uuid,:email,:email_cipher,:email_blind_index,:frequency,:substances,:keywords,:author,:journal,:topic,:cited_doi,:active,:token,:token_cipher,:token_blind_index,:confirmation_token,:confirmation_token_cipher,:confirmation_token_blind_index,:confirmation_sent_at,:confirmed_at,:created_at,:updated_at)');
        foreach ($rows as $row) {
            $email = isset($row['email_cipher']) && $row['email_cipher'] !== null
                ? SensitiveData::decrypt((string)$row['email_cipher'])
                : (string)($row['email'] ?? '');
            $email = SensitiveData::canonicalEmail((string)$email);
            $token = isset($row['token_cipher']) && $row['token_cipher'] !== null ? SensitiveData::decrypt((string)$row['token_cipher']) : (string)($row['token'] ?? '');
            $confirmationToken = isset($row['confirmation_token_cipher']) && $row['confirmation_token_cipher'] !== null ? SensitiveData::decrypt((string)$row['confirmation_token_cipher']) : ($row['confirmation_token'] ?? null);
            $stmt->execute([
                'id' => (int)$row['id'],
                'public_uuid' => (string)($row['public_uuid'] ?? self::uuidV4()),
                'email' => '[encrypted]',
                'email_cipher' => SensitiveData::encrypt($email),
                'email_blind_index' => SensitiveData::blindIndex('alert-email', $email),
                'frequency' => (string)$row['frequency'],
                'substances' => (string)$row['substances'],
                'keywords' => $row['keywords'] ?? null,
                'author' => $row['author'] ?? null,
                'journal' => $row['journal'] ?? null,
                'topic' => $row['topic'] ?? null,
                'cited_doi' => $row['cited_doi'] ?? null,
                'active' => (int)$row['active'],
                'token' => '[encrypted]',
                'token_cipher' => SensitiveData::encrypt($token),
                'token_blind_index' => SensitiveData::blindIndex('alert-token', $token),
                'confirmation_token' => $confirmationToken === null ? null : '[encrypted]',
                'confirmation_token_cipher' => $confirmationToken === null ? null : SensitiveData::encrypt((string)$confirmationToken),
                'confirmation_token_blind_index' => $confirmationToken === null ? null : SensitiveData::blindIndex('alert-confirmation-token', (string)$confirmationToken),
                'confirmation_sent_at' => $row['confirmation_sent_at'] ?? null,
                'confirmed_at' => $row['confirmed_at'] ?? null,
                'created_at' => (string)$row['created_at'],
                'updated_at' => (string)$row['updated_at'],
            ]);
        }
        $this->pdo->exec('DROP TABLE alert_subscriptions');
        $this->pdo->exec('ALTER TABLE alert_subscriptions_new RENAME TO alert_subscriptions');
        $this->pdo->exec('COMMIT');
        $this->pdo->exec('PRAGMA foreign_keys = ON');
    }

    private function migratePushSensitiveColumns(): void
    {
        $columns = $this->pdo->query('PRAGMA table_info(push_subscriptions)')->fetchAll();
        $names = array_map(fn (array $column): string => (string)$column['name'], $columns);
        $needsRebuild = !in_array('endpoint_cipher', $names, true) || !in_array('endpoint_blind_index', $names, true) || !in_array('p256dh_cipher', $names, true) || !in_array('auth_cipher', $names, true);
        foreach ($this->pdo->query('PRAGMA index_list(push_subscriptions)')->fetchAll() as $index) {
            if (empty($index['unique'])) {
                continue;
            }
            $cols = [];
            foreach ($this->pdo->query('PRAGMA index_info(' . $index['name'] . ')')->fetchAll() as $info) {
                $cols[] = $info['name'];
            }
            if ($cols === ['endpoint']) {
                $needsRebuild = true;
            }
        }
        if (!$needsRebuild) {
            return;
        }

        $rows = $this->pdo->query('SELECT * FROM push_subscriptions')->fetchAll();
        $this->pdo->exec('PRAGMA foreign_keys = OFF');
        $this->pdo->exec('BEGIN IMMEDIATE');
        $this->pdo->exec('CREATE TABLE push_subscriptions_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            endpoint TEXT NOT NULL DEFAULT "[encrypted]",
            endpoint_cipher TEXT,
            endpoint_blind_index TEXT UNIQUE,
            p256dh TEXT,
            p256dh_cipher TEXT,
            auth TEXT,
            auth_cipher TEXT,
            user_agent TEXT,
            user_agent_cipher TEXT,
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            last_sent_at TEXT,
            last_error TEXT
        )');
        $stmt = $this->pdo->prepare('INSERT OR REPLACE INTO push_subscriptions_new
            (id,endpoint,endpoint_cipher,endpoint_blind_index,p256dh,p256dh_cipher,auth,auth_cipher,user_agent,user_agent_cipher,active,created_at,updated_at,last_sent_at,last_error)
            VALUES (:id,:endpoint,:endpoint_cipher,:endpoint_blind_index,:p256dh,:p256dh_cipher,:auth,:auth_cipher,:user_agent,:user_agent_cipher,:active,:created_at,:updated_at,:last_sent_at,:last_error)');
        foreach ($rows as $row) {
            $endpoint = isset($row['endpoint_cipher']) && $row['endpoint_cipher'] !== null ? SensitiveData::decrypt((string)$row['endpoint_cipher']) : (string)($row['endpoint'] ?? '');
            $p256dh = isset($row['p256dh_cipher']) && $row['p256dh_cipher'] !== null ? SensitiveData::decrypt((string)$row['p256dh_cipher']) : (string)($row['p256dh'] ?? '');
            $auth = isset($row['auth_cipher']) && $row['auth_cipher'] !== null ? SensitiveData::decrypt((string)$row['auth_cipher']) : (string)($row['auth'] ?? '');
            $userAgent = isset($row['user_agent_cipher']) && $row['user_agent_cipher'] !== null ? SensitiveData::decrypt((string)$row['user_agent_cipher']) : ($row['user_agent'] ?? null);
            $stmt->execute([
                'id' => (int)$row['id'],
                'endpoint' => '[encrypted]',
                'endpoint_cipher' => SensitiveData::encrypt($endpoint),
                'endpoint_blind_index' => SensitiveData::blindIndex('push-endpoint', $endpoint),
                'p256dh' => '[encrypted]',
                'p256dh_cipher' => SensitiveData::encrypt($p256dh),
                'auth' => '[encrypted]',
                'auth_cipher' => SensitiveData::encrypt($auth),
                'user_agent' => $userAgent === null ? null : '[encrypted]',
                'user_agent_cipher' => $userAgent === null ? null : SensitiveData::encrypt((string)$userAgent),
                'active' => (int)$row['active'],
                'created_at' => (string)$row['created_at'],
                'updated_at' => (string)$row['updated_at'],
                'last_sent_at' => $row['last_sent_at'] ?? null,
                'last_error' => $row['last_error'] ?? null,
            ]);
        }
        $this->pdo->exec('DROP TABLE push_subscriptions');
        $this->pdo->exec('ALTER TABLE push_subscriptions_new RENAME TO push_subscriptions');
        $this->pdo->exec('COMMIT');
        $this->pdo->exec('PRAGMA foreign_keys = ON');
    }
}
