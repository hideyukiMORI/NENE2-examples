# habitlog — Habit Tracker (hardened)

> **FT224** &nbsp;·&nbsp; [NENE2 Examples](https://github.com/hideyukiMORI/NENE2-examples) &nbsp;·&nbsp; [Howto guide ↗](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/habit-tracker.md)

Daily habit tracking with streak calculation and duplicate-completion protection. **Hardened build** — the FT224 howto's ATK review flagged exposures; each is closed here and asserted by a test.

## What the howto's ATK flagged → how this build fixes it

| ATK | Original verdict | Fix here |
|-----|------------------|----------|
| ATK-01 / ATK-02 (no auth / no ownership) | **EXPOSED** | `X-User-Id` ownership; every query `WHERE owner_id = ?` → cross-user `404` |
| ATK-04 (semantically invalid date) | **EXPOSED** | round-trip `!Y-m-d` validation rejects `2026-02-30` (`422`) |
| ATK-08 (unbounded name) | **EXPOSED** | `mb_strlen` ≤ 200 on name (`422`) |
| ATK-10 (`?today=` manipulation) | **PARTIAL** | `today` validated → `422`, never a 500 |
| ATK-06 / 03 / 09 / 11 / 12 | BLOCKED | kept: duplicate `409`, parameterized SQL, `trim` name, existence check, in-memory frequency filter |

## Run

```bash
composer install
composer test        # PHPUnit (13 tests)
composer analyse     # PHPStan level 8
composer cs          # PHP CS Fixer dry-run
```

## Endpoints (all require `X-User-Id`)

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/habits` | Own habits (`?frequency=`) |
| `POST` | `/habits` | Create (`name`≤200, `frequency` daily/weekly/monthly) |
| `GET` | `/habits/{id}` | Get owned habit |
| `DELETE` | `/habits/{id}` | Delete (cascades completions) |
| `POST` | `/habits/{id}/completions` | Record completion (409 on duplicate date) |
| `GET` | `/habits/{id}/completions` | List completions |
| `GET` | `/habits/{id}/streak` | Streak (`?today=YYYY-MM-DD`) |

## Related

- [Howto: Habit Tracker](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/habit-tracker.md)
- [NENE2 framework](https://github.com/hideyukiMORI/NENE2)
