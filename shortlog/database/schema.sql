CREATE TABLE links (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    slug        TEXT    NOT NULL UNIQUE,
    url         TEXT    NOT NULL,
    click_count INTEGER NOT NULL DEFAULT 0,   -- server-managed; never from request body
    created_at  TEXT    NOT NULL
);
CREATE INDEX idx_links_user ON links (user_id);
