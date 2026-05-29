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

## ✅ DONE: fill the FT178–FT190 README-only stubs (13/13)

A prior session committed **README-only stubs** (no `src`/`tests`/`composer.json`)
for **FT178–FT190**. These looked "covered" by the README FT-number scan but were
hollow. **All 13 are now filled** with real implementations (src+tests+composer,
PHPUnit green, PHPStan L8 clean, CS clean), each README's inflated "N tests" count
corrected to reality.

Stubs → status (all ✅, committed+pushed to `main`):
`patchlog`(178), `isolationlog`(179), `sortlog`(180), `reminderlog`(181),
`batchlog`(182), `shortlog`(183), `onetimelog`(184), `statuslog`(185),
`sessionlog`(186), `encryptlog`(187), `verifylog`(188), `consentlog`(189),
`announcelog`(190).

**Next workstream → re-run the dir-vs-howto gap scan** (see Coverage below) to find
the next genuinely-uncovered howto topics and build them in FT order.

**NENE2 core bug found (file a fix PR like #1346):** released `V::futureDatetime()`
(1.5.323) compares ATOM *strings* → wrong across TZ offsets; `V::isoDatetime()`
does not range-check the offset (`+25:00` passes its regex). `reminderlog` works
around both in-app and documents it; the core helpers should be fixed (the howto
already shows the intended fix). Verified empirically. `reminderlog`, `consentlog`
and `announcelog` all work around the offset range-check in-app (reject offsets
beyond ±14:00). **The core fix PR is still PENDING** — file it like #1346.

**Per stub recipe (kept for reference):** read its `README.md` (spec + ATK/VULN
table), build `composer.json`+`src`+`tests`+`database/schema.sql`+configs (keep the
README, correct inflated "N tests" claims to reality), verify phpunit+phpstan L8+cs,
commit `feat(<name>): FT<N> 実装を追加`. Released `^1.5` (=1.5.323) **has `V`,
`ConditionalGetHelper`, `ConditionalWriteHelper`, `ProblemDetailsResponseFactory`**.

---

## Coverage

- **Fully covered now:** FT97–FT190 (FT142 gap **closed** → `draftlog`).
- **Added by this workstream:** FT352 `reorderlog`, FT194 `assetlog`,
  FT195 `vaultlog`, FT196 `ticketlog`, FT197 `templatelog`, FT198 `walletlog`,
  FT142 `draftlog`.

### phase 2: distinct uncovered howto topics (post-FT198)

The workstream is **howto topics whose intended dir does not exist** (verified by
reading each howto's `FT reference: FT… (NENE2-FT/<dir>)` line and checking the dir
is absent). The naive FT-number scan over-reports because most FT199+ howtos re-doc
topics already built under an earlier `*log/` dir — confirm against existing dirs by
**topic**, not number.

Phase-2 batch 1 — **DONE** (all green, committed/pushed):
`productlog`(FT212), `reservationlog`(FT216), `polllog`(FT217), `cqrslog`(FT233),
`timelog`(FT246), `deadletterlog`(FT72), `ftslog`(FT254), `waitlistlog`(FT287).
**Skipped as dups** (different stem, same topic): `cursorlog`(FT242→`pagelog`),
`idempotencylog`(FT316→`deduplog`), `threadlog`(FT343→`commentlog`),
`ratinglog`(FT333→`reviewlog`), `schedulelog`(FT286→`pubschedulelog`),
plus the obvious ones (subscriptionlog→planlog, flaglog→featureflaglog,
locklog/optimisticlog→optlocklog, webhooklog→webhookdeliverylog, etc.).

Phase-2 batch 2 — **DONE** (all green, committed/pushed):
`contentlog`(FT301 content-negotiation — Accept/415/problem+json),
`unicodelog`(FT345 unicode-aware text — mb_strlen/null-byte/VULN-01..08).

Phase-2 batch 3 — **DONE** (all green, committed/pushed):
`statslog`(FT51 event-analytics — json_extract/strftime/DAU, distinct from agglog),
`tagfilterlog`(FT250 multi-value tag AND/OR filtering, distinct from taglog),
`bulkupdatelog`(FT85 bulk status update, hardened past FT231 V-01/V-02; distinct from
batchlog's batch-create).

### ✅ DISTINCT-TOPIC BACKLOG EXHAUSTED (gap scan 2026-05-29)

The final dir-vs-howto gap scan leaves ~37 howtos whose intended dir name is absent,
but **every one maps by TOPIC to an existing `*log` dir** — building them is the exact
near-duplicate trap this workstream exists to avoid. The mapping:

| absent dir (howto) | already covered by |
|---|---|
| approvallog / flowlog / workflowlog / statemachinelog | stepflowlog, draftlog |
| authlog (bearer-token) | jwtlog, tokenlog, refreshlog, oauthlog |
| bookinglog / reservelog | reservationlog (FT216) |
| bulklog | batchlog (FT182) |
| creditslog / moneylog | pointlog, walletlog |
| cursorlog | pagelog (FT100) |
| doclog / optimisticlog / locklog | versionlog, contentvlog, optlocklog, etaglog |
| eventstore | eventsourcelog |
| flaglog | featureflaglog |
| idempotencylog | deduplog (FT170, Idempotency-Key) |
| leaderboardlog / scorelog | ranklog (FT141) |
| linklog | bookmarklog |
| notelog / noteslog | generic IDOR CRUD (isolationlog etc.) |
| notiflog | notificationlog, queuelog |
| pinverifylog | pinlog, lockoutlog, otplog, verifylog |
| quotalog / ratelimitlog / ratelog | limitlog, throttlelog, meterlog |
| ratinglog / feedbacklog | reviewlog |
| reactionlog | emojilog, votelog |
| schedulelog | pubschedulelog (FT172) |
| softdelete / softlog | softdeletelog |
| subscriptionlog | planlog |
| threadlog | commentlog (FT127, threaded) |
| treelog | hierarchylog, nestedlog |
| webhooklog | webhookdeliverylog |

**Conclusion:** the examples repo now covers every genuinely-distinct documented topic
through FT349 that warrants a standalone example. Match any *new* howto against this
table before building. The one judgment call left open: `ratelog`
(sliding-window rate limiter) is a different *algorithm* from the existing fixed-window
/ token-bucket limiters — build it only if that algorithmic distinction is deemed worth
a dedicated example. Otherwise the backfill is complete.

### Howto bugs found & fixed via examples (good-citizen PRs)
- **#1348 (merged)** time-tracking julianday truncation (60s→59s) → `strftime('%s')`.
  Found by `timelog`.
- **#1350 (merged)** sqlite-fts5-search "implicit OR" → actually **implicit AND**.
  Found by `ftslog`.
- Still PENDING: `V::futureDatetime` / `V::isoDatetime` core fixes (from `reminderlog`).

The gap scan that produces candidates:
```bash
# 1. covered FT set = FT numbers appearing in NENE2-examples-repo/*/README.md
# 2. for each howto, grep its first FTnnn marker; if not in covered set, candidate
# 3. for each candidate, read its "NENE2-FT/<dir>" reference → intended dir;
#    keep only those whose <dir> does NOT exist AND whose TOPIC has no existing
#    *log dir (check `grep -m1 description */composer.json` of the nearest stem)
```

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
~~`price-history`~~ (→ `pricelog`, hardened past the howto's ATK findings),
~~`shift-management`~~ (→ `shiftlog`, hardened past the howto's VULN findings),
~~`multilingual-content`~~ (→ `i18nlog`),
~~`article-relations-api`~~ (→ `artrellog`; named to avoid the existing `relatedlog`/FT173).

**Likely already-covered (verify before building — probably duplicates, skip):**
`article-versioning-api` (vs `versionlog`/`contentvlog`), `aggregate-reporting`
(vs `reportlog`/`agglog`), `quota-management` (vs `limitlog`/`throttlelog`).

- **Resume point → re-run the dir-vs-howto gap scan.** The hand-picked candidate
  list is exhausted; the next genuinely-uncovered topics need a fresh scan:
  ```bash
  ls -d ../NENE2-examples-repo/*/ | sed 's#.*/\([^/]*\)/#\1#'   # existing dirs
  ls ../NENE2/docs/howto/*.md                                   # all 256 howtos
  ```
  Diff howto topics against dir stems; build only clearly-distinct ones (do NOT
  build near-duplicates of an existing `*log` — that's the trap this whole
  workstream avoids).

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
> PHPStan note 2: a combined `assert($a !== null && $b !== null)` placed **after**
> an error-accumulation block (push to `$errors[]`, then `if ($errors) throw`)
> trips `notIdentical.alwaysTrue`. Narrow via control flow instead:
> `if ($a === null || $b === null || !ok) throw …;` then use `$a`/`$b` directly.
> (Hit in `announcelog` and `reservationlog`.)

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
