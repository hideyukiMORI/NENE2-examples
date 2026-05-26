CREATE TABLE IF NOT EXISTS experiments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT NOT NULL UNIQUE,
    description TEXT NOT NULL DEFAULT '',
    status      TEXT NOT NULL DEFAULT 'draft'
                    CHECK(status IN ('draft', 'active', 'stopped')),
    created_at  TEXT NOT NULL,
    updated_at  TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS experiment_variants (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    experiment_id INTEGER NOT NULL REFERENCES experiments(id) ON DELETE CASCADE,
    name          TEXT NOT NULL,
    weight        INTEGER NOT NULL DEFAULT 100,
    UNIQUE(experiment_id, name)
);

CREATE TABLE IF NOT EXISTS experiment_assignments (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    experiment_id INTEGER NOT NULL REFERENCES experiments(id) ON DELETE CASCADE,
    user_id       TEXT NOT NULL,
    variant_id    INTEGER NOT NULL REFERENCES experiment_variants(id),
    assigned_at   TEXT NOT NULL,
    UNIQUE(experiment_id, user_id)
);

CREATE TABLE IF NOT EXISTS experiment_events (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    experiment_id INTEGER NOT NULL REFERENCES experiments(id) ON DELETE CASCADE,
    assignment_id INTEGER NOT NULL REFERENCES experiment_assignments(id),
    event_type    TEXT NOT NULL,
    created_at    TEXT NOT NULL
);
