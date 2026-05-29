# shortlog — FT183: URL Shortener API & SSRF Prevention

> **NENE2 Field Trial 183** — 脆弱性診断周期 (VULN-A〜L). SSRF prevention via scheme allowlist + private IP blocking + injectable DNS resolver for tests.

---

## What This Trial Proves

A URL shortener stores user-submitted URLs as redirect targets. Without validation, attackers can point these URLs at **internal services** — a Server-Side Request Forgery (SSRF) attack.

This trial demonstrates:
1. **Why `filter_var(FILTER_VALIDATE_URL)` alone is insufficient** — it accepts `javascript:alert(1)` and `ftp://`
2. **SSRF prevention using `parse_url()` + scheme allowlist + IP range filter**
3. **Injectable DNS resolver** for deterministic unit tests without real DNS calls

---

## API

| Method | Path | Auth | Description |
|---|---|---|---|
| `POST` | `/links` | X-User-Id | Create a short link |
| `GET` | `/links` | X-User-Id | List your links |
| `GET` | `/links/{slug}` | — | Get link details |
| `DELETE` | `/links/{slug}` | X-User-Id | Delete own link |

---

## SSRF Attack Vectors — All Blocked

| URL | Block reason |
|---|---|
| `http://127.0.0.1/admin` | loopback IP (`NO_RES_RANGE`) |
| `http://localhost/` | exact `localhost` match |
| `http://internal.localhost/` | `.localhost` suffix |
| `http://10.0.0.1/` | private IP (`NO_PRIV_RANGE`) |
| `http://192.168.1.1/router` | private IP |
| `http://169.254.169.254/` | AWS IMDS — reserved IP (`NO_RES_RANGE`) |
| `javascript:alert(1)` | scheme not in `['http', 'https']` |
| `file:///etc/passwd` | scheme not allowed |
| `ftp://example.com/` | scheme not allowed |

---

## Core Pattern: UrlValidator

```php
final class UrlValidator
{
    /** @param (callable(string): string)|null $ipResolver */
    public function __construct(private readonly mixed $ipResolver = null) {}

    public function isSafe(string $url): bool
    {
        $parts = parse_url($url);

        // Must have scheme and host
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        // Scheme allowlist — rejects javascript:, file://, ftp://, data:
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower($parts['host']);

        // Block localhost aliases
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return false;
        }

        // IP literal: check directly
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return !$this->isBlockedIp($host);
        }

        // Hostname: resolve → check resolved IP
        /** @var callable(string): string $resolver */
        $resolver = $this->ipResolver ?? static fn (string $h): string => gethostbyname($h);
        $resolved = $resolver($host);

        if ($resolved !== $host) {        // resolved successfully
            return !$this->isBlockedIp($resolved);
        }

        return true;  // unresolvable → allow
    }

    private function isBlockedIp(string $ip): bool
    {
        if ($ip === '::1') return true;   // IPv6 loopback

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }
}
```

### Injectable DNS Resolver (for tests)

```php
$stubResolver = static function (string $host): string {
    return match ($host) {
        'private.internal'   => '10.0.0.1',       // blocked
        'public.example.com' => '93.184.216.34',  // allowed
        default              => $host,             // unresolvable → allowed
    };
};

$validator = new UrlValidator($stubResolver);
$router    = AppFactory::create($pdo, $validator);
```

---

## VULN-A〜L Results

| ID | Vulnerability | Result |
|---|---|---|
| VULN-A | Integer overflow (limit param) | ✅ PASS |
| VULN-B | Type confusion (url/slug as int/bool/null) | ✅ PASS |
| VULN-C | SQL injection in slug | ✅ PASS |
| VULN-D | Parameter pollution | ✅ PASS |
| VULN-E | IDOR (cross-user delete) | ✅ PASS |
| VULN-F | ReDoS immunity | ✅ PASS |
| VULN-G | Path traversal | N/A |
| VULN-H | Timing attacks (hash_equals) | ✅ PASS |
| VULN-I | Empty secret bypass | ✅ PASS |
| VULN-J | ISO 8601 date overflow (Feb 30, +25:00) | ✅ PASS |
| VULN-K | SSRF | ✅ PASS |
| VULN-L | Mass assignment (click_count) | ✅ PASS |

**11/11 applicable: PASS**

---

## Test Results

```
9 tests / 24 assertions — all PASS (SSRF vectors + IDOR + mass-assignment)
PHPStan level 8 — no errors
PHP CS Fixer — clean
```

---

## Key Takeaways

| Pattern | Rule |
|---|---|
| URL validation | `parse_url()` + scheme allowlist, NOT `filter_var(FILTER_VALIDATE_URL)` alone |
| Private IP block | `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` |
| DNS in tests | Inject a stub resolver callback — no real network calls |
| Slug safety | Anchored allowlist regex: `[a-z0-9_-]{3-20}` |
| IDOR | `WHERE slug = ? AND user_id = ?` in all write queries |
| Mass assignment | Server-side fields set in repository, never from body |

Full guide: [`docs/howto/url-shortener-ssrf.md`](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/url-shortener-ssrf.md) in the NENE2 repository.
