CREATE TABLE documents (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_id   INTEGER NOT NULL,
    title      TEXT    NOT NULL,
    status     TEXT    NOT NULL DEFAULT 'draft'
                              CHECK (status IN ('draft', 'published', 'archived')),
    version    INTEGER NOT NULL DEFAULT 1,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
