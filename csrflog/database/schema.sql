CREATE TABLE IF NOT EXISTS orders (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    idempotency_key  TEXT    NOT NULL UNIQUE,
    item             TEXT    NOT NULL,
    quantity         INTEGER NOT NULL,
    total_price      REAL    NOT NULL,
    status           TEXT    NOT NULL DEFAULT 'pending',
    created_at       TEXT    NOT NULL
);
