CREATE TABLE items (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    name        TEXT    NOT NULL,
    quantity    INTEGER NOT NULL CHECK (quantity >= 1),
    price_cents INTEGER NOT NULL CHECK (price_cents >= 0),
    created_at  TEXT    NOT NULL
);
CREATE INDEX idx_items_user ON items (user_id);
