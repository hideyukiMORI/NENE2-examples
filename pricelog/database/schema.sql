CREATE TABLE products (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE price_tiers (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id     INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    amount         INTEGER NOT NULL CHECK (amount >= 0),   -- cents
    currency       TEXT    NOT NULL DEFAULT 'USD',
    effective_from TEXT    NOT NULL,                         -- ISO 8601 UTC (…Z)
    effective_to   TEXT,                                     -- NULL = open / current
    created_at     TEXT    NOT NULL
);
CREATE INDEX idx_price_tiers_product ON price_tiers (product_id, effective_from);
