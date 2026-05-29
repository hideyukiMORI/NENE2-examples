CREATE TABLE components (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    status     TEXT    NOT NULL DEFAULT 'operational'
                              CHECK (status IN ('operational', 'degraded', 'partial_outage', 'major_outage')),
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE incidents (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    title       TEXT    NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'investigating'
                              CHECK (status IN ('investigating', 'identified', 'monitoring', 'resolved')),
    impact      TEXT    NOT NULL CHECK (impact IN ('none', 'minor', 'major', 'critical')),
    resolved_at TEXT,                          -- server-set on resolve
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);

CREATE TABLE incident_updates (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    incident_id INTEGER NOT NULL REFERENCES incidents(id) ON DELETE CASCADE,
    status      TEXT    NOT NULL,
    message     TEXT    NOT NULL,
    created_at  TEXT    NOT NULL
);
