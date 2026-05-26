# statuslog — FT185: Service Status Page API

> **NENE2 Field Trial 185** — Component health tracking, incident lifecycle management.
> `V::secret()` + `hash_equals()` for admin key auth. `V::enum()` allowlist for status values.
> Transition guard: resolved incidents are immutable.

---

## What This Trial Proves

A service status page backend needs:
1. **Public read, admin write** — component/incident data is public, mutations require a key
2. **Constant-time admin key comparison** — `V::secret()` uses `hash_equals()`, not `===`
3. **Status enum enforcement** — `V::enum()` allowlist blocks unknown values
4. **Lifecycle transition guard** — resolved incidents cannot be updated (prevents accidental reopen)
5. **Immutable update history** — incident updates are append-only

---

## API

| Method | Path | Auth | Description |
|---|---|---|---|
| `GET` | `/components` | — | List all components |
| `POST` | `/components` | X-Admin-Key | Create a component |
| `PATCH` | `/components/{id}` | X-Admin-Key | Update component status |
| `GET` | `/incidents` | — | List incidents (`?open=1` for active only) |
| `GET` | `/incidents/{id}` | — | Incident detail + update timeline |
| `POST` | `/incidents` | X-Admin-Key | Create an incident |
| `PATCH` | `/incidents/{id}` | X-Admin-Key | Update incident status |
| `POST` | `/incidents/{id}/updates` | X-Admin-Key | Add update message |

---

## Status Values

**Component:** `operational` | `degraded` | `partial_outage` | `major_outage`

**Incident:** `investigating` → `identified` → `monitoring` → `resolved`

**Impact:** `none` | `minor` | `major` | `critical`

---

## Core Pattern: Admin Key Auth

```php
// V::secret() checks: $expected !== '' && hash_equals($expected, $actual)
private function requireAdmin(ServerRequestInterface $request): bool
{
    return V::secret($this->adminKey, $request->getHeaderLine('X-Admin-Key'));
}
```

**Why `V::secret()` not `=== $key`:** `===` leaks timing information — `hash_equals()` is constant-time.

---

## Core Pattern: Enum Validation

```php
// V::enum takes class name — returns typed enum instance or null
$statusEnum = V::enum($body['status'] ?? null, ComponentStatus::class);

if (!$statusEnum instanceof ComponentStatus) {
    return $this->responseFactory->create(
        ['error' => 'status must be one of: ' . implode(', ', ComponentStatus::values())],
        422,
    );
}

// Use the typed enum directly — no ::from() needed
$component = $this->repository->updateComponentStatus($id, $statusEnum);
```

---

## Core Pattern: Transition Guard

```php
// Resolved incidents are immutable — prevents accidental reopen
if ($incident->status->isResolved()) {
    return $this->responseFactory->create(
        ['error' => 'Resolved incidents cannot be updated.'],
        409,  // Conflict — valid request, wrong resource state
    );
}
```

---

## Full Lifecycle Example

```
POST /incidents {title: "DB lag", impact: "major"}
→ 201 {status: "investigating", resolved_at: null}

POST /incidents/1/updates {status: "identified", message: "Root cause found."}
→ 201

PATCH /incidents/1 {status: "monitoring"}
→ 200 {status: "monitoring"}

PATCH /incidents/1 {status: "resolved"}
→ 200 {status: "resolved", resolved_at: "2026-05-26T10:00:00+00:00"}

PATCH /incidents/1 {status: "monitoring"}
→ 409 Resolved incidents cannot be updated.

GET /incidents?open=1
→ 200 {count: 0}  — resolved incidents excluded
```

---

## Test Results

```
46 tests / 93 assertions — all PASS
PHPStan level 8 — no errors
PHP CS Fixer — clean
```

---

## Key Takeaways

| Pattern | Rule |
|---|---|
| Admin key | `V::secret()` — `hash_equals()` + non-empty guard |
| Enum validation | `V::enum($raw, Enum::class)` — returns typed enum, blocks unknown values |
| Transition guard | Check `isResolved()` before any write — 409 Conflict |
| `resolved_at` | Server-set on transition to resolved — never from body |
| Integer IDs | `ctype_digit() + > 0` — rejects strings, negatives, zero |
| Update history | Append-only — no edit/delete of updates |

Full guide: [`docs/howto/service-status-page.md`](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/service-status-page.md) in the NENE2 repository.
