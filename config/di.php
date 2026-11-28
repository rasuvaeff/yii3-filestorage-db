<?php

declare(strict_types=1);

use Psr\Clock\ClockInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Rasuvaeff\Yii3Filestorage\Id\IdGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Mime\ExtensionMap;
use Rasuvaeff\Yii3Filestorage\Mime\MimeTypeDetectorInterface;
use Rasuvaeff\Yii3Filestorage\Policy\PolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Storage;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Path\ContentAddressedKeyGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Repository\FileScopeProviderInterface;
use Rasuvaeff\Yii3Filestorage\Repository\MaintenanceRepositoryInterface;
use Rasuvaeff\Yii3Filestorage\Repository\RepositoryInterface;
use Rasuvaeff\Yii3Filestorage\Repository\ScopedFileResolverInterface;
use Rasuvaeff\Yii3Filestorage\Store\BlobLedgerInterface;
use Rasuvaeff\Yii3FilestorageDb\BlobReservationTableName;
use Rasuvaeff\Yii3FilestorageDb\BlobTableName;
use Rasuvaeff\Yii3FilestorageDb\Command\DeduplicateCommand;
use Rasuvaeff\Yii3FilestorageDb\DbBlobLedger;
use Rasuvaeff\Yii3FilestorageDb\DbRepository;
use Rasuvaeff\Yii3FilestorageDb\DbScopedFileResolver;
use Rasuvaeff\Yii3FilestorageDb\DeduplicatingStorageFactory;
use Rasuvaeff\Yii3FilestorageDb\FileTableName;
use Yiisoft\Db\Connection\ConnectionInterface;

/** @var array $params */

// This package owns the metadata half of the family: the repository, the
// signed-download resolver and the deduplication ledger. It deliberately does
// NOT bind StorageInterface (core's) or StoreInterface (-flysystem's, or the
// application's) — `yiisoft/config` allows exactly one vendor package per key,
// and two packages claiming one is a `Duplicate key` error by design.
//
// FileScopeProviderInterface is not bound here either: only the application
// knows what a tenant is. Left unbound, the repository adds no predicate and
// the installation is single-tenant. Bound and returning null, it is refused.
//
// `globalMaintenanceAccess` lets a null scope mean "every tenant" — but only
// for the two keys core's maintenance commands resolve,
// MaintenanceRepositoryInterface and BlobLedgerInterface. Console params are
// shared by every console process, queue workers included; a flag that
// flipped the one repository would hand a worker without a tenant every
// tenant's rows. The hot path (RepositoryInterface, DbRepository, the
// deduplicating storage and its ledger) always refuses.
$globalMaintenanceAccess = (bool) ($params['rasuvaeff/yii3-filestorage-db']['globalMaintenanceAccess'] ?? false);

return [
    // the migrations resolve these by type through Injector::make(), so the
    // repository, the ledger and the schema can never disagree about a name
    FileTableName::class => static fn (): FileTableName => new FileTableName(
        ((string) $params['rasuvaeff/yii3-filestorage-db']['tablePrefix'])
        . ((string) $params['rasuvaeff/yii3-filestorage-db']['fileTable']),
    ),

    BlobTableName::class => static fn (): BlobTableName => new BlobTableName(
        ((string) $params['rasuvaeff/yii3-filestorage-db']['tablePrefix'])
        . ((string) $params['rasuvaeff/yii3-filestorage-db']['blobTable']),
    ),

    BlobReservationTableName::class => static fn (): BlobReservationTableName => new BlobReservationTableName(
        ((string) $params['rasuvaeff/yii3-filestorage-db']['tablePrefix'])
        . ((string) $params['rasuvaeff/yii3-filestorage-db']['blobReservationTable']),
    ),

    DbRepository::class => static fn (
        ConnectionInterface $db,
        FileTableName $table,
        ?FileScopeProviderInterface $scopes = null,
    ): DbRepository => new DbRepository(db: $db, table: $table, scopes: $scopes),

    RepositoryInterface::class => DbRepository::class,

    // what core's gc/stat/verify/backfill-hash (and -images' warm) resolve; the
    // same object as the hot-path repository unless the maintenance opt-in is
    // on — and on the same connection either way
    MaintenanceRepositoryInterface::class => static fn (
        DbRepository $repository,
        ConnectionInterface $db,
        FileTableName $table,
        ?FileScopeProviderInterface $scopes = null,
    ): MaintenanceRepositoryInterface => $globalMaintenanceAccess
        ? new DbRepository(db: $db, table: $table, scopes: $scopes, allowGlobalAccess: true)
        : $repository,

    ScopedFileResolverInterface::class => static fn (
        ConnectionInterface $db,
        FileTableName $table,
    ): ScopedFileResolverInterface => new DbScopedFileResolver(db: $db, table: $table),

    // the ledger writes file rows through the repository, never itself: the
    // mandatory tenant predicate lives there, and a ledger with its own
    // `DELETE ... WHERE id = :id` would be a cross-tenant delete. This one is
    // the hot path's: the deduplicating storage and filestorage:deduplicate.
    DbBlobLedger::class => static fn (
        ConnectionInterface $db,
        DbRepository $repository,
        ClockInterface $clock,
        FileTableName $fileTable,
        BlobTableName $blobTable,
        BlobReservationTableName $reservationTable,
    ): DbBlobLedger => new DbBlobLedger(
        db: $db,
        repository: $repository,
        clock: $clock,
        fileTable: $fileTable,
        blobTable: $blobTable,
        reservationTable: $reservationTable,
    ),

    // What core's filestorage:gc resolves. It releases expired rows through
    // the ledger, so under the maintenance opt-in the ledger must see the rows
    // the maintenance repository listed — otherwise gc fails on the first
    // deduplicated row it meets. The same object as above without the opt-in.
    BlobLedgerInterface::class => static fn (
        DbBlobLedger $ledger,
        ConnectionInterface $db,
        ClockInterface $clock,
        FileTableName $fileTable,
        BlobTableName $blobTable,
        BlobReservationTableName $reservationTable,
        ?FileScopeProviderInterface $scopes = null,
    ): BlobLedgerInterface => $globalMaintenanceAccess
        ? new DbBlobLedger(
            db: $db,
            repository: new DbRepository(db: $db, table: $fileTable, scopes: $scopes, allowGlobalAccess: true),
            clock: $clock,
            fileTable: $fileTable,
            blobTable: $blobTable,
            reservationTable: $reservationTable,
        )
        : $ledger,

    // The factory is bound; the deduplicating facade is not. Replacing
    // StorageInterface is the application's call and the application's only —
    // core binds that key, and a second vendor package claiming it is the
    // `Duplicate key` error this family is arranged to avoid. An application
    // that wants sharing writes, in its own config:
    //
    //     StorageInterface::class => static fn (DeduplicatingStorageFactory $f)
    //         => $f->create(scope: DedupScope::TenantGroup),
    //
    // Which is why the base facade below is asked for as `Storage`, core's
    // concrete id, and not as `StorageInterface`. The application above has
    // just pointed that interface at this factory's own product: resolving it
    // here would resolve to the deduplicating storage being built, and the
    // container answers `CircularReferenceException` naming an interface the
    // recipe never mentions. `DeduplicatingStorageFactory` itself still accepts
    // the interface, so a test can hand it any double.
    DeduplicatingStorageFactory::class => static fn (
        Storage $unique,
        StoreRegistry $stores,
        DbRepository $repository,
        DbBlobLedger $ledger,
        MimeTypeDetectorInterface $mimeTypeDetector,
        IdGeneratorInterface $idGenerator,
        PolicyRegistry $policies,
        ClockInterface $clock,
        ExtensionMap $extensions,
        ?FileScopeProviderInterface $scopes = null,
        ?ContentAddressedKeyGeneratorInterface $keys = null,
    ): DeduplicatingStorageFactory => new DeduplicatingStorageFactory(
        unique: $unique,
        stores: $stores,
        repository: $repository,
        ledger: $ledger,
        mimeTypeDetector: $mimeTypeDetector,
        idGenerator: $idGenerator,
        policies: $policies,
        clock: $clock,
        scopes: $scopes,
        keys: $keys,
        extensions: $extensions,
    ),

    // The migration for existing data. It lives here rather than in core
    // because it has to produce byte-identical content keys to the
    // DeduplicatingStorage the application configured — same DedupScope, same
    // scope provider, same ExtensionMap (core's, carrying the application's
    // `extensionOverrides`) — and a second copy of that decision in core is how
    // the two drift into keys that never join.
    DeduplicateCommand::class => static fn (
        StoreRegistry $stores,
        DbRepository $repository,
        DbBlobLedger $ledger,
        StreamFactoryInterface $streams,
        ClockInterface $clock,
        ExtensionMap $extensions,
        ?FileScopeProviderInterface $scopes = null,
        ?ContentAddressedKeyGeneratorInterface $keys = null,
    ): DeduplicateCommand => new DeduplicateCommand(
        stores: $stores,
        repository: $repository,
        ledger: $ledger,
        streams: $streams,
        clock: $clock,
        scopes: $scopes,
        keys: $keys,
        extensions: $extensions,
    ),
];
