<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Filestorage\Id\Uuid7IdGenerator;
use Rasuvaeff\Yii3Filestorage\Mime\FinfoMimeTypeDetector;
use Rasuvaeff\Yii3Filestorage\Path\RandomPathGenerator;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Policy\PolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Storage;
use Rasuvaeff\Yii3Filestorage\Store\FileSystem\FileSystemStore;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Upload;
use Rasuvaeff\Yii3FilestorageDb\DbRepository;
use Rasuvaeff\Yii3FilestorageDb\Migration\M260807000000CreateFilestorageFileTable;
use Rasuvaeff\Yii3FilestorageDb\Migration\M260807000001CreateFilestorageBlobTables;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Migration\Informer\NullMigrationInformer;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\Clock\StaticClock;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

require __DIR__ . '/../vendor/autoload.php';

// Objects on disk, metadata in the database. Wired by hand so the whole flow
// fits in one file; in an application `config/di.php` does all of this.
$root = sys_get_temp_dir() . '/filestorage-db-example-' . bin2hex(random_bytes(6));
$factory = new Psr17Factory();
$clock = new StaticClock(new DateTimeImmutable('2026-01-01T00:00:00.000000+00:00'));

$db = new SqliteConnection(
    driver: new SqliteDriver(dsn: 'sqlite::memory:'),
    schemaCache: new SchemaCache(psrCache: new MemorySimpleCache()),
);
$db->open();

$builder = new MigrationBuilder(db: $db, informer: new NullMigrationInformer());
(new M260807000000CreateFilestorageFileTable())->up($builder);
(new M260807000001CreateFilestorageBlobTables())->up($builder);

$repository = new DbRepository($db);

$storage = new Storage(
    stores: new StoreRegistry([new FileSystemStore(name: 'upload', rootPath: $root, streamFactory: $factory)]),
    repository: $repository,
    pathGenerator: new RandomPathGenerator(),
    mimeTypeDetector: new FinfoMimeTypeDetector(),
    idGenerator: new Uuid7IdGenerator($clock),
    policies: new PolicyRegistry(),
    deliveryPolicies: new DeliveryPolicyRegistry(),
    clock: $clock,
    defaultUrlTtl: new DateInterval('PT1H'),
);

$file = $storage->add(
    upload: Upload::fromStream($factory->createStream("id,name\n1,Ada\n"), 'people.csv', $factory),
    groupName: 'documents',
    description: 'Exported people',
    metadata: ['rows' => 1],
);

echo "stored {$file->id}\n";
echo "  path      {$file->relativePath}\n";
echo "  type      {$file->mimeType}\n";
echo "  size      {$file->size} bytes\n";

// The row survives the object that wrote it: a fresh repository reads it back.
$reloaded = (new DbRepository($db))->find($file->id);
echo "reloaded from the database: {$reloaded?->originalName}\n";
echo "  metadata  " . json_encode($reloaded?->metadata) . "\n";
echo "  created   " . $reloaded?->createdAt->format(DateTimeInterface::RFC3339_EXTENDED) . "\n";

// Maintenance walks page by id, so a long job can resume where it stopped.
foreach ($repository->files(limit: 10) as $row) {
    echo "walked {$row->id} ({$row->groupName})\n";
}

$storage->remove($file->id);
echo "removed, still there? " . ($repository->find($file->id) === null ? 'no' : 'yes') . "\n";

$db->close();
