# Examples

Runnable scripts. Each one is self-contained: it wires the package by hand and
runs the migrations itself, so you can read the whole flow in one file without
a framework in the way. Both use in-memory SQLite, so nothing is left behind.

```bash
composer install
php examples/store-in-sqlite.php
```

| Script | Shows | Needs a server? |
|---|---|---|
| [`store-in-sqlite.php`](store-in-sqlite.php) | Objects on disk, metadata in the database: storing a file through `Storage`, reading the row back with a fresh `DbRepository`, paging with `files()`, removing it | No |
| [`blob-ledger.php`](blob-ledger.php) | The deduplication lifecycle end to end: two writers on one object, reference release, the grace period, an exclusive collection lease, and a writer being told to retry | No |

The scripts use `yiisoft/db-sqlite`, `nyholm/psr7` and `yiisoft/test-support`
because all three are development dependencies of this package. Any
`yiisoft/db` driver, any PSR-17 implementation and any PSR-20 clock work; the
package never names one.
