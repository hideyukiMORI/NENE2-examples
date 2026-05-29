CREATE TABLE IF NOT EXISTS waitlist_entries (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL UNIQUE,            -- one entry per user
    status     TEXT    NOT NULL DEFAULT 'waiting',  -- waiting | approved | declined
    note       TEXT,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    CHECK (status IN ('waiting', 'approved', 'declined'))
);

CREATE INDEX IF NOT EXISTS idx_waitlist_status ON waitlist_entries (status, id);
