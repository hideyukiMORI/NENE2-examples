CREATE TABLE feature_flags (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    name             TEXT    NOT NULL UNIQUE,
    description      TEXT    NOT NULL DEFAULT '',
    globally_enabled INTEGER NOT NULL DEFAULT 0,
    rollout_pct      INTEGER NOT NULL DEFAULT 0,
    created_at       TEXT    NOT NULL
);

CREATE TABLE flag_targets (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    flag_id     INTEGER NOT NULL,
    target_type TEXT    NOT NULL,
    target_id   TEXT    NOT NULL,
    enabled     INTEGER NOT NULL DEFAULT 1,
    UNIQUE (flag_id, target_type, target_id),
    FOREIGN KEY (flag_id) REFERENCES feature_flags(id)
);
