# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

Initial development. Not released.

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
