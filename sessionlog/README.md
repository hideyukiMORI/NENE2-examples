# sessionlog — FT186: Multi-Device Session Manager API

> **NENE2 Field Trial 186** — Multi-device session tracking, IDOR prevention, mass-assignment guard, timing-oracle-free revocation.
> VULN-A〜L 脆弱性診断サイクル。

---

## What This Trial Proves

A multi-device session manager needs:
1. **256-bit token entropy** — `bin2hex(random_bytes(32))` for session tokens
2. **IDOR prevention** — all mutations scope `WHERE token = ? AND user_id = ?`
3. **Mass assignment guard** — `token/user_id/created_at/revoked_at` set server-side only
4. **Timing oracle prevention** — generic 404 for all failures (not-found, wrong-user, already-revoked)
5. **Integer overflow guard** — `V::queryInt()` 18-digit strlen limit
6. **Type confusion prevention** — `V::str()` rejects non-string `device_name`/`ip_address`

---

## API

| Method | Path | Header | Description |
|---|---|---|---|
| `POST` | `/sessions` | `X-User-Id` | Create a session |
| `GET` | `/sessions` | `X-User-Id` | List own active sessions |
| `DELETE` | `/sessions/{token}` | `X-User-Id` | Revoke one session |
| `DELETE` | `/sessions` | `X-User-Id` + `X-Current-Session` | Revoke all except current |

---

## Core Pattern: IDOR Prevention

```php
public function revokeForUser(string $token, int $userId): bool
{
    $stmt = $this->pdo->prepare(
        'UPDATE sessions SET revoked_at = :now
         WHERE token = :token AND user_id = :user_id AND revoked_at IS NULL',
    );
    $stmt->execute(['now' => $this->now(), 'token' => $token, 'user_id' => $userId]);

    return $stmt->rowCount() > 0;
}
```

`rowCount() === 0` when: token doesn't exist, belongs to another user, or already revoked.
All three cases return the **same 404** — no ownership oracle.

---

## Core Pattern: Timing Oracle Prevention

```php
private function handleRevokeOne(ServerRequestInterface $request): ResponseInterface
{
    // ...validate userId, rawToken...

    // Returns false for: not found, wrong user, already revoked
    $revoked = $this->repository->revokeForUser($rawToken, $userId);

    if (!$revoked) {
        // Same error message regardless of reason — no timing oracle
        return $this->responseFactory->create(['error' => 'Session not found.'], 404);
    }

    return $this->responseFactory->create([], 204);
}
```

---

## Core Pattern: Mass Assignment Guard

```php
// Body may contain: {"token": "custom", "user_id": 999, "revoked_at": "now"}
// Only safe fields are read:
$deviceName = V::str($body['device_name'] ?? null, 200);  // VULN-B: rejects non-string
$ipAddress  = V::str($body['ip_address'] ?? null, 45);

// token, user_id, created_at, revoked_at → set by repository only
$session = $this->repository->create($userId, $deviceName, $ipAddress);
```

---

## VULN-A〜L Coverage

| Vuln | Pattern | Result |
|---|---|---|
| A | limit 19桁オーバーフロー → `V::queryInt` strlen guard → 422 | ✅ |
| B | `device_name` int/bool/array → `V::str()` rejects → 422 | ✅ |
| C | SQL injection token → PDO param + `/^[0-9a-f]{64}$/` gate → 404 | ✅ |
| D | 負数・小数・hex limit → `ctype_digit` rejects → 422 | ✅ |
| E | 他ユーザーのセッション revoke → `WHERE token AND user_id` → 404 | ✅ |
| F | 100桁 limit string → `ctype_digit` O(n)、regex backtracking なし | ✅ |
| H | 全失敗ケース同一 404（owner / cross-user / not-found 区別なし） | ✅ |
| I | 空/63桁/65桁/パストラバーサルトークン → DB 到達前 404 | ✅ |
| L | body の `token/user_id/revoked_at/created_at` 無視 | ✅ |

---

## Test Results

```
11 tests / 20 assertions — all PASS (IDOR, timing-oracle 404, mass-assignment, revoke-all)
PHPStan level 8 — no errors
PHP CS Fixer — clean
```

---

## Key Takeaways

| Pattern | Rule |
|---|---|
| Token generation | `bin2hex(random_bytes(32))` — 256-bit, 64 hex chars |
| IDOR guard | `WHERE token = ? AND user_id = ?` — both conditions mandatory |
| Timing oracle | Same 404 for not-found, wrong-user, already-revoked |
| Mass assignment | Server sets `token/user_id/created_at/revoked_at` — never from body |
| Overflow guard | `V::queryInt()` strlen > 18 — prevents silent PHP int wrap |
| Token format gate | `/^[0-9a-f]{64}$/` before DB query — blocks SQL injection strings |

Full guide: [`docs/howto/session-management.md`](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/session-management.md) in the NENE2 repository.
