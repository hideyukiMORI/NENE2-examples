CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'user',
    created_at TEXT NOT NULL,
    CHECK (role IN ('user', 'admin'))
);

CREATE TABLE point_transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    type TEXT NOT NULL,
    amount INTEGER NOT NULL,
    balance_after INTEGER NOT NULL,
    description TEXT NOT NULL,
    reference_id TEXT,
    created_at TEXT NOT NULL,
    CHECK (type IN ('earn', 'spend', 'adjust', 'expire')),
    CHECK (amount > 0),
    CHECK (balance_after >= 0),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
