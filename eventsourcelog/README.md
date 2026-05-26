# eventsourcelog — Event Sourcing

> **FT129** &nbsp;·&nbsp; [NENE2 Examples](https://github.com/hideyukiMORI/NENE2-examples) &nbsp;·&nbsp; [Howto guide ↗](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/event-sourcing.md)

Append-only event log, aggregate replay via ORDER BY id ASC, integer overflow guard.

## Run

```bash
composer install
composer test        # PHPUnit  (--testdox)
composer analyse     # PHPStan level 8
composer cs          # PHP CS Fixer dry-run
```

## Contents

| Path | Description |
|------|-------------|
| `src/` | Domain model, repository, HTTP route registrar, DI factory |
| `database/schema.sql` | SQLite schema (also usable as MySQL/PostgreSQL reference) |
| `tests/` | PHPUnit test suite |

## Related

- [Howto: Event Sourcing](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/event-sourcing.md)
- [NENE2 framework](https://github.com/hideyukiMORI/NENE2)
- [Full examples index](https://github.com/hideyukiMORI/NENE2-examples#examples)
