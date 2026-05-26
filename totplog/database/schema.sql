CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE totp_secrets (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         INTEGER NOT NULL UNIQUE,
    secret          TEXT    NOT NULL,
    is_enabled      INTEGER NOT NULL DEFAULT 0 CHECK (is_enabled IN (0, 1)),
    failed_attempts INTEGER NOT NULL DEFAULT 0,
    locked_until    TEXT,
    created_at      TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE used_totp_steps (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    time_step  INTEGER NOT NULL,
    used_at    TEXT    NOT NULL,
    UNIQUE (user_id, time_step),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
