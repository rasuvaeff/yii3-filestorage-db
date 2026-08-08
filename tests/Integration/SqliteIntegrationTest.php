<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Tests\Integration;

use DateTimeImmutable;
use Rasuvaeff\Yii3Filestorage\Store\BlobId;
use Rasuvaeff\Yii3Filestorage\Store\BlobState;
use Rasuvaeff\Yii3FilestorageDb\BlobReservationTableName;
use Rasuvaeff\Yii3FilestorageDb\BlobTableName;
use Rasuvaeff\Yii3FilestorageDb\DbBlobLedger;
use Rasuvaeff\Yii3FilestorageDb\DbRepository;
use Rasuvaeff\Yii3FilestorageDb\FileTableName;
use Rasuvaeff\Yii3FilestorageDb\Migration\M260807000000CreateFilestorageFileTable;
use Rasuvaeff\Yii3FilestorageDb\Migration\M260807000001CreateFilestorageBlobTables;
use Rasuvaeff\Yii3FilestorageDb\Tests\Support\SqliteDatabase;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Migration\Informer\NullMigrationInformer;
use Yiisoft\Db\Migration\Migrator;
use Yiisoft\Db\Migration\Service\MigrationService;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Injector\Injector;
use Yiisoft\Test\Support\Clock\StaticClock;
use Yiisoft\Test\Support\Container\SimpleContainer;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

/**
 * The migration path the README hands the user, executed exactly as written.
 *
 * This suite exists because of a real incident: `setSourceNamespaces()` used
 * to resolve a namespace to the *core* package's directory — a namespace
 * neighbour looked like a parent to a `str_starts_with` over a trimmed PSR-4
 * key — so `migrate:up` found nothing, printed "up-to-date", exited 0 and
 * created no tables. Nine packages shipped that recipe in their README, and
 * not one test executed it: they all built migrations through `Injector::make()`
 * directly, which is a different code path. `yiisoft/db-migration` 2.1.0
 * carries the fix, and this is the test that keeps us from documenting a
 * recipe again without running it.
 *
 * It is a separate suite because `composer test` runs Unit only; the CI job
 * runs `composer test:integration` right after `composer build`.
 */
#[Test]
#[CoversNothing]
final class SqliteIntegrationTest
{
    private ConnectionInterface $db;
    private MigrationService $migrations;
    private Migrator $migrator;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->db = new SqliteConnection(
            driver: new SqliteDriver(dsn: 'sqlite::memory:'),
            schemaCache: new SchemaCache(psrCache: new MemorySimpleCache()),
        );
        $this->db->open();

        // the container the discovery path resolves migration arguments from:
        // the table-name value objects, exactly as `config/di.php` binds them
        $injector = new Injector(new SimpleContainer([
            FileTableName::class => new FileTableName(),
            BlobTableName::class => new BlobTableName(),
            BlobReservationTableName::class => new BlobReservationTableName(),
        ]));

        $this->migrator = new Migrator(db: $this->db, informer: new NullMigrationInformer());
        $this->migrations = new MigrationService(
            db: $this->db,
            injector: $injector,
            migrator: $this->migrator,
        );
        $this->migrations->setSourceNamespaces(['Rasuvaeff\\Yii3FilestorageDb\\Migration']);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->db->close();
    }

    /**
     * The assertion that would have caught the incident: discovery finds the
     * migrations, and finds *these* ones, fully qualified.
     */
    public function namespaceDiscoveryFindsThisPackagesMigrations(): void
    {
        Assert::same($this->migrations->getNewMigrations(), [
            M260807000000CreateFilestorageFileTable::class,
            M260807000001CreateFilestorageBlobTables::class,
        ]);
    }

    public function theDocumentedRegistrationCreatesAllThreeTables(): void
    {
        $this->migrateUp();

        Assert::notNull($this->db->getTableSchema('filestorage_file', true));
        Assert::notNull($this->db->getTableSchema('filestorage_blob', true));
        Assert::notNull($this->db->getTableSchema('filestorage_blob_reservation', true));
    }

    public function aSecondRunHasNothingLeftToApply(): void
    {
        $this->migrateUp();

        Assert::same($this->migrations->getNewMigrations(), []);
    }

    /**
     * End to end on the schema the migrations produced, not on one a test
     * wrote: reserve, publish, commit, release, collect.
     */
    public function theWholeDeduplicatedLifecycleRunsOnTheMigratedSchema(): void
    {
        $this->migrateUp();

        $repository = new DbRepository($this->db);
        $ledger = new DbBlobLedger(
            db: $this->db,
            repository: $repository,
            clock: new StaticClock($this->at('00:00')),
        );
        $blob = BlobId::create('upload', 'sha/e3/b0/original');

        $first = $ledger->reserve($blob, SqliteDatabase::HASH, 12, $this->at('00:10'));
        $second = $ledger->reserve($blob, SqliteDatabase::HASH, 12, $this->at('00:10'));
        $ledger->commit($first, SqliteDatabase::file('a'));
        $ledger->commit($second, SqliteDatabase::file('b'));

        Assert::same($ledger->find($blob)?->referenceCount, 2);
        Assert::same($repository->find('a')?->id, 'a');

        Assert::true($ledger->releaseFile('a', $this->at('01:00')));
        Assert::same($ledger->find($blob)?->state, BlobState::Active);

        Assert::true($ledger->releaseFile('b', $this->at('01:00')));
        Assert::same($ledger->find($blob)?->state, BlobState::PendingDelete);

        $lease = $ledger->claimForDeletion($this->at('01:00'), $this->at('01:05'));
        Assert::same($lease?->blob->key(), $blob->key());
        Assert::true($ledger->completeDeletion($lease));
        Assert::null($ledger->find($blob));
    }

    private function migrateUp(): void
    {
        foreach ($this->migrations->getNewMigrations() as $class) {
            $this->migrator->up($this->migrations->makeMigration($class));
        }
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable("2026-01-01T{$time}:00.000000+00:00");
    }
}
