CREATE TABLE IF NOT EXISTS uploads (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    original_filename TEXT    NOT NULL,
    stored_filename   TEXT    NOT NULL UNIQUE,
    mime_type         TEXT    NOT NULL,
    size_bytes        INTEGER NOT NULL,
    uploaded_at       TEXT    NOT NULL
);
