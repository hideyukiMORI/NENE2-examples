# walletlog — Multi-Currency Wallet

> **FT198** &nbsp;·&nbsp; [NENE2 Examples](https://github.com/hideyukiMORI/NENE2-examples) &nbsp;·&nbsp; [Howto guide ↗](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/multi-currency-wallet.md)

Per-user, per-currency balances with deposit / withdraw / transfer. Integer minor units (no floats), atomic transactional transfer, IDOR-safe ledger.

## Highlights

- **Integer minor units** — balances are stored as cents (`INTEGER`), never floats, so no rounding drift.
- **Atomic transfer** — debit + credit run inside `transactional()`; insufficient funds throw and **roll the whole transfer back** (verified: recipient gets nothing, sender unchanged).
- **Atomic withdraw/debit** — `UPDATE … WHERE balance >= ?`; 0 rows affected → `409`, no overdraft (`CHECK(balance >= 0)` backs it up).
- **Self-transfer rejected** before any DB work (`422`).
- **IDOR** — every balance / ledger query is `WHERE user_id = ?`; a user's transactions never include another user's rows.
- **Currency allow-list** — server-side (`USD`/`EUR`/`JPY`), never trusts the request.

## Run

```bash
composer install
composer test        # PHPUnit (12 tests)
composer analyse     # PHPStan level 8
composer cs          # PHP CS Fixer dry-run
```

## Endpoints

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `GET` | `/wallet` | `X-User-Id` | Own balances per currency |
| `GET` | `/wallet/transactions` | `X-User-Id` | Own ledger |
| `POST` | `/wallet/deposit` | `X-User-Id` | `{ currency, amount }` |
| `POST` | `/wallet/withdraw` | `X-User-Id` | `{ currency, amount }` (409 if insufficient) |
| `POST` | `/wallet/transfer` | `X-User-Id` | `{ to_user_id, currency, amount }` (422 self, 409 insufficient) |

## Contents

| Path | Description |
|------|-------------|
| `src/Wallet/WalletService.php` | Atomic transactional transfer (the core pattern) |
| `src/Wallet/WalletRepository.php` | Atomic credit/debit, append-only ledger |
| `src/Wallet/RouteRegistrar.php` | Handlers, currency allow-list, self-transfer guard |
| `database/schema.sql` | `wallets` (UNIQUE per user+currency) + `wallet_transactions` |
| `tests/` | Deposit/withdraw, transfer rollback, self-transfer, per-user ledger isolation |

## Related

- [Howto: Multi-Currency Wallet](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/multi-currency-wallet.md)
- [Howto: Use database transactions](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/use-transactions.md)
- [NENE2 framework](https://github.com/hideyukiMORI/NENE2)
