# ftslog — FT254: SQLite FTS5 Full-Text Search

> **NENE2 Field Trial 254** — Full-text search with SQLite's FTS5 extension: a virtual
> table shadows `posts`, kept in sync by triggers, with `MATCH` + relevance ranking.

Executable companion to the NENE2 howto
[`sqlite-fts5-search.md`](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/sqlite-fts5-search.md).
Validates the **released** framework (`hideyukimori/nene2: ^1.5`).

> Distinct from `searchlog` (FT157), which uses `LIKE` prefix search — this trial uses a
> real FTS5 inverted index with relevance ranking and FTS query syntax.

---

## What This Trial Proves

- **FTS5 virtual table + triggers** — `posts_fts` (`content='posts'`) stores tokens
  only; `AFTER INSERT/UPDATE/DELETE` triggers keep it in sync (delete via the special
  `INSERT INTO posts_fts(posts_fts, …) VALUES ('delete', …)` command).
- **`MATCH` + `rank`** — search joins `posts_fts` to `posts`, orders by `fts.rank`
  (a negative float; lower = more relevant).
- **FTS query syntax** — prefix (`progr*`), phrase (`"quick brown"`), boolean
  (`AND`/`OR`/`NOT`), column-scoped (`title:php`).
- **Parameterized + guarded** — the query is a bound parameter; malformed FTS syntax
  (e.g. an unclosed quote) is caught and returned as **400**, never a 500.
- **Static route first** — `/posts/search` before `/posts/{id}`.

---

## ⚠️ Howto deviation: implicit operator is AND, not OR

The FT254 howto's syntax table states that a bare `php api` is an implicit **OR**. In
FTS5 the implicit operator between bare terms is **AND** — `php api` matches only
documents containing *both* terms (verified by `testAndOrOperators`). Use `php OR api`
for OR semantics. A correction to the howto is filed separately.

---

## API

| Method | Path | Description |
|---|---|---|
| `POST` | `/posts` | Create (auto-indexed) → 201 |
| `GET` | `/posts` | List |
| `GET` | `/posts/search` | FTS search (`?q=`) → 200 / 422 (empty) / 400 (malformed) |
| `GET` | `/posts/{id}` | Get one |

---

## Test Results

```
15 tests / 25 assertions — all PASS
PHPStan level 8 — no errors
PHP CS Fixer — clean
```

> Requires a PHP SQLite build with FTS5 (standard in most distributions).

---

## Key Takeaways

| Pattern | Rule |
|---|---|
| Index | FTS5 virtual table + sync triggers (`content=`/`content_rowid=`) |
| Search | `WHERE posts_fts MATCH ? ORDER BY fts.rank` (param-bound) |
| Ranking | `fts.rank` negative float; lower = more relevant |
| Operators | implicit **AND**; `OR`/`NOT`/`"phrase"`/`prefix*`/`col:term` |
| Errors | malformed FTS → catch → 400 (never 500) |
