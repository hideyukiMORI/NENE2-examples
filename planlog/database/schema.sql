CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE plans (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    slug       TEXT    NOT NULL UNIQUE,
    name       TEXT    NOT NULL,
    price_cents INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE subscriptions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL UNIQUE,
    plan_id      INTEGER NOT NULL,
    status       TEXT    NOT NULL DEFAULT 'active',
    started_at   TEXT    NOT NULL,
    cancelled_at TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (plan_id) REFERENCES plans(id),
    CHECK (status IN ('active', 'cancelled'))
);

-- Seed plans
INSERT INTO plans (slug, name, price_cents) VALUES ('free', 'Free', 0);
INSERT INTO plans (slug, name, price_cents) VALUES ('pro', 'Pro', 999);
INSERT INTO plans (slug, name, price_cents) VALUES ('enterprise', 'Enterprise', 4999);
