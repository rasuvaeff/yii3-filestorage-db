# rasuvaeff/yii3-filestorage-db

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/yii3-filestorage-db/v)](https://packagist.org/packages/rasuvaeff/yii3-filestorage-db)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-filestorage-db/downloads)](https://packagist.org/packages/rasuvaeff/yii3-filestorage-db)
[![Build](https://github.com/rasuvaeff/yii3-filestorage-db/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-filestorage-db/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/yii3-filestorage-db/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-filestorage-db/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/yii3-filestorage-db/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-filestorage-db/php)](https://packagist.org/packages/rasuvaeff/yii3-filestorage-db)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)
[Русская версия](README.ru.md)

The metadata half of [`rasuvaeff/yii3-filestorage`](https://github.com/rasuvaeff/yii3-filestorage):
file records in a database table, optional tenant scoping that no code path can
switch off, and the ledger that makes deduplicated bytes safe to delete.

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference you can share with the model.
> Projects using the [llm/skills](https://github.com/roxblnfk/skills) Composer plugin also get this package's agent skill synced into `.agents/skills/` automatically on install.

**Status: `0.x`.** The API may still change while the Flysystem and web
packages are built against it.

## Requirements

- PHP 8.3+
- `ext-json`
- `rasuvaeff/yii3-filestorage` ^0.1
- `yiisoft/db` ^2.0 (plus a driver: `yiisoft/db-sqlite`, `-mysql`, `-pgsql`, …)
- `yiisoft/db-migration` ^2.1 — **not** `^2.0`, see [Migrations](#migrations)

## Installation

```bash
composer require rasuvaeff/yii3-filestorage-db
```

This package binds `RepositoryInterface`, `MaintenanceRepositoryInterface`,
`ScopedFileResolverInterface` and `BlobLedgerInterface`. Core binds the facade.
You still bind a `StoreInterface` — a local `FileSystemStore`, or
`rasuvaeff/yii3-filestorage-flysystem` for S3 and friends.

## Migrations

Register by namespace:

```php
// config/common/di/db-migration.php
use Yiisoft\Db\Migration\Service\MigrationService;

return [
    MigrationService::class => [
        'setSourceNamespaces()' => [['Rasuvaeff\\Yii3FilestorageDb\\Migration']],
    ],
];
```

```bash
./yii migrate:up
```

Three tables: `filestorage_file`, `filestorage_blob` and
`filestorage_blob_reservation`.

> **`yiisoft/db-migration` ^2.1 is a hard requirement.** On 2.0.x this exact
> registration silently found nothing: namespace resolution matched a
> *neighbouring* namespace as if it were a parent, landed in the core package's
> directory, and `migrate:up` printed "up-to-date", exited 0 and created no
> tables. 2.1.0 carries the fix ([#350](https://github.com/yiisoft/db-migration/pull/350)),
> and this package's integration suite runs the recipe above on every CI build
> rather than trusting it.

## Configuration

```php
// config/common/params.php
return [
    'rasuvaeff/yii3-filestorage-db' => [
        'fileTable' => 'filestorage_file',
        'blobTable' => 'filestorage_blob',
        'blobReservationTable' => 'filestorage_blob_reservation',
        'tablePrefix' => '',
    ],
];
```

The prefix is prepended to all three. Names are validated by typed value
objects — `FileTableName`, `BlobTableName`, `BlobReservationTableName` — which
the migrations resolve from the container, so the schema and the queries cannot
disagree about a name.

## Tenant scoping

Bind `FileScopeProviderInterface` and every statement this package issues gains
a `scope_id` predicate. Leave it unbound and the installation is single-tenant:
no predicate, no cost.

```php
// config/common/di/filestorage.php
use Rasuvaeff\Yii3Filestorage\Repository\FileScopeProviderInterface;

return [
    FileScopeProviderInterface::class => static fn (TenantContext $tenants): FileScopeProviderInterface
        => new class ($tenants) implements FileScopeProviderInterface {
            public function __construct(private TenantContext $tenants) {}

            public function currentScopeId(): ?string
            {
                return $this->tenants->currentId();
            }
        },
];
```

Only your application knows what a tenant is — from `rasuvaeff/yii3-tenancy`, a
session, a subdomain — so this package cannot bind it for you without being
wrong in every installation that does it differently.

**There is no method that skips the predicate.** Not for downloads, not for
maintenance. A signed download has no ambient tenant, which is the point of
signing it, and the shortcut everyone reaches for — looking up by id with the
filter off — reads any file whose id leaks. Use `ScopedFileResolverInterface`
instead: the scope was authenticated when the token was minted, travels inside
the HMAC, and is matched as a second predicate.

```php
$file = $resolver->findInScope($payload->fileId, $payload->scopeId);
```

`null` is a scope, not the absence of one: it matches `scope_id IS NULL`, which
is what an unscoped application's rows carry. An unscoped token is never a
wildcard.

## Deduplication

Two people upload the same file. One object on disk, two rows in the database —
and the object must not disappear when the first row does. `BlobLedgerInterface`
is how that is coordinated; this package is the transactional implementation.

```php
$blob = BlobId::create('upload', $contentAddressedPath);

$reservation = $ledger->reserve($blob, $sha256, $size, $expiresAt);  // claim the key
$store->putIfAbsent($upload, $blob->object);                          // publish the bytes
$ledger->commit($reservation, $file);                                 // row + reference, one transaction
```

On any failure between steps, `release($reservation, $deleteAfter)`. Removal is
`releaseFile($fileId, $deleteAfter)`.

| Rule | Why |
|---|---|
| Shared bytes are **never** deleted inside a request | An object deleted mid-request is one some concurrent add has already decided to reuse. The last release only marks a blob `pending_delete` |
| Only a collector deletes, and only under a lease | Exclusive and expiring, so a worker that dies mid-delete is recovered by the next one stealing the lease rather than blocking forever |
| Every guard is in the statement that acts | A `SELECT` then an `UPDATE` is two moments with a gap, and the gap is where a committed row ends up pointing at deleted bytes |
| Ownership is a `BlobId`, never a content hash | A hash is identical across stores, groups and tenants, so a hash-keyed count lets one tenant delete what another is reading |
| There is no `reference_count` column | A reference *is* a file row with this blob's id, and the guards ask `NOT EXISTS`. A counter is a second source of truth that can drift, and every underflow guard only detects drift after it happens |

States are `writing`, `active`, `pending_delete` and `deleting`. A writer may
join any of them except the last, where it gets a `BlobBusyException` and
retries after the lease ends.

The collection pass:

```php
$ledger->expireReservations($now, $deleteAfter);         // sweep abandoned writers first

while ($lease = $ledger->claimForDeletion($now, $now->add($leaseTtl))) {
    try {
        $store->deleteObject($lease->blob->object);
        $ledger->completeDeletion($lease);               // refuses if the blob was revived
    } catch (StoreException) {
        $ledger->abandonDeletion($lease, $retryAfter);   // back off, keep the row
    }
}
```

### Turning it on

The protocol above is driven for you by `DeduplicatingStorage`. It is **not**
bound by this package: replacing `StorageInterface` is a root-application
decision, because core owns that key and two vendor packages claiming one is a
`yiisoft/config` `Duplicate key` error by design. So the application opts in:

```php
// config/common/di/filestorage.php
use Rasuvaeff\Yii3Filestorage\StorageInterface;
use Rasuvaeff\Yii3FilestorageDb\DedupScope;
use Rasuvaeff\Yii3FilestorageDb\DeduplicatingStorageFactory;

return [
    StorageInterface::class => static fn (
        DeduplicatingStorageFactory $factory,
    ): StorageInterface => $factory->create(
        scope: DedupScope::TenantGroup,
        dedupMaxBytes: 104_857_600,
        deleteGracePeriod: new DateInterval('PT1H'),
    ),
];
```

`create()` refuses two configurations that would otherwise lose data quietly,
and says what to do instead: a store that does not implement
`ContentAddressableStoreInterface`, and a ledger on a different connection than
the repository — which makes `commit()` two transactions rather than one, so a
crash between them leaves a row with no reference or a reference with no row.

Everything the consumer sees is unchanged. `add()` still returns a `File` with
its own id, group, description and metadata; two uploads of the same bytes just
end up pointing at one object. Above `dedupMaxBytes` an upload silently takes
the unique path — the content key *is* the hash, so it cannot be chosen without
reading the whole body, and that read is not worth an unbounded second pass.

### How widely bytes are shared

`DedupScope` is a security choice, not a space/CPU dial:

| Scope | Pool | When |
|---|---|---|
| `TenantGroup` *(default)* | per tenant, per group | Always safe. The only one appropriate for untrusted tenants |
| `Tenant` | per tenant | One tenant's groups share; tenants stay apart |
| `Global` | everything | Saves the most space, and **discloses content existence across tenants** |

The disclosure is real: whoever uploads a file learns from the write timing, and
from a quota that does not move, whether that exact content already existed.
Between one tenant's own files that says nothing new. Across tenants it is an
oracle — upload a suspected document, see whether it was "already there".

## Maintenance

`DbRepository` implements `MaintenanceRepositoryInterface`, so a long job pages
by id and resumes where it stopped:

```php
$afterId = null;
do {
    $page = iterator_to_array($repository->files($afterId, limit: 500), false);
    foreach ($page as $file) {
        // …
        $afterId = $file->id;
    }
} while ($page !== []);
```

The cursor keeps the tenant predicate.

## Storage format

| Decision | Why |
|---|---|
| Timestamps are `Y-m-d\TH:i:s.uP` text, normalised to UTC | Microseconds survive (a truncating column would break `File`'s round-trip contract), and the ledger compares deadlines as strings — which only agrees with chronological order when every row carries the same offset |
| `metadata` is JSON in a text column | The shape is a flat `array<string, scalar\|null>` and nothing queries inside it, so a native JSON column would buy nothing and cost portability |
| `save()` is a scoped update falling through to an insert, not an upsert | An upsert matches on the primary key alone, so another tenant's row would be silently taken over. Ids are unguessable, but "unguessable" is not a boundary |
| A malformed row is an exception, not a coerced `File` | `InvalidFileRowException`. Coercing until `File::create()` stops refusing turns a corrupted row into a plausible record pointing at the wrong object |
| The blob primary key is `sha256(store:path)` | The pair itself exceeds MySQL's index key length at a 512-character path, and a derived key makes two writers racing to create one blob collide on the primary key instead of producing two rows |

## Examples

Runnable, self-contained, no server needed — see [`examples/`](examples/).

## Development

No PHP or Composer on the host; everything runs in Docker.

```bash
make build            # validate, normalize, require-checker, cs, psalm, test
make test-integration # the documented migration recipe, executed
make cs-fix
make mutation
make release-check
```

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
