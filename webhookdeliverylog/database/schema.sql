CREATE TABLE IF NOT EXISTS webhook_endpoints (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    url         TEXT    NOT NULL,
    event_type  TEXT    NOT NULL,
    secret_hash TEXT    NOT NULL,
    max_retries INTEGER NOT NULL DEFAULT 3,
    active      INTEGER NOT NULL DEFAULT 1,
    created_at  TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS webhook_deliveries (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    endpoint_id   INTEGER NOT NULL REFERENCES webhook_endpoints(id),
    event_type    TEXT    NOT NULL,
    payload       TEXT    NOT NULL DEFAULT '{}',
    status        TEXT    NOT NULL DEFAULT 'pending',
    attempt_count INTEGER NOT NULL DEFAULT 0,
    last_status   INTEGER,
    last_error    TEXT,
    delivered_at  TEXT,
    created_at    TEXT    NOT NULL,
    updated_at    TEXT    NOT NULL
);
