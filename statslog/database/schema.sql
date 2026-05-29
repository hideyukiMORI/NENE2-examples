CREATE TABLE IF NOT EXISTS events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type  TEXT    NOT NULL,
    user_id     TEXT    NOT NULL,            -- free-form (UUID / opaque / session token)
    session_id  TEXT    NOT NULL DEFAULT '',
    properties  TEXT    NOT NULL DEFAULT '{}',
    occurred_at TEXT    NOT NULL             -- ISO 8601 UTC
);

CREATE INDEX IF NOT EXISTS idx_events_type     ON events (event_type);
CREATE INDEX IF NOT EXISTS idx_events_occurred ON events (occurred_at);
CREATE INDEX IF NOT EXISTS idx_events_user     ON events (user_id);
