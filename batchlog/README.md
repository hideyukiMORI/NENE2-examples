# batchlog — FT182: Batch Write API & Partial Success Pattern

> **NENE2 Field Trial 182** — `V::bodyInt()` for JSON type confusion prevention; batch-level vs item-level error separation; `array_is_list()` for JSON object detection; MAX_BATCH DoS guard; index-preserving error reporting.

---

## What This Trial Proves

JSON array body APIs need two distinct validation layers:

1. **Batch-level** — is the overall structure valid? (key present? is it a list? is count in range?)
2. **Item-level** — is each element valid independently? (type? range? required fields?)

Treating both layers the same leads to either over-rejection (one bad item kills the entire batch) or over-acceptance (invalid items silently skipped).

---

## API

### `POST /batch`

Create items in bulk. Valid items are created, invalid items are reported with their index.

**Request:**
```json
{
  "items": [
    {"name": "Widget A", "quantity": 3, "price_cents": 999},
    {"name": "Widget B", "quantity": "5", "price_cents": 4999},
    {"name": "", "quantity": 1, "price_cents": 100}
  ]
}
```

**Response 200 (partial success):**
```json
{
  "created": [
    {"id": 1, "user_id": 1, "name": "Widget A", "quantity": 3, "price_cents": 999, "created_at": "..."}
  ],
  "errors": [
    {"index": 1, "error": "quantity must be an integer between 1 and 999."},
    {"index": 2, "error": "name is required (max 100 chars)."}
  ],
  "total_created": 1,
  "total_errors": 2
}
```

**Response 422 (batch-level error):**
```json
{"error": "\"items\" must not be empty."}
```

### `GET /items?limit=N`

List items for the authenticated user (X-User-Id header).

---

## Error Response Convention

| Scenario | Status | Reasoning |
|---|---|---|
| Missing/invalid `items` key | `422` | Request shape is fundamentally wrong |
| `items` is not an array | `422` | Type mismatch at batch level |
| Empty items array | `422` | No work to do |
| Exceeds MAX_BATCH (50) | `422` | DoS guard; don't iterate |
| Some items invalid | `200` | Batch succeeded; report per-item |
| All items invalid | `200` | Batch succeeded (with zero created) |

---

## Key Pattern: V::bodyInt() vs V::queryInt()

The core validation insight from this trial:

```php
// Query string: always strings, so string "5" is accepted
V::queryInt(['limit' => '5'], 'limit', 1, 100)   // → 5 ✓

// JSON body: PHP int required; string "5" is a type confusion attack
V::bodyInt(5, 1, 999)       // → 5     ✓ PHP int
V::bodyInt("5", 1, 999)     // → null  ✗ ATK-07: type confusion
V::bodyInt(5.5, 1, 999)     // → null  ✗ float
V::bodyInt(true, 1, 999)    // → null  ✗ bool
V::bodyInt(null, 1, 999)    // → null  ✗ null
```

`is_int()` (not `is_numeric()`) is the correct check. `is_numeric("5")` returns `true`, which would silently accept the type confusion.

---

## array_is_list() — JSON Object vs JSON Array

PHP's `json_decode` maps:
- JSON objects → associative arrays: `["name" => "foo"]`
- JSON arrays  → list arrays: `[1, 2, 3]`

The guard `!is_array($item) || array_is_list($item)` catches:
- Scalars (not arrays) — caught by `!is_array`
- JSON arrays like `[1, 2]` — caught by `array_is_list`
- Only plain JSON objects pass

```php
foreach ($rawItems as $index => $rawItem) {
    $intIndex = (int) $index; // cast: foreach keys can be string

    if (!is_array($rawItem) || array_is_list($rawItem)) {
        $errors[] = ['index' => $intIndex, 'error' => 'Each item must be a JSON object.'];
        continue;
    }
    // safe to access $rawItem['name'], etc.
}
```

---

## Test Results

```
11 tests / 20 assertions — all PASS
PHPStan level 8 — no errors
PHP CS Fixer — clean
```

---

## Files

```
src/
  Item/
    ItemRepository.php    — create / list, MAX_BATCH=50 constant
    RouteRegistrar.php    — POST /batch (partial success) + GET /items
  AppFactory.php
tests/
  Item/
    BatchTest.php         — 11 tests covering all scenarios
database/
  schema.sql              — items table (SQLite)
```

---

## Key Takeaways

| Pattern | Rule |
|---|---|
| JSON int field | `V::bodyInt()` / `is_int()` — not `is_numeric()` |
| JSON object vs array | `!is_array() \|\| array_is_list()` |
| Batch-level error | `422` — before iterating |
| Item-level error | `200` — index + message in `errors[]` |
| Size DoS guard | `count($items) > MAX_BATCH` → `422` before loop |
| Error correlation | Preserve original `$index`, cast to `int` |

Full guide: [`docs/howto/batch-api-partial-success.md`](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/batch-api-partial-success.md) in the NENE2 repository.
