# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
