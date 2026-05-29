CREATE TABLE IF NOT EXISTS tasks (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,                 -- owner (hardening over the FT85 demo)
    title      TEXT    NOT NULL,
    status     TEXT    NOT NULL DEFAULT 'pending'
                       CHECK (status IN ('pending', 'in_progress', 'done', 'cancelled')),
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_tasks_user ON tasks (user_id);
