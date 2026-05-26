CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE leaderboards (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE scores (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    leaderboard_id INTEGER NOT NULL,
    user_id        INTEGER NOT NULL,
    score          INTEGER NOT NULL,
    submitted_at   TEXT    NOT NULL,
    UNIQUE (leaderboard_id, user_id),
    FOREIGN KEY (leaderboard_id) REFERENCES leaderboards(id),
    FOREIGN KEY (user_id)        REFERENCES users(id)
);
