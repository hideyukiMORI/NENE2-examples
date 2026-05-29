# ticketlog — Event Ticket Booking

> **FT196** &nbsp;·&nbsp; [NENE2 Examples](https://github.com/hideyukiMORI/NENE2-examples) &nbsp;·&nbsp; [Howto guide ↗](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/event-ticket-booking.md)

Event capacity management with per-user ticket purchasing. Oversell-proof capacity guard, duplicate-proof purchase, IDOR-safe cancel.

## Highlights

- **Oversell-proof capacity** — purchase is a single conditional `INSERT … SELECT … WHERE (SELECT COUNT(*) …) < capacity`, so two concurrent buyers for the last seat cannot both succeed (`409` sold-out, verified).
- **Duplicate-proof** — `UNIQUE(event_id, user_id)`; a repeat buy returns `409` and does **not** consume capacity. A concurrent double-buy that slips past the pre-check is caught as a `DatabaseConstraintException`.
- **IDOR cancel** — cancelling another user's ticket returns `403`; the ticket survives.
- **Capacity is freed on cancel** — a cancelled seat becomes immediately purchasable by a new buyer.

## Run

```bash
composer install
composer test        # PHPUnit (12 tests)
composer analyse     # PHPStan level 8
composer cs          # PHP CS Fixer dry-run
```

## Endpoints

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/events` | `X-Admin-Key` | Create event (`name`, `capacity`) |
| `GET` | `/events` | — | List events with `remaining` / `sold_out` |
| `GET` | `/events/{id}` | — | Get one event |
| `POST` | `/events/{id}/tickets` | `X-User-Id` | Buy a ticket (409 sold-out / duplicate) |
| `DELETE` | `/tickets/{id}` | `X-User-Id` | Cancel own ticket (403 if not owner) |

## Contents

| Path | Description |
|------|-------------|
| `src/Ticket/TicketRepository.php` | Conditional-insert capacity guard, duplicate handling, cancel |
| `src/Ticket/RouteRegistrar.php` | Handlers, admin key, user-id validation |
| `database/schema.sql` | `events` + `tickets` with `UNIQUE(event_id, user_id)` |
| `tests/` | Capacity enforcement, duplicate, IDOR cancel, capacity-freed-on-cancel |

## Related

- [Howto: Event Ticket Booking](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/event-ticket-booking.md)
- [NENE2 framework](https://github.com/hideyukiMORI/NENE2)
