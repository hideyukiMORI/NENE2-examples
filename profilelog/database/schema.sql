CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    email      TEXT    NOT NULL UNIQUE,
    created_at TEXT    NOT NULL
);

CREATE TABLE profiles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL UNIQUE,
    display_name TEXT  NOT NULL DEFAULT '',
    bio        TEXT    NOT NULL DEFAULT '',
    avatar_url TEXT    NOT NULL DEFAULT '',
    updated_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
