# deadletterlog — FT72: Dead Letter Queue (DLQ)

> **NENE2 Field Trial 72** — A reliable message queue with exponential-backoff retries
> and a dead letter queue: failed messages reschedule with growing delays, then move to
> a `dead` state where they can be inspected and replayed.

Executable companion to the NENE2 howto
[`dead-letter-queue.md`](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/dead-letter-queue.md).
Validates the **released** framework (`hideyukimori/nene2: ^1.5`).

---

## Message lifecycle

```
enqueue → pending → claim → processing ─┬─ succeed → succeeded
                                        ├─ fail (retries left) → pending (retry_after = now + 2^n s)
                                        └─ fail (exhausted)     → dead → replay → pending
```

| Status | Meaning |
|---|---|
| `pending` | Ready to claim (or waiting until `retry_after`) |
| `processing` | Claimed by a worker |
| `succeeded` | Done |
| `dead` | Retries exhausted — in the DLQ |

---

## What This Trial Proves

- **Atomic claim (hardened)** — the howto notes that a bare SELECT+UPDATE lets two
  workers grab the same message. This example wraps the claim in a `transactional()`
  so the read and the `→ processing` write can't interleave.
- **Exponential backoff** — failure `n` schedules `retry_after = now + min(2^n, 3600)`
  seconds; `retry_after > now` keeps the message out of `claim` until it's due.
- **DLQ promotion** — once `retry_count >= max_retries`, the message goes `dead`.
- **Replay** — a dead message resets to `pending` with `retry_count = 0` (fresh budget,
  original `max_retries` preserved).
- **Named queues** — `{queue}` path param; queues are isolated and implicit.
- **Deterministic clock** — an `X-Now` header (worker/test clock seam) makes backoff and
  due-time behaviour fully testable.

---

## API

| Method | Path | Description |
|---|---|---|
| `POST` | `/queues/{queue}/messages` | Enqueue (`payload`, `max_retries` 1–10) → 201 |
| `GET` | `/queues/{queue}/messages` | List (`?status=&limit=&offset=`) |
| `GET` | `/queues/{queue}/messages/{id}` | Get one |
| `POST` | `/queues/{queue}/claim` | Claim next due pending message |
| `POST` | `/queues/{queue}/messages/{id}/succeed` | → succeeded (409 if not processing) |
| `POST` | `/queues/{queue}/messages/{id}/fail` | Retry or DLQ (409 if not processing) |
| `POST` | `/queues/{queue}/messages/{id}/replay` | Dead → pending (409 if not dead) |

---

## Test Results

```
18 tests / 36 assertions — all PASS
PHPStan level 8 — no errors
PHP CS Fixer — clean
```

---

## Key Takeaways

| Pattern | Rule |
|---|---|
| Claim | SELECT + `→ processing` UPDATE inside `transactional()` — no double-claim |
| Backoff | `retry_after = now + min(2^n, 3600)s`; `retry_after <= now` gate in claim |
| DLQ | `retry_count >= max_retries` → `dead` |
| Replay | reset to `pending`, `retry_count = 0`; only from `dead` (else 409) |
| State guards | succeed/fail require `processing`; replay requires `dead` |
| Testability | `X-Now` header overrides the clock |
