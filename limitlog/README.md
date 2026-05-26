# limitlog — Pagination Boundary Attack

> **FT177** &nbsp;·&nbsp; [NENE2 Examples](https://github.com/hideyukiMORI/NENE2-examples) &nbsp;·&nbsp; [Howto guide ↗](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/pagination-boundary-attack.md)

Bullet-proof integer parameter validation for offset and cursor-based pagination —
`ctype_digit()` O(n) ReDoS-immune, `strlen > 18` overflow guard, `clampInt` pattern.
VULN-A–L: float injection, padding, duplicate param shadowing, ReDoS payload.

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

- [Howto: Pagination Boundary Attack](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/pagination-boundary-attack.md)
- [NENE2 framework](https://github.com/hideyukiMORI/NENE2)
- [Full examples index](https://github.com/hideyukiMORI/NENE2-examples#examples)
