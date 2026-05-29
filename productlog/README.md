# productlog — FT212: Product Catalog API (ATK-01〜12 hardened)

> **NENE2 Field Trial 212** — A product catalog with admin-only writes, parameterized
> `LIKE` search, and soft delete — built to survive a cracker-mindset attack pass.

Executable companion to the NENE2 howto
[`product-catalog.md`](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/product-catalog.md).
Validates the **released** framework (`hideyukimori/nene2: ^1.5`).

---

## What This Trial Proves

- **Public reads, admin writes** — create/delete require a constant-time
  `X-Admin-Key` (`hash_equals`, empty key fails closed).
- **SKU allowlist** — `/\A[A-Z0-9\-]{1,32}\z/` rejects injection, spaces, lowercase,
  and overlong values before the DB.
- **Integer-only prices** — `is_int()` rejects `9.99`; negatives rejected.
- **Parameterized search** — `%`/`_` are literal LIKE wildcards, never interpolated;
  a 100-char keyword length guard stops multi-MB LIKE bombs.
- **Soft delete** — `active = 0`; all reads filter `active = 1`; a double-delete is a
  clean 404, not an error.

---

## API

| Method | Path | Auth | Description |
|---|---|---|---|
| `POST` | `/products` | `X-Admin-Key` | Create → 201 / 409 (dup SKU) |
| `GET` | `/products` | none | List / search (`?q=`, `?limit=`, `?offset=`) |
| `GET` | `/products/{id}` | none | Get active product |
| `DELETE` | `/products/{id}` | `X-Admin-Key` | Soft delete → 200 / 404 |

---

## ATK coverage (executable in tests)

| ID | Attack | Defence | Test |
|----|--------|---------|------|
| ATK-01 | SQL injection in `q` | parameterized LIKE | `testSearchInjectionIsInert` |
| ATK-02 | Empty admin key | fail closed → 403 | `testEmptyConfiguredKeyFailsClosed` |
| ATK-03 | Integer overflow id | `strlen ≤ 18` guard → 404 | `testOverlongIdIs404` |
| ATK-04 | Negative id | `ctype_digit` → 404 | `testNegativeIdIs404` |
| ATK-05 | Float price | `is_int` → 422 | `testFloatPriceRejected` |
| ATK-06 | SKU injection | regex allowlist → 422 | `testSkuInjectionRejected` |
| ATK-07 | Wildcard search | broad match, inert | `testWildcardSearchMatchesBroadly` |
| ATK-08 | Double delete | second affects 0 rows → 404 | `testDoubleDeleteIs404` |
| ATK-09 | Overlong SKU | `{1,32}` quantifier → 422 | `testSkuInjectionRejected` |
| ATK-10 | Wrong admin key | `hash_equals` constant-time → 403 | `testWrongAdminKeyForbidden` |

---

## Test Results

```
17 tests / 27 assertions — all PASS
PHPStan level 8 — no errors
PHP CS Fixer — clean
```

---

## Key Takeaways

| Pattern | Rule |
|---|---|
| Admin auth | `hash_equals`; empty configured key = always 403 |
| SKU | Strict regex allowlist, not denylist |
| Price | `is_int` (rejects floats), `>= 0` |
| Search | Parameterized LIKE + keyword length guard |
| Delete | Soft (`active = 0`); reads always filter `active = 1` |
