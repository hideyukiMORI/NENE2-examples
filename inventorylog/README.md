# inventorylog — Inventory / Stock Management

> **FT220** &nbsp;·&nbsp; [NENE2 Examples](https://github.com/hideyukiMORI/NENE2-examples) &nbsp;·&nbsp; [Howto guide ↗](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/inventory-management.md)

Stock items with signed adjustments (restock / consume), an append-only adjustment log, and an ATK-hardened admin surface.

## Highlights

- **Atomic, over-drain-proof adjust** — `UPDATE … SET quantity = quantity + ? WHERE id = ? AND quantity + ? >= 0`; an adjustment that would go negative updates 0 rows → `409`, stock unchanged. `CHECK(quantity >= 0)` backs it.
- **Strict integers** — `is_int()` for `quantity` / `price_cents` / `delta` (JSON `1.0`, `"1"` rejected). `delta` must be non-zero and `|delta| <= 1_000_000`.
- **SKU allow-list** — `\A[A-Z0-9-]{1,32}\z` blocks injection attempts (`422`); `UNIQUE(sku)` duplicates → `409`.
- **ReDoS-safe path ids** — `ctype_digit` + length cap → `404` on non-numeric / oversized ids.
- **Fail-closed admin key** — `hash_equals`, denies when no key is configured.

## Run

```bash
composer install
composer test        # PHPUnit (16 tests)
composer analyse     # PHPStan level 8
composer cs          # PHP CS Fixer dry-run
```

## Endpoints

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/items` | `X-Admin-Key` | Create item (`sku`, `name`, `quantity`, `price_cents`) |
| `GET` | `/items/{id}` | — | Get item with current stock |
| `POST` | `/items/{id}/adjust` | `X-Admin-Key` | Adjust stock (`delta` ±N, 409 if insufficient) |
| `GET` | `/items/{id}/history` | — | Adjustment history |

## ATK coverage (executable)

`tests/Item/ItemTest.php` exercises the FT220 attacks: SKU SQL-injection, float `price_cents` / `delta`, oversized `quantity`, non-digit path id, over-drain conflict (stock preserved), zero delta, admin fail-closed.

## Related

- [Howto: Inventory Management](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/inventory-management.md)
- [NENE2 framework](https://github.com/hideyukiMORI/NENE2)
