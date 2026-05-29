# contactlog — Contact Management

> **FT238** &nbsp;·&nbsp; [NENE2 Examples](https://github.com/hideyukiMORI/NENE2-examples) &nbsp;·&nbsp; [Howto guide ↗](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/contact-management.md)

Owner-scoped contacts with a many-to-many group system, escaped `LIKE` search combined with an `EXISTS` group filter, and idempotent group membership.

## Highlights

- **Owner-scoped (IDOR-safe)** — `{ownerId}` in the path; every query is `WHERE owner_id = ?`. Another owner's contact returns `404`.
- **Escaped LIKE search** — `?q=` searches name/email with `%`/`_`/`\` escaped + `ESCAPE '\'`, so a query of `%` matches literally (no wildcard injection).
- **`EXISTS` group filter** — `?group_id=` filters via a correlated subquery (no JOIN duplicates).
- **Idempotent membership** — composite `PRIMARY KEY(contact_id, group_id)`; re-adding is a no-op `204` (catches `DatabaseConstraintException`). Cross-owner add → `404`.
- **Unique group names per owner** — `UNIQUE(owner_id, name)`; duplicate → `409`; the same name under a different owner is fine.

## Run

```bash
composer install
composer test        # PHPUnit (14 tests)
composer analyse     # PHPStan level 8
composer cs          # PHP CS Fixer dry-run
```

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/owners/{ownerId}/contacts` | Create contact |
| `GET` | `/owners/{ownerId}/contacts` | Search (`?q=`, `?group_id=`) |
| `GET` | `/owners/{ownerId}/contacts/{id}` | Get contact (with groups) |
| `PUT` | `/owners/{ownerId}/contacts/{id}` | Update contact |
| `DELETE` | `/owners/{ownerId}/contacts/{id}` | Delete contact |
| `POST` | `/owners/{ownerId}/groups` | Create group (409 on dup name) |
| `PUT` | `/owners/{ownerId}/contacts/{contactId}/groups/{groupId}` | Add to group (idempotent) |
| `DELETE` | `/owners/{ownerId}/contacts/{contactId}/groups/{groupId}` | Remove from group |

## Related

- [Howto: Contact Management](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/contact-management.md)
- [NENE2 framework](https://github.com/hideyukiMORI/NENE2)
