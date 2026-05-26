CREATE TABLE IF NOT EXISTS articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    author     TEXT    NOT NULL,
    category   TEXT    NOT NULL DEFAULT 'general',
    body       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_articles_id_desc ON articles (id DESC);
CREATE INDEX IF NOT EXISTS idx_articles_category ON articles (category, id DESC);
