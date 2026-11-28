# Upgrade

## 0.3.x → 0.4.0

1. **Run `./yii migrate:up`.** On MySQL/MariaDB the new
   `M261128000000MakeFilestorageFileScopeIdCaseSensitive` switches `filestorage_file.scope_id` to a binary NO PAD
   collation — `utf8mb4_0900_bin` on MySQL 8.0.17+, `utf8mb4_nopad_bin` on MariaDB. Tenant ids that differ only in
   case, accents or trailing spaces become different tenants — that is the fix; normalise (and trim) the ids your
   `FileScopeProviderInterface` returns if you relied on the old folding.
   - **MySQL older than 8.0.17** has no NO PAD binary collation: the migration throws `RuntimeException` there rather
     than promise an exact comparison it cannot make. Upgrade the server first.
   - **The table is locked for writes while it is rebuilt.** Changing the collation of an indexed column is an
     `ALGORITHM=COPY` rebuild on MySQL and MariaDB; uploads and deletions wait for it. It takes roughly as long as
     copying `filestorage_file` once — run it in a maintenance window on a large table.
   - **Row format `DYNAMIC` is required** (the default since MySQL 5.7.9 and MariaDB 10.2.2). On a `COMPACT` table
     the rebuilt 255-character `utf8mb4` index exceeds 767 bytes and the `ALTER` fails; convert with
     `ALTER TABLE filestorage_file ROW_FORMAT=DYNAMIC` first.
2. **Multi-tenant installations: check every console and maintenance entry point.** A bound
   `FileScopeProviderInterface` that returns `null` is now refused with `MissingScopeException` by every
   `DbRepository` method — it used to drop the tenant predicate silently. Core's `filestorage:gc` (also without
   `--orphans`: it pages `files()` to release application rows), `filestorage:stat`, `filestorage:verify` and
   `filestorage:backfill-hash` read through `MaintenanceRepositoryInterface`, so a cron job whose tenant does not
   resolve now throws instead of acting on every tenant. For each such entry point, either:
   - leave the provider unbound there (as `gc --orphans` already requires), or
   - keep it and set, in the console params:

     ```php
     'rasuvaeff/yii3-filestorage-db' => ['globalMaintenanceAccess' => true],
     ```

     A null scope then means every tenant for reads, updates and deletes — through `MaintenanceRepositoryInterface`
     and `BlobLedgerInterface` only, the two keys core's maintenance commands (and `-images`' `warm`) resolve.
     `RepositoryInterface`, `DbRepository`, `DbBlobLedger` and the deduplicating storage keep refusing, so queue workers
     and other console jobs that share the console params are not opened up. Do not resolve
     `MaintenanceRepositoryInterface` or `BlobLedgerInterface` in your own request or job code: under this flag they
     are the global ones.
3. `filestorage:deduplicate` refuses a bound provider returning null even under the maintenance opt-in (it would key
   every tenant into one pool): run it once per tenant, as before.
4. `DeduplicatingStorageFactory::create()`, `DeduplicatingStorage`, `DeduplicateCommand` and `DbBlobLedger` refuse a
   zero or negative `reservationTtl`/`deleteGracePeriod`. `new DeduplicatingStorage(...)` also runs the content-key
   checks of point 5 itself, so building it by hand no longer skips them.
5. **Requires `rasuvaeff/yii3-filestorage` ^0.10.** `DeduplicatingStorageFactory::create()` and
   `filestorage:deduplicate` now refuse (`InvalidConfigException` / command failure) a content-key generator whose keys
   the store's layout cannot delete one by one — `FlatSha256KeyGenerator` needs `FlatSiblingLayout` on the store — and,
   with a `FileScopeProviderInterface` bound and a scope other than `Global`, a generator that leaves the tenant out of
   the key (`FlatSha256KeyGenerator(prefix: …)`). A configuration that boots on 0.3 may stop booting: fix the pair, it
   was deleting neighbouring blobs or sharing bytes across tenants.
6. **`filestorage:deduplicate` with no provider bound** now refuses while any row carries a `scope_id`. Run it per
   tenant with the provider bound, or pass `--scope=global` if pooling every tenant is intended.
7. **MariaDB 11.6.2+, calling `add()`/`remove()` inside your own transaction.** The ledger runs its own transactions
   at `READ COMMITTED` on the MySQL driver, which closes error 1020 ("Record has changed since last read") for
   concurrent uploads of the same bytes. Inside a transaction you opened, it cannot change the level: there a
   concurrent change still fails, now as `LedgerException` (wrapped in `AddException`/`RemoveException`) naming the
   cause instead of a driver `IntegrityException`. Retry your transaction, or open it at `READ COMMITTED`. Statement-
   based binary logging (`binlog_format=STATEMENT`) does not allow writes under `READ COMMITTED`; `ROW` and `MIXED`
   (the defaults) do.
