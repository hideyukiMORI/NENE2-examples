CREATE TABLE habits (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_id    INTEGER NOT NULL,
    name        TEXT    NOT NULL,
    description TEXT    NOT NULL DEFAULT '',
    frequency   TEXT    NOT NULL CHECK (frequency IN ('daily', 'weekly', 'monthly')),
    created_at  TEXT    NOT NULL
);
CREATE INDEX idx_habits_owner ON habits (owner_id);

CREATE TABLE completions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    habit_id     INTEGER NOT NULL REFERENCES habits(id) ON DELETE CASCADE,
    completed_on TEXT    NOT NULL,
    note         TEXT    NOT NULL DEFAULT '',
    UNIQUE (habit_id, completed_on)   -- one completion per day → duplicate = 409
);
