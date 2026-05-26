-- FT171: Hierarchical Data (Materialized Path)
CREATE TABLE categories (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    parent_id  INTEGER,
    path       TEXT    NOT NULL UNIQUE, -- e.g. "/1/", "/1/3/", "/1/3/7/"
    depth      INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (parent_id) REFERENCES categories(id)
);
