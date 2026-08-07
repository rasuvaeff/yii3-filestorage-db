<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Tests\Command;

use DateTimeImmutable;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Path\RandomPathGenerator;
use Rasuvaeff\Yii3Filestorage\Path\Sha256KeyGenerator;
use Rasuvaeff\Yii3Filestorage\Store\BlobId;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Test\InMemoryStore;
use Rasuvaeff\Yii3Filestorage\Upload;
use Rasuvaeff\Yii3FilestorageDb\Command\DeduplicateCommand;
use Rasuvaeff\Yii3FilestorageDb\DbBlobLedger;
use Rasuvaeff\Yii3FilestorageDb\DbRepository;
use Rasuvaeff\Yii3FilestorageDb\DedupScope;
use Rasuvaeff\Yii3FilestorageDb\Tests\Support\ContentAddressableInMemoryStore;
use Rasuvaeff\Yii3FilestorageDb\Tests\Support\FixedKeyGenerator;
use Rasuvaeff\Yii3FilestorageDb\Tests\Support\FixedScope;
use Rasuvaeff\Yii3FilestorageDb\Tests\Support\SqliteDatabase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Test\Support\Clock\StaticClock;

#[Test]
#[Covers(DeduplicateCommand::class)]
final class DeduplicateCommandTest
{
    private SqliteDatabase $database;
    private DbRepository $repository;
    private DbBlobLedger $ledger;
    private ContentAddressableInMemoryStore $store;
    private InMemoryStore $inner;
    private Psr17Factory $factory;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->database = new SqliteDatabase();
        $this->factory = new Psr17Factory();
        $clock = new StaticClock($this->at('00:00'));

        $this->repository = new DbRepository($this->database->db);
        $this->ledger = new DbBlobLedger($this->database->db, $this->repository, $clock);
        $this->inner = new InMemoryStore('upload', $this->factory, $clock);
        $this->store = new ContentAddressableInMemoryStore($this->inner);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->database->close();
    }

    /**
     * The default is a report. A migration whose first run rewrites rows is one
     * somebody eventually runs against the wrong database.
     */
    public function nothingIsWrittenWithoutApply(): void
    {
        $file = $this->store('a', 'hello');

        $tester = $this->run();

        Assert::string($tester->getDisplay())->contains('Dry run');
        Assert::string($tester->getDisplay())->contains('would move a to');
        Assert::same($this->repository->find('a')?->relativePath, $file->relativePath);
        Assert::null($this->ledger->find($this->blobFor('common', 'hello')));
    }

    public function applyRepointsTheRowAtItsContentKey(): void
    {
        $unique = $this->store('a', 'hello')->relativePath;
        $blob = $this->blobFor('common', 'hello');

        Assert::same($this->run(['--apply' => true])->getStatusCode(), Command::SUCCESS);

        Assert::same($this->repository->find('a')?->relativePath, $blob->relativePath());
        Assert::same($this->repository->find('a')?->contentHash, hash('sha256', 'hello'));
        Assert::same($this->store->bytesAt($blob->relativePath()), 'hello');
        Assert::same($this->ledger->find($blob)?->referenceCount, 1);
        Assert::same($unique === $blob->relativePath(), false);
    }

    /**
     * Everything else about the row is the same file: the migration moves bytes
     * to a new address, it does not mint a new record.
     */
    public function theRowKeepsItsIdentity(): void
    {
        $before = $this->store('a', 'hello', group: 'avatars', description: 'my avatar');

        $this->run(['--apply' => true]);
        $after = $this->repository->find('a');

        Assert::same($after?->id, $before->id);
        Assert::same($after?->groupName, 'avatars');
        Assert::same($after?->originalName, $before->originalName);
        Assert::same($after?->description, 'my avatar');
        Assert::same($after?->createdAt->format('H:i'), $before->createdAt->format('H:i'));
    }

    /**
     * The old object is deliberately left in place: it becomes an orphan the
     * moment the row is repointed, and `filestorage:gc --orphans` reclaims it.
     * Deleting it here would race the readers still holding the old path.
     */
    public function theOldObjectIsLeftForTheOrphanSweep(): void
    {
        $unique = $this->store('a', 'hello')->relativePath;

        $tester = $this->run(['--apply' => true]);

        Assert::same($this->inner->bytesAt($unique), 'hello');
        Assert::string($tester->getDisplay())->contains('filestorage:gc --orphans --apply');
    }

    /**
     * Two rows with the same bytes converge on one object — the entire point of
     * the exercise, and the thing a per-row migration could easily get wrong by
     * writing each row its own copy.
     */
    public function identicalRowsConvergeOnOneObject(): void
    {
        $this->store('a', 'hello');
        $this->store('b', 'hello');
        $before = $this->store->writeCount();

        $this->run(['--apply' => true]);

        $blob = $this->blobFor('common', 'hello');
        Assert::same($this->repository->find('a')?->relativePath, $blob->relativePath());
        Assert::same($this->repository->find('b')?->relativePath, $blob->relativePath());
        Assert::same($this->ledger->find($blob)?->referenceCount, 2);
        Assert::same($this->store->writeCount() - $before, 1, 'the second row must join, not write again');
    }

    /**
     * A second pass over the same range does nothing. Without this a migration
     * interrupted halfway cannot simply be re-run.
     */
    public function aSecondRunIsANoOp(): void
    {
        $this->store('a', 'hello');
        $this->run(['--apply' => true]);

        $tester = $this->run(['--apply' => true]);

        Assert::string($tester->getDisplay())->contains('Migrated 0 of 1 row');
        Assert::string($tester->getDisplay())->contains('1 already shared');
        Assert::same($this->ledger->find($this->blobFor('common', 'hello'))?->referenceCount, 1);
    }

    public function theCursorResumesWhereTheLastBatchStopped(): void
    {
        $this->store('a', 'one');
        $this->store('b', 'two');

        $tester = $this->run(['--apply' => true, '--after' => 'a']);

        Assert::string($tester->getDisplay())->contains('Migrated 1 of 1 row');
        Assert::string($tester->getDisplay())->contains('Last id: b');
        Assert::same($this->repository->find('a')?->contentHash, null, 'the row before the cursor is untouched');
    }

    public function theBatchStopsAtItsLimit(): void
    {
        $this->store('a', 'one');
        $this->store('b', 'two');
        $this->store('c', 'three');

        $tester = $this->run(['--apply' => true, '--limit' => '2']);

        Assert::string($tester->getDisplay())->contains('Migrated 2 of 2 rows');
        Assert::null($this->repository->find('c')?->contentHash);
    }

    /**
     * Same cap a live add applies, for the same reason: the content key is the
     * hash, so addressing a multi-gigabyte object means reading it twice.
     */
    public function rowsOverTheCapKeepTheirUniqueObject(): void
    {
        $file = $this->store('a', 'hello');

        $tester = $this->run(['--apply' => true, '--max-bytes' => '2']);

        Assert::string($tester->getDisplay())->contains('over --max-bytes');
        Assert::same($this->repository->find('a')?->relativePath, $file->relativePath);
    }

    public function aRowWhoseBytesAreGoneIsReportedAndSkipped(): void
    {
        $this->store('a', 'hello');
        $this->inner->clear();

        $tester = $this->run(['--apply' => true]);

        Assert::string($tester->getDisplay())->contains('unreadable: a');
        Assert::same($tester->getStatusCode(), Command::SUCCESS);
    }

    /**
     * The scope decides the key, so a typo would migrate every row onto keys no
     * future upload ever joins — silently, and only detectably much later. It
     * fails up front instead.
     */
    public function anUnknownScopeIsRefusedBeforeAnythingIsRead(): void
    {
        $file = $this->store('a', 'hello');

        $tester = $this->run(['--apply' => true, '--scope' => 'per-user']);

        Assert::same($tester->getStatusCode(), Command::FAILURE);
        Assert::string($tester->getDisplay())->contains('Unknown scope "per-user"');
        Assert::same($this->repository->find('a')?->relativePath, $file->relativePath);
    }

    public function theScopeChangesTheKeyTheRowLandsOn(): void
    {
        $this->store('a', 'hello');

        $this->run(['--apply' => true, '--scope' => 'global']);

        Assert::same(
            $this->repository->find('a')?->relativePath,
            $this->blobFor('common', 'hello', DedupScope::Global)->relativePath(),
        );
    }

    /**
     * A store that cannot promise atomic put-if-absent has nothing to migrate
     * onto, and finding that out row by row would leave the table half moved.
     */
    public function aStoreThatCannotDeduplicateIsRefusedUpFront(): void
    {
        $this->store('a', 'hello');
        $plain = new InMemoryStore('plain', $this->factory, new StaticClock($this->at('00:00')));

        $tester = $this->run(['--apply' => true], new StoreRegistry([$plain]));

        Assert::same($tester->getStatusCode(), Command::FAILURE);
        Assert::string($tester->getDisplay())->contains('cannot deduplicate');
    }

    /**
     * Rows belonging to another physical store are not this run's business —
     * their bytes are not even reachable from here.
     */
    public function rowsInAnotherStoreAreLeftAlone(): void
    {
        $this->repository->save(File::create(
            id: 'a',
            storeName: 'elsewhere',
            groupName: 'common',
            relativePath: 'ab/cd/ef/original.bin',
            originalName: 'a.txt',
            size: 5,
            createdAt: $this->at('00:00'),
        ));

        $this->run(['--apply' => true]);

        Assert::same($this->repository->find('a')?->relativePath, 'ab/cd/ef/original.bin');
    }


    /**
     * The header, exactly. It is the only place an operator sees which scope and
     * which tenant the run is about to key everything under — the two settings
     * that decide whether the migration lands where future uploads will look.
     */
    public function theHeaderNamesTheScopeTheTenantAndTheStore(): void
    {
        $display = $this->run()->getDisplay();

        Assert::string($display)->contains('Scope tenant-group, tenant (none), store "upload".');
    }

    public function theHeaderNamesTheTenantWhenOneIsBound(): void
    {
        $tester = new CommandTester(new DeduplicateCommand(
            stores: new StoreRegistry([$this->store]),
            repository: $this->repository,
            ledger: $this->ledger,
            streams: $this->factory,
            clock: new StaticClock($this->at('01:00')),
            scopes: new FixedScope('tenant-a'),
        ));
        $tester->execute([]);

        Assert::string($tester->getDisplay())->contains('tenant tenant-a,');
    }

    /**
     * The refusal has to say which values are acceptable, or the operator's next
     * move is another guess.
     */
    public function theScopeRefusalListsTheAcceptableValues(): void
    {
        $display = $this->run(['--scope' => 'per-user'])->getDisplay();

        Assert::string($display)->contains('Use one of: tenant-group, tenant, global.');
        Assert::string($display)->contains('DeduplicatingStorageFactory::create()');
    }

    /**
     * Every counter, in one report. A migration that says "migrated 3" while it
     * skipped two and failed one is a migration nobody can reconcile against
     * the table afterwards.
     */
    public function everyOutcomeIsCountedSeparately(): void
    {
        $this->store('a', 'hello');                       // moved
        $this->store('b', 'small', size: 9_999_999);      // skipped: over the cap
        $gone = $this->store('c', 'vanished');            // skipped: unreadable
        $this->inner->deleteObject(new StoredObjectId($gone->relativePath));

        $display = $this->run(['--apply' => true, '--max-bytes' => '1000'])->getDisplay();

        Assert::string($display)->contains('Migrated 1 of 3 rows; 0 already shared, 2 skipped, 0 failed.');
    }

    public function oneRowIsReportedInTheSingular(): void
    {
        $this->store('a', 'hello');

        Assert::string($this->run()->getDisplay())
            ->contains('Would migrate 1 of 1 row; 0 already shared, 0 skipped, 0 failed.');
    }

    /**
     * The cap is opt-out, not always-on: zero means "no cap", and a row larger
     * than any sane default still migrates when the operator asks for it.
     */
    public function aZeroCapMeansNoCap(): void
    {
        $this->store('a', 'hello');

        $this->run(['--apply' => true, '--max-bytes' => '0']);

        Assert::same($this->repository->find('a')?->relativePath, $this->blobFor('common', 'hello')->relativePath());
    }

    /**
     * A row exactly at the cap is within it. Off by one here means the boundary
     * documented in `--max-bytes` is not the boundary enforced.
     */
    public function aRowExactlyAtTheCapStillMigrates(): void
    {
        $this->store('a', 'hello');

        $this->run(['--apply' => true, '--max-bytes' => '5']);

        Assert::same($this->repository->find('a')?->relativePath, $this->blobFor('common', 'hello')->relativePath());
    }

    public function theSkipMessageNamesTheRowAndItsSize(): void
    {
        $this->store('a', 'hello');

        Assert::string($this->run(['--apply' => true, '--max-bytes' => '2'])->getDisplay())
            ->contains('skipping a: 5 bytes is over --max-bytes');
    }

    /**
     * A nonsense limit floors at one rather than at zero: `--limit=0` doing
     * nothing looks exactly like "there was nothing to migrate".
     */
    public function anImpossibleLimitStillMigratesOneRow(): void
    {
        $this->store('a', 'hello');

        $this->run(['--apply' => true, '--limit' => '0']);

        Assert::same($this->repository->find('a')?->relativePath, $this->blobFor('common', 'hello')->relativePath());
    }

    /**
     * The closing advice only appears when there is something to clean up.
     * Telling an operator to run the collector after a run that moved nothing
     * is how the collector gets run on a schedule nobody reviewed.
     */
    public function theOrphanReminderOnlyFollowsRealWork(): void
    {
        Assert::string($this->run(['--apply' => true])->getDisplay())->notContains('filestorage:gc');

        $this->store('a', 'hello');
        Assert::string($this->run()->getDisplay())->notContains('filestorage:gc');
    }

    /**
     * The size in the ledger is counted from the bytes, not read off the row.
     * A row whose recorded size drifted — exactly what `verify --deep` exists to
     * find — would otherwise reserve the real hash beside a stale size, and
     * every later upload of that content would fail on the mismatch.
     */
    public function theReservedSizeComesFromTheBytesNotTheRow(): void
    {
        $this->store('a', 'hello', size: 9_999);

        $this->run(['--apply' => true]);

        Assert::same($this->ledger->find($this->blobFor('common', 'hello'))?->size, 5);
    }

    /**
     * A row that fails mid-migration must not leave its reservation behind: the
     * blob would stay alive forever, holding bytes nothing references.
     */
    public function aFailedRowReleasesItsReservation(): void
    {
        $this->store('a', 'hello');
        $this->store->failNextPut();

        $tester = $this->run(['--apply' => true]);

        Assert::same($tester->getStatusCode(), Command::FAILURE);
        Assert::string($tester->getDisplay())->contains('1 row could not be migrated.');
        Assert::same($this->ledger->find($this->blobFor('common', 'hello'))?->reservationCount, 0);
    }


    /**
     * The full closing sentence: it is the only place the operator is told the
     * old objects still exist and what reclaims them. A truncated version reads
     * as "done", and the storage never shrinks.
     */
    public function theClosingLineSaysExactlyWhatIsLeftToDo(): void
    {
        $this->store('a', 'hello');

        // Asserted in two halves because the success block hard-wraps at the
        // terminal width; together they still pin both concatenation operands.
        $display = $this->run(['--apply' => true])->getDisplay();

        Assert::string($display)->contains('Done. The objects the migrated rows used to point at are orphans now');
        Assert::string($display)->contains('reclaim them with `filestorage:gc --orphans --apply` once in-flight reads');
    }

    /**
     * The short recipe is wrong for the installation that is *most* likely to
     * run this: `gc --orphans` refuses under a bound scope provider, and this
     * command only works under one when the ambient scope is set. Sending a
     * multi-tenant operator to a command that will not run leaves the objects
     * stranded with nothing linking the refusal back to here.
     */
    public function theClosingLineSendsAMultiTenantOperatorSomewhereThatWorks(): void
    {
        $this->store('a', 'hello');

        $tester = new CommandTester(new DeduplicateCommand(
            stores: new StoreRegistry([$this->store]),
            repository: $this->repository,
            ledger: $this->ledger,
            streams: $this->factory,
            clock: new StaticClock($this->at('01:00')),
            scopes: new FixedScope('tenant-a'),
        ));
        $tester->execute(['--apply' => true]);

        $display = (string) preg_replace('/\s+/u', ' ', $tester->getDisplay());

        Assert::string($display)->contains('maintenance entry point that leaves');
        Assert::string($display)->contains('the sweep refuses under a bound scope provider');
    }

    public function theLimitCanBeOne(): void
    {
        $this->store('a', 'one');
        $this->store('b', 'two');

        $tester = $this->run(['--apply' => true, '--limit' => '1']);

        Assert::string($tester->getDisplay())->contains('Migrated 1 of 1 row');
        Assert::null($this->repository->find('b')?->contentHash);
    }

    /**
     * A row in another store is skipped on the store name alone, before its
     * bytes are looked for. Falling through would migrate a row whose path
     * happens to exist here — pointing this store's object at another store's
     * record.
     */
    public function aRowInAnotherStoreIsSkippedEvenWhenItsPathExistsHere(): void
    {
        $here = $this->store('a', 'hello');
        $this->repository->save(File::create(
            id: 'b',
            storeName: 'elsewhere',
            groupName: 'common',
            relativePath: $here->relativePath,
            originalName: 'a.txt',
            size: 5,
            createdAt: $this->at('00:00'),
        ));

        $this->run(['--apply' => true]);

        Assert::same($this->repository->find('b')?->relativePath, $here->relativePath);
        Assert::null($this->repository->find('b')?->contentHash);
    }

    /**
     * The key generator is swappable, and the migration has to use the injected
     * one — a migration keyed differently from the running storage lands every
     * row where no future upload will look.
     */
    public function anInjectedKeyGeneratorDecidesWhereRowsLand(): void
    {
        $this->store('a', 'hello');
        $tester = new CommandTester(new DeduplicateCommand(
            stores: new StoreRegistry([$this->store]),
            repository: $this->repository,
            ledger: $this->ledger,
            streams: $this->factory,
            clock: new StaticClock($this->at('01:00')),
            keys: new FixedKeyGenerator('shared/one/original.bin'),
        ));
        $tester->execute(['--apply' => true]);

        Assert::same($this->repository->find('a')?->relativePath, 'shared/one/original.bin');
    }

    /**
     * Content longer than one read: the byte counter has to accumulate across
     * chunks, or the ledger records the size of the last chunk.
     */
    public function theSizeAccumulatesAcrossReads(): void
    {
        $contents = str_repeat('x', 700_000);
        $this->store('a', $contents);

        $this->run(['--apply' => true]);

        Assert::same($this->ledger->find($this->blobFor('common', $contents))?->size, 700_000);
    }

    private function run(array $arguments = [], ?StoreRegistry $stores = null): CommandTester
    {
        $tester = new CommandTester(new DeduplicateCommand(
            stores: $stores ?? new StoreRegistry([$this->store]),
            repository: $this->repository,
            ledger: $this->ledger,
            streams: $this->factory,
            clock: new StaticClock($this->at('01:00')),
        ));
        $tester->execute($arguments);

        return $tester;
    }

    private function store(
        string $id,
        string $contents,
        string $group = 'common',
        ?string $description = null,
        ?int $size = null,
    ): File {
        $result = $this->inner->write(
            Upload::fromStream($this->factory->createStream($contents), 'a.txt', $this->factory),
            $group,
            new RandomPathGenerator(),
        );

        $file = File::create(
            id: $id,
            storeName: 'upload',
            groupName: $group,
            relativePath: $result->relativePath,
            originalName: 'a.txt',
            size: $size ?? $result->size,
            createdAt: $this->at('00:00'),
            description: $description,
        );
        $this->repository->save($file);

        return $file;
    }

    private function blobFor(string $group, string $contents, DedupScope $scope = DedupScope::TenantGroup): BlobId
    {
        return BlobId::create('upload', (new Sha256KeyGenerator())->generate(
            $scope->keyFor($group, null),
            hash('sha256', $contents),
        ));
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable("2026-01-01T{$time}:00.000000+00:00");
    }
}
