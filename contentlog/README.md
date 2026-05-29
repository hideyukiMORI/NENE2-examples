# contentlog — FT301: Content Negotiation (JSON API)

> **NENE2 Field Trial 301** — How a JSON-only API handles HTTP content negotiation:
> always `application/json` for success, `application/problem+json` for errors, and
> `415` for a non-JSON request `Content-Type`.

Executable companion to the NENE2 howto
[`content-negotiation-api.md`](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/content-negotiation-api.md).
Validates the **released** framework (`hideyukimori/nene2: ^1.5`).

---

## What This Trial Proves

- **Accept header ignored** — success responses are `application/json` for every
  `Accept` value (`*/*`, `text/html`, `application/xml`, …). An API-only service serves
  JSON to all clients rather than returning 406.
- **Errors are `application/problem+json`** — 404 (not found) and 422 (validation) use
  RFC 9457 Problem Details via `ProblemDetailsResponseFactory`, so clients can detect
  errors by `Content-Type` as well as status.
- **Request `Content-Type` → 415** — a POST with an explicit non-JSON type
  (`text/plain`) is rejected `415`; a missing `Content-Type` with a valid JSON body is
  accepted; `application/json; charset=utf-8` is accepted.
- **Trim before validate** — `" "` is treated as missing (`trim()` first).

---

## API

| Method | Path | Success | Errors |
|---|---|---|---|
| `POST` | `/articles` | 201 `application/json` | 415 / 422 `application/problem+json` |
| `GET` | `/articles` | 200 `application/json` | — |
| `GET` | `/articles/{id}` | 200 `application/json` | 404 `application/problem+json` |

---

## Test Results

```
14 tests / 27 assertions — all PASS  (Accept matrix via data provider)
PHPStan level 8 — no errors
PHP CS Fixer — clean
```

---

## Key Takeaways

| Pattern | Rule |
|---|---|
| Success | always `application/json`; ignore `Accept` (no 406) |
| Errors | `application/problem+json` (RFC 9457) for 404 / 422 / 415 |
| Request body | non-JSON `Content-Type` → 415; missing CT + JSON body → ok |
| Validation | `trim()` before the empty check; structured `errors[]` |
