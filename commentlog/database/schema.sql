CREATE TABLE posts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE comments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id     INTEGER NOT NULL,
    parent_id   INTEGER,
    author_name TEXT    NOT NULL,
    body        TEXT    NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'published',
    depth       INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL,
    FOREIGN KEY (post_id)   REFERENCES posts(id),
    FOREIGN KEY (parent_id) REFERENCES comments(id)
);
