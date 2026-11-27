<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Tests\Integration;

use DateInterval;
use DateTimeImmutable;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Filestorage\Id\Uuid7IdGenerator;
use Rasuvaeff\Yii3Filestorage\Mime\FinfoMimeTypeDetector;
use Rasuvaeff\Yii3Filestorage\Path\RandomPathGenerator;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Policy\PolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Storage;
use Rasuvaeff\Yii3Filestorage\Store\BlobId;
use Rasuvaeff\Yii3Filestorage\Store\BlobState;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Test\InMemoryStore;
use Rasuvaeff\Yii3Filestorage\Upload;
use Rasuvaeff\Yii3FilestorageDb\DbBlobLedger;
use Rasuvaeff\Yii3FilestorageDb\DbRepository;
use Rasuvaeff\Yii3FilestorageDb\DeduplicatingStorage;
use Rasuvaeff\Yii3FilestorageDb\DeduplicatingStorageFactory;
use Rasuvaeff\Yii3FilestorageDb\Migration\M260807000000CreateFilestorageFileTable;
use Rasuvaeff\Yii3FilestorageDb\Migration\M260807000001CreateFilestorageBlobTables;
use Rasuvaeff\Yii3FilestorageDb\Tests\Support\ContentAddressableInMemoryStore;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Migration\Informer\NullMigrationInformer;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Mysql\Connection as MysqlConnection;
use Yiisoft\Db\Mysql\Driver as MysqlDriver;
use Yiisoft\Db\Pgsql\Connection as PgsqlConnection;
use Yiisoft\Db\Pgsql\Driver as PgsqlDriver;
use Yiisoft\Test\Support\Clock\StaticClock;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

/**
 * The migrations and the whole ledger lifecycle on MySQL and PostgreSQL.
 *
 * Everything else in this package runs on SQLite, which accepts SQL the other
 * engines reject. The first `remove()` of a deduplicated file once failed on
 * both of them — the `NOT EXISTS` guard selected `'1'`, which `yiisoft/db`
 * quotes as a column name — while every SQLite test stayed green. So this
 * drives every statement the ledger issues, not only the DDL: two adds of the
 * same bytes, both removes, a released and a revived reservation, an expired
 * reservation, a claimed, abandoned, re-claimed and completed deletion, and
 * both migrations down.
 *
 * Runs only when `FILESTORAGE_TEST_DB` names a live server; the ungated
 * `database-integration` matrix job in CI supplies both.
 */
#[Test]
#[CoversNothing]
final class CrossDatabaseMigrationTest
{
    private const array TABLES = ['filestorage_blob_reservation', 'filestorage_blob', 'filestorage_file'];

    public function migrationsAndTheLedgerLifecycleWorkOnTheConfiguredDatabase(): void
    {
        $database = getenv('FILESTORAGE_TEST_DB');

        if ($database !== 'mysql' && $database !== 'pgsql') {
            Assert::true($database === false || $database === '');

            return;
        }

        $db = $this->connection($database);
        $db->open();

        try {
            $this->dropTables($db);

            $builder = new MigrationBuilder(db: $db, informer: new NullMigrationInformer());
            $files = new M260807000000CreateFilestorageFileTable();
            $blobs = new M260807000001CreateFilestorageBlobTables();
            $files->up($builder);
            $blobs->up($builder);

            foreach (self::TABLES as $table) {
                Assert::notNull($db->getTableSchema($table, true), "{$table} was not created");
            }

            $this->deduplicatedAddsAndRemoves($db);
            $this->reservationsAndCollection($db);

            $blobs->down($builder);
            $files->down($builder);

            foreach (self::TABLES as $table) {
                Assert::null($db->getTableSchema($table, true), "{$table} was not dropped");
            }
        } finally {
            $this->dropTables($db);
            $db->close();
        }
    }

    /**
     * Two adds of identical content share one object; removing the first
     * leaves the blob active, removing the second schedules it, and a
     * collector claims and deletes it once the grace period is over.
     */
    private function deduplicatedAddsAndRemoves(ConnectionInterface $db): void
    {
        $clock = new StaticClock($this->at('00:00'));
        $repository = new DbRepository($db);
        $ledger = new DbBlobLedger(db: $db, repository: $repository, clock: $clock);
        $http = new Psr17Factory();
        $store = new ContentAddressableInMemoryStore(new InMemoryStore('upload', $http, $clock));
        $storage = $this->storage($repository, $ledger, $store, $clock);

        $first = $storage->add(Upload::fromStream($http->createStream('hello'), 'a.txt', $http));
        $second = $storage->add(Upload::fromStream($http->createStream('hello'), 'b.txt', $http));
        $blob = BlobId::create('upload', $first->relativePath);

        Assert::same($second->relativePath, $first->relativePath);
        Assert::same($store->writeCount(), 1);
        Assert::same($ledger->find($blob)?->referenceCount, 2);
        Assert::same($ledger->find($blob)?->size, 5);

        // re-setting the hash a row already holds still finds the row
        Assert::true($repository->updateContentHash($second->id, (string) $second->contentHash));
        Assert::false($repository->updateContentHash('missing', (string) $second->contentHash));

        Assert::true($storage->remove($first->id));
        Assert::null($repository->find($first->id));
        Assert::same($ledger->find($blob)?->state, BlobState::Active);
        Assert::same($ledger->find($blob)?->referenceCount, 1);

        Assert::true($storage->remove($second->id));
        Assert::false($storage->remove($second->id));
        Assert::same($ledger->find($blob)?->state, BlobState::PendingDelete);
        Assert::same($ledger->find($blob)?->deleteAfter?->format('H:i'), '01:00');

        Assert::null($ledger->claimForDeletion($this->at('00:30'), $this->at('00:35')));

        $lease = $ledger->claimForDeletion($this->at('01:00'), $this->at('01:05'));
        Assert::same($lease?->blob->key(), $blob->key());
        Assert::same($ledger->find($blob)?->state, BlobState::Deleting);
        Assert::true($ledger->completeDeletion($lease));
        Assert::null($ledger->find($blob));
    }

    /**
     * The paths a writer that never commits takes: an explicit release, a
     * revival of a scheduled blob, a reservation left to expire, and a
     * collector that abandons its lease before another one finishes the job.
     */
    private function reservationsAndCollection(ConnectionInterface $db): void
    {
        $repository = new DbRepository($db);
        $ledger = new DbBlobLedger(db: $db, repository: $repository, clock: new StaticClock($this->at('00:00')));
        $blob = BlobId::create('upload', 'sha/2c/f2/original');
        $hash = hash('sha256', 'orphan');

        $reservation = $ledger->reserve($blob, $hash, 6, $this->at('00:10'));
        Assert::same($ledger->find($blob)?->state, BlobState::Writing);
        Assert::same($ledger->find($blob)?->reservationCount, 1);

        $ledger->release($reservation, $this->at('01:00'));
        Assert::same($ledger->find($blob)?->state, BlobState::PendingDelete);
        Assert::same($ledger->find($blob)?->reservationCount, 0);

        // joining a scheduled blob revives it, with nothing committed yet
        $ledger->reserve($blob, $hash, 6, $this->at('00:10'));
        Assert::same($ledger->find($blob)?->state, BlobState::Writing);
        Assert::null($ledger->claimForDeletion($this->at('02:00'), $this->at('02:05')));

        Assert::same($ledger->expireReservations($this->at('00:05'), $this->at('00:30')), 0);
        Assert::same($ledger->expireReservations($this->at('00:20'), $this->at('00:30')), 1);
        Assert::same($ledger->find($blob)?->state, BlobState::PendingDelete);
        Assert::same($ledger->find($blob)?->reservationCount, 0);

        $abandoned = $ledger->claimForDeletion($this->at('00:30'), $this->at('00:35'));
        Assert::notNull($abandoned);
        Assert::true($ledger->abandonDeletion($abandoned, $this->at('02:00')));
        Assert::false($ledger->completeDeletion($abandoned));
        Assert::same($ledger->find($blob)?->state, BlobState::PendingDelete);
        Assert::null($ledger->claimForDeletion($this->at('01:00'), $this->at('01:05')));

        $lease = $ledger->claimForDeletion($this->at('02:00'), $this->at('02:05'));
        Assert::notNull($lease);
        Assert::true($ledger->completeDeletion($lease));
        Assert::null($ledger->find($blob));
    }

    private function storage(
        DbRepository $repository,
        DbBlobLedger $ledger,
        ContentAddressableInMemoryStore $store,
        StaticClock $clock,
    ): DeduplicatingStorage {
        $stores = new StoreRegistry([$store]);

        return (new DeduplicatingStorageFactory(
            unique: new Storage(
                stores: $stores,
                repository: $repository,
                pathGenerator: new RandomPathGenerator(),
                mimeTypeDetector: new FinfoMimeTypeDetector(),
                idGenerator: new Uuid7IdGenerator($clock),
                policies: new PolicyRegistry(),
                deliveryPolicies: new DeliveryPolicyRegistry(),
                clock: $clock,
                defaultUrlTtl: new DateInterval('PT1H'),
            ),
            stores: $stores,
            repository: $repository,
            ledger: $ledger,
            mimeTypeDetector: new FinfoMimeTypeDetector(),
            idGenerator: new Uuid7IdGenerator($clock),
            policies: new PolicyRegistry(),
            clock: $clock,
        ))->create();
    }

    private function dropTables(ConnectionInterface $db): void
    {
        foreach (self::TABLES as $table) {
            $db->createCommand(sprintf('DROP TABLE IF EXISTS %s', $table))->execute();
        }
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable("2026-01-01T{$time}:00.000000+00:00");
    }

    private function connection(string $database): ConnectionInterface
    {
        $cache = new SchemaCache(psrCache: new MemorySimpleCache());
        $mysqlPort = getenv('FILESTORAGE_TEST_MYSQL_PORT') ?: '3306';
        $pgsqlPort = getenv('FILESTORAGE_TEST_PGSQL_PORT') ?: '5432';

        return $database === 'mysql'
            ? new MysqlConnection(
                driver: new MysqlDriver(
                    dsn: sprintf('mysql:host=127.0.0.1;port=%s;dbname=filestorage;charset=utf8mb4', $mysqlPort),
                    username: 'root',
                    password: 'filestorage',
                ),
                schemaCache: $cache,
            )
            : new PgsqlConnection(
                driver: new PgsqlDriver(
                    dsn: sprintf('pgsql:host=127.0.0.1;port=%s;dbname=filestorage', $pgsqlPort),
                    username: 'postgres',
                    password: 'filestorage',
                ),
                schemaCache: $cache,
            );
    }
}
