CREATE TABLE vault_entries (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    key_name   TEXT    NOT NULL,
    value      TEXT    NOT NULL,
    hmac       TEXT    NOT NULL,   -- HMAC-SHA256(userId|key|value) integrity tag
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    UNIQUE (user_id, key_name)     -- one entry per (user, key); enables upsert
);
