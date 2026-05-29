# pricelog — Product Price History (hardened)

> **FT67** &nbsp;·&nbsp; [NENE2 Examples](https://github.com/hideyukiMORI/NENE2-examples) &nbsp;·&nbsp; [Howto guide ↗](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/price-history.md)

A temporal price-tier API: each product keeps a timeline of prices (`effective_from` / `effective_to`), so the current price and the price at any past/future instant can be queried. **Hardened build** — the FT228 howto's ATK flagged exposures; each is closed and tested.

## What the howto's ATK flagged → how this build fixes it

| ATK | Original verdict | Fix here |
|-----|------------------|----------|
| ATK-01 (no auth) | **EXPOSED** | price/product mutations require `X-Admin-Key` (`hash_equals`, fail-closed) |
| ATK-06 (currency injection) | **EXPOSED** | ISO-4217 allow-list (`422`) |
| ATK-08 (invalid datetime) | **PARTIAL** | strict ISO-8601-UTC round-trip on `effective_from` and `?datetime=` (`422`) |
| ATK-10 (concurrent setPrice race) | **EXPOSED** | close-old + open-new wrapped in `transactional()` |
| ATK-05 / 07 / 11 / 12 | BLOCKED | kept: `is_int()` non-negative amount, existence check, `ctype_digit` path ids |
| ATK-04 / 09 | by design | zero price and future (scheduled) `effective_from` allowed |

## Run

```bash
composer install
composer test        # PHPUnit (13 tests)
composer analyse     # PHPStan level 8
composer cs          # PHP CS Fixer dry-run
```

## Endpoints

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/products` | `X-Admin-Key` | Create product |
| `GET` | `/products` / `/products/{id}` | — | List / get |
| `POST` | `/products/{id}/prices` | `X-Admin-Key` | Open a new price tier |
| `GET` | `/products/{id}/prices` | — | Full timeline |
| `GET` | `/products/{id}/prices/current` | — | Current active price |
| `GET` | `/products/{id}/prices/at?datetime=` | — | Price at an instant |

## Related

- [Howto: Product Price History](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/price-history.md)
- [Howto: Use database transactions](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/use-transactions.md)
- [NENE2 framework](https://github.com/hideyukiMORI/NENE2)
