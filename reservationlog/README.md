# reservationlog — FT216: Resource Reservation / Time-Slot Booking

> **NENE2 Field Trial 216** — Time-slot booking with half-open-interval overlap
> detection, admin-managed resources, and IDOR-safe public/admin views.

Executable companion to the NENE2 howto
[`resource-reservation.md`](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/resource-reservation.md).
Validates the **released** framework (`hideyukimori/nene2: ^1.5`).

---

## What This Trial Proves

- **Overlap prevention** — `[start, end)` half-open intervals: two slots overlap iff
  `existing.start < new.end AND existing.end > new.start`. Adjacent slots
  (`end == start`) are allowed.
- **Resource-scoped conflicts** — bookings on different resources never collide.
- **IDOR-safe views** — a readonly `Booking` VO exposes `toPublicArray()` (no
  `user_id`) for users and `toAdminArray()` (with `user_id`) for admin auditing.
- **Ownership on cancel** — wrong owner → **403** (the booking id is already known to
  its lister, so existence isn't secret); missing → **404**.
- **Admin fail-closed** — empty configured `X-Admin-Key` always 403; `hash_equals`
  constant-time compare.

---

## API

| Method | Path | Auth | Description |
|---|---|---|---|
| `POST` | `/resources` | `X-Admin-Key` | Create resource → 201 |
| `GET` | `/resources/{id}/bookings` | `X-Admin-Key` | All bookings (admin view, with `user_id`) |
| `POST` | `/resources/{id}/book` | `X-User-Id` | Book a slot → 201 / 409 (overlap) |
| `GET` | `/bookings` | `X-User-Id` | Own bookings (public view) |
| `DELETE` | `/bookings/{id}` | `X-User-Id` | Cancel own → 200 / 403 / 404 |

---

## Core Pattern: Half-Open Overlap

```sql
SELECT COUNT(*) FROM bookings
WHERE resource_id = ? AND starts_at < :ends_at AND ends_at > :starts_at
```

`A.start < B.end AND A.end > B.start` covers contains / overlaps / identical while
allowing back-to-back slots. ISO 8601 UTC strings compare lexicographically.

---

## Test Results

```
17 tests / 22 assertions — all PASS
PHPStan level 8 — no errors
PHP CS Fixer — clean
```

---

## Key Takeaways

| Pattern | Rule |
|---|---|
| Overlap | Half-open `[start, end)`; adjacent allowed; check before insert → 409 |
| Views | VO `toPublicArray()` / `toAdminArray()` — never leak `user_id` to users |
| Cancel | Wrong owner → 403, missing → 404 |
| Datetime | ISO 8601 + offset range guard; `ends_at > starts_at` as instants |
| Admin | `hash_equals`, empty key fails closed |
