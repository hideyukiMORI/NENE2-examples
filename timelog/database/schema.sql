CREATE TABLE IF NOT EXISTS time_entries (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    label      TEXT NOT NULL,
    start_time TEXT NOT NULL,
    end_time   TEXT,              -- NULL = running
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_time_entries_running ON time_entries (end_time);
