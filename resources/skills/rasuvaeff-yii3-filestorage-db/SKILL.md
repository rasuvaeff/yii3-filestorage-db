---
name: rasuvaeff-yii3-filestorage-db
description: >-
  Database metadata backend for rasuvaeff/yii3-filestorage — DbRepository with
  an optional tenant predicate
  applied whenever a scope provider is bound, DbScopedFileResolver for signed downloads,
  DbBlobLedger implementing the deduplication state machine, DeduplicatingStorage
  with its factory and DedupScope, the filestorage:deduplicate migration command,
  table-name value objects and the bundled migrations. Use when writing,
  reviewing or debugging file-metadata queries, tenant scoping, migrations or
  deduplication in a project that has this package installed.
---

# rasuvaeff/yii3-filestorage-db

File metadata in a database, plus the ledger that makes shared bytes safe to
delete. Namespace `Rasuvaeff\Yii3FilestorageDb\`. Full API reference:
`llms.txt`.

## Safety rules — verify these on every change

1. **Never bypass the tenant predicate.** Every statement against the file
   table goes through `DbRepository`. A signed download has no ambient tenant,
   and the tempting fix — looking up by id with the filter off — reads any file
   whose id leaks. Use `DbScopedFileResolver::findInScope($id, $scopeId)`; the
   scope was authenticated when the token was minted.

2. **`scope_id = null` is a scope, not a wildcard.** It matches
   `scope_id IS NULL`, which is what an unscoped application's rows carry. A
   *bound* provider returning null is refused with `MissingScopeException` —
   never turn that into "no predicate". The only exception is the explicit
   maintenance opt-in — param `globalMaintenanceAccess`, which `config/di.php`
   applies only to the `MaintenanceRepositoryInterface` and
   `BlobLedgerInterface` bindings (console params are shared with queue
   workers, so it must never reach `RepositoryInterface`/`DbRepository`) — and
   even then a new row needs a tenant. Content keys (`DeduplicatingStorage`,
   `filestorage:deduplicate`) refuse a null from a bound provider regardless.

3. **Never delete shared bytes inside a request.** `releaseFile()` and
   `release()` only *schedule* a blob. Deletion happens in a collection pass
   (`filestorage:gc`), under an exclusive expiring lease, in the order
   quarantine → `completeDeletion()` → purge, or restore when it refuses.
   Deleting before `completeDeletion()` is the race the quarantine closed.

4. **Put ledger guards in the statement that acts.** The collectable test and
   both `NOT EXISTS` checks belong in the same `UPDATE`/`DELETE`, with the
   affected-row count as the answer. A `SELECT` then an `UPDATE` leaves a gap,
   and the gap is where a committed row starts pointing at deleted bytes.

5. **Do not add a `reference_count` column.** A reference is a file row with
   the blob's id. A counter is a second source of truth that drifts.

6. **`yiisoft/db-migration` must stay at `^2.1`.** On 2.0.x the documented
   `setSourceNamespaces()` registration silently found nothing and `migrate:up`
   exited 0 having created no tables.

7. **`filestorage:deduplicate` is dry-run by default and never deletes the old
   object.** The row is repointed and the object it used to point at becomes an
   orphan for `filestorage:gc --orphans --apply`; deleting it inside the
   migration would race readers still holding the old path. Its `--scope` must
   equal what the application passes to `DeduplicatingStorageFactory::create()`,
   or every migrated row lands on a key no future upload will ever join. Under
   tenancy the reclaim step moves: `gc --orphans` refuses while a scope provider
   is bound, so migrate per tenant and sweep once with the provider unbound.
   With no provider bound it refuses while any row carries a `scope_id`
   (`DbRepository::hasTenantRows()`), unless `--scope=global`.

8. **Sizes are counted while hashing, never read off the row.** `Upload::size()`
   is null for a body that never declares its length, and a row's recorded size
   can have drifted. Pairing the real hash with a stale size in the ledger makes
   every later add of that content fail on the size check.

9. **`DeduplicatingStorage` and `filestorage:deduplicate` must compute the same
   content key.** Same `DedupScope`, same key generator, same `ExtensionMap`:
   the live path passes `extensionFor($detectedType)`, the command
   `extensionFor($file->mimeType)` — the type the row recorded at add time. Any
   divergence puts migrated rows on keys no upload ever joins. Both refuse a
   generator whose keys the store's layout cannot delete one by one (core's
   `LayoutCompatibility`: flat keys need `FlatSiblingLayout`) and, with a scope
   provider bound and a scope other than `Global`, a generator that leaves the
   tenant out (`FlatSha256KeyGenerator(prefix: …)`). Tests that need flat keys
   build the store with `FlatSiblingLayout`.

- `DbBlobLedger::findMany()` (core's optional batch lookup, used by `gc`):
  one statement per 500 blobs with correlated `COUNT(*)` subqueries; it must
  answer exactly what `find()` answers per blob — cover any change on MySQL,
  MariaDB and PostgreSQL. `DeduplicatingStorage::withProxyUrls()` delegates to
  core's `Storage::withProxyUrls()`; refuse rather than mint unpinned.

10. **`referencedPaths()` decides in PHP, not in SQL.** The database narrows
    with `LIKE '<dir>/%' ESCAPE '!'` — never yiisoft/db's `Like`, whose MySQL
    form has no `ESCAPE` and breaks under `NO_BACKSLASH_ESCAPES`; the exact store and directory comparison happens
    after, because MySQL's default collation is case-insensitive and `LIKE`
    also matches subdirectories. Never add `DISTINCT` there — it would fold
    `A/x` and `a/x` before the check and can lose a real reference, which the
    orphan sweep turns into deleted bytes.

11. **Grace periods and reservation TTLs are positive.** Zero makes
    "scheduled" and "collectable" one instant. `commit()` never activates a
    `deleting` blob; a writer only takes over a `deleting` blob whose lease
    has expired.

12. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.

13. **Verification is mandatory.** `make build` *and* `make test-integration` —
    `composer build` runs the Unit suite only.

## Gotchas

- Timestamps are `Y-m-d\TH:i:s.uP` text normalised to UTC. Microseconds are
  part of `File`'s round-trip contract, and the ledger compares deadlines as
  strings — which only agrees with chronological order at one fixed offset.
- `DbRepository::save()` is a scoped update falling through to an insert. Do
  not "simplify" it into an upsert: an upsert matches on the primary key alone
  and would hand a row to another tenant.
- The blob primary key is `sha256("store:path")`: a *unique* index over the
  pair needs the whole 512-character path, which MySQL refuses under the
  `COMPACT` row format. The non-unique `(store_name, relative_path)` index on
  the file table gets away with a 191-character prefix on MySQL; on PostgreSQL
  it needs `varchar_pattern_ops` or `LIKE 'x%'` ignores it.
- A malformed row throws `InvalidFileRowException`. Never coerce values until
  `File::create()` stops refusing.
- Table names come from typed value objects because `yiisoft/db-migration`
  builds migrations through `Injector::make()`, which resolves by type — a
  scalar constructor argument silently gets the default.
- Unit tests run against real in-memory SQLite with the migrations applied. A
  fake connection would have to reimplement the SQL under test.
- SQLite is not enough for the ledger: it accepts `select('1')` (MySQL/PostgreSQL
  read it as a column) and reports matched rows where MySQL reports changed ones.
  Every new ledger statement goes into `tests/Integration/CrossDatabaseMigrationTest`
  (`FILESTORAGE_TEST_DB=mysql|mariadb|pgsql`), which CI runs on MySQL 8.4,
  MariaDB 11.8 and PostgreSQL; `LedgerConcurrencyTest` runs there with two
  connections, MySQL-family under `NO_BACKSLASH_ESCAPES,ANSI_QUOTES`.
- Every ledger transaction goes through `DbBlobLedger::transaction()`: READ
  COMMITTED on the MySQL driver, because MariaDB 11.6.2+ fails a REPEATABLE READ
  read-then-update with error 1020. Never call `$db->transaction()` directly
  there.
- MySQL's default collation is case- and accent-insensitive. Anything compared
  as an identifier (`scope_id`) is a binary NO PAD collation there
  (`utf8mb4_0900_bin` / MariaDB `utf8mb4_nopad_bin` — not `utf8mb4_bin`, which
  ignores trailing spaces); paths are compared exactly in PHP.
