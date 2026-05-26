-- Delegated access grants: a grantor gives a grantee time-limited, scoped
-- access to a named resource.  One unique grant per (grantor, grantee, resource).
CREATE TABLE grants (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    grantor_id  INTEGER NOT NULL,
    grantee_id  INTEGER NOT NULL,
    resource    TEXT    NOT NULL,                  -- opaque resource identifier, e.g. "doc:42"
    scope       TEXT    NOT NULL DEFAULT 'read',   -- 'read' | 'write' | 'admin'
    expires_at  TEXT    NOT NULL,                  -- ISO 8601; grant becomes invalid after this
    revoked_at  TEXT,                              -- NULL = active; set on revocation
    used_count  INTEGER NOT NULL DEFAULT 0,        -- how many times the grantee used this grant
    created_at  TEXT    NOT NULL,
    UNIQUE (grantor_id, grantee_id, resource),
    CHECK (scope IN ('read', 'write', 'admin')),
    CHECK (grantor_id != grantee_id)               -- self-grants prohibited at DB level
);
