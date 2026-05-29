# FT178 — patchlog

**JSON Merge Patch & ETag Conflict Detection**

Demonstrates PATCH (RFC 7396 JSON Merge Patch) and PUT semantics with
optimistic locking via ETag, immutable field protection, and the first
real-world usage of `Nene2\Validation\V`.

## What this example covers

- **JSON Merge Patch (RFC 7396)**: null = reset to default, absent = unchanged, value = update
- **Immutable field protection**: `id`, `owner_id`, `version`, `created_at` rejected in write body (422)
- **ETag / If-Match optimistic locking**: stale version → 412 Precondition Failed
- **V.php integration**: `V::queryInt`, `V::str`, `V::enum`, `V::userId` in production use
- **Type confusion attacks**: string "draft", int 2, bool, array → only valid enum strings accepted
- **The `?? ''` trap**: optional body fields must be validated separately from absent ones

## Run

```bash
cd patchlog
composer install
composer check    # cs + analyse + test
```

## Endpoints

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/documents` | X-User-Id | Create document |
| `GET` | `/documents` | — | List (page/limit) |
| `GET` | `/documents/{id}` | — | Get + ETag header |
| `PATCH` | `/documents/{id}` | X-User-Id + If-Match | JSON Merge Patch |
| `PUT` | `/documents/{id}` | X-User-Id + If-Match | Full replace |
| `DELETE` | `/documents/{id}` | X-User-Id | Delete |

## Contents

```
src/Document/
  DocumentRepository.php  — create / listByPage / update(version bump) / delete
  DocumentStatus.php      — enum: draft | published | archived
  RouteRegistrar.php      — 6 routes; V.php validation + ConditionalWriteHelper
src/AppFactory.php        — wires JsonResponseFactory + ProblemDetailsResponseFactory
database/schema.sql
tests/Document/PatchTest.php  — 16 tests / 33 assertions, ATK-01〜12
```

ETag is version-based (`"{id}-{version}"`); `ConditionalWriteHelper::check()`
maps a missing `If-Match` to `428` and a stale one to `412`.

## Attack scenarios tested (ATK-01〜12)

| ATK | Attack | Result |
|-----|--------|--------|
| 01 | `PATCH {"id": 999}` | 422 — immutable field |
| 02 | `PATCH {"owner_id": 99}` | 422 — immutable field |
| 03 | `PATCH {"version": 999}` | 422 — immutable field |
| 04 | `PATCH {"title": 42}` — type confusion | 422 |
| 05 | PATCH by non-owner (IDOR) | 404 |
| 06 | If-Match stale ETag → 412 | 412 Precondition Failed |
| 07 | PUT missing required title | 422 |
| 08 | `PATCH {}` — empty body | 200 no-op (RFC 7396 §3) |
| 09 | `PATCH {"status": null}` — null reset | 200 → default `draft` |
| 10 | `PATCH {"status": 2}` — type confusion | 422 |
| 11 | `PATCH {"__proto__": {...}}` | 200 — ignored, no crash |
| 12 | `?limit=999999`, `?page=-1` | 422 — V::queryInt guards |

## Related

- [How-to: JSON Merge Patch](../../NENE2/docs/howto/json-merge-patch.md)
- [Validation V helper](../../NENE2/src/Validation/V.php)
- [FT177 — limitlog](../limitlog/README.md) — integer boundary attacks
- [FT176 — grantlog](../grantlog/README.md) — delegated access grants
