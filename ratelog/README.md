# ratelog — Sliding-Window Rate Limiter

> Per-user, per-endpoint rate limiting with a **rolling** time window: requests are
> counted within the last `WINDOW` seconds from *now*, and once the limit is reached
> further requests get `429 Too Many Requests`.

Executable companion to the NENE2 howto
[`sliding-window-rate-limiter.md`](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/sliding-window-rate-limiter.md).
Validates the **released** framework (`hideyukimori/nene2: ^1.5`).

> **Why a separate example from `throttlelog` / `limitlog`?** Those cover rate limiting
> as a topic; this one demonstrates the *sliding-window algorithm* specifically — and
> how it differs from a fixed window (no burst-at-the-boundary, gradual recovery).

---

## What This Trial Proves

- **Sliding window** — every check counts `rate_events` with
  `created_at >= now - WINDOW`. Old events fall out of scope **one at a time**, so the
  limit recovers gradually rather than resetting all at once on a window boundary
  (the fixed-window weakness). Demonstrated by `testWindowSlidesEventsOutGradually`.
- **Rejected requests aren't recorded** — a 429 does not consume a slot, so the count
  never exceeds the limit.
- **Per-user / per-endpoint isolation** — counters are keyed on `(user_id, endpoint)`.
- **Admin reset, fail-closed** — `hash_equals` admin key; empty configured key → 403.
- **Deterministic clock** — an `X-Now` header (test/worker seam) makes the windowing
  behaviour fully testable.

---

## API

| Method | Path | Auth | Description |
|---|---|---|---|
| `POST` | `/rate/check` | `X-User-Id` | Record a request; `429` if over limit |
| `GET` | `/rate/status` | `X-User-Id` | Current usage for a user/endpoint |
| `DELETE` | `/rate/reset/{userId}` | `X-Admin-Key` | Reset counters (optional `?endpoint=`) |

---

## Sliding vs fixed window

```
limit = 3, window = 60s, events at 00:00:00, 00:00:30, 00:00:59

  at 00:01:00  window [00:00:00 .. 00:01:00]  → 3 events → 429
  at 00:01:01  window [00:00:01 .. 00:01:01]  → 00:00:00 slid out → 2 → 200
```

A fixed window would reject everything until the boundary, then allow a full burst;
the sliding window frees one slot as each event ages out.

---

## ATK coverage (executable)

| ATK | Pattern | Defence | Status |
|----|---------|---------|--------|
| 01 | Missing `X-User-Id` | 400 | `testAtk01MissingUserId` |
| 02 | Empty endpoint | 422 | `testAtk02EmptyEndpoint` |
| 03 | 129-char endpoint (DoS) | length 422 | `testAtk03OverlongEndpoint` |
| 04 | SQL injection in endpoint | parameterized | `testAtk04SqlInjectionEndpointIsInert` |
| 05 | Non-admin reset | 403 fail-closed | `testAtk05NonAdminReset` |
| 06 | Wrong / empty admin key | 403 `hash_equals` | `testAtk06*` |
| 07–09 | Negative / zero / non-digit path userId | 404 `ctype_digit` | `testAtk07to09InvalidPathUserId` |
| 10 | Status without endpoint | 422 | `testAtk10StatusWithoutEndpoint` |
| 11 | Check without body | 400 | `testAtk11CheckWithoutBody` |
| 12 | Body missing endpoint key | 422 | `testAtk12BodyMissingEndpoint` |

---

## Test Results

```
19 tests / 32 assertions — all PASS
PHPStan level 8 — no errors
PHP CS Fixer — clean
```

---

## Notes (from the howto)

- **Concurrency**: the read-then-write check has a small TOCTOU window; high-concurrency
  production use wants atomic counters (Redis `INCR`+`EXPIRE`) or row locking.
- **Storage growth**: old `rate_events` accumulate — add a periodic
  `DELETE FROM rate_events WHERE created_at < :cutoff` job.
- **Clock**: timestamps are UTC to avoid DST/timezone surprises.
