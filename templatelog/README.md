# templatelog — Document Template Engine

> **FT197** &nbsp;·&nbsp; [NENE2 Examples](https://github.com/hideyukiMORI/NENE2-examples) &nbsp;·&nbsp; [Howto guide ↗](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/document-template-engine.md)

Template CRUD with `{{variable}}` substitution. Admin-gated writes, public render, unique names.

## Highlights

- **`{{ key }}` substitution** — known vars are replaced, **unknown placeholders are left verbatim** (no error). Spaces inside the braces are tolerated.
- **Unique names** — `UNIQUE(name)`; a duplicate create returns `409` (caught from `DatabaseConstraintException`).
- **Light list** — `GET /templates` omits `body` to keep the payload small.
- **Admin-gated writes / public render** — create/update/delete require `X-Admin-Key` (`hash_equals`, fail-closed); `render` is open.

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
| `POST` | `/templates` | `X-Admin-Key` | Create (`name`, `body`) — 409 on dup name |
| `GET` | `/templates` | — | List (no `body`) |
| `GET` | `/templates/{id}` | — | Get full template |
| `PUT` | `/templates/{id}` | `X-Admin-Key` | Update `body` |
| `DELETE` | `/templates/{id}` | `X-Admin-Key` | Delete |
| `POST` | `/templates/{id}/render` | — | Render with `{ "vars": {...} }` |

## Related

- [Howto: Document Template Engine](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/document-template-engine.md)
- [NENE2 framework](https://github.com/hideyukiMORI/NENE2)
