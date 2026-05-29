# watchlog — Media Watchlist

> **FT59** &nbsp;·&nbsp; [NENE2 Examples](https://github.com/hideyukiMORI/NENE2-examples) &nbsp;·&nbsp; [Howto guide ↗](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/media-watchlist.md)

A personal media watchlist showcasing PHP backed enums for status/type, a nullable 1–5 rating, and archive/restore action endpoints.

## Highlights

- **Backed enums + `tryFrom()`** — `WatchStatus` / `MediaType` validate input and serialize via `->value`; DB `CHECK` constraints mirror the cases.
- **Nullable rating** — `array_key_exists` distinguishes "absent" (keep) from explicit `null` (clear); strict `is_int()` 1–5 rejects `4.0` / `"4"`.
- **Archive / restore** — `POST /watch/{id}/archive` sets `archived_at`; `restore` clears it. The list hides archived entries unless `?include_archived=1`.
- **Enum-typed filters** — `?status=` / `?media_type=` parsed then `tryFrom`-validated (`422` on unknown).

## Run

```bash
composer install
composer test        # PHPUnit (13 tests)
composer analyse     # PHPStan level 8
composer cs          # PHP CS Fixer dry-run
```

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/watch` | List (`?status=&media_type=&include_archived=&limit=&offset=`) |
| `POST` | `/watch` | Add (`title`, `media_type`, `status?`, `rating?`, `note?`) |
| `GET` | `/watch/{id}` | Get entry |
| `PATCH` | `/watch/{id}/status` | Update status (+ optional rating/note) |
| `POST` | `/watch/{id}/archive` | Archive |
| `POST` | `/watch/{id}/restore` | Restore |
| `DELETE` | `/watch/{id}` | Delete |

## Related

- [Howto: Media Watchlist](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/media-watchlist.md)
- [NENE2 framework](https://github.com/hideyukiMORI/NENE2)
