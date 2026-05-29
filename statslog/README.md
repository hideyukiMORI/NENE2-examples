# statslog — FT51: Event Analytics API

> **NENE2 Field Trial 51** — Event tracking with arbitrary JSON properties and
> aggregation endpoints: `json_extract()` property filtering, `strftime()` day
> bucketing, and `COUNT(DISTINCT user_id)` daily-active-user metrics.

Executable companion to the NENE2 howto
[`event-analytics.md`](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/event-analytics.md).
Validates the **released** framework (`hideyukimori/nene2: ^1.5`).

> Distinct from `agglog` (admin report aggregation) and `meterlog` (usage metering):
> this trial is event-property analytics over a free-form JSON column.

---

## What This Trial Proves

- **`json_extract()` property filtering** — `WHERE json_extract(properties, ?) = ?`
  with both the JSONPath (`$.key`) and value bound as parameters; supports nested keys
  (`browser.name`). A key-shape allowlist (dotted alphanumerics) blocks anything weird.
- **`strftime()` day bucketing** — `GROUP BY strftime('%Y-%m-%d', occurred_at)` for
  per-day counts (UTC `…Z` timestamps).
- **DAU via `COUNT(DISTINCT user_id)`** — same user twice in a day counted once.
- **String user_id** — `TEXT` (UUID / opaque / session token), no FK to a users table.
- **Static route before parameterised** — `/events/by-property` before `/events/{id}`.
- **Properties must be an object** — JSON lists/scalars rejected (422); stored as JSON,
  returned decoded.

---

## API

| Method | Path | Description |
|---|---|---|
| `POST` | `/events` | Record an event |
| `GET` | `/events` | List (paginated) |
| `GET` | `/events/by-property` | Filter by JSON property (`?key=&value=`) |
| `GET` | `/events/{id}` | Get one |
| `GET` | `/stats/per-day` | Count per day (`?from=&to=`) |
| `GET` | `/stats/per-type` | Count per type |
| `GET` | `/stats/unique-users` | Unique users per day |

---

## Test Results

```
16 tests / 24 assertions — all PASS
PHPStan level 8 — no errors
PHP CS Fixer — clean
```

---

## Key Takeaways

| Pattern | Rule |
|---|---|
| Property filter | `json_extract(properties, ?)` — JSONPath + value both bound |
| Key safety | allowlist dotted-alphanumeric key shape |
| Buckets | `strftime('%Y-%m-%d', occurred_at)` on UTC timestamps |
| DAU | `COUNT(DISTINCT user_id)` per bucket |
| Routes | static `/events/by-property` before `/events/{id}` |
| properties | object only; stored JSON, returned decoded |
