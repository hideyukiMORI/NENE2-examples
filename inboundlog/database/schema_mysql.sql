CREATE TABLE IF NOT EXISTS webhook_sources (
    id         INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(191)    NOT NULL UNIQUE,
    secret     VARCHAR(255)    NOT NULL,
    active     TINYINT(1)      NOT NULL DEFAULT 1,
    created_at VARCHAR(32)     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inbound_events (
    id           INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
    source_id    INT UNSIGNED    NOT NULL,
    event_id     VARCHAR(191)    NOT NULL,
    event_type   VARCHAR(191)    NOT NULL,
    payload      TEXT            NOT NULL,
    processed_at VARCHAR(32)     NOT NULL,
    UNIQUE KEY uq_source_event (source_id, event_id),
    CONSTRAINT fk_inbound_source FOREIGN KEY (source_id) REFERENCES webhook_sources(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
