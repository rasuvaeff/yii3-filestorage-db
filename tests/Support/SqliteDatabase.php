<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Tests\Support;

use DateTimeImmutable;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3FilestorageDb\Migration\M260807000000CreateFilestorageFileTable;
use Rasuvaeff\Yii3FilestorageDb\Migration\M260807000001CreateFilestorageBlobTables;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Migration\Informer\NullMigrationInformer;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

/**
 * An in-memory SQLite database with this package's own schema applied.
 *
 * The unit tests run against a real database rather than a hand-written fake
 * connection, because what they are testing *is* the SQL: a conditional update
 * that a fake would have to reimplement to answer. A fake that reimplements
 * the guards proves the fake works.
 *
 * @internal
 */
final readonly class SqliteDatabase
{
    public ConnectionInterface $db;

    public function __construct()
    {
        $this->db = new SqliteConnection(
            driver: new SqliteDriver(dsn: 'sqlite::memory:'),
            schemaCache: new SchemaCache(psrCache: new MemorySimpleCache()),
        );
        $this->db->open();

        // the migrations are the schema — a second CREATE TABLE in the tests
        // would be a copy that silently drifts from the one users get
        $builder = new MigrationBuilder(db: $this->db, informer: new NullMigrationInformer());
        (new M260807000000CreateFilestorageFileTable())->up($builder);
        (new M260807000001CreateFilestorageBlobTables())->up($builder);
    }

    public function close(): void
    {
        $this->db->close();
    }

    public static function file(
        string $id,
        string $relativePath = 'sha/e3/b0/original',
        string $storeName = 'upload',
        string $originalName = 'thing.txt',
        ?string $contentHash = self::HASH,
        array $metadata = [],
        ?DateTimeImmutable $createdAt = null,
    ): File {
        $createdAt ??= new DateTimeImmutable('2026-01-01T00:00:00.000000+00:00');

        return File::create(
            id: $id,
            storeName: $storeName,
            groupName: 'common',
            relativePath: $relativePath,
            originalName: $originalName,
            size: 12,
            createdAt: $createdAt,
            mimeType: 'text/plain',
            contentHash: $contentHash,
            metadata: $metadata,
        );
    }

    public const string HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
    public const string OTHER_HASH = 'da39a3ee5e6b4b0d3255bfef95601890afd80709da39a3ee5e6b4b0d32550000';
}
