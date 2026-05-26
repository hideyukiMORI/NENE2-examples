CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE products (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE flash_sales (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    price      INTEGER NOT NULL,
    quantity   INTEGER NOT NULL,
    starts_at  TEXT    NOT NULL,
    ends_at    TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    CHECK (quantity > 0),
    CHECK (price >= 0),
    FOREIGN KEY (product_id) REFERENCES products(id)
);

CREATE TABLE purchases (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    sale_id    INTEGER NOT NULL,
    user_id    INTEGER NOT NULL,
    purchased_at TEXT  NOT NULL,
    UNIQUE (sale_id, user_id),
    FOREIGN KEY (sale_id) REFERENCES flash_sales(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
