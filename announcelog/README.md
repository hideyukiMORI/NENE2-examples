# announcelog — FT190: System Announcement Management

> **NENE2 Field Trial 190** — Time-based system announcements with constant-time admin key authentication and per-user dismissal.

---

## What This Trial Proves

A system announcement API for broadcasting maintenance notices, feature updates, and alerts:
1. **UTC time-based activation** — `starts_at` / `ends_at` ISO 8601 filtering via lexicographic comparison
2. **Constant-time admin key** — `hash_equals()` prevents timing attacks on admin authentication
3. **Idempotent dismissal** — `UNIQUE(user_id, announcement_id)` + `ON CONFLICT DO NOTHING`
4. **Optional user context** — `GET /announcements` with or without `X-User-Id` (excludes dismissed if provided)
5. **Priority ordering** — `ORDER BY priority DESC, id DESC`

---

## API

| Method | Path | Auth | Description |
|---|---|---|---|
| `POST` | `/announcements` | `X-Admin-Key` | Create (201) |
| `PUT` | `/announcements/{id}` | `X-Admin-Key` | Update (200) |
| `DELETE` | `/announcements/{id}` | `X-Admin-Key` | Delete (200) |
| `GET` | `/announcements` | optional `X-User-Id` | List active |
| `POST` | `/announcements/{id}/dismiss` | `X-User-Id` | Dismiss for user (200) |

---

## Core Pattern: Constant-Time Admin Key

```php
private function isAdmin(ServerRequestInterface $request): bool
{
    if ($this->adminKey === '') return false; // fail closed

    $provided = $request->getHeaderLine('X-Admin-Key');
    return $provided !== '' && hash_equals($this->adminKey, $provided);
}
```

`hash_equals()` is constant-time — prevents timing attacks that could brute-force the key character-by-character.

---

## Core Pattern: UTC Time Filter

```php
$now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);

SELECT ... FROM announcements
WHERE starts_at <= :now AND ends_at > :now
ORDER BY priority DESC, id DESC
```

ISO 8601 UTC strings sort lexicographically — no `strtotime()` needed in SQL.

---

## Core Pattern: Idempotent Dismissal

```php
INSERT INTO announcement_dismissals (user_id, announcement_id, dismissed_at)
VALUES (:user_id, :announcement_id, :now)
ON CONFLICT(user_id, announcement_id) DO NOTHING
```

Safe to call repeatedly — the second dismiss is a no-op.

---

## Test Results

```
38 tests / 93 assertions — all PASS
PHPStan level 8 — no errors
PHP CS Fixer — clean
```

---

## Key Takeaways

| Pattern | Rule |
|---|---|
| Admin auth | `hash_equals()` constant-time; empty config = always 401 |
| Time filter | ISO 8601 UTC — lexicographic comparison works in SQL |
| Dismissal | `ON CONFLICT DO NOTHING` — idempotent, never errors on repeat |
| User context | Optional on list endpoint; absent = show all active |
| ends_at validation | Server-side: `ends_at > starts_at` — reject silently broken data |
| Internal fields | `created_at` / `updated_at` not in public response |

Full guide: [`docs/howto/system-announcement-management.md`](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/system-announcement-management.md) in the NENE2 repository.
