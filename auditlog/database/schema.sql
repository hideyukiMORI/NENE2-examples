CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT    NOT NULL UNIQUE,
    password_hash TEXT    NOT NULL,
    created_at    TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS tasks (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    status     TEXT    NOT NULL DEFAULT 'open',
    actor_id   INTEGER NOT NULL REFERENCES users(id),
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

-- No FK constraints on actor_id/resource_id: audit records must survive
-- the deletion of the subjects they describe.
CREATE TABLE IF NOT EXISTS audit_log (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_id      INTEGER NOT NULL,
    action        TEXT    NOT NULL,
    resource_type TEXT    NOT NULL,
    resource_id   INTEGER NOT NULL,
    occurred_at   TEXT    NOT NULL,
    payload       TEXT    NOT NULL DEFAULT '{}'
);
