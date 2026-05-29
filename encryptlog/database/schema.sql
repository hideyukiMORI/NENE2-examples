CREATE TABLE records (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    email_enc  TEXT    NOT NULL,   -- base64(nonce[12] ‖ ciphertext ‖ tag[16])
    email_idx  TEXT    NOT NULL,   -- HMAC-SHA256(email, indexKey) — searchable, no decrypt
    note_enc   TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
CREATE INDEX idx_records_user ON records (user_id);
CREATE INDEX idx_records_email ON records (user_id, email_idx);
