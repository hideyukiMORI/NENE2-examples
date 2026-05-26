-- Per-user quota configuration
CREATE TABLE quotas (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL UNIQUE,
    daily_limit INTEGER NOT NULL DEFAULT 1000,  -- max API calls per day
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL,
    CHECK (daily_limit > 0)
);

-- Append-only usage events
CREATE TABLE usage_events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    endpoint    TEXT    NOT NULL,   -- e.g. "GET /articles"
    day_key     TEXT    NOT NULL,   -- YYYY-MM-DD for efficient date grouping
    recorded_at TEXT    NOT NULL    -- ISO 8601
);

CREATE INDEX idx_usage_user_day ON usage_events (user_id, day_key);
