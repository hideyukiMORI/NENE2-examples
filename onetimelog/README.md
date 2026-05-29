# onetimelog — FT184: One-Time Secret API & ATK-01~12 Cracker Attack Test

> **NENE2 Field Trial 184** — クラッカー攻撃試験周期 (ATK-01〜12). Atomic secret consumption via `UPDATE WHERE consumed=0`. Token IS the credential.

---

## What This Trial Proves

A one-time secret stores a message accessible exactly once. Without careful implementation, attackers can:
- **Read secrets twice** via race conditions (two concurrent requests)
- **Delete others' secrets** via IDOR (no user ownership check)
- **Pre-mark secrets as consumed** via mass assignment (`consumed=1` in body)
- **Brute-force token space** if entropy is too low

This trial demonstrates all 12 cracker attack vectors and proves they are mitigated.

---

## API

| Method | Path | Auth | Description |
|---|---|---|---|
| `POST` | `/secrets` | X-User-Id | Create a one-time secret |
| `GET` | `/secrets` | X-User-Id | List own secrets (metadata only, no message) |
| `GET` | `/secrets/{token}` | — | Read + consume (token IS the credential) |
| `DELETE` | `/secrets/{token}` | X-User-Id | Cancel before reading (must own) |

---

## ATK-01~12 Results

| ID | Attack Vector | Defense | Result |
|---|---|---|---|
| ATK-01 | SQL injection in token | PDO parameterized queries | ✅ PASS |
| ATK-02 | IDOR cross-user delete | `WHERE token=? AND user_id=?` | ✅ PASS |
| ATK-03 | Mass assignment (`consumed=1` in body) | Server-side fields only | ✅ PASS |
| ATK-04 | XSS payload in message | JSON API — no HTML rendering | ✅ PASS |
| ATK-05 | Double-encoded / malformed token | `/^[0-9a-f]{64}$/` format check | ✅ PASS |
| ATK-06 | Auth bypass on read | Token IS the credential — by design | ✅ PASS |
| ATK-07 | Message as non-string (int/bool/null) | `V::str()` enforces `is_string()` | ✅ PASS |
| ATK-08 | 20-digit limit overflow | `V::queryInt()` strlen > 18 guard | ✅ PASS |
| ATK-09 | ReDoS in limit parameter | `ctype_digit()` — O(n), no backtracking | ✅ PASS |
| ATK-10 | Brute force token | `random_bytes(32)` = 2^256 entropy | ✅ PASS |
| ATK-11 | Race condition double-read | `UPDATE WHERE consumed=0` + rowCount | ✅ PASS |
| ATK-12 | Header injection in X-User-Id | `V::userId()` `ctype_digit()` + PSR-7 | ✅ PASS |

**12/12: PASS**

---

## Core Pattern: Atomic Consumption

```php
final class SecretRepository
{
    public function consumeByToken(string $token, ?string $password): ?Secret
    {
        // Step 1: Fetch (ordinary SELECT — not the guard)
        $stmt = $this->pdo->prepare('SELECT * FROM secrets WHERE token = :token');
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) return null;

        $secret = Secret::fromRow($row);

        if ($secret->consumed)  return null;  // already consumed
        if ($secret->isExpired()) return null; // expired

        // Password check (constant-time)
        if ($secret->passwordHash !== null) {
            if ($password === null) return null;
            if (!hash_equals($secret->passwordHash, hash('sha256', $password))) return null;
        }

        // Step 2: Atomic guard — only one reader wins
        $update = $this->pdo->prepare(
            'UPDATE secrets SET consumed = 1 WHERE token = :token AND consumed = 0'
        );
        $update->execute(['token' => $token]);

        if ($update->rowCount() === 0) return null; // race loser

        return $secret; // race winner gets the message
    }
}
```

---

## Token Generation

```php
$token = bin2hex(random_bytes(32)); // 64 hex chars = 256-bit CSPRNG entropy
```

**Token format validation** (ATK-05):
```php
private const TOKEN_PATTERN = '/^[0-9a-f]{64}$/';
// Rejects: uppercase, path traversal ../../, URL-encoded chars, integers, empty
```

---

## IDOR Prevention (ATK-02)

```php
// DELETE requires BOTH token AND user_id match
'DELETE FROM secrets WHERE token = :token AND user_id = :user_id AND consumed = 0'
// Returns 404 regardless of reason — avoids enumeration oracle
```

---

## Mass Assignment Prevention (ATK-03)

```php
// POST /secrets — only these fields accepted from body:
$message   = V::str($body['message'] ?? null, 10000);   // user content
$password  = V::str($body['password'] ?? null, 512);    // optional
$expiresAt = V::isoDatetime($body['expires_at'] ?? null); // optional

// Server-side only (not from body):
$token     = bin2hex(random_bytes(32)); // generated
$consumed  = 0;                         // always starts at 0
$createdAt = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
```

---

## V.php Validation Chain

```php
// ATK-07: rejects int/bool/null/array as message
$message = V::str($body['message'] ?? null, 10000);

// ATK-12: rejects CRLF, negatives, floats, strings
$userId = V::userId($request->getHeaderLine('X-User-Id'));

// ATK-08/09: overflow + ReDoS safe
$limit = V::queryInt($params, 'limit', 1, 100, 20);
```

---

## Test Results

```
13 tests / 24 assertions — all PASS (one-time read, password, expiry, IDOR, mass-assignment)
PHPStan level 8 — no errors
PHP CS Fixer — clean
```

---

## Key Takeaways

| Pattern | Rule |
|---|---|
| Atomic consumption | `UPDATE WHERE consumed=0` + `rowCount()` — not SELECT-then-UPDATE |
| Token entropy | `random_bytes(32)` minimum (256 bits) — never sequential IDs |
| Token format | Anchored allowlist regex `/^[0-9a-f]{64}$/` |
| IDOR | All write operations must scope by both token AND user_id |
| Mass assignment | consumed / token / created_at — server-side only |
| Password timing | `hash_equals()` — constant-time, not `===` |
| Wrong password | 404 not 403 — avoids confirming the secret exists |
| Metadata list | Omit message from list endpoint — only reveal on consume |

Full guide: [`docs/howto/one-time-secrets.md`](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/one-time-secrets.md) in the NENE2 repository.
