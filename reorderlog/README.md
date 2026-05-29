# reorderlog — Bulk Reorder (Drag-and-Drop Ordering)

> **FT352** &nbsp;·&nbsp; [NENE2 Examples](https://github.com/hideyukiMORI/NENE2-examples) &nbsp;·&nbsp; [Howto guide ↗](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/bulk-reorder-api.md)

Reorder a whole list in one request. Server-assigned positions (the client sends only the order of ids), board-scoped ownership, and a single integrity check that rejects partial / foreign / unknown ids.

## The interesting bit: surviving `UNIQUE(board_id, position)`

The `items` table enforces `UNIQUE (board_id, position)`. SQLite checks that constraint **per row** as an `UPDATE` is applied, so *any* reorder that swaps positions — whether done row-by-row or in a single `CASE WHEN` statement — transiently produces a duplicate position and fails:

```
UNIQUE constraint failed: items.board_id, items.position
```

`ReorderService` fixes this with a **two-phase update inside one transaction**: first shift every position into a collision-free negative range (`position = -1 - position`), then assign the final positions. `tests/Reorder/ReorderTest.php::testReorderAdjacentSwapDoesNotCollide` proves the adjacent-swap case that a naive update would break.

## Run

```bash
composer install
composer test        # PHPUnit (13 tests)
composer analyse     # PHPStan level 8
composer cs          # PHP CS Fixer dry-run
```

## Contents

| Path | Description |
|------|-------------|
| `src/Reorder/ReorderService.php` | Two-phase transactional reorder (the core pattern) |
| `src/Reorder/ReorderRepository.php` | Board / item reads |
| `src/Reorder/RouteRegistrar.php` | HTTP handlers, ownership + id-set validation |
| `src/AppFactory.php` | DI wiring (executor + transaction manager) |
| `database/schema.sql` | SQLite schema with `UNIQUE(board_id, position)` |
| `tests/` | Happy-path + executable ATK assessment (IDOR, foreign item, partial, injection, duplicates, enumeration) |

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/boards/{boardId}/items` | List items in position order (owner only) |
| `PUT` | `/boards/{boardId}/order` | Reorder; body `{ "ids": [3, 1, 4, 2] }` |

Auth is a simplified `X-User-Id` header standing in for an authenticated owner.

## Related

- [Howto: Bulk Reorder API](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/bulk-reorder-api.md)
- [Howto: Use database transactions](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/use-transactions.md)
- [NENE2 framework](https://github.com/hideyukiMORI/NENE2)
