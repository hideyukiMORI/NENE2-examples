CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE boards (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (owner_id) REFERENCES users(id)
);

-- A board's items carry an integer `position`. UNIQUE(board_id, position)
-- enforces a gap-free, collision-free ordering at the database level — which
-- is exactly what makes a naive row-by-row reorder fail (see ReorderService).
CREATE TABLE items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    board_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    position INTEGER NOT NULL,
    UNIQUE (board_id, position),
    FOREIGN KEY (board_id) REFERENCES boards(id)
);
