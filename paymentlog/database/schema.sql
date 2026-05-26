CREATE TABLE payments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    external_id TEXT    NOT NULL UNIQUE,
    amount      INTEGER NOT NULL,
    currency    TEXT    NOT NULL DEFAULT 'usd',
    status      TEXT    NOT NULL DEFAULT 'pending'
                        CHECK (status IN ('pending', 'succeeded', 'failed', 'refunded')),
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);

CREATE TABLE webhook_events (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id     TEXT    NOT NULL UNIQUE,
    event_type   TEXT    NOT NULL,
    payload      TEXT    NOT NULL,
    processed_at TEXT    NOT NULL
);
