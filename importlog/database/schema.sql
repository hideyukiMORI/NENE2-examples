CREATE TABLE import_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    filename TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'completed',
    total_rows INTEGER NOT NULL DEFAULT 0,
    imported_rows INTEGER NOT NULL DEFAULT 0,
    failed_rows INTEGER NOT NULL DEFAULT 0,
    errors TEXT NOT NULL DEFAULT '[]',
    created_at TEXT NOT NULL,
    completed_at TEXT
);

CREATE TABLE imported_records (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    import_job_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    email TEXT NOT NULL,
    age INTEGER,
    created_at TEXT NOT NULL,
    FOREIGN KEY (import_job_id) REFERENCES import_jobs(id)
);
