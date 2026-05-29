CREATE TABLE IF NOT EXISTS messages (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    queue       TEXT    NOT NULL DEFAULT 'default',
    payload     TEXT    NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'pending',
    retry_count INTEGER NOT NULL DEFAULT 0,
    max_retries INTEGER NOT NULL DEFAULT 3,
    retry_after TEXT,           -- NULL when not scheduled for retry
    last_error  TEXT,           -- NULL until first failure
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL,
    CHECK (status IN ('pending', 'processing', 'succeeded', 'dead'))
);

CREATE INDEX IF NOT EXISTS idx_messages_claim ON messages (queue, status, retry_after, created_at);
