CREATE TABLE events (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    capacity   INTEGER NOT NULL CHECK (capacity >= 0),
    created_at TEXT    NOT NULL
);

CREATE TABLE tickets (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id   INTEGER NOT NULL,
    user_id    INTEGER NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE (event_id, user_id),                 -- one ticket per user per event
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
);
