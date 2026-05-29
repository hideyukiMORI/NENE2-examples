# draftlog — FT142: Content Draft Lifecycle (Draft → Published → Archived)

> **NENE2 Field Trial 142** — An article state machine driven by a backed enum, with author-only transitions, existence-hiding visibility rules, and same-second sort stability.

This is the executable companion to the NENE2 howto
[`content-draft-lifecycle.md`](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/content-draft-lifecycle.md).
It validates the **released** framework (`hideyukimori/nene2: ^1.5`).

---

## What This Trial Proves

1. **Enum state machine** — `ArticleStatus` (`Draft`/`Published`/`Archived`) owns the
   transition guards (`canEdit` / `canPublish` / `canArchive`); the handler never
   hard-codes string comparisons.
2. **One-way transitions** — `draft → published → archived`. No "unpublish", no
   re-open; invalid transitions return **422**.
3. **Mass-assignment safety** — articles always start as `draft`; a `status` field
   in the request body is ignored.
4. **Existence-hiding visibility** — non-authors reading a draft get **404, not 403**,
   so the API never confirms a hidden article exists.
5. **Same-second sort stability** — the public list orders by
   `published_at DESC, id DESC` so articles published within the same second have a
   deterministic order.

---

## API

| Method | Path | Auth | Description |
|---|---|---|---|
| `POST` | `/articles` | `X-User-Id` | Create (always `draft`) → 201 |
| `GET` | `/articles` | none | List **published** only |
| `GET` | `/articles/{id}` | optional `X-User-Id` | Read (author sees any status; others only `published`) |
| `PUT` | `/articles/{id}` | `X-User-Id` (author) | Edit (draft only) → 200 / 422 |
| `POST` | `/articles/{id}/publish` | `X-User-Id` (author) | `draft → published` → 200 / 422 |
| `POST` | `/articles/{id}/archive` | `X-User-Id` (author) | `published → archived` → 200 / 422 |

---

## Core Pattern: Enum Transition Guards

```php
enum ArticleStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function canEdit(): bool    { return $this === self::Draft; }
    public function canPublish(): bool { return $this === self::Draft; }
    public function canArchive(): bool { return $this === self::Published; }
}

$status = ArticleStatus::tryFrom((string) $article['status']) ?? ArticleStatus::Draft;
if (!$status->canPublish()) {
    return $this->json->create(['error' => 'only draft articles can be published'], 422);
}
```

## Core Pattern: Existence-Hiding 404

```php
// Non-author reading a draft → 404, never 403 (403 would confirm it exists).
if ($article === null || ($article['status'] !== 'published' && (int) $article['author_id'] !== $actorId)) {
    return $this->notFound();
}
```

## Core Pattern: Same-Second Sort Stability

```sql
SELECT * FROM articles WHERE status = 'published'
ORDER BY published_at DESC, id DESC
```

---

## Test Results

```
15 tests / 25 assertions — all PASS
PHPStan level 8 — no errors
PHP CS Fixer — clean
```

---

## Key Takeaways

| Pattern | Rule |
|---|---|
| Transitions | Backed enum owns the guards; handlers ask, never compare strings |
| Start state | Always `draft`; body `status` is ignored (mass-assignment) |
| Hidden content | 404 for non-author drafts — never 403 |
| Ownership | Author-only edit/publish/archive; cross-user → 404 |
| List | Published only; `published_at DESC, id DESC` for stable order |
| Internal fields | `updated_at` not echoed in responses |
