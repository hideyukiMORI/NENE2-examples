CREATE TABLE IF NOT EXISTS webhook_sources (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL UNIQUE,
    secret     TEXT    NOT NULL,
    active     INTEGER NOT NULL DEFAULT 1,
    created_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS inbound_events (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    source_id    INTEGER NOT NULL REFERENCES webhook_sources(id),
    event_id     TEXT    NOT NULL,
    event_type   TEXT    NOT NULL,
    payload      TEXT    NOT NULL,
    processed_at TEXT    NOT NULL,
    UNIQUE(source_id, event_id)
);
