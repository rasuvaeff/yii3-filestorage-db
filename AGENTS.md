# AGENTS.md — yii3-filestorage-db

Guidance for AI agents working on this package. Read before changing code.

## What this is

The metadata backend for `rasuvaeff/yii3-filestorage`: file records in a
database table, optional tenant scoping, and the transactional blob ledger that
makes deduplicated bytes safe to delete. Namespace
`Rasuvaeff\Yii3FilestorageDb`.

Public API: `DbRepository` (core's `RepositoryInterface` +
`MaintenanceRepositoryInterface`), `DbScopedFileResolver`
(`ScopedFileResolverInterface`), `DbBlobLedger` (`BlobLedgerInterface`),
`DeduplicatingStorage` / `DeduplicatingStorageFactory` / `DedupScope`,
`Command\DeduplicateCommand`, the
table-name value objects `FileTableName` / `BlobTableName` /
`BlobReservationTableName`, the migrations under `src/Migration/`, and
`Exception\InvalidFileRowException`. `FileRowMapper`, `Timestamps` and
`BlobRowId` are `@internal`.

DI wiring: `config/di.php` binds the four contracts above, the three table
names, `DeduplicatingStorageFactory` and `Command\DeduplicateCommand`;
`config/params.php` names the command under `yiisoft/yii-console`. It must
**not** bind `StorageInterface` (core's), `StoreInterface` (a store backend's) or `FileScopeProviderInterface` (the application's) —
`yiisoft/config` allows exactly one vendor package per key, and two packages
claiming one is a `Duplicate key` error by design.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `make build` *and* `make test-integration`. `composer build` runs the Unit
   suite only, and the Integration suite is the one that executes the migration
   recipe the README hands the user.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **The tenant predicate has no bypass.** Every statement against the file
   table goes through `DbRepository`, which adds it whenever a
   `FileScopeProviderInterface` is bound. `DbBlobLedger` writes file rows
   *through the repository* rather than issuing its own SQL, precisely because
   its own `DELETE ... WHERE id = :id` would be a cross-tenant delete. A signed
   download uses `DbScopedFileResolver`; turning the filter off for downloads
   reads any file whose id leaks.
4. **Preserve the public contract.** Update `README.md` **and `README.ru.md`**,
   `llms.txt` and the tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
make build
make test-integration
make cs-fix
make psalm
make test
make test-coverage
make mutation
make release-check
```

`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver.
`composer.lock` is gitignored (library).

## Before the first release

Two development-only things must go, together, when
`rasuvaeff/yii3-filestorage` is published and tagged:

- the `repositories` block in `composer.json` (a path repository pointing at
  `../yii3-filestorage`, with a pinned `options.versions`);
- the monorepo-root mount in the `Makefile`'s `DOCKER` variable, which exists
  only so that relative path repository resolves inside the container.

A published `composer.json` carrying a path repository is broken for everyone
who installs it. See `docs/evolved-rules.md` ER-019.

**Do not push this repository to GitHub before core is on Packagist.** A CI
checkout has no sibling directory and no registry copy, so `composer install`
cannot resolve `rasuvaeff/yii3-filestorage` in any job — every run would be red,
including on a branch that is about to be protected. Core is published first;
then the path repository and the mount come out; then this repository goes up.

## Mutation testing

`minMsi` is **89, and no mutator is ignored.** The 49 survivors fall into six
groups, none of which a test can kill without inventing a scenario the schema
or the driver rules out:

| Group | Example | Why no test kills it |
|---|---|---|
| Guards the schema already enforces | `isset($row['state']) && \is_string(...)` in the ledger's row readers | The columns are `NOT NULL`, and `yiisoft/db` typecasts numeric columns on read — so the null and wrong-type branches cannot be produced through any driver. They stay because a hand-edited row is not impossible, only unreachable from here |
| Predicates a single process cannot weaken observably | dropping `['id' => $id]` from `completeDeletion()` beside a unique `lease_token`; the `state = 'deleting'` half of the lease-steal arm, where only `deleting` rows have a non-null `lease_expires_at` | Both halves select the same row. Weakening one is visible only to a second process holding a colliding claim, which random 128-bit tokens make unreachable |
| Ordering and floors | `orderBy(['id' => SORT_ASC])` on the candidate scan; `max(0, …)` around a decrement | Fairness and defence, not correctness: the scan returns the same set unordered, and the floors guard an underflow the callers already make impossible |
| Exception-code arguments | the `0` in `new InvalidFileRowException($m, 0, $e)` | Nothing asserts an exception code, and asserting one would pin a value that carries no meaning |
| Page-size constants and paged-walk `continue` | the `500` in `files($after, 500)` and the `262_144` read size in `DeduplicateCommand`; the `continue` in each of its "skip this row" branches | Killing the page sizes needs 500-row fixtures, and even then a different boundary is not a different result. The `continue`s are equivalent to `break` because the cursor advances *before* the branch and the outer `while` re-pages from it |
| Console option casts and guards | `(bool) $input->getOption('apply')` and the `isset() && is_string() && !== ''` chain in `stringOption()` | Symfony already guarantees the type, so only the `!== ''` half is reachable — and that half is tested. The rest exists so psalm can narrow without a suppression |

Two shapes are worth knowing before adding tests here.

**`['and', [], $x]` is not a contradiction in `yiisoft/db` — the empty clause is
dropped and only `$x` is applied.** So a mutant that deletes `['id' => $id]`
from a conditional update produces a statement that hits *every* row the rest of
the predicate matches. Only a test with two blobs in play catches that; a
single-blob test passes happily. That is why the multi-blob isolation tests
exist, and why deleting one is a real loss of coverage rather than tidying.

**Benchmarks inflate "covered".** `composer mutation` runs every suite, so code
`LedgerBench` executes counts as covered while `--type=!bench` stops benchmarks
from ever killing anything. Adding a benchmark over an under-tested class lowers
MSI without any test getting worse.

## Invariants & gotchas

- **Guards live in the statement that acts.** `claimForDeletion()`,
  `completeDeletion()` and `scheduleIfUnused()` put the collectable test and
  both `NOT EXISTS` emptiness checks into the same `UPDATE`/`DELETE`, and read
  the answer off the affected-row count. A `SELECT` followed by an `UPDATE` is
  two moments with a gap, and the gap is where a committed file row ends up
  pointing at bytes another process already removed.
- **There is no `reference_count` column, and adding one would be a
  regression.** A reference *is* a `filestorage_file` row carrying the blob's
  id. A denormalised counter is a second source of truth that can drift from
  the first, and every underflow guard only detects drift after it happened.
- **Shared bytes are never deleted inside a request.** `releaseFile()` and
  `release()` only *schedule*. Only a collector holding a lease deletes.
- **`filestorage:deduplicate` leaves the old object behind, deliberately.** The
  row is repointed and the object it used to point at becomes an orphan for
  `filestorage:gc --orphans`. Deleting it inside the migration would race the
  readers still holding the old path.
- **Sizes are counted, never read off the row.** Both `DeduplicatingStorage` and
  `DeduplicateCommand` count bytes during the hashing pass. `Upload::size()` is
  null for a body that never declares its length, and a row's recorded size can
  have drifted — that is what `filestorage:verify --deep` exists to find.
  Reserving one number while committing another makes every later add of the
  same content fail on the ledger's size check.
- **`DeduplicateCommand` lives here, not in core.** It must produce
  byte-identical content keys to the `DeduplicatingStorage` the application
  configured — same `DedupScope`, same scope provider — and it commits through a
  ledger that shares the repository's connection. A second copy of that decision
  in core is how the two drift.
- **A deletion lease is exclusive and expiring.** Expiry is not a detail: it is
  the entire recovery story for a worker that dies mid-delete. `reserve()`
  refuses a `deleting` blob with `BlobBusyException` rather than joining it.
- **Timestamps are text, UTC, with microseconds.** The ledger compares
  deadlines with `<=` against a stored string, and lexicographic order only
  agrees with chronological order when every row carries the same offset.
  Microseconds stay because `File`'s round-trip contract needs them.
- **`save()` is a scoped update falling through to an insert, not an upsert.**
  An upsert matches on the primary key alone, so another tenant's row would be
  silently taken over. Ids are unguessable; "unguessable" is not a boundary.
- **The blob primary key is `sha256(store:path)`.** A unique index over the
  pair would say the same thing but exceeds MySQL's key length at a
  512-character path. The derived key also makes two writers racing to create
  one blob collide on the primary key instead of producing two rows.
- **A malformed row is `InvalidFileRowException`, never a coerced `File`.**
  Coercing until `File::create()` stops refusing turns a corrupted row into a
  plausible record pointing at the wrong object.
- **`yiisoft/db-migration` ^2.1 is a hard floor.** On 2.0.x
  `setSourceNamespaces()` resolved a namespace *neighbour* as a parent, landed
  in the core package's directory, found nothing, and `migrate:up` exited 0
  having created no tables (`docs/evolved-rules.md` ER-044). Nine packages
  documented that recipe without a test executing it;
  `tests/Integration/SqliteIntegrationTest` is this package's version of that
  test and must keep running in CI.
- **Unit tests run against real in-memory SQLite**, not a fake connection, and
  they build the schema from the migrations. What they are testing *is* the
  SQL; a fake that reimplemented the guards would prove the fake works.
- **Two packages contributing `params['yiisoft/yii-console']['commands']` is
  only safe because the runner merges `params` recursively.** This package names
  `filestorage:deduplicate` there and core names its five; without recursion
  `yiisoft/config` refuses the *top-level* key, not the leaf. Every Yii3
  application gets the recursion — `ApplicationRunner` constructs `Config` with
  `RecursiveMerge::groups(...$paramsGroups, ...)` — so this holds in practice,
  but a harness that builds `Config` by hand must pass the same modifier or it
  will report a `Duplicate key` that no application would ever see. Verified
  against real `yiisoft/config` with a fake vendor layout, 2026-08-07.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types, named arguments, trailing commas.
- Every validation regex ends with `\z`, never `$` (`docs/evolved-rules.md`
  ER-001).
- `config/di.php` is covered by neither cs, nor psalm, nor `src`-scoped tests.
  `ConfigWiringTest` exercises it through a real container instead.
- `examples/` is part of the public contract: keep scripts runnable and update
  `examples/README.md` when example usage changes.
- **CI workflows are SHA-pinned.** Every `uses:` references a 40-char commit
  SHA with a `# vN` trailing comment. Never revert to floating `@vN` tags;
  updates go through Dependabot. Workflows carry
  `permissions: { contents: read }` and `persist-credentials: false` on every
  checkout. Verify with `zizmor --persona=auditor .github/`.
- **The property-testing corpus uses `actions/cache/restore` + an explicit
  `actions/cache/save` with `if: ${{ !cancelled() }}`.** The combined
  `actions/cache` declares `post-if: "success()"`, and the run that records a
  counterexample is the failing one.

## When you finish

- Update `README.md` **and `README.ru.md`** (both languages, same commit), plus
  `llms.txt`, `resources/skills/*/SKILL.md` and `examples/` if usage changed;
  update `CHANGELOG.md` when releasing.
- Re-run `make build` and `make test-integration`; if the change affects public
  API or release safety, also run `make release-check`. Paste the output.
