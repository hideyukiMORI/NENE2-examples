CREATE TABLE watch_entries (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    title       TEXT    NOT NULL,
    media_type  TEXT    NOT NULL CHECK (media_type IN ('movie', 'tv')),
    status      TEXT    NOT NULL DEFAULT 'want-to-watch'
                              CHECK (status IN ('want-to-watch', 'watching', 'completed', 'dropped')),
    rating      INTEGER CHECK (rating IS NULL OR (rating >= 1 AND rating <= 5)),
    note        TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL,
    archived_at TEXT                        -- NULL = active, non-null = archived
);
CREATE INDEX idx_watch_status ON watch_entries (status);
CREATE INDEX idx_watch_archived_at ON watch_entries (archived_at);
