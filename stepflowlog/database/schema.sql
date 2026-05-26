CREATE TABLE IF NOT EXISTS workflows (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT NOT NULL UNIQUE,
    description TEXT NOT NULL DEFAULT '',
    created_at  TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS workflow_steps (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    workflow_id INTEGER NOT NULL REFERENCES workflows(id) ON DELETE CASCADE,
    name        TEXT NOT NULL,
    step_order  INTEGER NOT NULL,
    UNIQUE(workflow_id, step_order)
);

CREATE TABLE IF NOT EXISTS workflow_runs (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    workflow_id     INTEGER NOT NULL REFERENCES workflows(id),
    title           TEXT NOT NULL,
    status          TEXT NOT NULL DEFAULT 'pending'
                        CHECK(status IN ('pending', 'in_progress', 'completed', 'rejected')),
    current_step_id INTEGER REFERENCES workflow_steps(id),
    created_at      TEXT NOT NULL,
    updated_at      TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS workflow_actions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id     INTEGER NOT NULL REFERENCES workflow_runs(id) ON DELETE CASCADE,
    step_id    INTEGER NOT NULL REFERENCES workflow_steps(id),
    action     TEXT NOT NULL CHECK(action IN ('approve', 'reject')),
    actor      TEXT NOT NULL,
    comment    TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
);
