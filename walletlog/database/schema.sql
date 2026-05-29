-- Balances are stored as integer minor units (e.g. cents) to avoid float errors.
CREATE TABLE wallets (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    currency   TEXT    NOT NULL,
    balance    INTEGER NOT NULL DEFAULT 0 CHECK (balance >= 0),
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    UNIQUE (user_id, currency)
);

-- Append-only ledger. amount is signed: positive = credit, negative = debit.
CREATE TABLE wallet_transactions (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         INTEGER NOT NULL,
    currency        TEXT    NOT NULL,
    amount          INTEGER NOT NULL,
    type            TEXT    NOT NULL,   -- deposit | withdraw | transfer_in | transfer_out
    counterparty_id INTEGER,            -- the other user, for transfers
    created_at      TEXT    NOT NULL
);
