CREATE TABLE sessions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    token       TEXT    NOT NULL UNIQUE,   -- 64 hex chars, 256-bit CSPRNG
    device_name TEXT    NOT NULL DEFAULT '',
    ip_address  TEXT    NOT NULL DEFAULT '',
    revoked_at  TEXT,                       -- NULL = active
    created_at  TEXT    NOT NULL
);
CREATE INDEX idx_sessions_user ON sessions (user_id);
