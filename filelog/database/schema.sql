CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE files (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    size INTEGER NOT NULL DEFAULT 0 CHECK (size >= 0),
    mime_type TEXT NOT NULL,
    description TEXT,
    visibility TEXT NOT NULL DEFAULT 'private' CHECK (visibility IN ('private', 'public')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE file_shares (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    file_id INTEGER NOT NULL,
    shared_with_user_id INTEGER NOT NULL,
    can_edit INTEGER NOT NULL DEFAULT 0 CHECK (can_edit IN (0, 1)),
    created_at TEXT NOT NULL,
    UNIQUE (file_id, shared_with_user_id),
    FOREIGN KEY (file_id) REFERENCES files(id),
    FOREIGN KEY (shared_with_user_id) REFERENCES users(id)
);
