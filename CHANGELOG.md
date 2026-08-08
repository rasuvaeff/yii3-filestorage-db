# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.1.0 — 2026-08-08

First release. Tracks `rasuvaeff/yii3-filestorage` `0.x`: the API settles
together with core's while the family is built out.

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
