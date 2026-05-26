# reminderlog — ISO 8601 Datetime Validation & Timezone-Aware API

**FT181** · NENE2 Field Trial · v1.5.116

---

## What This Example Proves

User-submitted datetime strings require two layers of validation:

1. **Format** — `V::isoDatetime()`: strict `±HH:MM` offset required, invalid offsets (e.g., `+25:00`) rejected, overflow dates (Feb 30) caught by round-trip comparison
2. **Future check** — `V::futureDatetime()`: correct **cross-timezone** comparison using `DateTimeImmutable` objects, not string comparison

---

## The Critical Bug Caught (and Fixed)

`V::futureDatetime()` originally used string comparison (`$dt > $now`). This fails when the submitted datetime and the server's "now" use different timezone offsets:

| Input | Actual (UTC) | String compare vs `2026-06-01T10:00:00+00:00` | Object compare | Correct? |
|---|---|---|---|---|
| `2026-06-01T18:00:00+09:00` | UTC 09:00 (past) | `"T18" > "T10"` → future ❌ | UTC 09 < UTC 10 → past ✅ | Fixed |
| `2026-06-01T08:00:00-05:00` | UTC 13:00 (future) | `"T08" < "T10"` → past ❌ | UTC 13 > UTC 10 → future ✅ | Fixed |

**Fix**: compare as `DateTimeImmutable` objects — PHP normalises both to UTC before comparing with `>`.

---

## API Design

| Method | Path | Description |
|---|---|---|
| `POST` | `/reminders` | Create reminder with `remind_at` (future ISO 8601) |
| `GET` | `/reminders` | List own reminders (`?status=&limit=`) |
| `PATCH` | `/reminders/:id/cancel` | Cancel pending reminder |

---

## V.php Bugs Discovered

| Bug | Impact | Fix |
|---|---|---|
| `isoDatetime()` accepts `+25:00` | Invalid TZ offset accepted | Added explicit range check: `tzHours > 14` → null |
| `futureDatetime()` string comparison | Cross-TZ comparison wrong | Changed to `DateTimeImmutable` object comparison |

---

## Key Implementation

```php
// ✅ Correct: DateTimeImmutable object comparison for future check
public static function futureDatetime(mixed $raw, string $now): ?string
{
    $dt = self::isoDatetime($raw);
    if ($dt === null) return null;

    $dtObj  = DateTimeImmutable::createFromFormat(DATE_ATOM, $dt);
    $nowObj = DateTimeImmutable::createFromFormat(DATE_ATOM, $now);

    if ($dtObj === false || $nowObj === false) return null;

    return $dtObj > $nowObj ? $dt : null;  // UTC-normalised comparison
}

// ✅ Correct: isoDatetime validates offset range
if ($tzHours > 14 || $tzMinutes > 59 || ($tzHours === 14 && $tzMinutes > 0)) {
    return null;
}
```

```php
// In handler — use DateTimeImmutable for timezone-preserving "now"
$now      = (new DateTimeImmutable())->format(DATE_ATOM);
$remindAt = V::futureDatetime($rawRemindAt, $now);
```

---

## Test Results

```
PHPUnit 11.5.55 · PHP 8.4.21

..........................  26 / 26 (100%)

OK (26 tests, 64 assertions)
PHPStan level 8: No errors
PHP CS Fixer: No issues

NENE2 core: 431 tests / 999 assertions — OK (includes 2 new cross-TZ regression tests)
```

---

## How-to Guide

→ [`docs/howto/iso-datetime-validation.md`](../../NENE2/docs/howto/iso-datetime-validation.md)
