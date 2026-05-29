# bulkupdatelog — FT85: Bulk Status Update API (hardened)

> **NENE2 Field Trial 85** — Two bulk-mutation shapes: per-item status update (each
> item its own target status) and homogeneous bulk update (all ids → one status), both
> with partial-success reporting.

Executable companion to the NENE2 howto
[`bulk-status-update.md`](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/bulk-status-update.md).
Validates the **released** framework (`hideyukimori/nene2: ^1.5`).

> Distinct from `batchlog` (FT182, batch *create*): this trial is bulk *update* of
> existing rows with per-item state transitions.

---

## What This Trial Proves

- **Per-item partial success** — `PATCH /tasks/status` returns `{updated, failed}` and
  always `200`; missing id / unknown status / not-found are per-item failures.
- **Homogeneous bulk** — `PATCH /tasks/done` moves a set of ids to `done` with one
  `UPDATE … WHERE id IN (…)`; non-integer ids silently dropped; empty-after-filter → 422.
- **Status allowlist** — `TaskStatus::tryFrom()` rejects unknown statuses; a DB
  `CHECK` is the backstop.

### Hardened beyond the FT85 demo (its VULN FT231 marks these EXPOSED)

- **V-01 (no auth)** → every endpoint requires `X-User-Id`; all bulk ops are
  **owner-scoped** (`WHERE … user_id = ?`). Another user's id is reported as
  *not found* and never mutated (tested).
- **V-02 (mass-update DoS)** → `MAX_ITEMS = 100` cap on both `updates` and `ids`.

---

## API

| Method | Path | Auth | Description |
|---|---|---|---|
| `POST` | `/tasks` | `X-User-Id` | Create → 201 |
| `GET` | `/tasks` | `X-User-Id` | List own tasks |
| `PATCH` | `/tasks/status` | `X-User-Id` | Per-item bulk update → 200 `{updated, failed}` |
| `PATCH` | `/tasks/done` | `X-User-Id` | Homogeneous bulk → `done` |

---

## Test Results

```
11 tests / 23 assertions — all PASS
PHPStan level 8 — no errors
PHP CS Fixer — clean
```

---

## Key Takeaways

| Pattern | Rule |
|---|---|
| Per-item | accumulate `updated`/`failed`; always 200 |
| Homogeneous | `UPDATE … WHERE id IN (…)`; filter non-ints; 422 if empty |
| Allowlist | `TaskStatus::tryFrom()` + DB `CHECK` |
| Ownership | `WHERE user_id = ?` on every bulk op (hardens V-01) |
| DoS | cap batch size (hardens V-02) |
