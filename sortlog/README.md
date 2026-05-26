# sortlog — SQL ORDER BY Injection Prevention

**FT180** · NENE2 Field Trial · v1.5.115  
Double special FT: Vulnerability Assessment (VULN-A〜L) + Cracker Attack Test (ATK-01〜12)

---

## What This Example Proves

SQL `ORDER BY` clauses cannot be parameterized with `?` placeholders. User-controlled sort
parameters must be validated against an explicit allowlist before interpolation into SQL.

This FT demonstrates:

- **Allowlist-only sort**: `['id', 'title', 'status', 'created_at']` — nothing else reaches SQL
- **Direction allowlist**: `['asc', 'desc']` — normalised via `strtolower(trim())`
- **PSR-7 single-decode semantics**: `getQueryParams()` decodes once; double-encoded values
  (`cr%2565ated_at` → `cr%65ated_at`) are caught by the allowlist after that one decode
- **Array injection**: `?sort[]=id` gives an array from PSR-7; type check rejects it (422)
- **Null byte injection**: `%00` decoded to actual null byte by PSR-7; `str_contains("\0")` catches it
- **Enum-based status filter**: `V::enum()` for safe `WHERE status = ?` filtering
- **V::queryInt()**: `ctype_digit` + `strlen > 18` guard — O(n), ReDoS-immune limit validation

---

## Vulnerability Assessment (VULN-A〜L)

| ID | Attack Vector | Result |
|---|---|---|
| VULN-A | `?sort='; DROP TABLE articles--` | 422 |
| VULN-B | `?sort=1; SELECT password` (stacked + index) | 422 |
| VULN-C | `?order=asc; UNION SELECT 1,2--` | 422 |
| VULN-D | `?sort=SLEEP(5)` (time-based blind) | 422 in < 100ms |
| VULN-E | `?sort=(SELECT name FROM sqlite_master)` | 422 |
| VULN-F | `?sort=nonexistent_column` | 422 |
| VULN-G | `?order=INVALID` | 422 |
| VULN-H | `?sort=created_at%00` (null byte) | 422 |
| VULN-I | `?sort[]=created_at` (array injection) | 422 |
| VULN-J | `?status=' OR '1'='1` (filter injection) | 422 |
| VULN-K | ReDoS payload in sort | 422 in < 100ms |
| VULN-L | `?sort=1` (column index injection) | 422 |

All 12 vulnerabilities: **blocked** ✓

---

## Cracker Attack Test (ATK-01〜12)

| ID | Attack | Result |
|---|---|---|
| ATK-01 | UNION SELECT via sort field | 422 |
| ATK-02 | `ORDER BY CASE WHEN … THEN … END` | 422 |
| ATK-03 | Subquery extraction `?sort=(...)` | 422 |
| ATK-04 | Comment truncation `?sort=created_at--` | 422 |
| ATK-05 | Double URL encoding (single: 200 ✓, double: 422) | ✓ |
| ATK-06 | Whitespace bypass (TAB/LF/CRLF in sort) | 422 |
| ATK-07 | Hex-encoded column name | 422 |
| ATK-08 | Boolean-based blind `?sort=1 AND 1=1` | 422 |
| ATK-09 | Error-based `?sort=abs(-9223372036854775808)` | 422 |
| ATK-10 | Mass extraction `?limit=999999` (V::queryInt) | 422 |
| ATK-11 | Filter type confusion (array status) | 422 |
| ATK-12 | Compound attack (inject both sort + filter) | 422 |

All 12 attack scenarios: **blocked** ✓

---

## Key Implementation

```php
// In RouteRegistrar — sort column validation
$rawSort = $params['sort'] ?? null;

if ($rawSort !== null) {
    if (!is_string($rawSort)) {                        // array injection
        return $this->responseFactory->create(['error' => 'sort must be a string.'], 422);
    }
    if (str_contains($rawSort, "\0")) {               // null byte (PSR-7 already decoded %00)
        return $this->responseFactory->create(['error' => 'sort contains invalid characters.'], 422);
    }
    // PSR-7 decodes once: single-encoded values (cr%65ated_at → created_at) pass.
    // Double-encoded (cr%2565ated_at → cr%65ated_at in $rawSort) fail the allowlist.
    if (!in_array($rawSort, ArticleRepository::SORT_COLUMNS, true)) {
        return $this->responseFactory->create(
            ['error' => sprintf('sort must be one of: %s.', implode(', ', ArticleRepository::SORT_COLUMNS))],
            422,
        );
    }
    $sortCol = $rawSort;
} else {
    $sortCol = 'created_at';
}
```

```php
// In ArticleRepository — pre-validated values interpolated directly
// $sortCol and $sortDir MUST be allowlist-verified by the caller.
$sql = "SELECT * FROM articles {$where} ORDER BY {$sortCol} {$sortDir} LIMIT ?";
```

---

## Critical Insight: No rawurldecode()

A naive implementation might call `rawurldecode($rawSort)` to "decode for the user". This
creates a double-encoding bypass:

1. `?sort=cr%2565ated_at` arrives
2. PSR-7 decodes `%25` → `%`: `$rawSort = 'cr%65ated_at'`
3. `rawurldecode('cr%65ated_at')` → `'created_at'`
4. Allowlist check passes → SQL injection opportunity (if allowlist were bypassed)

The fix: **do not call rawurldecode**. PSR-7 has already decoded once. Check `$rawSort` directly.

---

## Test Results

```
PHPUnit 11.5.55 · PHP 8.4.21

................................  32 / 32 (100%)

OK (32 tests, 115 assertions)
PHPStan level 8: No errors
PHP CS Fixer: No issues
```

---

## How-to Guide

→ [`docs/howto/sql-orderby-injection.md`](../../NENE2/docs/howto/sql-orderby-injection.md)
