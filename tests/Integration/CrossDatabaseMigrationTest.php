<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Tests\Integration;

use DateInterval;
use DateTimeImmutable;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Filestorage\File;
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
use Rasuvaeff\Yii3FilestorageDb\DbScopedFileResolver;
use Rasuvaeff\Yii3FilestorageDb\DeduplicatingStorage;
use Rasuvaeff\Yii3FilestorageDb\DeduplicatingStorageFactory;
use Rasuvaeff\Yii3FilestorageDb\Migration\M260807000000CreateFilestorageFileTable;
use Rasuvaeff\Yii3FilestorageDb\Migration\M260807000001CreateFilestorageBlobTables;
use Rasuvaeff\Yii3FilestorageDb\Migration\M261127000000AddFilestorageFileStorePathIndex;
use Rasuvaeff\Yii3FilestorageDb\Migration\M261128000000MakeFilestorageFileScopeIdCaseSensitive;
use Rasuvaeff\Yii3FilestorageDb\Tests\Support\ContentAddressableInMemoryStore;
use Rasuvaeff\Yii3FilestorageDb\Tests\Support\FixedScope;
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
            $storePath = new M261127000000AddFilestorageFileStorePathIndex();
            $scopeCase = new M261128000000MakeFilestorageFileScopeIdCaseSensitive();
            $files->up($builder);
            $blobs->up($builder);
            $storePath->up($builder);
            $scopeCase->up($builder);

            foreach (self::TABLES as $table) {
                Assert::notNull($db->getTableSchema($table, true), "{$table} was not created");
            }
            Assert::true(
                \in_array('idx_filestorage_file_store_path', $this->indexNames($db), strict: true),
                'the store/path index was not created',
            );

            $this->deduplicatedAddsAndRemoves($db);
            $this->reservationsAndCollection($db);
            $this->tenantsDifferingOnlyInCaseAreApart($db);
            $this->referencedPaths($db);
            if ($database === 'mysql') {
                // `LIKE` without `ESCAPE` on MySQL relies on the backslash
                // being the escape character, which this mode switches off:
                // a directory with `_` then found nothing, and the orphan
                // sweep quarantined bytes live rows point at
                $db->createCommand("SET SESSION sql_mode = CONCAT(@@sql_mode, ',NO_BACKSLASH_ESCAPES')")->execute();
                $this->referencedPaths($db);
                $db->createCommand('SET SESSION sql_mode = DEFAULT')->execute();
            }

            $scopeCase->down($builder);
            $storePath->down($builder);
            Assert::false(
                \in_array('idx_filestorage_file_store_path', $this->indexNames($db), strict: true),
                'the store/path index was not dropped',
            );
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

    /**
     * The orphan sweep's lookup, on the engines whose `LIKE` and collation
     * differ from SQLite's: MySQL compares `=` and `LIKE` case-insensitively
     * by default, so the case variant comes back from the server and has to
     * be dropped by the exact comparison. A 512-character path goes past the
     * MySQL index prefix.
     */
    private function referencedPaths(ConnectionInterface $db): void
    {
        $repository = new DbRepository($db);
        $long = 'g/' . str_repeat('x', 500);
        $rows = [
            'r1' => 'g/ab/cd/k1/original',
            'r2' => 'g/ab/cd/k1/thumb.webp',
            'r3' => 'g/ab/cd/k1/sub/deeper',
            'r4' => 'G/AB/CD/K1/original',
            'r5' => 'a_b/original',
            'r6' => 'axb/original',
            'r9' => 'user_avatars/ab/cd/k1/original',
            'r10' => '50%!/x',
            'r11' => '50x!/x',
            'r7' => 'root.txt',
            'r8' => $long . '/original',
        ];
        foreach ($rows as $id => $path) {
            $repository->save(File::create(
                id: $id,
                storeName: 'upload',
                groupName: 'common',
                relativePath: $path,
                originalName: 'a.txt',
                size: 1,
                createdAt: $this->at('00:00'),
            ));
        }

        $paths = $repository->referencedPaths(
            'upload',
            ['g/ab/cd/k1', 'a_b', 'root.txt', $long, 'user_avatars/ab/cd/k1', '50%!'],
        );
        sort($paths);

        $expected = [
            'a_b/original',
            'g/ab/cd/k1/original',
            'g/ab/cd/k1/thumb.webp',
            $long . '/original',
            'root.txt',
            'user_avatars/ab/cd/k1/original',
            '50%!/x',
        ];
        sort($expected);

        Assert::same($paths, $expected);
        Assert::same($repository->referencedPaths('upload', ['ROOT.TXT', 'g/ab/cd/k2']), []);
        Assert::same($repository->referencedPaths('other', ['g/ab/cd/k1']), []);

        foreach (array_keys($rows) as $id) {
            $repository->delete($id);
        }
    }

    /**
     * MySQL's default collation compares `Acme` and `acme` — and `acme` and
     * `acmé` — as equal, which made two tenants one for every scoped
     * statement. The scope column is compared byte for byte.
     */
    private function tenantsDifferingOnlyInCaseAreApart(ConnectionInterface $db): void
    {
        $scope = new FixedScope('Acme');
        $repository = new DbRepository($db, scopes: $scope);
        $repository->save(File::create(
            id: 'tenant-row',
            storeName: 'upload',
            groupName: 'common',
            relativePath: 'g/tenant-row',
            originalName: 'a.txt',
            size: 1,
            createdAt: $this->at('00:00'),
        ));

        foreach (['acme', 'ACME', 'Acmé'] as $other) {
            $scope->switchTo($other);
            Assert::null($repository->find('tenant-row'), "tenant \"{$other}\" must not see the row of \"Acme\"");
            Assert::false($repository->delete('tenant-row'));
        }

        Assert::null((new DbScopedFileResolver($db))->findInScope('tenant-row', 'acme'));

        $scope->switchTo('Acme');
        Assert::true($repository->delete('tenant-row'));
    }

    /**
     * @return list<string>
     */
    private function indexNames(ConnectionInterface $db): array
    {
        $names = [];
        foreach ($db->getSchema()->getTableIndexes('filestorage_file', true) as $index) {
            $names[] = $index->name;
        }

        return $names;
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
