CREATE TABLE secrets (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id       INTEGER NOT NULL,
    token         TEXT    NOT NULL UNIQUE,   -- 64 hex chars, 256-bit CSPRNG
    message       TEXT    NOT NULL,
    password_hash TEXT,                       -- NULL = no password
    expires_at    TEXT,                       -- NULL = never expires (ISO 8601)
    consumed      INTEGER NOT NULL DEFAULT 0, -- server-managed; never from body
    created_at    TEXT    NOT NULL
);
CREATE INDEX idx_secrets_user ON secrets (user_id);
