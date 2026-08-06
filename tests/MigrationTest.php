<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Tests;

use Rasuvaeff\Yii3FilestorageDb\BlobReservationTableName;
use Rasuvaeff\Yii3FilestorageDb\BlobTableName;
use Rasuvaeff\Yii3FilestorageDb\FileTableName;
use Rasuvaeff\Yii3FilestorageDb\Migration\M260807000000CreateFilestorageFileTable;
use Rasuvaeff\Yii3FilestorageDb\Migration\M260807000001CreateFilestorageBlobTables;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Migration\Informer\NullMigrationInformer;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

#[Test]
#[Covers(M260807000000CreateFilestorageFileTable::class)]
#[Covers(M260807000001CreateFilestorageBlobTables::class)]
final class MigrationTest
{
    private ConnectionInterface $db;
    private MigrationBuilder $builder;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->db = new SqliteConnection(
            driver: new SqliteDriver(dsn: 'sqlite::memory:'),
            schemaCache: new SchemaCache(psrCache: new MemorySimpleCache()),
        );
        $this->db->open();
        $this->builder = new MigrationBuilder(db: $this->db, informer: new NullMigrationInformer());
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->db->close();
    }

    public function theFileTableCarriesEveryColumnAFileNeeds(): void
    {
        (new M260807000000CreateFilestorageFileTable())->up($this->builder);

        $schema = $this->db->getTableSchema('filestorage_file', true);
        Assert::notNull($schema);
        Assert::same($schema?->getPrimaryKey(), ['id']);
        foreach (
            [
                'id', 'store_name', 'external_id', 'group_name', 'relative_path', 'original_name',
                'mime_type', 'size', 'description', 'content_hash', 'metadata', 'created_at',
                'updated_at', 'blob_id', 'scope_id',
            ] as $column
        ) {
            Assert::notNull($schema?->getColumn($column), "missing column {$column}");
        }
    }

    /**
     * The ledger asks "does anything still reference this blob?" on every
     * release and every collection pass. Without this index that question is
     * a full scan of the largest table in the schema.
     */
    public function theFileTableIsIndexedForTheLedgersQuestions(): void
    {
        (new M260807000000CreateFilestorageFileTable())->up($this->builder);

        $indexes = $this->indexNames('filestorage_file');
        Assert::true(\in_array('idx_filestorage_file_blob_id', $indexes, true));
        Assert::true(\in_array('idx_filestorage_file_scope_id', $indexes, true));
        Assert::true(\in_array('idx_filestorage_file_content_hash', $indexes, true));
        Assert::true(\in_array('idx_filestorage_file_group_created', $indexes, true));
    }

    public function theLedgerTablesCarryTheirColumnsAndIndexes(): void
    {
        (new M260807000001CreateFilestorageBlobTables())->up($this->builder);

        $blobs = $this->db->getTableSchema('filestorage_blob', true);
        Assert::notNull($blobs);
        Assert::same($blobs?->getPrimaryKey(), ['id']);
        foreach (
            [
                'id', 'store_name', 'relative_path', 'content_hash', 'size', 'state',
                'delete_after', 'lease_token', 'lease_expires_at', 'created_at', 'updated_at',
            ] as $column
        ) {
            Assert::notNull($blobs?->getColumn($column), "missing column {$column}");
        }
        // there is deliberately no reference_count: a reference is a file row
        Assert::null($blobs?->getColumn('reference_count'));

        $reservations = $this->db->getTableSchema('filestorage_blob_reservation', true);
        Assert::notNull($reservations);
        Assert::same($reservations?->getPrimaryKey(), ['token']);
        foreach (['token', 'blob_id', 'expires_at', 'created_at'] as $column) {
            Assert::notNull($reservations?->getColumn($column), "missing column {$column}");
        }

        Assert::true(\in_array(
            'idx_filestorage_blob_state_delete_after',
            $this->indexNames('filestorage_blob'),
            true,
        ));
        Assert::true(\in_array(
            'idx_filestorage_blob_reservation_blob_id',
            $this->indexNames('filestorage_blob_reservation'),
            true,
        ));
    }

    public function bothMigrationsRevert(): void
    {
        $file = new M260807000000CreateFilestorageFileTable();
        $blobs = new M260807000001CreateFilestorageBlobTables();

        $file->up($this->builder);
        $blobs->up($this->builder);
        $blobs->down($this->builder);
        $file->down($this->builder);

        Assert::null($this->db->getTableSchema('filestorage_file', true));
        Assert::null($this->db->getTableSchema('filestorage_blob', true));
        Assert::null($this->db->getTableSchema('filestorage_blob_reservation', true));
    }

    /**
     * Names come from the value objects, and index names follow them: two
     * installations sharing one PostgreSQL schema would otherwise collide on
     * a hard-coded index name.
     */
    public function customNamesReachTheSchemaAndItsIndexes(): void
    {
        (new M260807000000CreateFilestorageFileTable(new FileTableName('app_files')))->up($this->builder);
        (new M260807000001CreateFilestorageBlobTables(
            new BlobTableName('app_blobs'),
            new BlobReservationTableName('app_claims'),
        ))->up($this->builder);

        Assert::notNull($this->db->getTableSchema('app_files', true));
        Assert::notNull($this->db->getTableSchema('app_blobs', true));
        Assert::notNull($this->db->getTableSchema('app_claims', true));
        Assert::null($this->db->getTableSchema('filestorage_file', true));
        Assert::true(\in_array('idx_app_files_blob_id', $this->indexNames('app_files'), true));
        Assert::true(\in_array('idx_app_claims_blob_id', $this->indexNames('app_claims'), true));
    }

    /**
     * @return list<string>
     */
    private function indexNames(string $table): array
    {
        $names = [];
        foreach ($this->db->getSchema()->getTableIndexes($table, true) as $index) {
            $names[] = $index->name;
        }

        return $names;
    }
}
