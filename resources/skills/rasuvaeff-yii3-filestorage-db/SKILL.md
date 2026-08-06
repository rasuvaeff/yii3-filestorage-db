---
name: rasuvaeff-yii3-filestorage-db
description: >-
  Database metadata backend for rasuvaeff/yii3-filestorage — DbRepository with
  a mandatory tenant predicate, DbScopedFileResolver for signed downloads,
  DbBlobLedger implementing the deduplication state machine, table-name value
  objects and the bundled migrations. Use when writing, reviewing or debugging
  file-metadata queries, tenant scoping, migrations or deduplication in a
  project that has this package installed.
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
   `scope_id IS NULL`, which is what an unscoped application's rows carry.

3. **Never delete shared bytes inside a request.** `releaseFile()` and
   `release()` only *schedule* a blob. Deletion happens in a collection pass,
   under an exclusive expiring lease, and `completeDeletion()` refuses if the
   blob was referenced again.

4. **Put ledger guards in the statement that acts.** The collectable test and
   both `NOT EXISTS` checks belong in the same `UPDATE`/`DELETE`, with the
   affected-row count as the answer. A `SELECT` then an `UPDATE` leaves a gap,
   and the gap is where a committed row starts pointing at deleted bytes.

5. **Do not add a `reference_count` column.** A reference is a file row with
   the blob's id. A counter is a second source of truth that drifts.

6. **`yiisoft/db-migration` must stay at `^2.1`.** On 2.0.x the documented
   `setSourceNamespaces()` registration silently found nothing and `migrate:up`
   exited 0 having created no tables.

7. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.

8. **Verification is mandatory.** `make build` *and* `make test-integration` —
   `composer build` runs the Unit suite only.

## Gotchas

- Timestamps are `Y-m-d\TH:i:s.uP` text normalised to UTC. Microseconds are
  part of `File`'s round-trip contract, and the ledger compares deadlines as
  strings — which only agrees with chronological order at one fixed offset.
- `DbRepository::save()` is a scoped update falling through to an insert. Do
  not "simplify" it into an upsert: an upsert matches on the primary key alone
  and would hand a row to another tenant.
- The blob primary key is `sha256("store:path")`, because the pair itself
  exceeds MySQL's index key length at a 512-character path.
- A malformed row throws `InvalidFileRowException`. Never coerce values until
  `File::create()` stops refusing.
- Table names come from typed value objects because `yiisoft/db-migration`
  builds migrations through `Injector::make()`, which resolves by type — a
  scalar constructor argument silently gets the default.
- Unit tests run against real in-memory SQLite with the migrations applied. A
  fake connection would have to reimplement the SQL under test.
