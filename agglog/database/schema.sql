CREATE TABLE IF NOT EXISTS orders (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id TEXT    NOT NULL,
    item_name   TEXT    NOT NULL,
    amount      INTEGER NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'pending'
                    CHECK(status IN ('pending', 'completed', 'refunded', 'cancelled')),
    created_at  TEXT    NOT NULL
);
