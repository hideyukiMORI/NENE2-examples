# budgetlog — Budget / Account Management (hardened)

> **FT244** &nbsp;·&nbsp; [NENE2 Examples](https://github.com/hideyukiMORI/NENE2-examples) &nbsp;·&nbsp; [Howto guide ↗](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/budget-tracking.md)

Multi-account budgeting with income / expense / transfer. **This example is the *hardened* build** — the FT244 howto's ATK review found real holes in the original; here every one of them is closed and proven by a test.

## What the howto's ATK flagged → how this build fixes it

| ATK | Original verdict | Fix here |
|-----|------------------|----------|
| ATK-01 / ATK-10 (no auth, cross-account) | **EXPOSED** | `X-User-Id` ownership; every query is `WHERE owner_id = ?` → other users get `404` |
| ATK-03 / ATK-09 (expense → negative balance, race) | **EXPOSED** | atomic `UPDATE … WHERE balance >= ?` inside `transactional()` → `422`, never negative |
| ATK-05 (float amount truncated) | **PARTIAL** | strict `is_int()` → `422` |
| ATK-11 (`recurring` coercion) | **PARTIAL** | strict `is_bool()` → `422` |
| ATK-12 (non-numeric id) | **PARTIAL** | `ctype_digit()` path ids → `404` |
| ATK-02 / 06 / 07 / 08 | BLOCKED | kept: negative-balance / zero-amount / same-account / insufficient-transfer guards |

`type='transfer'` is internal-only — it cannot be injected through `POST /accounts/{id}/transactions`.

## Run

```bash
composer install
composer test        # PHPUnit (16 tests)
composer analyse     # PHPStan level 8
composer cs          # PHP CS Fixer dry-run
```

## Endpoints (all require `X-User-Id`)

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/accounts` | Own accounts |
| `POST` | `/accounts` | Create (`name`, `initial_balance>=0`) |
| `GET` | `/accounts/{id}` | Get owned account |
| `POST` | `/accounts/{id}/transactions` | Income / expense (expense 422 if insufficient) |
| `GET` | `/accounts/{id}/transactions` | List (`?category=&min_amount=&max_amount=&recurring=`) |
| `GET` | `/accounts/{id}/summary` | Balance + income/expense by category |
| `POST` | `/transfers` | Transfer between own accounts (atomic) |

## Related

- [Howto: Budget Tracking](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/budget-tracking.md)
- [Howto: Use database transactions](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/use-transactions.md)
- [NENE2 framework](https://github.com/hideyukiMORI/NENE2)
