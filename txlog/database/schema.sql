CREATE TABLE IF NOT EXISTS inventory (
    product_id   INTEGER PRIMARY KEY,
    product_name TEXT    NOT NULL,
    stock        INTEGER NOT NULL CHECK (stock >= 0)
);

CREATE TABLE IF NOT EXISTS orders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    status     TEXT    NOT NULL DEFAULT 'placed',
    created_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS order_items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id   INTEGER NOT NULL REFERENCES orders(id),
    product_id INTEGER NOT NULL,
    quantity   INTEGER NOT NULL
);
