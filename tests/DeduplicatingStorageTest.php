<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Tests;

use DateInterval;
use DateTimeImmutable;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Filestorage\Exception\InvalidConfigException;
use Rasuvaeff\Yii3Filestorage\Id\Uuid7IdGenerator;
use Rasuvaeff\Yii3Filestorage\Mime\FinfoMimeTypeDetector;
use Rasuvaeff\Yii3Filestorage\Path\RandomPathGenerator;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Policy\PolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Storage;
use Rasuvaeff\Yii3Filestorage\StorageInterface;
use Rasuvaeff\Yii3Filestorage\Store\BlobId;
use Rasuvaeff\Yii3Filestorage\Store\BlobState;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Test\InMemoryStore;
use Rasuvaeff\Yii3Filestorage\Upload;
use Rasuvaeff\Yii3FilestorageDb\DbBlobLedger;
use Rasuvaeff\Yii3FilestorageDb\DbRepository;
use Rasuvaeff\Yii3FilestorageDb\DeduplicatingStorage;
use Rasuvaeff\Yii3FilestorageDb\DeduplicatingStorageFactory;
use Rasuvaeff\Yii3FilestorageDb\DedupScope;
use Rasuvaeff\Yii3FilestorageDb\Tests\Support\ContentAddressableInMemoryStore;
use Rasuvaeff\Yii3FilestorageDb\Tests\Support\FixedScope;
use Rasuvaeff\Yii3FilestorageDb\Tests\Support\SqliteDatabase;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Test\Support\Clock\StaticClock;

#[Test]
#[Covers(DeduplicatingStorage::class)]
#[Covers(DeduplicatingStorageFactory::class)]
#[Covers(DedupScope::class)]
final class DeduplicatingStorageTest
{
    private SqliteDatabase $database;
    private DbRepository $repository;
    private DbBlobLedger $ledger;
    private ContentAddressableInMemoryStore $store;
    private Psr17Factory $factory;
    private DeduplicatingStorage $storage;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->database = new SqliteDatabase();
        $this->factory = new Psr17Factory();
        $clock = new StaticClock($this->at('00:00'));

        $this->repository = new DbRepository($this->database->db);
        $this->ledger = new DbBlobLedger($this->database->db, $this->repository, $clock);
        $this->store = new ContentAddressableInMemoryStore(
            new InMemoryStore('upload', $this->factory, $clock),
        );
        $this->storage = $this->factory()->create();
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->database->close();
    }

    public function aFirstAddWritesTheObjectAndTheRow(): void
    {
        $file = $this->storage->add($this->upload('hello'));

        Assert::same($file->size, 5);
        Assert::same($file->contentHash, hash('sha256', 'hello'));
        Assert::same($this->repository->find($file->id)?->id, $file->id);
        Assert::same($this->store->writeCount(), 1);
        Assert::same($this->ledger->find($this->blobOf($file->relativePath))?->referenceCount, 1);
    }

    /**
     * The whole point: two uploads of the same bytes, two rows, one object —
     * and the second `add()` never writes.
     */
    public function identicalContentIsStoredOnce(): void
    {
        $first = $this->storage->add($this->upload('hello'));
        $second = $this->storage->add($this->upload('hello'));

        Assert::true($first->id !== $second->id);
        Assert::same($first->relativePath, $second->relativePath);
        Assert::same($this->store->writeCount(), 1, 'the second add must not write');
        Assert::same($this->ledger->find($this->blobOf($first->relativePath))?->referenceCount, 2);
    }

    public function differentContentGetsDifferentObjects(): void
    {
        $first = $this->storage->add($this->upload('hello'));
        $second = $this->storage->add($this->upload('goodbye'));

        Assert::true($first->relativePath !== $second->relativePath);
        Assert::same($this->store->writeCount(), 2);
    }

    /**
     * Each row keeps its own identity even though the bytes are shared — that
     * is what makes sharing invisible to the consumer.
     */
    public function sharedBytesDoNotShareMetadata(): void
    {
        $first = $this->storage->add($this->upload('hello'), description: 'the first', metadata: ['n' => 1]);
        $second = $this->storage->add($this->upload('hello'), description: 'the second', metadata: ['n' => 2]);

        Assert::same($this->repository->find($first->id)?->description, 'the first');
        Assert::same($this->repository->find($second->id)?->description, 'the second');
        Assert::same($this->repository->find($second->id)?->metadata, ['n' => 2]);
    }

    /**
     * Removing one row must not take the bytes the other row is using — and
     * must not delete them at all, only schedule.
     */
    public function removingOneOfTwoLeavesTheObjectAlone(): void
    {
        $first = $this->storage->add($this->upload('hello'));
        $second = $this->storage->add($this->upload('hello'));

        Assert::true($this->storage->remove($first->id));

        Assert::null($this->repository->find($first->id));
        Assert::same($this->repository->find($second->id)?->id, $second->id);
        Assert::same($this->store->bytesAt($second->relativePath), 'hello');
        Assert::same($this->ledger->find($this->blobOf($second->relativePath))?->state, BlobState::Active);
    }

    public function removingTheLastRowSchedulesRatherThanDeletes(): void
    {
        $file = $this->storage->add($this->upload('hello'));

        Assert::true($this->storage->remove($file->id));

        $record = $this->ledger->find($this->blobOf($file->relativePath));
        Assert::same($record?->state, BlobState::PendingDelete);
        // still there: only a collector deletes shared bytes
        Assert::same($this->store->bytesAt($file->relativePath), 'hello');
    }

    public function removingSomethingThatIsNotThereSaysSo(): void
    {
        Assert::false($this->storage->remove('nope'));
    }

    /**
     * Above the cap the upload takes the unique path. The caller cannot tell,
     * which is the point — but the object is not content-addressed and the
     * ledger knows nothing about it.
     */
    public function anUploadTooLargeToHashIsStoredUniquely(): void
    {
        $storage = $this->factory()->create(dedupMaxBytes: 4);

        $first = $storage->add($this->upload('hello'));
        $second = $storage->add($this->upload('hello'));

        Assert::true($first->relativePath !== $second->relativePath, 'unique paths, not one shared object');
        Assert::same($this->store->writeCount(), 2);
        Assert::null($this->ledger->find($this->blobOf($first->relativePath)));
        Assert::null($first->contentHash, 'the unique path does not hash unless asked to');
    }

    /**
     * The scope decides how widely bytes are shared, and the default keeps
     * tenants apart — sharing across them tells an uploader whether content
     * they suspect exists already does.
     */
    public function theDefaultScopeKeepsTenantsApart(): void
    {
        $scopes = new FixedScope('tenant-a');
        $storage = $this->factory(scopes: $scopes)->create();

        $first = $storage->add($this->upload('hello'));
        $scopes->switchTo('tenant-b');
        $second = $storage->add($this->upload('hello'));

        Assert::true($first->relativePath !== $second->relativePath);
        Assert::same($this->store->writeCount(), 2);
    }

    public function aGlobalScopeSharesAcrossTenants(): void
    {
        $scopes = new FixedScope('tenant-a');
        $storage = $this->factory(scopes: $scopes)->create(scope: DedupScope::Global);

        $first = $storage->add($this->upload('hello'));
        $scopes->switchTo('tenant-b');
        $second = $storage->add($this->upload('hello'));

        Assert::same($first->relativePath, $second->relativePath);
        Assert::same($this->store->writeCount(), 1);
    }

    public function aTenantScopeSharesAcrossThatTenantsGroups(): void
    {
        $storage = $this->factory(scopes: new FixedScope('tenant-a'))->create(scope: DedupScope::Tenant);

        $first = $storage->add($this->upload('hello'), groupName: 'avatars');
        $second = $storage->add($this->upload('hello'), groupName: 'documents');

        Assert::same($first->relativePath, $second->relativePath);
    }

    public function theDefaultScopeSeparatesGroups(): void
    {
        $first = $this->storage->add($this->upload('hello'), groupName: 'avatars');
        $second = $this->storage->add($this->upload('hello'), groupName: 'documents');

        Assert::true($first->relativePath !== $second->relativePath);
    }

    /**
     * Everything that is not sharing is the base facade's, and delegation has
     * to be complete: a consumer swapping the binding must not lose reads.
     */
    public function readsAreTheBaseFacadesAnswers(): void
    {
        $file = $this->storage->add($this->upload('hello'));

        Assert::same($this->storage->find($file->id)?->id, $file->id);
        Assert::true($this->storage->exists($file));
        Assert::same($this->storage->content($file), 'hello');
        Assert::same((string) $this->storage->stream($file)?->getContents(), 'hello');
        Assert::null($this->storage->url($file));
        Assert::null($this->storage->temporaryUrl($file, $this->at('01:00')));
        Assert::null($this->storage->urlFor($file));
    }

    /**
     * A store this class cannot share into is not an error — the base facade
     * handles it, and the caller gets a unique object.
     */
    public function anotherStoreGoesThroughTheBaseFacade(): void
    {
        $other = new InMemoryStore('archive', $this->factory, new StaticClock($this->at('00:00')));
        $storage = $this->factory(stores: new StoreRegistry([$this->store, $other]))->create();

        $file = $storage->add($this->upload('hello'), storeName: 'archive');

        Assert::same($file->storeName, 'archive');
        Assert::null($this->ledger->find($this->blobOf($file->relativePath)));
    }

    public function anInvalidGroupNameIsRefused(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Invalid group name');

        $this->storage->add($this->upload('hello'), groupName: '../escape');
    }

    /**
     * The factory's job is to refuse a configuration that would corrupt data
     * quietly, and to say what to do instead.
     */
    public function aStoreThatCannotDeduplicateIsRefusedWithInstructions(): void
    {
        $plain = new InMemoryStore('upload', $this->factory, new StaticClock($this->at('00:00')));

        Expect::exception(InvalidConfigException::class)
            ->withMessageContaining('cannot deduplicate')
            ->withMessageContaining('keep the base StorageInterface binding');

        $this->factory(stores: new StoreRegistry([$plain]))->create();
    }

    /**
     * Two connections to one database look identical until a crash lands
     * between the two transactions `commit()` was supposed to be.
     */
    public function aSplitConnectionIsRefused(): void
    {
        $shared = new SqliteDatabase(shared: true);
        $ledger = new DbBlobLedger(
            db: $shared->connect(),
            repository: new DbRepository($shared->db),
            clock: new StaticClock($this->at('00:00')),
        );

        Expect::exception(InvalidConfigException::class)->withMessageContaining('different database connections');

        try {
            $this->factory(ledger: $ledger)->create();
        } finally {
            $shared->close();
        }
    }

    private function factory(
        ?StoreRegistry $stores = null,
        ?FixedScope $scopes = null,
        ?DbBlobLedger $ledger = null,
    ): DeduplicatingStorageFactory {
        $stores ??= new StoreRegistry([$this->store]);
        $clock = new StaticClock($this->at('00:00'));
        $repository = $scopes instanceof \Rasuvaeff\Yii3FilestorageDb\Tests\Support\FixedScope ? new DbRepository($this->database->db, scopes: $scopes) : $this->repository;

        return new DeduplicatingStorageFactory(
            unique: $this->baseStorage($stores, $repository),
            stores: $stores,
            repository: $repository,
            ledger: $ledger ?? new DbBlobLedger($this->database->db, $repository, $clock),
            mimeTypeDetector: new FinfoMimeTypeDetector(),
            idGenerator: new Uuid7IdGenerator($clock),
            policies: new PolicyRegistry(),
            clock: $clock,
            scopes: $scopes,
        );
    }

    private function baseStorage(StoreRegistry $stores, DbRepository $repository): StorageInterface
    {
        $clock = new StaticClock($this->at('00:00'));

        return new Storage(
            stores: $stores,
            repository: $repository,
            pathGenerator: new RandomPathGenerator(),
            mimeTypeDetector: new FinfoMimeTypeDetector(),
            idGenerator: new Uuid7IdGenerator($clock),
            policies: new PolicyRegistry(),
            deliveryPolicies: new DeliveryPolicyRegistry(),
            clock: $clock,
            defaultUrlTtl: new DateInterval('PT1H'),
        );
    }

    private function upload(string $contents): Upload
    {
        return Upload::fromStream($this->factory->createStream($contents), 'a.txt', $this->factory);
    }

    private function blobOf(string $relativePath): BlobId
    {
        return BlobId::create('upload', $relativePath);
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable("2026-01-01T{$time}:00.000000+00:00");
    }
}
