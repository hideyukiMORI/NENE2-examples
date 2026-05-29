# shiftlog — Employee Shift Scheduling (hardened)

> **FT43** &nbsp;·&nbsp; [NENE2 Examples](https://github.com/hideyukiMORI/NENE2-examples) &nbsp;·&nbsp; [Howto guide ↗](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/shift-management.md)

Employee shift scheduling with transactional overlap detection and hour summaries. **Hardened build** — the FT225 howto's VULN review flagged exposures; each is closed and tested.

## What the howto's VULN flagged → how this build fixes it

| V | Original verdict | Fix here |
|---|------------------|----------|
| V-01 / V-02 (no auth/authz) | **EXPOSED** | employee/shift mutations require `X-Admin-Key` (`hash_equals`, fail-closed) |
| V-06 (negative `hourly_rate` → 500) | **PARTIAL** | app-layer `is_int() && > 0` (`422`) |
| V-07 (semantically invalid datetime) | **EXPOSED** | strict ISO-8601-UTC round-trip on `starts_at`/`ends_at` (`422`) |
| V-08 (unbounded date range) | **EXPOSED** | aggregate/window endpoints cap the range to 90 days (`422`) |
| V-09 / V-10 (unbounded name/location) | **EXPOSED** | `mb_strlen` ≤ 100 / ≤ 200 (`422`) |
| V-04 / V-05 / V-12 | BLOCKED | kept: transactional overlap check, `ends_at > starts_at`, `ctype_digit` ids |

## Run

```bash
composer install
composer test        # PHPUnit (14 tests)
composer analyse     # PHPStan level 8
composer cs          # PHP CS Fixer dry-run
```

## Endpoints

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `GET` | `/employees` / `/employees/{id}` | — | List / get |
| `POST` | `/employees` | `X-Admin-Key` | Create employee |
| `GET` | `/employees/{id}/shifts` | — | Employee's shifts |
| `POST` | `/shifts` | `X-Admin-Key` | Schedule (409 on overlap) |
| `GET` | `/shifts/{id}` | — | Get shift |
| `DELETE` | `/shifts/{id}` | `X-Admin-Key` | Delete shift |
| `GET` | `/schedule?from=&to=` | — | Shifts in a ≤90-day window |
| `GET` | `/summary/hours?from=&to=&threshold=` | — | Hours per employee (optional overtime threshold) |

## Related

- [Howto: Shift Management](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/shift-management.md)
- [Howto: Prevent double booking](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/prevent-double-booking.md)
- [NENE2 framework](https://github.com/hideyukiMORI/NENE2)
