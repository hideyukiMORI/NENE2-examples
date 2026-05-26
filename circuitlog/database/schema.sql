CREATE TABLE IF NOT EXISTS circuits (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    name             TEXT    NOT NULL UNIQUE,
    state            TEXT    NOT NULL DEFAULT 'closed',
    failure_count    INTEGER NOT NULL DEFAULT 0,
    failure_threshold INTEGER NOT NULL DEFAULT 5,
    open_until       TEXT,
    half_open_at     TEXT,
    last_failure_at  TEXT,
    updated_at       TEXT    NOT NULL
);
