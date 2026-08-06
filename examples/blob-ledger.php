<?php

declare(strict_types=1);

use Rasuvaeff\Yii3Filestorage\Exception\BlobBusyException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Store\BlobId;
use Rasuvaeff\Yii3FilestorageDb\DbBlobLedger;
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

// Two people upload the same file. One object, two rows, and nothing deleted
// until both rows are gone — that is the whole ledger, end to end.
$db = new SqliteConnection(
    driver: new SqliteDriver(dsn: 'sqlite::memory:'),
    schemaCache: new SchemaCache(psrCache: new MemorySimpleCache()),
);
$db->open();

$builder = new MigrationBuilder(db: $db, informer: new NullMigrationInformer());
(new M260807000000CreateFilestorageFileTable())->up($builder);
(new M260807000001CreateFilestorageBlobTables())->up($builder);

$at = static fn(string $time): DateTimeImmutable
    => new DateTimeImmutable("2026-01-01T{$time}:00.000000+00:00");

$repository = new DbRepository($db);
$ledger = new DbBlobLedger(db: $db, repository: $repository, clock: new StaticClock($at('00:00')));

$hash = hash('sha256', 'the same bytes');
$blob = BlobId::create('upload', "sha/{$hash[0]}{$hash[1]}/{$hash[2]}{$hash[3]}/{$hash}/original");

$row = static fn(string $id): File => File::create(
    id: $id,
    storeName: 'upload',
    groupName: 'documents',
    relativePath: $blob->relativePath(),
    originalName: "{$id}.txt",
    size: 14,
    createdAt: $at('00:00'),
    contentHash: $hash,
);

// 1. Both writers reserve. Neither blocks the other — identical content is the
//    normal case, and each claim is separately expirable.
$alice = $ledger->reserve($blob, $hash, 14, $at('00:10'));
$bob = $ledger->reserve($blob, $hash, 14, $at('00:10'));
echo "reserved: {$ledger->find($blob)?->reservationCount} claims, "
    . "state {$ledger->find($blob)?->state->value}\n";

// 2. Bytes are published once, then each writer commits its own row. The file
//    row and the reference land in one transaction.
$ledger->commit($alice, $row('alice'));
$ledger->commit($bob, $row('bob'));
echo "committed: {$ledger->find($blob)?->referenceCount} references, "
    . "state {$ledger->find($blob)?->state->value}\n";

// 3. Alice deletes hers. Bob's file is untouched — and so are the bytes.
$ledger->releaseFile('alice', $at('01:00'));
echo "after one delete: {$ledger->find($blob)?->referenceCount} references, "
    . "state {$ledger->find($blob)?->state->value}\n";
echo "bob can still read his: " . ($repository->find('bob') === null ? 'no' : 'yes') . "\n";

// 4. Bob deletes his. Still no store I/O: the blob is only *scheduled*.
$ledger->releaseFile('bob', $at('01:00'));
echo "after both deletes: state {$ledger->find($blob)?->state->value}, "
    . "collectable after {$ledger->find($blob)?->deleteAfter?->format('H:i')}\n";

// 5. Too early: the grace period is what lets a writer that reserved a moment
//    ago finish instead of racing the collector.
var_dump($ledger->claimForDeletion($at('00:59'), $at('01:05')) === null);

// 6. The collector takes an exclusive, expiring lease.
$lease = $ledger->claimForDeletion($at('01:00'), $at('01:05'));
echo "leased: state {$ledger->find($blob)?->state->value}\n";
echo "a second collector finds nothing: "
    . ($ledger->claimForDeletion($at('01:01'), $at('01:06')) === null ? 'yes' : 'no') . "\n";

// 7. A writer arriving now is told to come back — joining a blob being deleted
//    is how a committed row ends up pointing at nothing.
try {
    $ledger->reserve($blob, $hash, 14, $at('01:10'));
} catch (BlobBusyException $e) {
    echo "reserve refused: {$e->getMessage()}\n";
}

// 8. Delete the object, then the row. Only now would the store be touched.
echo "deleted: " . ($ledger->completeDeletion($lease) ? 'yes' : 'no') . "\n";
echo "ledger row gone: " . ($ledger->find($blob) === null ? 'yes' : 'no') . "\n";

$db->close();
