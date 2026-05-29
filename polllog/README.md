# polllog — FT217: Poll / Survey API

> **NENE2 Field Trial 217** — Polls with 2–20 options, one-vote-per-user enforcement,
> cross-poll option-injection defence, and zero-vote-inclusive live results.

Executable companion to the NENE2 howto
[`poll-survey.md`](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/poll-survey.md).
Validates the **released** framework (`hideyukimori/nene2: ^1.5`).

---

## What This Trial Proves

- **Atomic poll creation** — poll + all options inserted in one `transactional()`;
  a failure leaves no half-built poll.
- **One vote per user** — `UNIQUE(poll_id, user_id)` plus an explicit pre-check; the
  constraint is the race safety net (`DatabaseConstraintException` → 409).
- **Cross-poll option injection blocked** — a vote's `option_id` must belong to the
  target poll (`WHERE id = ? AND poll_id = ?`), else 422.
- **Strict types** — `option_id` via `is_int` (rejects `"1"`/floats); `is_public` via
  `is_bool` (rejects `1`/`"true"`).
- **Private-poll existence hiding** — non-admins get 404 (not 403) for private polls.
- **Zero-vote options preserved** — results use `LEFT JOIN` so every option appears.

---

## API

| Method | Path | Auth | Description |
|---|---|---|---|
| `POST` | `/polls` | `X-Admin-Key` | Create poll + options → 201 |
| `GET` | `/polls/{id}` | public (admin for private) | Get poll + options |
| `POST` | `/polls/{id}/vote` | `X-User-Id` | Cast vote → 201 / 409 / 422 |
| `GET` | `/polls/{id}/results` | public (admin for private) | Per-option counts + total |

---

## Core Pattern: Zero-Vote-Inclusive Results

```sql
SELECT o.id, o.label, o.sort_order, COUNT(v.id) AS votes
FROM poll_options o
LEFT JOIN votes v ON v.option_id = o.id AND v.poll_id = o.poll_id
WHERE o.poll_id = ?
GROUP BY o.id, o.label, o.sort_order
ORDER BY o.sort_order ASC, o.id ASC
```

---

## Test Results

```
16 tests / 20 assertions — all PASS
PHPStan level 8 — no errors
PHP CS Fixer — clean
```

---

## Key Takeaways

| Pattern | Rule |
|---|---|
| Create | poll + options atomic via `transactional()` |
| One vote | `UNIQUE(poll_id, user_id)` + pre-check; constraint catches races → 409 |
| Option injection | `WHERE id = ? AND poll_id = ?` — option must belong to poll |
| Types | `is_int(option_id)`, `is_bool(is_public)` — strict |
| Private polls | 404 for non-admin (existence hiding) |
| Results | `LEFT JOIN` keeps zero-vote options |
