CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE user_preferences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    pref_key TEXT NOT NULL,
    pref_value TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (user_id, pref_key),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
