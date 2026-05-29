-- Accounts are scoped to an owner (X-User-Id) — closes the IDOR holes the
-- FT244 howto's ATK flagged (ATK-01 / ATK-10).
CREATE TABLE accounts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_id   INTEGER NOT NULL,
    name       TEXT    NOT NULL,
    balance    INTEGER NOT NULL DEFAULT 0 CHECK (balance >= 0),
    created_at TEXT    NOT NULL
);

CREATE TABLE transactions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id  INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    amount      INTEGER NOT NULL CHECK (amount > 0),
    type        TEXT    NOT NULL CHECK (type IN ('income', 'expense', 'transfer')),
    category    TEXT    NOT NULL,
    description TEXT    NOT NULL DEFAULT '',
    recurring   INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL
);
