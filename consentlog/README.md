# consentlog — FT189: Privacy Consent Management

> **NENE2 Field Trial 189** — GDPR-style consent tracking with immutable history, IDOR prevention, and user enumeration resistance. VULN-A〜L 全 Pass。

---

## What This Trial Proves

A privacy consent management API with full security audit coverage:
1. **Idempotent UPSERT** — grant/withdraw are safe to repeat; `UNIQUE(user_id, purpose)` ensures atomicity
2. **Append-only history** — every consent change is recorded; the current state table is never the history
3. **IDOR prevention** — all reads/writes scope `WHERE user_id = :user_id`
4. **User enumeration resistance** — unknown user returns empty 200, not 404
5. **Purpose slug validation** — `ctype_alnum()` O(n), no regex, no ReDoS

---

## API

| Method | Path | Header | Description |
|---|---|---|---|
| `POST` | `/consents` | `X-User-Id` | Grant consent (201) |
| `DELETE` | `/consents/{purpose}` | `X-User-Id` | Withdraw consent (200) |
| `GET` | `/consents` | `X-User-Id` | List current consents |
| `GET` | `/consents/{purpose}/history` | `X-User-Id` | Audit history (append-only) |

---

## Core Pattern: Idempotent UPSERT

```php
// Grant — re-granting an already-granted purpose is safe
INSERT INTO consents (user_id, purpose, granted, created_at, updated_at)
VALUES (:user_id, :purpose, 1, :now, :now)
ON CONFLICT(user_id, purpose) DO UPDATE
SET granted = 1, updated_at = :now

// Always append to history
INSERT INTO consent_history (user_id, purpose, granted, created_at)
VALUES (:user_id, :purpose, 1, :now)
```

---

## Core Pattern: Purpose Validation

```php
// VULN-G: is_string before ctype_alnum — rejects int/array/null
if (!is_string($raw)) return null;

// VULN-I: ctype_alnum is O(n) — no regex, no ReDoS
// VULN-D: alphanumeric only — no HTML, no SQL metacharacters
if (!ctype_alnum($raw) || strlen($raw) > 50) return null;
```

---

## VULN-A〜L 全 Pass

| VULN | 攻撃 | 防御 |
|---|---|---|
| A | SQL injection in X-User-Id | `ctype_digit()` + strlen > 18 guard |
| B | IDOR — 他ユーザーの同意操作 | `WHERE user_id = :user_id` 全クエリ |
| C | Mass assignment (granted) | エンドポイントが granted を決定 |
| D | XSS in purpose | `ctype_alnum()` 英数字のみ |
| E | 同意状態の直接書き換え | grant/withdraw は独立エンドポイント |
| F | ユーザー列挙 | 不明 user_id → 200 空配列 |
| G | Type confusion | `is_string()` + `ctype_alnum()` |
| H | 同意リプレイ | history は append-only |
| I | ReDoS in purpose | `ctype_alnum()` O(n) |
| J | Integer overflow in X-User-Id | strlen > 18 guard |
| K | race condition | UNIQUE UPSERT 原子性 |
| L | CRLF injection | PSR-7 が HTTP 層で拒否 |

---

## Test Results

```
51 tests / 142 assertions — all PASS
PHPStan level 8 — no errors
PHP CS Fixer — clean
VULN-A〜L 全 Pass
```

---

## Key Takeaways

| Pattern | Rule |
|---|---|
| UPSERT atomicity | `UNIQUE(user_id, purpose)` + `ON CONFLICT DO UPDATE` |
| History | Separate `consent_history` table — append-only, never updated |
| Purpose validation | `is_string()` → `ctype_alnum()` → length check — no regex |
| User enumeration | Unknown user → 200 empty, not 404 |
| IDOR | `WHERE user_id = :user_id` on all reads and writes |
| Server-controlled fields | `granted` is set by the endpoint, not from body |

Full guide: [`docs/howto/privacy-consent-management.md`](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/privacy-consent-management.md) in the NENE2 repository.
