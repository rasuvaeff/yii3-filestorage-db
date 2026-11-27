# Upgrade

## 0.3.x → 0.4.0

1. **Run `./yii migrate:up`.** On MySQL/MariaDB the new
   `M261128000000MakeFilestorageFileScopeIdCaseSensitive` switches `filestorage_file.scope_id` to `utf8mb4_bin`.
   Tenant ids that differ only in case or accents become different tenants — that is the fix; normalise the ids your
   `FileScopeProviderInterface` returns if you relied on the old folding.
2. **Multi-tenant installations: check every console and maintenance entry point.** A bound
   `FileScopeProviderInterface` that returns `null` is now refused with `MissingScopeException` by every
   `DbRepository` method — it used to drop the tenant predicate silently. Core's `filestorage:gc` (also without
   `--orphans`: it pages `files()` to release application rows), `filestorage:stat`, `filestorage:verify` and
   `filestorage:backfill-hash` read through `DbRepository`, so a cron job whose tenant does not resolve now throws
   instead of acting on every tenant. For each such entry point, either:
   - leave the provider unbound there (as `gc --orphans` already requires), or
   - keep it and set, in that entry point's own params only:

     ```php
     'rasuvaeff/yii3-filestorage-db' => ['allowGlobalAccess' => true],
     ```

     A null scope then means every tenant for reads, updates and deletes. Never set it where requests are served.
3. `filestorage:deduplicate` refuses a bound provider returning null even with `allowGlobalAccess` (it would key every
   tenant into one pool): run it once per tenant, as before.
4. `DeduplicatingStorageFactory::create()`, `DeduplicatingStorage`, `DeduplicateCommand` and `DbBlobLedger` refuse a
   zero or negative `reservationTtl`/`deleteGracePeriod`.
