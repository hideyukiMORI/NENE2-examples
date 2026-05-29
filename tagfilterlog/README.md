# tagfilterlog — FT250: Multi-value Tag Filter API

> **NENE2 Field Trial 250** — Multi-tag filtering over an M:N join table with **AND**
> semantics (posts having *all* tags) and **OR** semantics (posts having *any* tag),
> accepting both comma-separated and PHP-array query formats.

Executable companion to the NENE2 howto
[`multi-value-tag-filter.md`](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/multi-value-tag-filter.md).
Validates the **released** framework (`hideyukimori/nene2: ^1.5`).

> Distinct from `taglog` (M:N tagging CRUD): this trial is the AND/OR multi-value
> *query/filter* pattern.

---

## What This Trial Proves

- **AND filter** — `WHERE tag IN (…) GROUP BY p.id HAVING COUNT(DISTINCT tag) = N`
  selects posts that match *all* N tags. `CAST(? AS INTEGER)` documents the integer
  compare (PDO binds params as strings).
- **OR filter** — `SELECT DISTINCT … WHERE tag IN (…)`; `DISTINCT` collapses posts that
  match several IN tags into one row.
- **Dual query format** — `?tags=php,api` (comma) and `?tags[]=php&tags[]=api` (PSR-7
  array) produce identical results.
- **Mode default** — `mode=all` (AND) is the default; unknown modes fall through to AND
  (narrower, safer).
- **Atomic create** — post + tags written in one `transactional()`; tags deduped + sorted
  in PHP, `INSERT OR IGNORE` guards the composite PK.
- **Empty/absent tags** — returns all posts in both modes.

---

## API

| Method | Path | Description |
|---|---|---|
| `POST` | `/posts` | Create with `tags[]` → 201 |
| `GET` | `/posts` | Filter (`?tags=&mode=all\|any`) |
| `GET` | `/posts/{id}` | Get one with its tags |

---

## AND vs OR

| Mode | SQL | `?tags=php,api` matches |
|------|-----|-------------------------|
| `all` (AND, default) | `HAVING COUNT(DISTINCT tag) = N` | posts with **both** php and api |
| `any` (OR) | `SELECT DISTINCT` | posts with **either** php or api |
| (no tags) | no filter | all posts |

---

## Test Results

```
11 tests / 12 assertions — all PASS
PHPStan level 8 — no errors
PHP CS Fixer — clean
```

---

## Key Takeaways

| Pattern | Rule |
|---|---|
| AND | `HAVING COUNT(DISTINCT tag) = N` (+`CAST` for int compare) |
| OR | `SELECT DISTINCT … WHERE tag IN (…)` |
| Query format | accept both `?tags=a,b` and `?tags[]=a&tags[]=b` |
| Default | unknown `mode` → AND |
| Create | atomic post+tags, dedup+sort, `INSERT OR IGNORE` |
