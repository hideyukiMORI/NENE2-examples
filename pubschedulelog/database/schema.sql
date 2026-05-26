CREATE TABLE articles (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    author_id    INTEGER NOT NULL,
    title        TEXT    NOT NULL,
    body         TEXT    NOT NULL,
    status       TEXT    NOT NULL DEFAULT 'draft',
    -- 'draft' | 'scheduled' | 'published' | 'archived'
    publish_at   TEXT,    -- ISO 8601; set when scheduled; NULL otherwise
    published_at TEXT,    -- set when published (immediate or scheduled trigger)
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL
);
