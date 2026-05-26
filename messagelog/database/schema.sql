CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE conversations (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    initiator_id INTEGER NOT NULL,
    recipient_id INTEGER NOT NULL,
    created_at   TEXT    NOT NULL,
    UNIQUE (initiator_id, recipient_id),
    CHECK (initiator_id != recipient_id),
    FOREIGN KEY (initiator_id) REFERENCES users(id),
    FOREIGN KEY (recipient_id) REFERENCES users(id)
);

CREATE TABLE messages (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    conversation_id INTEGER NOT NULL,
    sender_id       INTEGER NOT NULL,
    content         TEXT    NOT NULL,
    created_at      TEXT    NOT NULL,
    FOREIGN KEY (conversation_id) REFERENCES conversations(id),
    FOREIGN KEY (sender_id)       REFERENCES users(id)
);
