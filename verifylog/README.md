# verifylog — FT188: Numeric Verification Code

> **NENE2 Field Trial 188** — 6-digit SMS/email verification code with brute-force protection, constant-time comparison, and replay prevention. ATK-01〜12 全 Pass。

---

## What This Trial Proves

A contact verification flow (email or phone) with full cracker-mindset attack coverage:
1. **Brute-force protection** — 3 max attempts → 429 Locked (fail-first counting)
2. **Timing attack prevention** — `hash_equals()` constant-time comparison (ATK-10)
3. **Code replay prevention** — verified code returns 410 Gone (ATK-11)
4. **User enumeration prevention** — `POST /verifications` always returns 202
5. **Mass assignment protection** — `code_hash` / `verified_at` set server-side only
6. **SQL injection prevention** — integer-only path param (`ctype_digit` + strlen > 18 guard)

---

## API

| Method | Path | Description |
|---|---|---|
| `POST` | `/verifications` | Request a code (always 202) |
| `POST` | `/verifications/{id}/check` | Submit code (3 attempts max) |
| `GET` | `/verifications/{id}` | Status check (no code revealed) |

---

## Core Pattern: Code Generation

```php
// Generate cryptographically random 6-digit code
$plainCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$codeHash  = hash('sha256', $plainCode);

// Store hash — NEVER the plaintext
INSERT INTO verifications (contact, code_hash, expires_at, created_at)
VALUES (:contact, :code_hash, :expires_at, :now)
```

`random_int(0, 999999)` uses CSPRNG. `str_pad(..., 6, '0', STR_PAD_LEFT)` ensures leading zeros (e.g., `000042`).

---

## Core Pattern: Constant-Time Comparison

```php
// ATK-10: hash_equals prevents timing attack
$valid = hash_equals($v->codeHash, hash('sha256', $submittedCode));
```

**Why not `===`:** `===` short-circuits on the first mismatch — an attacker can measure timing differences to narrow down the correct code. `hash_equals()` is constant-time regardless of where the mismatch occurs.

---

## Core Pattern: Fail-First Attempt Counting

```php
// Increment BEFORE checking — prevents race exploitation
UPDATE verifications SET attempts_count = attempts_count + 1 WHERE id = :id

// ATK-10: constant-time comparison
$valid = hash_equals($v->codeHash, hash('sha256', $submittedCode));
```

Incrementing attempts **before** the comparison ensures concurrent requests cannot bypass the limit.

---

## Response Design

| Scenario | Status | Body |
|---|---|---|
| Code correct | 200 | `{verified: true}` |
| Code wrong, attempts remaining | 422 | `{error: "Incorrect code.", attempts_left: N}` |
| Max attempts reached | 429 | `{error: "Too many failed attempts. Request a new code."}` |
| Already verified (replay) | 410 | `{error: "This verification has already been completed."}` |
| Expired | 410 | `{error: "Verification has expired. Request a new code."}` |
| Not found | 404 | `{error: "Verification not found."}` |

---

## ATK-01〜12 全 Pass

| ATK | 攻撃 | 防御 |
|---|---|---|
| 01 | SQL injection in `{id}` | `ctype_digit()` + strlen > 18 ガード |
| 02 | IDOR — 他者の verification ID で check | 同一 404 — ownership oracle なし |
| 03 | Mass assignment (code_hash/verified_at) | サーバー側のみ設定 |
| 04 | XSS in contact | JSON output のみ — contact をレスポンスに返さない |
| 05 | Brute force 6桁コード | 3回失敗で 429 Locked |
| 06 | Auth bypass | verified_at はサーバーのみ設定 |
| 07 | Type confusion (code as int/bool/array) | `is_string()` + `ctype_digit()` |
| 08 | Integer overflow in `{id}` | strlen > 18 guard |
| 09 | ReDoS-style code input | `ctype_digit()` O(n) |
| 10 | Timing attack on code comparison | `hash_equals()` 定数時間 |
| 11 | Code replay after success | 410 Gone |
| 12 | CRLF injection in headers | PSR-7 が HTTP 層で拒否 |

---

## Test Results

```
48 tests / 103 assertions — all PASS
PHPStan level 8 — no errors
PHP CS Fixer — clean
ATK-01〜12 全 Pass
```

---

## Key Takeaways

| Pattern | Rule |
|---|---|
| Code generation | `random_int(0, 999999)` CSPRNG + `str_pad(..., 6)` for leading zeros |
| Hash storage | `hash('sha256', $code)` — never store plaintext code |
| Comparison | `hash_equals()` — constant-time, prevents timing attack |
| Attempt counting | Increment **before** comparison — fail-first prevents race exploitation |
| Request endpoint | Always 202 — prevents user enumeration |
| Replay prevention | `verified_at` set → 410 Gone on all subsequent checks |
| Type validation | `is_string()` before `ctype_digit()` — rejects int/bool/array JSON |

Full guide: [`docs/howto/numeric-verification-code.md`](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/numeric-verification-code.md) in the NENE2 repository.
