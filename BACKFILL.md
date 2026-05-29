# Examples Backfill — Status & Handoff

**Purpose.** NENE2 (PHP) has howto guides through **FT349** (259 howtos), but this
examples repo — the *implementation + test* layer — only covered up to **FT190**.
This workstream backfills deep example apps for the documented-but-unimplemented
field trials, in FT order. Each app validates the **released** framework
(`hideyukimori/nene2: ^1.5`) and turns the howto's ATK/VULN claims into
*executable* PHPUnit tests.

> Running directive (2026-05-29): build non-stop, from the frontier in order,
> until told to stop. Insert progress reports / records as we go. When the
> implementation reveals a howto bug or a framework improvement, fix it in the
> NENE2 repo per its rules (Issue → branch → PR → CI → merge).

---

## Coverage

- **Already covered:** FT97–FT190 **(FT142 is a gap)**.
- **Added by this workstream:** FT352 `reorderlog`, FT194 `assetlog`,
  FT195 `vaultlog`, FT196 `ticketlog`, FT197 `templatelog`, FT198 `walletlog`.
- Also pending: backfill the **FT142** gap.

### ⚠️ Selection method (corrected — read this)

**Do NOT map by FT number for FT199+.** Investigation showed the FT→howto→dir
mapping breaks down past ~FT198: most FT199–FT349 howtos are variants / re-docs
of topics that were **already implemented** under an earlier `*log/` dir, and
only FT196–198 carry a clean `Field trial: FTxxx (…/NENE2-FT/<dir>/)` line.

The real backlog is **howto topics that have no example dir** — far fewer than
the naive "159". Pick the next target this way:
```bash
ls -d ../NENE2-examples-repo/*/ | sed 's#.*/\([^/]*\)/#\1#'   # existing dirs
ls ../NENE2/docs/howto/*.md                                   # all howtos
```
and build howtos whose topic has no matching dir.

**Verified-uncovered candidates (no example dir as of 2026-05-29):**
~~`project-task-management`~~ (→ `projtrack`),
~~`inventory-management`~~ (→ `inventorylog`),
~~`expense-tracker`~~ (→ `expenselog`),
~~`budget-tracking`~~ (→ `budgetlog`, hardened past the howto's ATK findings),
~~`contact-management`~~ (→ `contactlog`),
~~`habit-tracker`~~ (→ `habitlog`, hardened past the howto's ATK findings),
~~`media-watchlist`~~ (→ `watchlog`),
`price-history`, `shift-management`, `multilingual-content`,
`article-relations-api`, `article-versioning-api`, `aggregate-reporting`.
(`quota-management` overlaps `limitlog`/`throttlelog` — likely already covered; verify, else skip.)
(Confirm each against the dir list before building — some may overlap a
differently-named dir, e.g. `report*`→`reportlog`, `quota*`→`limitlog`.)

- **Resume point → `price-history`** (confirm no `price*` dir), then
  `shift-management`, `multilingual-content`, `article-relations-api`,
  `article-versioning-api`, `aggregate-reporting`.

> Test note: pass query-string params as **strings** in tests
> (`withQueryParams(['limit' => '2'])`) — `QueryStringParser` reads `getQueryParams()`
> and Nyholm does not coerce ints. (Hit in `projtrack`.)
> PSR-4 note: a class must live in a file matching its name —
> `AccountNotFoundException` in `AccountNotFoundException.php`, not a shared
> `FooException.php`, or it won't autoload (silent `Error` → 500). (Hit in `budgetlog`.)
> When a howto's own ATK lists EXPOSED items, build the **hardened** version and
> assert the fixes in tests (see `budgetlog`).

> PHPStan note: it treats repo `findById()` as pure, so a second call with the
> same arg is narrowed non-null — don't re-fetch-then-`assert(!== null)`; capture
> once or `(array)`-cast the re-read. (Hit in `templatelog`.)

The frontier (FT191–193) has no howto carrying an `FT19x` marker; start at the
lowest FT that maps to a howto (FT194 was the first).

---

## How to do one FT (the recipe)

1. **Find the theme.** Map FT number → howto by grepping the howto body for its
   `Field trial: FTxxx` / `FT reference: FTxxx` marker:
   ```bash
   for f in ../NENE2/docs/howto/*.md; do ft=$(grep -oE 'FT[0-9]{3}' "$f" | head -1); [ -n "$ft" ] && echo "$ft $(basename "$f")"; done | sort -t T -k2 -n
   ```
   (CHANGELOG is **not** a complete FT index — don't rely on it.)
2. **Read the howto** in `../NENE2/docs/howto/<name>.md` for the spec
   (schema, routes, security pattern, ATK/VULN list). The intended dir name is
   usually in the `../NENE2-FT/<name>/` reference → use `<name>` here.
3. **Verify the tricky SQL/logic first** against SQLite (e.g.
   `docker compose run --rm app php -r '...'` in the NENE2 repo) before writing.
   This is how howto bugs get caught.
4. **Scaffold** by copying configs from `reorderlog/`:
   ```bash
   mkdir -p <name>/{database,src/<Domain>,tests/<Domain>}
   cp reorderlog/phpstan.neon reorderlog/.php-cs-fixer.php <name>/
   sed 's/reorderlog/<name>/' reorderlog/phpunit.xml > <name>/phpunit.xml
   ```
5. **Write** `composer.json`, `database/schema.sql`, `src/AppFactory.php`,
   `src/<Domain>/*Repository.php`, `src/<Domain>/RouteRegistrar.php`,
   `tests/<Domain>/*Test.php`, `README.md`. Follow `reorderlog`/`assetlog`/
   `vaultlog`/`ticketlog` as templates.
6. **Verify locally** (host has `composer` and `php8.4`; this repo has **no CI**):
   ```bash
   cd <name> && composer install
   php8.4 vendor/bin/phpunit
   php8.4 vendor/bin/phpstan analyse --level=8 --memory-limit=512M src tests
   php8.4 vendor/bin/php-cs-fixer fix --dry-run
   ```
   All three must be green before committing.
7. **Commit + push** (this repo: direct to `main`, no PR):
   ```bash
   git add <name> && git commit -m "feat(<name>): FT<N> <topic> example を追加"
   git push origin main
   ```
   `vendor/` is gitignored; **commit `composer.lock`**.
8. **Update this file's resume point**, then go to the next FT.

### Quality bar
- PHPUnit green · PHPStan **level 8** clean · PHP CS Fixer clean.
- Security-flavored FTs (VULN/ATK howtos): make the claims *executable* —
  fire the actual attack in a test and assert the safe outcome.

---

## NENE2 framework consumer patterns (released ^1.5)

- `AppFactory::createSqlite(string $dbFile, ...): RequestHandlerInterface` wiring
  `PdoConnectionFactory` → `PdoDatabaseQueryExecutor` (+ `PdoDatabaseTransactionManager`
  when transactions are needed) → repo → `RouteRegistrar` →
  `RuntimeApplicationFactory(..., routeRegistrars: [...])->create()`.
- `DatabaseQueryExecutorInterface`: `execute(): int` (affected rows),
  `insert(): int` (last id), `fetchOne(): ?array`, `fetchAll(): array`.
- Transactions: `$tx->transactional(fn($executor) => ...)` — **instantiate repos
  inside the callback** with the passed `$executor` (IMP-18 rule).
- `DatabaseConstraintException` is thrown on UNIQUE/FK/CHECK violations — catch it
  for duplicate handling.
- Auth in examples is a simplified `X-User-Id` header; admin via `X-Admin-Key`
  (`hash_equals`, fail-closed). User-id validation: `ctype_digit` + length ≤ 18 + `> 0`.
- Tests build the app per request and use `withParsedBody($body)` (Nyholm PSR-7
  does not auto-parse JSON). `PdoConnectionFactory` sets `PRAGMA foreign_keys = ON`.

---

## Discoveries / core fixes (NENE2 repo)

| When | Found via | Fix (NENE2 PR) |
|------|-----------|----------------|
| FT352 reorderlog | `UNIQUE(board_id, position)` makes a single `CASE WHEN` UPDATE collide (SQLite checks per row) — the howto claimed it was safe | **#1346** corrected `bulk-reorder-api.md` (two-phase negate→set in a transaction) |
| (tooling) | `start-ft.sh` CHANGELOG insert silently failed when `[Unreleased]` had content | **#1342** |

When a future FT reveals a framework gap (missing export, wrong behavior),
prefer a small NENE2 core change/ADR over working around it in the example.

---

## SemVer / scope note

Examples depend on the **released** `^1.5`, so they validate the public, shipped
framework — not dev HEAD. Keep them that way; if an example needs an unreleased
feature, that's a signal to cut a release or pick a different theme.
