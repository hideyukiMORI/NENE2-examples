# expenselog — Expense Tracker

> **FT223** &nbsp;·&nbsp; [NENE2 Examples](https://github.com/hideyukiMORI/NENE2-examples) &nbsp;·&nbsp; [Howto guide ↗](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/expense-tracker.md)

Personal expense tracking: CRUD, category + date-range filtering, monthly summary aggregation, and pagination.

## Highlights

- **Canonical date validation** — round-trip `!Y-m-d` parse rejects `2026-5-1` and `2026-13-40` (`422`).
- **Integer amounts (cents)** — `is_int() && > 0`; floats / zero / negatives rejected.
- **Category allow-list** — strict `in_array`, blocks typos and injection.
- **Date-range + category filter** — `?from=&to=&category=`; ISO dates compare lexicographically.
- **Monthly summary** — `strftime('%Y-%m', date)` grouped per category, totals + counts.
- **`PATCH`** — `array_key_exists` merge; omitted fields preserved.

## Run

```bash
composer install
composer test        # PHPUnit (13 tests)
composer analyse     # PHPStan level 8
composer cs          # PHP CS Fixer dry-run
```

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/expenses` | List (`?from=&to=&category=`, paginated) |
| `POST` | `/expenses` | Create |
| `GET` | `/expenses/summary` | Monthly totals by category |
| `GET` | `/expenses/{id}` | Get |
| `PATCH` | `/expenses/{id}` | Partial update |
| `DELETE` | `/expenses/{id}` | Delete |

## Related

- [Howto: Expense Tracker](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/expense-tracker.md)
- [NENE2 framework](https://github.com/hideyukiMORI/NENE2)
