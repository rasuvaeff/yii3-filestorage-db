# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

- **Upgrading:** see `UPGRADE.md` — run `migrate:up`, and give every console/maintenance entry point of a multi-tenant installation either an unbound scope provider or `allowGlobalAccess`, or core's `gc`/`stat`/`verify`/`backfill-hash` now throw `MissingScopeException` where the tenant does not resolve.
- **Changed (security):** a bound `FileScopeProviderInterface` that returns `null` no longer drops the tenant predicate. Every `DbRepository` method throws the new `Exception\MissingScopeException`; before, `find()`, `delete()`, `files()` and `referencedPaths()` reached every tenant's rows and `save()` inserted rows with no scope. A maintenance entry point that must act across tenants with the provider bound sets the new `allowGlobalAccess` (constructor argument, `params['rasuvaeff/yii3-filestorage-db']['allowGlobalAccess']`, default `false`); a new row is still refused without a tenant. `DeduplicatingStorage::add()` and `filestorage:deduplicate` refuse a null scope too, even under the opt-in, instead of keying content into the `shared` pool. `DbScopedFileResolver` already treated null as `scope_id IS NULL`; the two now agree that null is never a wildcard.
- **Fixed:** `referencedPaths()` (the `gc --orphans` lookup) found nothing for a directory containing `_` or `%` on MySQL with `sql_mode=NO_BACKSLASH_ESCAPES` — yiisoft/db-mysql emits no `ESCAPE` clause — so the sweep quarantined objects live rows point at. The statement now declares `ESCAPE '!'`.
- **Fixed:** MySQL/MariaDB compared `scope_id` case- and accent-insensitively, so tenants `Acme` and `acme` were one. New migration `M261128000000MakeFilestorageFileScopeIdCaseSensitive` switches the column to `utf8mb4_bin` (no-op on SQLite/PostgreSQL). Run `migrate:up` after upgrading.
- **Fixed:** `DbBlobLedger::commit()` schedules the blob a row pointed at before when the row moves to another content key (a second `filestorage:deduplicate` with another `--scope`, key generator or extension map). The old blob used to stay `active` with no reference, invisible to every collector. New optional constructor argument `deleteGracePeriod` (default `PT1H`).
- **Fixed:** a writer joins a `deleting` blob whose lease has expired instead of getting `BlobBusyException` until the next `gc` — the retry the exception message promised now works. A stale collector's `completeDeletion()` is refused and core's `gc` restores, keeping the re-published bytes.
- **Fixed:** `commit()` no longer activates a blob a collector claimed after the writer joined it (reachable on PostgreSQL READ COMMITTED with a zero grace period); it throws `LedgerException` and the writer releases. A zero affected count is settled with a locking re-read on MySQL/PostgreSQL. Zero or negative `reservationTtl`/`deleteGracePeriod` are refused (`InvalidArgumentException`) by `DeduplicatingStorage`, `DeduplicatingStorageFactory::create()`, `DeduplicateCommand` and `DbBlobLedger`.
- **Fixed:** `DeduplicatingStorage::remove()` deletes the bytes of a row on the unique path (over `dedupMaxBytes`, or in another store), as the base facade does; shared bytes are still only deleted by the collector. The factory passes its `StoreRegistry` (new optional constructor argument of `DeduplicatingStorage`).
- **Fixed:** an upload too large to hash was stored in the registry's default store rather than the deduplicating one. Hashing now stops at the group's `maxBytes` with `UploadSizeException` instead of reading up to `dedupMaxBytes` first, and a stream failure while hashing is an `AddException`.
- **Fixed:** `filestorage:deduplicate` wrote the store's size of an existing object on the row instead of the counted size the ledger was given.
- **Fixed:** `DeduplicatingStorage::add()` built the file row only after publishing the bytes, so metadata `File::create()` refuses, or a failing id generator or clock, left a reserved and written blob behind. The row is now built before anything is reserved or written (the same ordering core 0.10 adopts); a failure after the write still releases the reservation and throws `AddException`.
- Tests: `save()` is pinned as an upsert by id whose `scope_id` is assigned once on insert (core's `RepositoryInterface::save()` contract).
- Added `DbRepository::findMany(list<string> $ids): list<File>` — scoped batch read.
- Docs: the README collection-pass example deleted the object before `completeDeletion()` — the race core 0.9's quarantine closed. It now recommends `filestorage:gc` and shows quarantine → `completeDeletion()` → purge/restore. New "What it does not do" table (no local content-addressable store, the `sha…` key prefix, no `filestorage:adopt` yet, `TenantGroup` keeps groups apart); `FlatSha256KeyGenerator` must be paired with `FlatSiblingLayout`.
- Tests: `LedgerConcurrencyTest` runs on MySQL and PostgreSQL with two connections when `FILESTORAGE_TEST_DB` is set (MySQL under `NO_BACKSLASH_ESCAPES,ANSI_QUOTES`); the cross-database test repeats the orphan lookup under `NO_BACKSLASH_ESCAPES` and checks tenants differing only in case.
- `extra.branch-alias.dev-master` is `0.4.x-dev`.

## 0.3.0 — 2026-11-27

- **Changed:** requires `rasuvaeff/yii3-filestorage` `^0.9`, whose maintenance and key-generator contracts changed (see core's 0.9.0 notes). This release no longer installs with core 0.8; stay on 0.2.1 for that.
- Enh #14: `DbRepository::referencedPaths(string $storeName, array $directories): list<string>` — what `filestorage:gc --orphans` asks per listing page. One parameterized statement per 200 directories (`LIKE '<dir>/%'` with `_`/`%` taken literally, plus root-level paths), the tenant predicate as everywhere, and an exact store and directory comparison in PHP, because MySQL's default collation compares case-insensitively.
- Enh #14: new migration `M261127000000AddFilestorageFileStorePathIndex` — an index on `filestorage_file (store_name, relative_path)` so that lookup is a range scan. MySQL indexes a 191-character prefix of the path; PostgreSQL uses `varchar_pattern_ops`, without which a btree is not used for `LIKE 'x%'`. Run `migrate:up` after upgrading. On PostgreSQL a plain `CREATE INDEX` blocks writes to the table while it builds: for a large, busy table create it beforehand with `CREATE INDEX CONCURRENTLY idx_<table>_store_path ON <table> (store_name, relative_path varchar_pattern_ops)` — the migration uses `IF NOT EXISTS` and then only records itself. On MySQL 8 the index is built online. `DeduplicatingStorage`/`filestorage:deduplicate` call the key generator positionally, so an implementation with different parameter names works.
- Enh #14: `DeduplicatingStorage` and `filestorage:deduplicate` pass the extension of the detected media type (core's `ExtensionMap`, `bin` for an unknown type) to `ContentAddressedKeyGeneratorInterface::generate()`, so core's `FlatSha256KeyGenerator` works with deduplication. The command takes it from the row's recorded `mimeType`, so a migrated row lands where a live add of the same bytes would. `DeduplicatingStorage`, `DeduplicatingStorageFactory` and `DeduplicateCommand` take an optional `ExtensionMap` (last parameter); `config/di.php` injects core's binding. With the default `Sha256KeyGenerator` keys do not move.
- CI: the backward-compatibility step tolerates a report across a pre-1.0 minor declared in `CHANGELOG.md`, as the template does.
- `extra.branch-alias.dev-master` is `0.3.x-dev` (was a stale `0.1.x-dev`).

## 0.2.1 — 2026-11-27

- **Fixed:** removing a deduplicated file failed on MySQL (`1054 Unknown column '1'`) and PostgreSQL (`42703 column "1" does not exist`). The ledger's `NOT EXISTS` guards selected the string `'1'`, which `yiisoft/db` quotes as a column name; SQLite accepts it. Every statement carrying the guard was affected: `releaseFile()`, `release()`, `expireReservations()`, `claimForDeletion()`, `completeDeletion()` (#12).
- **Fixed:** on MySQL, a second `add()` of identical content could fail with `AddException` ("Blob ... disappeared while its reservation was being committed"). MySQL reports changed rows rather than matched ones, so `commit()`'s activation update read 0 when it wrote only values the row already held (a frozen clock, or the same microsecond). `commit()` now re-reads the blob row before concluding it is gone; `DbRepository::updateContentHash()` likewise returns `true` when the row already holds that hash (#12).
- CI: an ungated `database-integration` matrix job runs the Integration suite against MySQL 8.4 and PostgreSQL 17; the new `CrossDatabaseMigrationTest` applies both migrations up and down and drives the whole ledger lifecycle there. `MigrationTest` asserts that no column carries a `DEFAULT` (MySQL rejects one on TEXT).

## 0.2.0 — 2026-11-27

- **Changed:** requires `rasuvaeff/yii3-filestorage` `^0.8.2`, so the whole family installs on one line of the core. This release no longer installs with core 0.1; stay on 0.1.1 for those.

## 0.1.1 — 2026-11-27

- `composer mutation` now runs Infection with `--with-uncovered`, so code the tests execute without naming it in `#[Covers]` is mutated and counted (overall MSI was 83% when measured this way, now 93% against `minMsi` 89). Added tests for the add failure path (`AddException`, release of the reservation), `remove()` failures, the digest pass (declared size over the cap, a stalled body), the ledger's insert-race retry, the lost-activation and busy-revive races (a `ConnectionInterface` decorator injects them), the ledger row readers, index columns of the migrations, claim order, and the command's failure, advice and summary messages. `infection.json5` gained the template's signature-default ignore regex and disabled `PublicVisibility`/`ProtectedVisibility`. `DbRepository::files()` now lists its parameters one per line (no behaviour change) so the ignore regex matches its default.

## 0.1.0 — 2026-08-08

First release. Tracks `rasuvaeff/yii3-filestorage` `0.x`: the API settles
together with core's while the family is built out.

- The documented way to turn deduplication on did not work. `create()` is
  reached by rebinding `StorageInterface` to `DeduplicatingStorageFactory`, and
  the factory's wiring asked for `StorageInterface` to get the plain facade —
  which the application had just pointed at the factory. Every container
  resolving it answered `CircularReferenceException`, naming an interface the
  recipe never mentions. The factory now takes core's concrete `Storage::class`
  (core `^0.1.1`, which is the release that added that id), and a wiring test
  loads both packages' `config/di.php` and runs the recipe end to end. Every
  wiring test in this family had only ever loaded its own package's, which is
  why nothing saw it.
- `FileRowMapper` no longer accepts a size one past `PHP_INT_MAX`. The pattern
  bounds the digit count at nineteen, `'9223372036854775808'` is nineteen
  digits, and `(int)` saturates it — so a corrupted row mapped to a plausible
  `File` with the wrong size instead of `InvalidFileRowException`.
- `filestorage:deduplicate` re-checks `--max-bytes` against the byte count taken
  while hashing, not only against the size the row claims. A row understating
  its size was read in full regardless of the cap, which is the one thing the
  option exists to prevent. A failure to release the reservation on the error
  path is now reported and survived rather than aborting the run past its
  resume cursor.
- `filestorage:deduplicate` no longer closes by telling a multi-tenant operator
  to run `filestorage:gc --orphans --apply`. That command refuses while a
  `FileScopeProviderInterface` is bound — which is exactly the condition this
  one runs under — so the recipe sent them to a command that would not run, with
  nothing linking the refusal back. With a provider bound the line now names the
  unscoped maintenance entry point instead.

- `Command\DeduplicateCommand` → `filestorage:deduplicate`: the resumable
  migration from unique paths onto ledger-managed content keys. Dry-run by
  default, idempotent on a second pass, cursor-resumable, and it leaves the old
  objects for `filestorage:gc --orphans`. It lives here rather than in core
  because it must produce byte-identical keys to the `DeduplicatingStorage` the
  application configured — same `DedupScope`, same scope provider.
- `DeduplicatingStorage` now counts the byte length while hashing instead of
  reading `Upload::size()`, which is null for a body that never declares its
  length. Reserving zero there while committing the real size made every later
  add of the same content fail on the ledger's size check.
- Added `symfony/console`, `psr/http-factory` and `psr/http-message` to
  `require` — the new command needs them at runtime.

- `DbRepository`, implementing core's `RepositoryInterface` and
  `MaintenanceRepositoryInterface`, with an optional mandatory tenant predicate
  that no method skips.
- `DbScopedFileResolver`, resolving a file by id *and* the scope a signed token
  carries, so a download never needs the tenant filter turned off.
- `DbBlobLedger`, the transactional implementation of core's
  `BlobLedgerInterface`: reservations, revival, the joint file-row and
  reference commit, delayed scheduling, and leased collection with every guard
  in the statement that acts on it.
- Migrations under `src/Migration/` creating `filestorage_file`,
  `filestorage_blob` and `filestorage_blob_reservation`, with the table-name
  value objects `FileTableName`, `BlobTableName` and
  `BlobReservationTableName`.
- An integration suite that runs the migration registration exactly as the
  README documents it, rather than through an equivalent shortcut.
