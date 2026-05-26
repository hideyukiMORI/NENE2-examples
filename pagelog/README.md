# pagelog — Pagination

> **FT100** &nbsp;·&nbsp; [NENE2 Examples](https://github.com/hideyukiMORI/NENE2-examples) &nbsp;·&nbsp; [Howto guide ↗](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/pagination.md)

OFFSET vs cursor-based pagination, fetch+1 has-more pattern, performance comparison.

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

- [Howto: Pagination](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/pagination.md)
- [NENE2 framework](https://github.com/hideyukiMORI/NENE2)
- [Full examples index](https://github.com/hideyukiMORI/NENE2-examples#examples)
