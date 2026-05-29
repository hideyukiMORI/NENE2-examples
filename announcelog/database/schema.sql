CREATE TABLE IF NOT EXISTS announcements (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    priority   INTEGER NOT NULL DEFAULT 0,
    starts_at  TEXT    NOT NULL,   -- ISO 8601 UTC
    ends_at    TEXT    NOT NULL,   -- ISO 8601 UTC, must be > starts_at
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_announcements_window ON announcements (starts_at, ends_at);

CREATE TABLE IF NOT EXISTS announcement_dismissals (
    user_id         INTEGER NOT NULL,
    announcement_id INTEGER NOT NULL,
    dismissed_at    TEXT    NOT NULL,
    PRIMARY KEY (user_id, announcement_id)
);
