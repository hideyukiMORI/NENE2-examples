CREATE TABLE tenants (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id  INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    user_id    INTEGER NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL
);
CREATE INDEX idx_notes_tenant ON notes (tenant_id);
