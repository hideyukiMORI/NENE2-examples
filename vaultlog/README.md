# vaultlog — Personal Secret Vault

> **FT195** &nbsp;·&nbsp; [NENE2 Examples](https://github.com/hideyukiMORI/NENE2-examples) &nbsp;·&nbsp; [Howto guide ↗](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/secret-vault.md)

Per-user key/value secrets with HMAC integrity, strict user isolation (IDOR-safe), admin metadata-only access, and idempotent upsert.

## Highlights

- **User isolation (IDOR)** — every query is scoped `WHERE user_id = ?`; a cross-user read/delete returns `404`, indistinguishable from "not found" (no key enumeration).
- **HMAC integrity** — `HMAC-SHA256(userId|key|value)` per entry; a GET recomputes and `hash_equals`-compares, returning `500` if the DB row was tampered out-of-band.
- **Admin sees metadata only** — `/admin/vault` never returns `value`, just `(user_id, key)`.
- **Upsert** — `UNIQUE(user_id, key_name)`; first write `201`, overwrite `200`.
- **Hardened input** — key `\A[a-z0-9_-]{1,64}\z` (ReDoS-safe), user id `ctype_digit` + length cap, fail-closed admin key.

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
| `POST` | `/vault` | `X-User-Id` | Store / update a secret |
| `GET` | `/vault` | `X-User-Id` | List own keys (no values) |
| `GET` | `/vault/{key}` | `X-User-Id` | Get own secret value |
| `DELETE` | `/vault/{key}` | `X-User-Id` | Delete own secret |
| `GET` | `/admin/vault` | `X-Admin-Key` | All `(user, key)` metadata |
| `GET` | `/admin/vault/{userId}` | `X-Admin-Key` | One user's keys |

## VULN coverage (executable)

`tests/Vault/VaultTest.php` turns the howto's VULN-A~L audit into assertions: key injection / path traversal / overlong key (A, G, K), cross-user IDOR read & delete & list (B, C), user-id negative/zero/overflow (H, I), admin bypass (D), upsert idempotency (F), HMAC tamper detection, and empty-secret no-crash (L).

## Related

- [Howto: Personal Secret Vault](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/secret-vault.md)
- [NENE2 framework](https://github.com/hideyukiMORI/NENE2)
