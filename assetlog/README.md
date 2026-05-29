# assetlog — Asset Check-out / Check-in

> **FT194** &nbsp;·&nbsp; [NENE2 Examples](https://github.com/hideyukiMORI/NENE2-examples) &nbsp;·&nbsp; [Howto guide ↗](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/asset-checkout.md)

Exclusive hold tracking (one holder at a time) with an append-only audit log, IDOR-safe projections, and a fail-closed admin key.

## Highlights

- **Atomic exclusive checkout** — `UPDATE … WHERE holder_id IS NULL`; a second concurrent checkout updates 0 rows → `409`, no double-hold.
- **IDOR projection** — the public response never includes `holder_id`; only a valid `X-Admin-Key` reveals the holder.
- **Fail-closed admin key** — `hash_equals()` constant-time compare; an unconfigured key denies all admin access.
- **Append-only history** — every checkout/checkin writes an `asset_history` row.

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
| `POST` | `/assets` | `X-Admin-Key` | Create asset |
| `GET` | `/assets` | — (admin reveals holder) | List assets |
| `GET` | `/assets/{id}` | — | Get asset |
| `POST` | `/assets/{id}/checkout` | `X-User-Id` | Check out (409 if held) |
| `POST` | `/assets/{id}/checkin` | `X-User-Id` | Check in (403 if not holder) |
| `GET` | `/assets/{id}/history` | — | Audit history |

## Contents

| Path | Description |
|------|-------------|
| `src/Asset/AssetRepository.php` | Atomic checkout/checkin, append-only history |
| `src/Asset/RouteRegistrar.php` | Handlers, admin key, IDOR projection, ReDoS-safe user id |
| `src/AppFactory.php` | DI wiring |
| `database/schema.sql` | `assets` + `asset_history` |
| `tests/` | Lifecycle, conflict (409), wrong-holder (403), IDOR projection, admin key |

## Related

- [Howto: Asset Check-out / Check-in](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/asset-checkout.md)
- [NENE2 framework](https://github.com/hideyukiMORI/NENE2)
