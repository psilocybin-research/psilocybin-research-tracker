CREATE TABLE IF NOT EXISTS publications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    normalized_title TEXT NOT NULL,
    authors TEXT,
    abstract TEXT,
    journal TEXT,
    publication_date TEXT,
    publication_year INTEGER,
    doi TEXT,
    pubmed_id TEXT,
    openalex_id TEXT,
    source_url TEXT,
    keywords TEXT,
    substance_tags TEXT NOT NULL DEFAULT '',
    topic_tags TEXT NOT NULL DEFAULT '',
    study_type TEXT,
    hidden INTEGER NOT NULL DEFAULT 0,
    false_positive INTEGER NOT NULL DEFAULT 0,
    curation_notes TEXT,
    curation_locked INTEGER NOT NULL DEFAULT 0,
    merged_into_id INTEGER,
    source_name TEXT,
    publication_status TEXT NOT NULL DEFAULT 'published',
    date_added TEXT NOT NULL,
    last_checked TEXT NOT NULL,
    raw_json TEXT,
    UNIQUE(doi),
    UNIQUE(pubmed_id),
    UNIQUE(openalex_id)
);

CREATE INDEX IF NOT EXISTS idx_publications_normalized_title ON publications(normalized_title);
CREATE INDEX IF NOT EXISTS idx_publications_date ON publications(publication_date);
CREATE INDEX IF NOT EXISTS idx_publications_year ON publications(publication_year);
CREATE INDEX IF NOT EXISTS idx_publications_journal ON publications(journal);
CREATE INDEX IF NOT EXISTS idx_publications_substance ON publications(substance_tags);

CREATE TABLE IF NOT EXISTS publication_authors (
    publication_id INTEGER NOT NULL,
    author_name TEXT NOT NULL,
    author_key TEXT NOT NULL,
    position INTEGER NOT NULL DEFAULT 0,
    orcid TEXT,
    openalex_id TEXT,
    FOREIGN KEY(publication_id) REFERENCES publications(id) ON DELETE CASCADE,
    UNIQUE(publication_id, author_key)
);

CREATE INDEX IF NOT EXISTS idx_publication_authors_key ON publication_authors(author_key);
CREATE INDEX IF NOT EXISTS idx_publication_authors_name ON publication_authors(author_name);
CREATE INDEX IF NOT EXISTS idx_publication_authors_publication ON publication_authors(publication_id);
CREATE INDEX IF NOT EXISTS idx_publication_authors_openalex ON publication_authors(openalex_id);
CREATE INDEX IF NOT EXISTS idx_publication_authors_orcid ON publication_authors(orcid);

CREATE TABLE IF NOT EXISTS publication_topics (
    publication_id INTEGER NOT NULL,
    topic TEXT NOT NULL,
    topic_key TEXT NOT NULL,
    source TEXT NOT NULL DEFAULT 'classifier',
    FOREIGN KEY(publication_id) REFERENCES publications(id) ON DELETE CASCADE,
    UNIQUE(publication_id, topic_key, source)
);

CREATE INDEX IF NOT EXISTS idx_publication_topics_key ON publication_topics(topic_key);
CREATE INDEX IF NOT EXISTS idx_publication_topics_topic ON publication_topics(topic);
CREATE INDEX IF NOT EXISTS idx_publication_topics_publication ON publication_topics(publication_id);

CREATE TABLE IF NOT EXISTS publication_datasets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    publication_id INTEGER NOT NULL,
    dataset_url TEXT NOT NULL,
    normalized_url TEXT NOT NULL,
    dataset_doi TEXT,
    dataset_title TEXT,
    repository TEXT NOT NULL,
    relation_type TEXT NOT NULL DEFAULT 'associated',
    access_status TEXT NOT NULL DEFAULT 'unknown' CHECK(access_status IN ('open', 'public', 'controlled', 'request', 'unknown')),
    license_name TEXT,
    license_url TEXT,
    resource_type TEXT NOT NULL DEFAULT 'Dataset',
    confidence INTEGER NOT NULL DEFAULT 0,
    review_status TEXT NOT NULL DEFAULT 'candidate' CHECK(review_status IN ('candidate', 'verified', 'rejected')),
    discovery_source TEXT NOT NULL,
    topic_tags TEXT NOT NULL DEFAULT '',
    first_seen_at TEXT NOT NULL,
    last_checked_at TEXT,
    http_status INTEGER,
    metadata_json TEXT,
    FOREIGN KEY(publication_id) REFERENCES publications(id) ON DELETE CASCADE,
    UNIQUE(publication_id, normalized_url)
);

CREATE INDEX IF NOT EXISTS idx_publication_datasets_publication ON publication_datasets(publication_id);
CREATE INDEX IF NOT EXISTS idx_publication_datasets_public ON publication_datasets(review_status, access_status, confidence DESC);
CREATE INDEX IF NOT EXISTS idx_publication_datasets_repository ON publication_datasets(repository, review_status);
CREATE INDEX IF NOT EXISTS idx_publication_datasets_doi ON publication_datasets(dataset_doi);

CREATE TABLE IF NOT EXISTS publication_dataset_scans (
    publication_id INTEGER PRIMARY KEY,
    last_scanned_at TEXT NOT NULL,
    source_fingerprint TEXT NOT NULL,
    candidates_found INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY(publication_id) REFERENCES publications(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS openalex_quality_reviews (
    openalex_id TEXT PRIMARY KEY,
    publication_id INTEGER,
    decision TEXT NOT NULL CHECK(decision IN ('approved', 'rejected', 'needs_review')),
    reason TEXT,
    reviewed_at TEXT NOT NULL,
    reviewer TEXT NOT NULL DEFAULT 'system',
    FOREIGN KEY(publication_id) REFERENCES publications(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_openalex_quality_decision ON openalex_quality_reviews(decision, reviewed_at);
CREATE INDEX IF NOT EXISTS idx_openalex_quality_publication ON openalex_quality_reviews(publication_id);

CREATE TABLE IF NOT EXISTS fetch_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    started_at TEXT NOT NULL,
    finished_at TEXT,
    status TEXT NOT NULL,
    source TEXT NOT NULL,
    imported_count INTEGER NOT NULL DEFAULT 0,
    updated_count INTEGER NOT NULL DEFAULT 0,
    skipped_count INTEGER NOT NULL DEFAULT 0,
    error_count INTEGER NOT NULL DEFAULT 0,
    message TEXT
);

CREATE TABLE IF NOT EXISTS fetch_errors (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fetch_run_id INTEGER,
    source TEXT NOT NULL,
    message TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY(fetch_run_id) REFERENCES fetch_runs(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS alert_subscriptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_uuid TEXT UNIQUE,
    email TEXT NOT NULL DEFAULT '[encrypted]',
    email_cipher TEXT,
    email_blind_index TEXT,
    frequency TEXT NOT NULL CHECK(frequency IN ('daily', 'weekly', 'monthly')),
    substances TEXT NOT NULL DEFAULT 'psilocybin,psilocin',
    keywords TEXT,
    author TEXT,
    journal TEXT,
    topic TEXT,
    cited_doi TEXT,
    active INTEGER NOT NULL DEFAULT 0,
    token TEXT NOT NULL DEFAULT '[encrypted]',
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
);

CREATE TABLE IF NOT EXISTS alert_deliveries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    subscription_id INTEGER NOT NULL,
    publication_id INTEGER NOT NULL,
    frequency TEXT NOT NULL,
    generated_at TEXT NOT NULL,
    FOREIGN KEY(subscription_id) REFERENCES alert_subscriptions(id) ON DELETE CASCADE,
    FOREIGN KEY(publication_id) REFERENCES publications(id) ON DELETE CASCADE,
    UNIQUE(subscription_id, publication_id, frequency)
);

CREATE TABLE IF NOT EXISTS push_subscriptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    endpoint TEXT NOT NULL DEFAULT '[encrypted]',
    endpoint_cipher TEXT,
    endpoint_blind_index TEXT UNIQUE,
    p256dh TEXT,
    p256dh_cipher TEXT,
    auth TEXT,
    auth_cipher TEXT,
    user_agent TEXT,
    user_agent_cipher TEXT,
    active INTEGER NOT NULL DEFAULT 1,
    frequency TEXT NOT NULL DEFAULT 'daily' CHECK(frequency IN ('daily', 'weekly', 'monthly')),
    substances TEXT NOT NULL DEFAULT 'psilocybin,psilocin',
    keywords TEXT,
    author TEXT,
    journal TEXT,
    topic TEXT,
    cited_doi TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    last_sent_at TEXT,
    last_checked_at TEXT,
    last_error TEXT
);

-- Aggregate-only product engagement counters. No IP address, user agent,
-- device identifier, or event-level timestamp is stored here.
CREATE TABLE IF NOT EXISTS engagement_daily_metrics (
    metric_date TEXT NOT NULL,
    metric TEXT NOT NULL,
    count INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (metric_date, metric)
);

CREATE TABLE IF NOT EXISTS pwa_funnel_daily (
    metric_date TEXT NOT NULL,
    strategy TEXT NOT NULL,
    platform TEXT NOT NULL,
    surface TEXT NOT NULL,
    metric TEXT NOT NULL,
    count INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (metric_date, strategy, platform, surface, metric)
);
CREATE TABLE IF NOT EXISTS pwa_metric_receipts (
    event_id TEXT PRIMARY KEY,
    received_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_pwa_receipts_received ON pwa_metric_receipts(received_at);

CREATE TABLE IF NOT EXISTS push_notification_batches (
    id TEXT PRIMARY KEY,
    paper_ids TEXT NOT NULL,
    created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS push_test_requests (
    endpoint_blind_index TEXT PRIMARY KEY,
    requested_at INTEGER NOT NULL
);
