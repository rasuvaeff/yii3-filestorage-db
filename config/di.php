<?php

declare(strict_types=1);

use Psr\Clock\ClockInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Rasuvaeff\Yii3Filestorage\Id\IdGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Mime\MimeTypeDetectorInterface;
use Rasuvaeff\Yii3Filestorage\Policy\PolicyRegistry;
use Rasuvaeff\Yii3Filestorage\StorageInterface;
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
// the installation is single-tenant.
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

    // one implementation, both contracts: M5's operations commands resolve the
    // maintenance one, and a second instance would be a second connection
    RepositoryInterface::class => DbRepository::class,
    MaintenanceRepositoryInterface::class => DbRepository::class,

    ScopedFileResolverInterface::class => static fn (
        ConnectionInterface $db,
        FileTableName $table,
    ): ScopedFileResolverInterface => new DbScopedFileResolver(db: $db, table: $table),

    // the ledger writes file rows through the repository, never itself: the
    // mandatory tenant predicate lives there, and a ledger with its own
    // `DELETE ... WHERE id = :id` would be a cross-tenant delete
    BlobLedgerInterface::class => static fn (
        ConnectionInterface $db,
        DbRepository $repository,
        ClockInterface $clock,
        FileTableName $fileTable,
        BlobTableName $blobTable,
        BlobReservationTableName $reservationTable,
    ): BlobLedgerInterface => new DbBlobLedger(
        db: $db,
        repository: $repository,
        clock: $clock,
        fileTable: $fileTable,
        blobTable: $blobTable,
        reservationTable: $reservationTable,
    ),

    // The factory is bound; the deduplicating facade is not. Replacing
    // StorageInterface is the application's call and the application's only —
    // core binds that key, and a second vendor package claiming it is the
    // `Duplicate key` error this family is arranged to avoid. An application
    // that wants sharing writes, in its own config:
    //
    //     StorageInterface::class => static fn (DeduplicatingStorageFactory $f)
    //         => $f->create(scope: DedupScope::TenantGroup),
    DeduplicatingStorageFactory::class => static fn (
        StorageInterface $unique,
        StoreRegistry $stores,
        DbRepository $repository,
        BlobLedgerInterface $ledger,
        MimeTypeDetectorInterface $mimeTypeDetector,
        IdGeneratorInterface $idGenerator,
        PolicyRegistry $policies,
        ClockInterface $clock,
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
    ),

    // The migration for existing data. It lives here rather than in core
    // because it has to produce byte-identical content keys to the
    // DeduplicatingStorage the application configured — same DedupScope, same
    // scope provider — and a second copy of that decision in core is how the
    // two drift into keys that never join.
    DeduplicateCommand::class => static fn (
        StoreRegistry $stores,
        DbRepository $repository,
        BlobLedgerInterface $ledger,
        StreamFactoryInterface $streams,
        ClockInterface $clock,
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
    ),
];
