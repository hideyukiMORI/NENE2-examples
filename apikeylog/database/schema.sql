CREATE TABLE IF NOT EXISTS api_keys (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_id    INTEGER NOT NULL,
    prefix      TEXT    NOT NULL,
    key_hash    TEXT    NOT NULL UNIQUE,
    scope       TEXT    NOT NULL DEFAULT 'read',
    description TEXT    NOT NULL DEFAULT '',
    expires_at  TEXT,
    revoked_at  TEXT,
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS owners (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);
