# timelog — FT246: Time Tracking API

> **NENE2 Field Trial 246** — A stopwatch API: `end_time IS NULL` encodes the running
> state, only one timer runs at a time, and daily summaries aggregate tracked seconds.

Executable companion to the NENE2 howto
[`time-tracking.md`](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/time-tracking.md).
Validates the **released** framework (`hideyukimori/nene2: ^1.5`).

---

## What This Trial Proves

- **State without a status column** — `end_time IS NULL` = running; non-NULL = stopped.
  `TimeEntry::isRunning()` / `durationSeconds()` derive everything from `end_time`.
- **Singleton timer** — `start()` rejects a second concurrent timer (409); `stop()`
  with nothing running is 409.
- **Consistent running contract** — `GET /timers/running` returns
  `{running:false, entry:null}` instead of 404 — the concept always exists.
- **Static-routes-first ordering** — `/timers/start|stop|running|summary` registered
  before `/timers/{id}` so literals aren't captured as params.
- **Cross-offset duration** — durations computed from epoch seconds, correct across
  different `±HH:MM` offsets.

---

## API

| Method | Path | Description |
|---|---|---|
| `POST` | `/timers/start` | Start (409 if one already running) |
| `POST` | `/timers/stop` | Stop the running timer (409 if none) |
| `GET` | `/timers/running` | `{running, entry}` |
| `GET` | `/timers/summary` | Daily `total_seconds` + `entry_count` (`?from=&to=`) |
| `GET` | `/timers` | List (`?label=&date=&limit=&offset=`) |
| `GET` | `/timers/{id}` | One entry |
| `DELETE` | `/timers/{id}` | Delete → 204 |

---

## ⚠️ Howto deviation: `julianday()` truncation

The FT246 howto aggregates seconds with
`CAST((julianday(end) - julianday(start)) * 86400 AS INTEGER)`. That product is a
float a hair below the whole second, so the `CAST` truncates a 60-second entry to
**59**. This example uses `strftime('%s', end) - strftime('%s', start)` instead —
exact integer epoch seconds, matching PHP's `getTimestamp()` diff. A correction to
the howto is filed separately.

---

## Test Results

```
16 tests / 30 assertions — all PASS
PHPStan level 8 — no errors
PHP CS Fixer — clean
```

---

## Key Takeaways

| Pattern | Rule |
|---|---|
| Running state | `end_time IS NULL` — no status column |
| Singleton | One running timer; 409 on conflict / no-op |
| running endpoint | `{running:false}` over 404 for the empty case |
| Routes | Static paths before `/{id}` |
| Duration | `strftime('%s')` (exact) — **not** `julianday * 86400` (truncates) |
