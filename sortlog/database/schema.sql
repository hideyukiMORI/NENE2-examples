CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    status     TEXT    NOT NULL DEFAULT 'draft'
                              CHECK (status IN ('draft', 'published', 'archived')),
    created_at TEXT    NOT NULL
);
