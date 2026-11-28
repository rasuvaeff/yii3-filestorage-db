<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Tests;

use DateInterval;
use DateTimeImmutable;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Filestorage\Exception\AddException;
use Rasuvaeff\Yii3Filestorage\Exception\InvalidConfigException;
use Rasuvaeff\Yii3Filestorage\Exception\PolicyViolationException;
use Rasuvaeff\Yii3Filestorage\Exception\RemoveException;
use Rasuvaeff\Yii3Filestorage\Exception\StoreException;
use Rasuvaeff\Yii3Filestorage\Exception\UploadSizeException;
use Rasuvaeff\Yii3Filestorage\Id\Uuid7IdGenerator;
use Rasuvaeff\Yii3Filestorage\Mime\ExtensionMap;
use Rasuvaeff\Yii3Filestorage\Mime\FinfoMimeTypeDetector;
use Rasuvaeff\Yii3Filestorage\Mime\MimeTypeDetectorInterface;
use Rasuvaeff\Yii3Filestorage\Path\ContentAddressedKeyGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Path\FlatSha256KeyGenerator;
use Rasuvaeff\Yii3Filestorage\Path\RandomPathGenerator;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Policy\PolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Storage;
use Rasuvaeff\Yii3Filestorage\StorageInterface;
use Rasuvaeff\Yii3Filestorage\Store\BlobId;
use Rasuvaeff\Yii3Filestorage\Store\BlobLedgerInterface;
use Rasuvaeff\Yii3Filestorage\Store\BlobState;
use Rasuvaeff\Yii3Filestorage\Store\ContentAddressableStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\FileSystem\FileSystemStore;
use Rasuvaeff\Yii3Filestorage\Store\FileSystem\PublicFileSystemStore;
use Rasuvaeff\Yii3Filestorage\Store\Layout\FlatSiblingLayout;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Test\InMemoryStore;
use Rasuvaeff\Yii3Filestorage\Test\MemoryBlobLedger;
use Rasuvaeff\Yii3Filestorage\Upload;
use Rasuvaeff\Yii3FilestorageDb\ContentKeyCheck;
use Rasuvaeff\Yii3FilestorageDb\DbBlobLedger;
use Rasuvaeff\Yii3FilestorageDb\DbRepository;
use Rasuvaeff\Yii3FilestorageDb\DeduplicatingStorage;
use Rasuvaeff\Yii3FilestorageDb\DeduplicatingStorageFactory;
use Rasuvaeff\Yii3FilestorageDb\DedupScope;
use Rasuvaeff\Yii3FilestorageDb\Exception\MissingScopeException;
use Rasuvaeff\Yii3FilestorageDb\Tests\Support\ContentAddressableInMemoryStore;
use Rasuvaeff\Yii3FilestorageDb\Tests\Support\FaultyLedger;
use Rasuvaeff\Yii3FilestorageDb\Tests\Support\FixedIdGenerator;
use Rasuvaeff\Yii3FilestorageDb\Tests\Support\FixedKeyGenerator;
use Rasuvaeff\Yii3FilestorageDb\Tests\Support\FixedScope;
use Rasuvaeff\Yii3FilestorageDb\Tests\Support\ForwardingStorage;
use Rasuvaeff\Yii3FilestorageDb\Tests\Support\NoMimeTypeDetector;
use Rasuvaeff\Yii3FilestorageDb\Tests\Support\PinnedProxyUrls;
use Rasuvaeff\Yii3FilestorageDb\Tests\Support\SlashRefusingKeyGenerator;
use Rasuvaeff\Yii3FilestorageDb\Tests\Support\SpyStream;
use Rasuvaeff\Yii3FilestorageDb\Tests\Support\SqliteDatabase;
use Rasuvaeff\Yii3FilestorageDb\Tests\Support\UnsizedStream;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Test\Support\Clock\StaticClock;

#[Test]
#[Covers(DeduplicatingStorage::class)]
#[Covers(DeduplicatingStorageFactory::class)]
#[Covers(DedupScope::class)]
#[Covers(ContentKeyCheck::class)]
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
     * The probe from the 1.0 review (`unique.php`): a file above the cap took
     * the unique path, and removing it dropped the row and left the bytes —
     * where the base facade deletes them. The consumer must not see a
     * difference.
     */
    public function removingAFileOnTheUniquePathDeletesItsBytes(): void
    {
        $storage = $this->factory()->create(dedupMaxBytes: 4);
        $file = $storage->add($this->upload('hello'));

        Assert::true($storage->remove($file->id));

        Assert::null($this->store->bytesAt($file->relativePath));
        Assert::null($this->repository->find($file->id));
    }

    public function removingAFileInAnotherStoreDeletesItsBytesThere(): void
    {
        $other = new InMemoryStore('archive', $this->factory, new StaticClock($this->at('00:00')));
        $storage = $this->factory(stores: new StoreRegistry([$this->store, $other]))->create();
        $file = $storage->add($this->upload('hello'), storeName: 'archive');

        Assert::true($storage->remove($file->id));

        Assert::false($other->exists($file));
    }

    /**
     * A shared row's path is a ledger blob, and those bytes are only ever
     * deleted by a collector. Checked against the path the row was read
     * with, so a row repointed in between can only make an orphan go, never
     * a blob.
     */
    /**
     * The row is gone by the time the store is looked up, so a store the
     * registry no longer knows is a failed remove, reported the way the base
     * facade reports it — not the registry's own exception.
     */
    public function aRowInAStoreTheRegistryDoesNotKnowIsARemoveException(): void
    {
        $this->repository->save(SqliteDatabase::file('gone', relativePath: 'g/unique', storeName: 'archive'));

        try {
            $this->storage->remove('gone');
            Assert::fail('Expected RemoveException');
        } catch (RemoveException $e) {
            Assert::string($e->getMessage())->contains('orphan for filestorage:gc');
        }

        Assert::null($this->repository->find('gone'));
    }

    public function removingASharedRowNeverDeletesItsBytes(): void
    {
        $file = $this->storage->add($this->upload('hello'));

        Assert::true($this->storage->remove($file->id));

        Assert::same($this->store->bytesAt($file->relativePath), 'hello');
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

    /**
     * The content key mixes the tenant in. A bound provider returning null
     * used to key the upload into the `shared` pool — every unresolved tenant's
     * uploads joining one another's blobs — before the repository was even
     * asked. Refused before anything is read or reserved.
     */
    public function aBoundProviderReturningNullIsRefusedBeforeAnythingIsWritten(): void
    {
        $storage = $this->factory(scopes: new FixedScope())->create();

        $refused = false;

        try {
            $storage->add($this->upload('hello'));
        } catch (MissingScopeException) {
            $refused = true;
        }

        Assert::true($refused);
        Assert::same($this->store->writeCount(), 0);
        Assert::null($this->ledger->find($this->blobOf((new \Rasuvaeff\Yii3Filestorage\Path\Sha256KeyGenerator())->generate(
            DedupScope::TenantGroup->keyFor('common', null),
            hash('sha256', 'hello'),
        ))));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function zeroIntervalProvider(): iterable
    {
        yield 'zero grace' => ['deleteGracePeriod', 'PT0S'];
        yield 'zero reservation ttl' => ['reservationTtl', 'PT0S'];
    }

    /**
     * A zero grace period makes "scheduled" and "collectable" one instant,
     * and a zero reservation TTL lets the sweep drop a claim mid-upload.
     */
    #[DataProvider('zeroIntervalProvider')]
    public function anIntervalThatIsNotPositiveIsRefused(string $name, string $interval): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining($name);

        $this->factory()->create(...[$name => new DateInterval($interval)]);
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
            ->withMessageContaining(
                'Store "upload" cannot deduplicate: it does not implement '
                . ContentAddressableStoreInterface::class,
            )
            ->withMessageContaining(
                'Sharing bytes between files needs atomic put-if-absent, which not every adapter can promise '
                . '— with Flysystem, bind FlysystemContentAddressableStore and declare AdapterSemantics. '
                . 'Without it, keep the base StorageInterface binding: unique storage is fully functional, '
                . 'just not deduplicating',
            );

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

        Expect::exception(InvalidConfigException::class)->withMessageContaining(
            'The blob ledger and the file repository are on different database connections, so committing a '
            . 'file row and its blob reference would be two transactions rather than one — a crash between '
            . 'them leaves a row with no reference, or a reference with no row. Bind one '
            . 'Yiisoft\\Db\\Connection\\ConnectionInterface and give it to both',
        );

        try {
            $this->factory(ledger: $ledger)->create();
        } finally {
            $shared->close();
        }
    }


    /**
     * A ledger that is not the transactional one cannot promise the joint
     * commit, and the message has to name what is bound instead — otherwise the
     * operator is looking for a class nobody mentioned.
     */
    public function aNonTransactionalLedgerIsRefusedByName(): void
    {
        Expect::exception(InvalidConfigException::class)
            ->withMessageContaining('Deduplication needs the transactional ' . DbBlobLedger::class)
            ->withMessageContaining(
                MemoryBlobLedger::class . ', which cannot promise that a file row and its blob reference commit '
                . 'together',
            );

        $this->factory(ledger: new MemoryBlobLedger($this->repository))->create();
    }

    /**
     * The key generator is swappable. Without the fallback the default
     * installation has none; without honouring an injected one, configuring a
     * different layout does nothing.
     */
    public function anInjectedKeyGeneratorDecidesTheLayout(): void
    {
        $storage = $this->factory(keys: new FixedKeyGenerator('shared/one'))->create();

        $file = $storage->add($this->upload('hello'));

        Assert::same($file->relativePath, 'shared/one/' . hash('sha256', 'hello') . '/original.bin');
    }

    /**
     * A flat key carries the extension of the *detected* type — `a.txt` is
     * named by the client, `text/plain` is what the bytes are.
     */
    public function theContentKeyCarriesTheDetectedExtension(): void
    {
        $storage = $this->factory(stores: $this->flatStores(), keys: new FlatSha256KeyGenerator())->create();

        $file = $storage->add($this->upload('hello'));

        Assert::same($file->relativePath, (new FlatSha256KeyGenerator())->generate(
            DedupScope::TenantGroup->keyFor('common', null),
            hash('sha256', 'hello'),
            'txt',
        ));
    }

    /**
     * The application's `extensionOverrides` decide the extension here too,
     * exactly as they do for the base facade's unique paths.
     */
    public function theExtensionMapOverridesReachTheContentKey(): void
    {
        $storage = $this->factory(
            stores: $this->flatStores(),
            keys: new FlatSha256KeyGenerator(),
            extensions: new ExtensionMap(['text/plain' => 'text']),
        )->create();

        $file = $storage->add($this->upload('hello'));

        Assert::true(str_ends_with($file->relativePath, '.text'), $file->relativePath);
    }

    /**
     * A body whose length is only known once it has been read: the cap has to
     * be enforced during the read, because there is nothing to check before it.
     */
    public function anUndeclaredBodyOverTheCapTakesTheUniquePath(): void
    {
        $storage = $this->factory()->create(dedupMaxBytes: 4);

        $file = $storage->add(Upload::fromStream(new UnsizedStream($this->factory->createStream('hello')), 'a.txt', $this->factory));

        Assert::null($file->contentHash, 'a unique add records no content hash');
        Assert::same($this->repository->find($file->id)?->id, $file->id);
    }

    /**
     * Exactly at the cap is within it. Off by one here means the documented
     * boundary is not the enforced one.
     */
    public function aBodyExactlyAtTheCapIsStillShared(): void
    {
        $storage = $this->factory()->create(dedupMaxBytes: 5);

        Assert::same($storage->add($this->upload('hello'))->contentHash, hash('sha256', 'hello'));
    }

    /**
     * A declared size over the cap is refused before a single byte is read —
     * the whole point of looking at the declaration first.
     */
    public function aDeclaredSizeOverTheCapIsRefusedWithoutReading(): void
    {
        $storage = $this->factory()->create(dedupMaxBytes: 4);

        Assert::null($storage->add($this->upload('hello'))->contentHash);
    }


    /**
     * The tenant id is hashed, not used as a path segment: it comes from the
     * application and may be an email or anything else a path cannot hold. The
     * width is part of the layout — changing it repartitions every pool.
     */
    #[DataProvider('scopeKeyProvider')]
    public function theScopeKeyIsBuiltFromAHashedTenant(DedupScope $scope, ?string $tenant, string $expected): void
    {
        Assert::same($scope->keyFor('avatars', $tenant), $expected);
    }

    public static function scopeKeyProvider(): iterable
    {
        $hashed = substr(hash('xxh128', 'tenant-a'), 0, 16);

        yield 'tenant and group' => [DedupScope::TenantGroup, 'tenant-a', "sha/{$hashed}/avatars"];
        yield 'tenant only' => [DedupScope::Tenant, 'tenant-a', "sha/{$hashed}"];
        yield 'global' => [DedupScope::Global, 'tenant-a', 'sha'];
        yield 'no tenant' => [DedupScope::TenantGroup, null, 'sha/shared/avatars'];
    }

    /**
     * Sixteen hex characters, not the whole digest and not a truncation that
     * would collide across tenants at any realistic count.
     */
    public function theHashedTenantIsSixteenCharactersWide(): void
    {
        $segment = explode('/', DedupScope::Tenant->keyFor('avatars', 'tenant-a'))[1];

        Assert::same(\strlen($segment), 16);
        Assert::same(preg_match('/^[0-9a-f]{16}\z/', $segment), 1);
    }


    /**
     * A body that never declares its length still records the size it actually
     * had. Reserving zero here and committing the real size makes the ledger
     * reject every later upload of the same content on the size check.
     */
    public function anUndeclaredBodyStillReservesItsRealSize(): void
    {
        $storage = $this->factory()->create();
        $unsized = Upload::fromStream(new UnsizedStream($this->factory->createStream('hello')), 'a.txt', $this->factory);

        $file = $storage->add($unsized);

        Assert::same($this->ledger->find($this->blobOf($file->relativePath))?->size, 5);
        Assert::same($storage->add($this->upload('hello'))->contentHash, hash('sha256', 'hello'));
    }

    /**
     * The cap counts every chunk, not the last one. A counter that forgot the
     * earlier reads would hash a body of any size and share it — the exact
     * unbounded second pass the cap exists to prevent.
     */
    public function theCapCountsAcrossReads(): void
    {
        $storage = $this->factory()->create(dedupMaxBytes: 300_000);
        $contents = str_repeat('x', 700_000);

        $file = $storage->add(Upload::fromStream(
            new UnsizedStream($this->factory->createStream($contents)),
            'a.txt',
            $this->factory,
        ));

        Assert::null($file->contentHash, 'a body over the cap takes the unique path');
    }

    /**
     * The upload policy runs on the shared path too. Skipping it there would
     * make deduplication a way past every size and media-type limit.
     */
    public function theUploadPolicyStillApplies(): void
    {
        $storage = $this->factory(policies: PolicyRegistry::fromArray(['*' => ['maxBytes' => 2]]))->create();

        Expect::exception(PolicyViolationException::class);

        $storage->add($this->upload('hello'));
    }

    /**
     * Summary A1: a flat `<prefix>/ab/cd/<sha>.ext` key under the default
     * {@see \Rasuvaeff\Yii3Filestorage\Store\Layout\DirectoryLayout} makes
     * deleting one blob delete its whole fan-out bucket. The pair is refused
     * when the storage is assembled, before a single byte is written.
     */
    public function aFlatKeyGeneratorOnTheDirectoryLayoutIsRefusedBeforeAnythingIsWritten(): void
    {
        $refused = null;

        try {
            $this->factory(keys: new FlatSha256KeyGenerator())->create()->add($this->upload('hello'));
        } catch (InvalidConfigException $e) {
            $refused = $e->getMessage();
        }

        Assert::string((string) $refused)->contains('Store "upload"');
        Assert::string((string) $refused)->contains(FlatSha256KeyGenerator::class);
        Assert::string((string) $refused)->contains('cannot share a store');
        Assert::same($this->store->writeCount(), 0);
    }

    /**
     * A generator that drops the tenant from the key (`FlatSha256KeyGenerator`
     * with a prefix) turns a tenant-separated scope into cross-tenant sharing —
     * the content-existence oracle {@see DedupScope} exists to prevent. With a
     * provider bound that is refused; `Global` states the sharing on purpose.
     */
    public function aGeneratorThatIgnoresTheTenantIsRefusedUnderATenantScope(): void
    {
        Expect::exception(InvalidConfigException::class)->withMessageContaining(
            'ignores the tenant: identical bytes under the scopes "sha/',
        );

        $this->factory(
            stores: $this->flatStores(),
            scopes: new FixedScope('tenant-a'),
            keys: new FlatSha256KeyGenerator(prefix: 'blobs'),
        )->create();
    }

    /**
     * Core 0.10 has `Storage::withProxyUrls()` for links minted outside the
     * tenant's request (review 1.0 pass 2, flysystem-web-images N10). With
     * deduplication on, `StorageInterface` resolves to this decorator, and
     * `$storage->withProxyUrls($urls->forScope($tenant))->urlFor($file)` has
     * to work on it too: a copy whose wrapped facade mints through the given
     * generator, sharing the ledger, the store and everything else.
     */
    public function withProxyUrlsMintsThroughTheGivenGeneratorAndLeavesTheOriginalAlone(): void
    {
        $file = $this->storage->add($this->upload('hello'));

        $pinned = $this->storage->withProxyUrls(new PinnedProxyUrls('tenant-a'));

        Assert::instanceOf($pinned, DeduplicatingStorage::class);
        Assert::same($pinned->urlFor($file, $this->at('02:00')), "/files/tenant-a/{$file->id}");
        Assert::null($this->storage->urlFor($file, $this->at('02:00')));
        Assert::same($pinned->add($this->upload('hello'))->relativePath, $file->relativePath);
        Assert::same($this->store->writeCount(), 1);
    }

    /**
     * A decorator over a decorator delegates down to core's facade; a wrapped
     * facade that cannot swap its generator is refused by name rather than
     * silently minting through the unpinned one.
     */
    public function withProxyUrlsReachesCoresFacadeOrRefuses(): void
    {
        $file = $this->storage->add($this->upload('hello'));
        Assert::instanceOf($this->storage, DeduplicatingStorage::class);
        $outer = $this->wrapping($this->storage);

        Assert::same(
            $outer->withProxyUrls(new PinnedProxyUrls('tenant-b'))->urlFor($file, $this->at('02:00')),
            "/files/tenant-b/{$file->id}",
        );

        Expect::exception(InvalidConfigException::class)->withMessageContaining('ForwardingStorage');

        $this->wrapping(new ForwardingStorage($this->storage))->withProxyUrls(new PinnedProxyUrls('tenant-c'));
    }

    /**
     * Review pass 2 N-B3: the constructor is public, and the checks used to
     * live only in the factory and the command. Built directly, a flat key
     * generator on the directory layout wrote blobs `gc` can never collect,
     * and a tenant-dropping one pooled every tenant without a signal.
     */
    public function theConstructorRefusesAFlatKeyGeneratorOnTheDirectoryLayout(): void
    {
        Expect::exception(InvalidConfigException::class)->withMessageContaining('cannot share a store');

        $this->direct(store: $this->store, keys: new FlatSha256KeyGenerator());
    }

    public function theConstructorRefusesAGeneratorThatIgnoresTheTenant(): void
    {
        Expect::exception(InvalidConfigException::class)->withMessageContaining('ignores the tenant');

        $store = $this->flatStores()->get('upload');
        Assert::instanceOf($store, ContentAddressableStoreInterface::class);
        $this->direct(
            store: $store,
            keys: new FlatSha256KeyGenerator(prefix: 'blobs'),
            scopes: new FixedScope('tenant-a'),
        );
    }

    /**
     * Review pass 2 N-C3: the ledger is read once more after the row is gone,
     * to tell a shared blob from a unique object. A failure there escaped as
     * the raw driver exception instead of the promised `RemoveException`.
     */
    public function aLedgerFailingAfterTheReleaseIsARemoveException(): void
    {
        $ledger = new DbBlobLedger($this->database->db, $this->repository, new StaticClock($this->at('00:00')));
        $storage = $this->direct(store: $this->store, keys: new \Rasuvaeff\Yii3Filestorage\Path\Sha256KeyGenerator(), ledger: new FaultyLedger($ledger, find: true));
        $file = $this->storage->add($this->upload('hello'));

        Expect::exception(RemoveException::class);

        $storage->remove($file->id);
    }

    /**
     * A commit that fails is followed by a release, and on MariaDB inside an
     * application transaction the release can fail on the same snapshot
     * conflict. The caller must still get the `AddException` for the commit —
     * the reservation's TTL takes care of a release that never happened.
     */
    public function aReleaseFailingAfterAFailedCommitStillReportsTheCommit(): void
    {
        $ledger = new DbBlobLedger($this->database->db, $this->repository, new StaticClock($this->at('00:00')));
        $storage = $this->direct(
            store: $this->store,
            keys: new \Rasuvaeff\Yii3Filestorage\Path\Sha256KeyGenerator(),
            ledger: new FaultyLedger($ledger, commit: true, release: true),
        );

        try {
            $storage->add($this->upload('hello'));
            Assert::fail('Expected AddException');
        } catch (AddException $e) {
            Assert::same($e->getPrevious()?->getMessage(), 'commit failed');
        }
    }

    public function aGeneratorRefusingTheTenantProbeIsAConfigurationError(): void
    {
        Expect::exception(InvalidConfigException::class)
            ->withMessageContaining(SlashRefusingKeyGenerator::class . ' refused a probe scope: Nested scope');

        $this->factory(scopes: new FixedScope('tenant-a'), keys: new SlashRefusingKeyGenerator())->create();
    }

    public function aGeneratorThatIgnoresTheTenantIsAcceptedUnderTheGlobalScopeOrWithoutAProvider(): void
    {
        $stores = $this->flatStores();
        $keys = new FlatSha256KeyGenerator(prefix: 'blobs');

        $global = $this->factory(stores: $stores, scopes: new FixedScope('tenant-a'), keys: $keys)
            ->create(scope: DedupScope::Global);
        $single = $this->factory(stores: $stores, keys: $keys)->create();

        Assert::true(str_starts_with($global->add($this->upload('hello'))->relativePath, 'blobs/'));
        Assert::true(str_starts_with($single->add($this->upload('bye'))->relativePath, 'blobs/'));
    }

    /**
     * The setup the cska-one adoption targets: a public local store whose root
     * is a shared web directory, flat sibling layout, one `blobs/` pool. Core
     * 0.10 made the local stores content-addressable, so the factory accepts
     * them; this pins the whole path — share, serve, remove — on real files.
     */
    public function aPublicLocalStoreWithFlatBlobsDeduplicatesEndToEnd(): void
    {
        $root = sys_get_temp_dir() . '/filestorage-db-' . bin2hex(random_bytes(6));
        $public = new PublicFileSystemStore(
            new FileSystemStore(
                name: 'upload',
                rootPath: $root,
                streamFactory: $this->factory,
                layout: new FlatSiblingLayout(),
                ownedPrefixes: ['blobs', 'common'],
            ),
            baseUrl: '/uploads',
        );

        try {
            $storage = $this->factory(
                stores: new StoreRegistry([$public]),
                keys: new FlatSha256KeyGenerator(prefix: 'blobs'),
            )->create();

            $first = $storage->add($this->upload('hello'));
            $second = $storage->add($this->upload('hello'));
            $sha = hash('sha256', 'hello');
            $path = 'blobs/' . substr($sha, 0, 2) . '/' . substr($sha, 2, 2) . "/{$sha}.txt";

            Assert::same($first->relativePath, $path);
            Assert::same($second->relativePath, $path);
            Assert::true(is_file("{$root}/{$path}"));
            Assert::same($this->filesUnder("{$root}/blobs"), 1);
            Assert::same($public->publicUrl($second), "/uploads/{$path}");
            Assert::same((string) $storage->stream($second), 'hello');

            Assert::true($storage->remove($first->id));
            Assert::true($storage->remove($second->id));

            Assert::same($this->ledger->find(BlobId::create('upload', $path))?->state, BlobState::PendingDelete);
            Assert::true(is_file("{$root}/{$path}"), 'only a collector deletes shared bytes');
        } finally {
            $this->removeTree($root);
        }
    }

    private function factory(
        ?StoreRegistry $stores = null,
        ?FixedScope $scopes = null,
        ?BlobLedgerInterface $ledger = null,
        ?ContentAddressedKeyGeneratorInterface $keys = null,
        ?PolicyRegistry $policies = null,
        ?MimeTypeDetectorInterface $mimeTypeDetector = null,
        ?ExtensionMap $extensions = null,
        ?\Rasuvaeff\Yii3Filestorage\Id\IdGeneratorInterface $idGenerator = null,
    ): DeduplicatingStorageFactory {
        $stores ??= new StoreRegistry([$this->store]);
        $clock = new StaticClock($this->at('00:00'));
        $repository = $scopes instanceof \Rasuvaeff\Yii3FilestorageDb\Tests\Support\FixedScope ? new DbRepository($this->database->db, scopes: $scopes) : $this->repository;

        return new DeduplicatingStorageFactory(
            unique: $this->baseStorage($stores, $repository),
            stores: $stores,
            repository: $repository,
            ledger: $ledger ?? new DbBlobLedger($this->database->db, $repository, $clock),
            mimeTypeDetector: $mimeTypeDetector ?? new FinfoMimeTypeDetector(),
            idGenerator: $idGenerator ?? new Uuid7IdGenerator($clock),
            policies: $policies ?? new PolicyRegistry(),
            clock: $clock,
            scopes: $scopes,
            keys: $keys,
            extensions: $extensions,
        );
    }

    private function direct(
        ContentAddressableStoreInterface $store,
        ContentAddressedKeyGeneratorInterface $keys,
        ?FixedScope $scopes = null,
        ?BlobLedgerInterface $ledger = null,
    ): DeduplicatingStorage {
        $clock = new StaticClock($this->at('00:00'));
        $stores = new StoreRegistry([$store]);

        return new DeduplicatingStorage(
            unique: $this->baseStorage($stores, $this->repository),
            ledger: $ledger ?? new DbBlobLedger($this->database->db, $this->repository, $clock),
            store: $store,
            keys: $keys,
            mimeTypeDetector: new FinfoMimeTypeDetector(),
            idGenerator: new Uuid7IdGenerator($clock),
            policies: new PolicyRegistry(),
            clock: $clock,
            scopes: $scopes,
        );
    }

    private function wrapping(StorageInterface $unique): DeduplicatingStorage
    {
        $clock = new StaticClock($this->at('00:00'));

        return new DeduplicatingStorage(
            unique: $unique,
            ledger: $this->ledger,
            store: $this->store,
            keys: new \Rasuvaeff\Yii3Filestorage\Path\Sha256KeyGenerator(),
            mimeTypeDetector: new FinfoMimeTypeDetector(),
            idGenerator: new Uuid7IdGenerator($clock),
            policies: new PolicyRegistry(),
            clock: $clock,
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

    private function filesUnder(string $directory): int
    {
        return iterator_count(new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        ));
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            \assert($entry instanceof \SplFileInfo);
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }

    /**
     * Flat content keys need the sibling layout; under `DirectoryLayout` the
     * factory refuses them (summary A1).
     */
    private function flatStores(): StoreRegistry
    {
        return new StoreRegistry([new ContentAddressableInMemoryStore(
            new InMemoryStore('upload', $this->factory, new StaticClock($this->at('00:00')), new FlatSiblingLayout()),
        )]);
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

    /**
     * A store that fails mid-protocol leaves a reservation behind. It must be
     * released (scheduled, never deleted outright) and the caller must see the
     * documented exception with the cause attached.
     */
    public function aFailedWriteReleasesTheReservationAndSurfacesAnAddException(): void
    {
        $this->store->failNextPut();
        $storage = $this->factory(keys: new FixedKeyGenerator('shared/one'))->create();
        $blob = BlobId::create('upload', 'shared/one/' . hash('sha256', 'hello') . '/original.bin');

        try {
            $storage->add($this->upload('hello'));
            Assert::fail('Expected AddException');
        } catch (AddException $e) {
            Assert::same($e->getMessage(), sprintf('Failed to store "%s"', $blob->key()));
            Assert::same($e->getCode(), 0);
            Assert::instanceOf($e->getPrevious(), StoreException::class);
            Assert::same($this->ledger->find($blob)?->state, BlobState::PendingDelete);
        }

        Assert::same($this->store->writeCount(), 0);
    }

    public function aFailureToReleaseTheFileRowSurfacesARemoveException(): void
    {
        $file = $this->storage->add($this->upload('hello'));
        $this->database->db->createCommand()->dropTable('filestorage_file')->execute();

        try {
            $this->storage->remove($file->id);
            Assert::fail('Expected RemoveException');
        } catch (RemoveException $e) {
            Assert::same($e->getMessage(), sprintf('Failed to release file "%s"', $file->id));
            Assert::same($e->getCode(), 0);
            Assert::instanceOf($e->getPrevious(), \Throwable::class);
        }
    }

    /**
     * The declaration is trusted before a byte is read: an upload that claims
     * to be over the cap is unique even when the bytes behind it are small.
     */
    public function aDeclaredSizeOverTheCapWinsOverTheRealBytes(): void
    {
        $storage = $this->factory()->create(dedupMaxBytes: 10);
        $stream = new SpyStream($this->factory->createStream('hello'), reportedSize: 100);

        $file = $storage->add(Upload::fromStream($stream, 'a.txt', $this->factory));

        Assert::null($file->contentHash);
    }

    /**
     * A body that stalls instead of reporting its end still has the bytes it
     * delivered hashed and counted: an empty read ends the loop.
     */
    public function anEmptyReadEndsTheDigestEvenWhenTheStreamNeverReportsItsEnd(): void
    {
        $storage = $this->factory(mimeTypeDetector: new NoMimeTypeDetector())->create();
        $stream = new SpyStream($this->factory->createStream('hello'), neverEof: true);

        $file = $storage->add(Upload::fromStream($stream, 'a.txt', $this->factory));

        Assert::same($file->size, 5);
        Assert::same($file->contentHash, hash('sha256', 'hello'));
    }

    /**
     * Too large to hash, so the unique path — but in the store deduplication
     * was configured for, not the registry's default. The two used to be
     * assumed to be one physical target.
     */
    public function anUploadTooLargeToHashStaysInTheDeduplicatingStore(): void
    {
        $other = new InMemoryStore('archive', $this->factory, new StaticClock($this->at('00:00')));
        $storage = $this->factory(stores: new StoreRegistry([$other, $this->store], 'archive'))
            ->create(storeName: 'upload', dedupMaxBytes: 4);

        $file = $storage->add($this->upload('hello'));

        Assert::same($file->storeName, 'upload');
        Assert::same($this->store->bytesAt($file->relativePath), 'hello');
    }

    /**
     * A body that does not declare its length is bounded by the group's
     * `maxBytes` while it is hashed, not only by `dedupMaxBytes` — the policy
     * limit is usually far smaller, and reading 100 MB to refuse 1 MB is the
     * cost the policy exists to avoid. The base facade's exception, before
     * anything is reserved.
     */
    public function anUndeclaredBodyOverThePolicyLimitIsRefusedWhileHashing(): void
    {
        $storage = $this->factory(policies: PolicyRegistry::fromArray(['*' => ['maxBytes' => 300_000]]))->create();
        $stream = new SpyStream($this->factory->createStream(str_repeat('x', 900_000)));

        try {
            $storage->add(Upload::fromStream(new UnsizedStream($stream), 'a.txt', $this->factory));
            Assert::fail('Expected UploadSizeException');
        } catch (UploadSizeException $e) {
            Assert::string($e->getMessage())->contains('300000 byte limit');
        }

        Assert::same($this->store->writeCount(), 0);
        Assert::same((new \Yiisoft\Db\Query\Query($this->database->db))->from('filestorage_blob_reservation')->count(), 0);
        Assert::true($stream->tell() < 900_000, 'the tail must not have been read');
    }

    /**
     * A stream that cannot be rewound after hashing is the add failing, and
     * the caller is promised an `AddException` for that — not the stream's
     * own exception.
     */
    public function aStreamThatCannotBeRewoundIsAnAddException(): void
    {
        $stream = new SpyStream($this->factory->createStream('hello'), rewindFailsAfterRead: true);
        // no detector: it reads the head and rewinds too, exactly as in the
        // base facade, and this is about the hashing pass
        $storage = $this->factory(mimeTypeDetector: new NoMimeTypeDetector())->create();

        try {
            $storage->add(Upload::fromStream($stream, 'a.txt', $this->factory));
            Assert::fail('Expected AddException');
        } catch (AddException $e) {
            Assert::instanceOf($e->getPrevious(), \RuntimeException::class);
        }

        Assert::same($this->store->writeCount(), 0);
    }

    /**
     * Same ordering bug as core's review A2: the row was only built after
     * the bytes were published, so metadata `File::create()` refuses — or an
     * id generator or clock that fails — left a reserved, written blob behind
     * for the collector. Everything the caller supplied is validated first.
     */
    public function invalidMetadataIsRefusedBeforeAnythingIsReservedOrWritten(): void
    {
        $refused = false;

        try {
            $this->storage->add($this->upload('hello'), metadata: ['nested' => ['not' => 'scalar']]);
        } catch (\InvalidArgumentException) {
            $refused = true;
        }

        Assert::true($refused);
        Assert::same($this->store->writeCount(), 0);
        Assert::same((new \Yiisoft\Db\Query\Query($this->database->db))->from('filestorage_blob')->count(), 0);
    }

    /**
     * After the write, a failure is the add-failure path: the reservation is
     * released (scheduled, never deleted — the bytes may already be shared)
     * and the caller gets `AddException`.
     */
    public function aFailureAfterTheWriteReleasesTheReservation(): void
    {
        // the id is taken by another tenant's row: the scoped update finds
        // nothing and the insert collides on the primary key — after the bytes
        // were published
        (new DbRepository($this->database->db, scopes: new FixedScope('tenant-b')))->save(SqliteDatabase::file('taken'));
        $storage = $this->factory(scopes: new FixedScope('tenant-a'), idGenerator: new FixedIdGenerator('taken'))->create();

        try {
            $storage->add($this->upload('hello'));
            Assert::fail('Expected AddException');
        } catch (AddException) {
        }

        $blob = $this->blobOf((new \Rasuvaeff\Yii3Filestorage\Path\Sha256KeyGenerator())->generate(
            DedupScope::TenantGroup->keyFor('common', 'tenant-a'),
            hash('sha256', 'hello'),
        ));
        Assert::same($this->store->writeCount(), 1);
        Assert::same($this->ledger->find($blob)?->reservationCount, 0, 'the reservation must be released');
        Assert::same($this->ledger->find($blob)?->state, BlobState::PendingDelete);
    }
}
