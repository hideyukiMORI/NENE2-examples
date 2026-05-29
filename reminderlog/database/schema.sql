CREATE TABLE reminders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    title      TEXT    NOT NULL,
    remind_at  TEXT    NOT NULL,   -- ISO 8601 with explicit ±HH:MM offset
    status     TEXT    NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'cancelled')),
    created_at TEXT    NOT NULL
);
CREATE INDEX idx_reminders_user ON reminders (user_id, remind_at);
