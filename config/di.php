<?php

declare(strict_types=1);

use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Filestorage\Repository\FileScopeProviderInterface;
use Rasuvaeff\Yii3Filestorage\Repository\MaintenanceRepositoryInterface;
use Rasuvaeff\Yii3Filestorage\Repository\RepositoryInterface;
use Rasuvaeff\Yii3Filestorage\Repository\ScopedFileResolverInterface;
use Rasuvaeff\Yii3Filestorage\Store\BlobLedgerInterface;
use Rasuvaeff\Yii3FilestorageDb\BlobReservationTableName;
use Rasuvaeff\Yii3FilestorageDb\BlobTableName;
use Rasuvaeff\Yii3FilestorageDb\DbBlobLedger;
use Rasuvaeff\Yii3FilestorageDb\DbRepository;
use Rasuvaeff\Yii3FilestorageDb\DbScopedFileResolver;
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
];
