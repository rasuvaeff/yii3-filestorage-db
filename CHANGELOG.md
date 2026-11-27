# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
