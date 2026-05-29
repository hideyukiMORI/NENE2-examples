# cqrslog — FT233: CQRS Pattern API

> **NENE2 Field Trial 233** — Command Query Responsibility Segregation: a normalised
> write model and a denormalised SQL-VIEW read model, with separate command/query
> handlers and read DTOs.

Executable companion to the NENE2 howto
[`cqrs-pattern.md`](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/cqrs-pattern.md).
Validates the **released** framework (`hideyukimori/nene2: ^1.5`).

---

## What This Trial Proves

- **Two sides, one DB** — writes go through `OrderCommandHandler` (normalised
  `orders` + `order_items`); reads go through `OrderQueryHandler` against the
  `order_summary` **VIEW**. No shared state, separate model objects.
- **Command then query** — after a write succeeds, the controller re-reads via the
  read model, so the response always reflects the view's projection (computed
  `item_count` / `total_cents`).
- **Atomic writes** — `place` runs in a `transactional()`; the command handler is
  rebuilt with the transaction-scoped executor (IMP-18).
- **Integer money** — `total_cents` summed in the view; `?? 0` guards the no-items
  `NULL`. `is_int` rejects float/string quantities and prices.

---

## API

| Method | Path | Side | Description |
|---|---|---|---|
| `POST` | `/orders` | Write | Place order (command) → 201 |
| `PATCH` | `/orders/{id}/status` | Write | Update status (command) → 200 / 404 |
| `GET` | `/orders` | Read | List summaries (`?status=`) |
| `GET` | `/orders/{id}` | Read | One summary |

---

## Read Model: SQL VIEW

```sql
CREATE VIEW order_summary AS
SELECT o.id, o.customer, o.status, o.created_at,
       COUNT(oi.id)                     AS item_count,
       SUM(oi.quantity * oi.unit_price) AS total_cents
FROM orders o
LEFT JOIN order_items oi ON oi.order_id = o.id
GROUP BY o.id;
```

The query handler reads only the view; if the write model's tables change, only the
view definition updates — the read side is untouched.

---

## Test Results

```
13 tests / 21 assertions — all PASS
PHPStan level 8 — no errors
PHP CS Fixer — clean
```

---

## Key Takeaways

| Pattern | Rule |
|---|---|
| Separation | Command handler writes; query handler reads — different objects |
| Read model | A denormalised VIEW = stable query surface over normalised tables |
| Response | Command then query — re-read the view for the response shape |
| Money | Integer cents in the view; `is_int` guards on input |
| Atomicity | `place` wraps order + items in `transactional()` |
