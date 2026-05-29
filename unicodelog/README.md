# unicodelog — FT345: Unicode-Aware Text API

> **NENE2 Field Trial 345** — A profile API that handles Unicode text safely: counts
> codepoints (not bytes), rejects null bytes, accepts every script/emoji/ZWJ sequence,
> and stores tags as a JSON array.

Executable companion to the NENE2 howto
[`unicode-aware-text-api.md`](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/unicode-aware-text-api.md).
Validates the **released** framework (`hideyukimori/nene2: ^1.5`).

---

## What This Trial Proves

- **`mb_strlen`, never `strlen`** — `name` limit is 50 *codepoints*: 50 × `あ`
  (150 bytes) passes, 51 fails; 50 emoji pass. A byte limit would mis-reject these.
- **Null-byte rejection** — `\x00` in `name`/`bio`/any tag → 422, checked *before*
  length and storage (truncation / validation-bypass vector).
- **Multi-script** — Japanese, Arabic, accented Latin, emoji, and ZWJ sequences
  (`👨‍👩‍👧`) are accepted and returned **verbatim** (no normalization).
- **Tag array hardening** — array-only, ≤ 10 items (DoS cap before per-item work),
  each a 1–30-codepoint string; non-string elements → 422; stored with
  `JSON_UNESCAPED_UNICODE`, returned as an array (no double-decode).
- **SQL injection inert** — Unicode SQL payloads stored verbatim via prepared
  statements.

---

## VULN coverage (executable)

| ID | Vulnerability | Finding | Test |
|----|---------------|---------|------|
| V-01 | Null byte injection | ✅ SAFE | `testNullByteIn*Rejected` |
| V-02 | Byte-count overflow (multibyte) | ✅ SAFE | `testFiftyJapaneseCharsPass` |
| V-03 | Tag array type injection | ✅ SAFE | `testNonStringTagRejected` |
| V-04 | SQL injection via Unicode | ✅ SAFE | `testSqlInjectionPayloadStoredVerbatim` |
| V-05 | Homograph / look-alike name | ⚠️ EXPOSED | `testHomographNamesCoexist` |
| V-06 | Oversized tags array DoS | ✅ SAFE | `testTooManyTagsRejected` |
| V-08 | ZWJ sequence length bypass | ✅ SAFE | `testZwjSequenceStoredVerbatim` |

**V-05 is a documented limitation** (also in the howto): names are stored verbatim
without NFC normalization or confusable detection, so `admin` (Latin) and `аdmin`
(Cyrillic `а`) coexist. For high-trust name fields, add
`Normalizer::normalize($name, Normalizer::FORM_C)` + confusable detection. Profile
names here are display-only, so the test asserts the coexistence as the known gap.

---

## API

| Method | Path | Description |
|---|---|---|
| `POST` | `/profiles` | Create → 201 / 422 |
| `GET` | `/profiles` | List |
| `GET` | `/profiles/{id}` | Get |
| `PATCH` | `/profiles/{id}` | Update |
| `DELETE` | `/profiles/{id}` | Delete → 204 |

---

## Test Results

```
19 tests / 31 assertions — all PASS
PHPStan level 8 — no errors
PHP CS Fixer — clean
```

> Requires `ext-mbstring` (standard).

---

## Key Takeaways

| Pattern | Rule |
|---|---|
| Length | `mb_strlen($v, 'UTF-8')` — codepoints, not bytes |
| Null bytes | `str_contains($v, "\x00")` → 422, before length/storage |
| Normalization | none — store/return verbatim (V-05 trade-off documented) |
| Tags | array-only, cap count first, per-item type+length, JSON array storage |
