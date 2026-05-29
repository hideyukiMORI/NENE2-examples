# waitlistlog — FT287: Waitlist System

> **NENE2 Field Trial 287** — A waitlist where users join a queue and admins approve or
> decline entries: one entry per user, a `waiting → approved/declined` state machine, and
> real queue-position tracking.

Executable companion to the NENE2 howto
[`waitlist-system.md`](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/waitlist-system.md).
Validates the **released** framework (`hideyukimori/nene2: ^1.5`).

---

## What This Trial Proves

- **One entry per user at the DB layer** — `UNIQUE(user_id)`; a duplicate join is caught
  as `DatabaseConstraintException` → **409** (no app-level race window).
- **Terminal-state machine** — `WaitlistStatus::isTerminal()` blocks any transition out
  of `approved`/`declined` (→ 409), and blocks `leave` from a terminal state (you can't
  approve-then-leave to dodge tracking).
- **Meaningful position** — `positionOf()` counts only `waiting` entries with `id <=`
  yours, so approved/declined users don't inflate the rank; terminal entries report
  `position: null`.
- **Static route before dynamic** — `/waitlist/me` registered before `/waitlist/{id}/…`
  so `"me"` isn't captured as an id.
- **Admin fail-closed** — empty `X-Admin-Key` always 403; `hash_equals` constant-time.
- **Soft-metadata note** — truncated to 500 chars, not rejected.

---

## API

| Method | Path | Auth | Description |
|---|---|---|---|
| `POST` | `/waitlist` | `X-User-Id` | Join → 201 / 409 (dup) |
| `GET` | `/waitlist/me` | `X-User-Id` | Own status + `position` |
| `DELETE` | `/waitlist/me` | `X-User-Id` | Leave (waiting only) → 200 / 409 / 404 |
| `GET` | `/waitlist` | `X-Admin-Key` | List all (admin view, with `user_id`) |
| `POST` | `/waitlist/{id}/approve` | `X-Admin-Key` | → approved (409 if terminal) |
| `POST` | `/waitlist/{id}/decline` | `X-Admin-Key` | → declined (409 if terminal) |

---

## Test Results

```
20 tests / 27 assertions — all PASS
PHPStan level 8 — no errors
PHP CS Fixer — clean
```

---

## Key Takeaways

| Pattern | Rule |
|---|---|
| One entry | `UNIQUE(user_id)` + catch constraint → 409 |
| Transitions | `isTerminal()` guards approve/decline/leave |
| Position | count `waiting` with `id <=` self; terminal → `null` |
| Routes | `/waitlist/me` before `/waitlist/{id}` |
| Admin | `hash_equals`, empty key fails closed |
| Notes | truncate (don't reject) soft metadata |
