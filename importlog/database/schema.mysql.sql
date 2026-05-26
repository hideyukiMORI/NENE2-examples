CREATE TABLE import_jobs (
    id            INT          NOT NULL AUTO_INCREMENT,
    filename      VARCHAR(255) NOT NULL,
    status        VARCHAR(20)  NOT NULL DEFAULT 'completed',
    total_rows    INT          NOT NULL DEFAULT 0,
    imported_rows INT          NOT NULL DEFAULT 0,
    failed_rows   INT          NOT NULL DEFAULT 0,
    errors        TEXT         NOT NULL,
    created_at    DATETIME     NOT NULL,
    completed_at  DATETIME,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE imported_records (
    id            INT          NOT NULL AUTO_INCREMENT,
    import_job_id INT          NOT NULL,
    name          VARCHAR(255) NOT NULL,
    email         VARCHAR(255) NOT NULL,
    age           INT,
    created_at    DATETIME     NOT NULL,
    PRIMARY KEY (id),
    FOREIGN KEY (import_job_id) REFERENCES import_jobs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
