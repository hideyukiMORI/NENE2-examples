CREATE TABLE verifications (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    contact        TEXT    NOT NULL,
    code_hash      TEXT    NOT NULL,   -- sha256 of the 6-digit code; never the plaintext
    attempts_count INTEGER NOT NULL DEFAULT 0,
    verified_at    TEXT,               -- set on success; replay → 410
    expires_at     TEXT    NOT NULL,
    created_at     TEXT    NOT NULL
);
