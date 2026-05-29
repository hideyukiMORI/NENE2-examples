CREATE TABLE expenses (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    date       TEXT    NOT NULL,            -- ISO 8601 YYYY-MM-DD (lexicographically sortable)
    amount     INTEGER NOT NULL CHECK (amount > 0),  -- minor units (cents)
    category   TEXT    NOT NULL,
    note       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE INDEX idx_expenses_date ON expenses (date DESC);
CREATE INDEX idx_expenses_category ON expenses (category, date DESC);
