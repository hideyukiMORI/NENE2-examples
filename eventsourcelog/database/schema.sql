CREATE TABLE accounts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    owner      TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE events (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    aggregate_id INTEGER NOT NULL,
    event_type   TEXT    NOT NULL,
    payload      TEXT    NOT NULL,
    occurred_at  TEXT    NOT NULL,
    FOREIGN KEY (aggregate_id) REFERENCES accounts(id)
);
