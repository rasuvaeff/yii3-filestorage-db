<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Tests;

use DateTimeImmutable;
use Rasuvaeff\Yii3Filestorage\Exception\BlobBusyException;
use Rasuvaeff\Yii3Filestorage\Exception\LedgerException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Store\BlobId;
use Rasuvaeff\Yii3Filestorage\Store\BlobLease;
use Rasuvaeff\Yii3Filestorage\Store\BlobReservation;
use Rasuvaeff\Yii3Filestorage\Store\BlobState;
use Rasuvaeff\Yii3Filestorage\Store\BlobToken;
use Rasuvaeff\Yii3FilestorageDb\BlobRowId;
use Rasuvaeff\Yii3FilestorageDb\DbBlobLedger;
use Rasuvaeff\Yii3FilestorageDb\DbRepository;
use Rasuvaeff\Yii3FilestorageDb\Tests\Support\FixedScope;
use Rasuvaeff\Yii3FilestorageDb\Tests\Support\SqliteDatabase;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Db\Query\Query;
use Yiisoft\Test\Support\Clock\StaticClock;

#[Test]
#[Covers(DbBlobLedger::class)]
#[Covers(BlobRowId::class)]
final class DbBlobLedgerTest
{
    private SqliteDatabase $database;
    private DbRepository $repository;
    private DbBlobLedger $ledger;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->database = new SqliteDatabase();
        $this->repository = new DbRepository($this->database->db);
        $this->ledger = new DbBlobLedger(
            db: $this->database->db,
            repository: $this->repository,
            clock: new StaticClock($this->at('00:00')),
        );
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->database->close();
    }

    public function aFirstReservationCreatesAWritingBlob(): void
    {
        $this->reserve();

        $record = $this->ledger->find($this->blob());
        Assert::same($record?->state, BlobState::Writing);
        Assert::same($record?->reservationCount, 1);
        Assert::same($record?->referenceCount, 0);
        Assert::same($record?->contentHash, SqliteDatabase::HASH);
        Assert::same($record?->size, 12);
    }

    public function findReturnsNullForAnUnknownBlob(): void
    {
        Assert::null($this->ledger->find($this->blob()));
    }

    public function aSecondWriterJoinsWithItsOwnToken(): void
    {
        $first = $this->reserve();
        $second = $this->reserve();

        Assert::false($first->token->equals($second->token));
        Assert::same($this->ledger->find($this->blob())?->reservationCount, 2);
    }

    public function commitInsertsTheFileRowAndTurnsTheReservationIntoAReference(): void
    {
        $this->ledger->commit($this->reserve(), SqliteDatabase::file('a'));

        $record = $this->ledger->find($this->blob());
        Assert::same($record?->state, BlobState::Active);
        Assert::same($record?->referenceCount, 1);
        Assert::same($record?->reservationCount, 0);
        Assert::same($this->repository->find('a')?->id, 'a');
        Assert::same($this->repository->findBlobRowId('a'), BlobRowId::for($this->blob()));
    }

    public function commitRejectsATokenTheLedgerNeverIssued(): void
    {
        $this->reserve();

        Expect::exception(LedgerException::class)->withMessageContaining('Unknown reservation');

        $this->ledger->commit($this->forgedReservation(), SqliteDatabase::file('a'));
    }

    /**
     * The commit must not have written the file row before discovering the
     * reservation was gone: the whole point of one transaction is that half of
     * it cannot survive.
     */
    public function aRefusedCommitLeavesNoFileRow(): void
    {
        $this->reserve();

        try {
            $this->ledger->commit($this->forgedReservation(), SqliteDatabase::file('a'));
        } catch (LedgerException) {
            // asserted in its own test
        }

        Assert::null($this->repository->find('a'));
    }

    public function commitRejectsAFileThatDoesNotPointAtTheBlob(): void
    {
        $reservation = $this->reserve();

        Expect::exception(LedgerException::class)->withMessageContaining('does not point at blob');

        $this->ledger->commit($reservation, SqliteDatabase::file('a', relativePath: 'elsewhere/original'));
    }

    public function commitRejectsAFileStoredInAnotherStore(): void
    {
        $reservation = $this->reserve();

        Expect::exception(LedgerException::class)->withMessageContaining('does not point at blob');

        $this->ledger->commit($reservation, SqliteDatabase::file('a', storeName: 'archive'));
    }

    public function reserveRejectsContentThatDisagreesWithTheStoredBlob(): void
    {
        $this->reserve();

        // spans the concatenation on purpose: asserting one half lets the operands swap undetected
        Expect::exception(LedgerException::class)
            ->withMessageContaining('already holds different content (hash ' . SqliteDatabase::HASH . ', 12 bytes)');

        $this->reserve(hash: SqliteDatabase::OTHER_HASH);
    }

    public function reserveRejectsASizeThatDisagreesWithTheStoredBlob(): void
    {
        $this->reserve();

        Expect::exception(LedgerException::class)->withMessageContaining('already holds different content');

        $this->reserve(size: 999);
    }

    public function releasingTheLastClaimSchedulesTheBlobWithoutDeletingIt(): void
    {
        $this->ledger->release($this->reserve(), $this->at('01:00'));

        $record = $this->ledger->find($this->blob());
        Assert::same($record?->state, BlobState::PendingDelete);
        Assert::same($record?->deleteAfter?->format('H:i'), '01:00');
    }

    public function releasingOneOfTwoClaimsLeavesTheBlobAlone(): void
    {
        $first = $this->reserve();
        $this->reserve();

        $this->ledger->release($first, $this->at('01:00'));

        $record = $this->ledger->find($this->blob());
        Assert::same($record?->state, BlobState::Writing);
        Assert::same($record?->reservationCount, 1);
    }

    public function releasingAnUnknownReservationIsANoOp(): void
    {
        $this->ledger->release($this->forgedReservation(), $this->at('01:00'));

        Assert::null($this->ledger->find($this->blob()));
    }

    /**
     * One writer must not be able to release another's claim by presenting a
     * token for a different blob.
     */
    public function aTokenOnlyReleasesItsOwnBlob(): void
    {
        $reservation = $this->reserve();
        $wrongBlob = new BlobReservation($this->otherBlob(), $reservation->token, $this->at('00:10'));

        $this->ledger->release($wrongBlob, $this->at('01:00'));

        Assert::same($this->ledger->find($this->blob())?->reservationCount, 1);
    }

    public function reservingAPendingDeleteBlobRevivesIt(): void
    {
        $this->ledger->release($this->reserve(), $this->at('01:00'));

        $this->reserve();

        $record = $this->ledger->find($this->blob());
        Assert::same($record?->state, BlobState::Writing);
        Assert::null($record?->deleteAfter);
    }

    /**
     * A blob whose last reference was removed and then regained goes back to
     * active, not to writing: something committed is holding it again.
     */
    public function revivingABlobThatStillHasReferencesReturnsItToActive(): void
    {
        $this->ledger->commit($this->reserve(), SqliteDatabase::file('a'));

        $this->reserve();

        $record = $this->ledger->find($this->blob());
        Assert::same($record?->state, BlobState::Active);
        Assert::same($record?->referenceCount, 1);
        Assert::same($record?->reservationCount, 1);
    }

    public function releaseFileDropsTheRowAndSchedulesTheBlob(): void
    {
        $this->ledger->commit($this->reserve(), SqliteDatabase::file('a'));

        Assert::true($this->ledger->releaseFile('a', $this->at('01:00')));

        Assert::null($this->repository->find('a'));
        $record = $this->ledger->find($this->blob());
        Assert::same($record?->state, BlobState::PendingDelete);
        Assert::same($record?->referenceCount, 0);
    }

    public function releaseFileKeepsABlobOtherFilesStillReference(): void
    {
        $this->ledger->commit($this->reserve(), SqliteDatabase::file('a'));
        $this->ledger->commit($this->reserve(), SqliteDatabase::file('b'));

        Assert::true($this->ledger->releaseFile('a', $this->at('01:00')));

        $record = $this->ledger->find($this->blob());
        Assert::same($record?->state, BlobState::Active);
        Assert::same($record?->referenceCount, 1);
    }

    public function releaseFileReportsAnUnknownIdentifier(): void
    {
        Assert::false($this->ledger->releaseFile('nope', $this->at('01:00')));
    }

    public function releaseFileHandlesARowWithNoBlob(): void
    {
        $this->repository->save(SqliteDatabase::file('a'));

        Assert::true($this->ledger->releaseFile('a', $this->at('01:00')));
        Assert::null($this->ledger->find($this->blob()));
    }

    /**
     * The ledger reaches the file table only through the scoped repository, so
     * another tenant's id resolves to nothing — and neither the row nor the
     * blob accounting moves.
     */
    public function releaseFileCannotReachAnotherTenantsRow(): void
    {
        $scope = new FixedScope('tenant-a');
        $scoped = new DbRepository($this->database->db, scopes: $scope);
        $ledger = new DbBlobLedger(
            db: $this->database->db,
            repository: $scoped,
            clock: new StaticClock($this->at('00:00')),
        );
        $ledger->commit($this->reserveWith($ledger), SqliteDatabase::file('a'));

        $scope->switchTo('tenant-b');

        Assert::false($ledger->releaseFile('a', $this->at('01:00')));
        Assert::same($ledger->find($this->blob())?->state, BlobState::Active);
    }

    public function expiredReservationsAreSweptAndWhatTheyHeldIsScheduled(): void
    {
        $this->reserve();

        Assert::same($this->ledger->expireReservations($this->at('00:30'), $this->at('01:00')), 1);

        $record = $this->ledger->find($this->blob());
        Assert::same($record?->state, BlobState::PendingDelete);
        Assert::same($record?->reservationCount, 0);
    }

    public function aLiveReservationSurvivesTheSweep(): void
    {
        $this->reserve();

        Assert::same($this->ledger->expireReservations($this->at('00:05'), $this->at('01:00')), 0);
        Assert::same($this->ledger->find($this->blob())?->state, BlobState::Writing);
    }

    public function aReservationIsSweptExactlyAtItsDeadline(): void
    {
        $this->reserve();

        Assert::same($this->ledger->expireReservations($this->at('00:09'), $this->at('01:00')), 0);
        Assert::same($this->ledger->expireReservations($this->at('00:10'), $this->at('01:00')), 1);
    }

    /**
     * A swept reservation on a blob a file still references must not schedule
     * it: the sweep is about abandoned writers, not about live rows.
     */
    public function aSweepDoesNotScheduleAReferencedBlob(): void
    {
        $this->ledger->commit($this->reserve(), SqliteDatabase::file('a'));
        $this->reserve();

        $this->ledger->expireReservations($this->at('00:30'), $this->at('01:00'));

        Assert::same($this->ledger->find($this->blob())?->state, BlobState::Active);
    }

    public function nothingIsCollectableBeforeItsGracePeriodEnds(): void
    {
        $this->ledger->release($this->reserve(), $this->at('01:00'));

        Assert::null($this->ledger->claimForDeletion($this->at('00:59'), $this->at('01:05')));
    }

    public function anExpiredScheduleIsClaimedExclusively(): void
    {
        $this->ledger->release($this->reserve(), $this->at('01:00'));

        $lease = $this->ledger->claimForDeletion($this->at('01:00'), $this->at('01:05'));

        Assert::same($lease?->blob->key(), $this->blob()->key());
        Assert::same($this->ledger->find($this->blob())?->state, BlobState::Deleting);
        Assert::null($this->ledger->claimForDeletion($this->at('01:01'), $this->at('01:06')));
    }

    public function aBlobThatIsNotReadyIsSkippedRatherThanEndingTheScan(): void
    {
        $this->ledger->release($this->reserve(), $this->at('02:00'));
        $this->ledger->release($this->reserveOther(), $this->at('01:00'));

        $lease = $this->ledger->claimForDeletion($this->at('01:30'), $this->at('01:35'));

        Assert::same($lease?->blob->key(), $this->otherBlob()->key());
    }

    public function anAbandonedLeaseIsStolenAfterItExpires(): void
    {
        $this->ledger->release($this->reserve(), $this->at('01:00'));
        $first = $this->ledger->claimForDeletion($this->at('01:00'), $this->at('01:05'));

        $second = $this->ledger->claimForDeletion($this->at('01:05'), $this->at('01:10'));

        Assert::instanceOf($first, BlobLease::class);
        Assert::instanceOf($second, BlobLease::class);
        Assert::false(($first ?? $this->forgedLease())->token->equals(($second ?? $this->forgedLease())->token));
    }

    public function aStolenLeaseCanNoLongerCompleteTheDeletion(): void
    {
        $this->ledger->release($this->reserve(), $this->at('01:00'));
        $first = $this->ledger->claimForDeletion($this->at('01:00'), $this->at('01:05'));
        $this->ledger->claimForDeletion($this->at('01:05'), $this->at('01:10'));

        Assert::false($this->ledger->completeDeletion($first ?? $this->forgedLease()));
        Assert::same($this->ledger->find($this->blob())?->state, BlobState::Deleting);
    }

    public function completingADeletionRemovesTheLedgerRow(): void
    {
        $this->ledger->release($this->reserve(), $this->at('01:00'));
        $lease = $this->ledger->claimForDeletion($this->at('01:00'), $this->at('01:05'));

        Assert::true($this->ledger->completeDeletion($lease ?? $this->forgedLease()));
        Assert::null($this->ledger->find($this->blob()));
    }

    public function completingWithoutALeaseIsRefused(): void
    {
        $this->ledger->release($this->reserve(), $this->at('01:00'));

        Assert::false($this->ledger->completeDeletion($this->forgedLease()));
        Assert::same($this->ledger->find($this->blob())?->state, BlobState::PendingDelete);
    }

    /**
     * The race the whole design exists for: a writer commits between the claim
     * and the delete. In memory this branch is unreachable; here it is one
     * statement away, and the `NOT EXISTS` guard is what refuses it.
     */
    public function aBlobReferencedAgainAfterTheClaimIsNotDeleted(): void
    {
        $this->ledger->release($this->reserve(), $this->at('01:00'));
        $lease = $this->ledger->claimForDeletion($this->at('01:00'), $this->at('01:05'));

        // a concurrent writer that already held a reservation commits its row
        $this->repository->save(SqliteDatabase::file('a'), $this->blob());

        Assert::false($this->ledger->completeDeletion($lease ?? $this->forgedLease()));
        Assert::same($this->ledger->find($this->blob())?->state, BlobState::Deleting);
    }

    /**
     * Same race, but on the scheduling side: a reservation taken between the
     * release and the schedule keeps the blob out of the collection queue.
     */
    public function aBlobReservedAgainIsNotScheduled(): void
    {
        $first = $this->reserve();
        $this->reserve();

        $this->ledger->release($first, $this->at('01:00'));

        Assert::same($this->ledger->find($this->blob())?->state, BlobState::Writing);
        Assert::null($this->ledger->claimForDeletion($this->at('02:00'), $this->at('02:05')));
    }

    public function abandoningADeletionReturnsTheBlobToTheQueueWithBackoff(): void
    {
        $this->ledger->release($this->reserve(), $this->at('01:00'));
        $lease = $this->ledger->claimForDeletion($this->at('01:00'), $this->at('01:05'));

        Assert::true($this->ledger->abandonDeletion($lease ?? $this->forgedLease(), $this->at('02:00')));

        $record = $this->ledger->find($this->blob());
        Assert::same($record?->state, BlobState::PendingDelete);
        Assert::same($record?->deleteAfter?->format('H:i'), '02:00');
        Assert::null($record?->leaseExpiresAt);
    }

    public function abandoningWithoutALeaseIsRefused(): void
    {
        $this->ledger->release($this->reserve(), $this->at('01:00'));

        Assert::false($this->ledger->abandonDeletion($this->forgedLease(), $this->at('02:00')));
    }

    /**
     * A blob a collector is holding keeps its lease through a release of some
     * other claim. Only the holder, or the expiry, moves it.
     */
    public function schedulingDoesNotDisturbALeasedBlob(): void
    {
        $this->ledger->release($this->reserve(), $this->at('01:00'));
        $lease = $this->ledger->claimForDeletion($this->at('01:00'), $this->at('01:05'));

        $this->ledger->release($this->forgedReservation(), $this->at('03:00'));

        $record = $this->ledger->find($this->blob());
        Assert::same($record?->state, BlobState::Deleting);
        Assert::true($this->ledger->completeDeletion($lease ?? $this->forgedLease()));
    }

    public function reservingADeletingBlobIsRefusedAsBusy(): void
    {
        $this->ledger->release($this->reserve(), $this->at('01:00'));
        $this->ledger->claimForDeletion($this->at('01:00'), $this->at('01:05'));

        Expect::exception(BlobBusyException::class)->withMessageContaining('is being deleted');

        $this->reserve();
    }

    /**
     * Two blobs with the same content in different stores are two rows. A
     * ledger keyed by hash would have merged them, and a delete in one store
     * would have removed what the other still points at.
     */
    public function theSameContentInAnotherStoreIsADifferentBlob(): void
    {
        $this->reserve();
        $other = BlobId::create('archive', 'sha/e3/b0/original');
        $this->ledger->reserve($other, SqliteDatabase::HASH, 12, $this->at('00:10'));

        Assert::same($this->ledger->find($this->blob())?->reservationCount, 1);
        Assert::same($this->ledger->find($other)?->reservationCount, 1);
        Assert::same((new Query($this->database->db))->from('filestorage_blob')->count(), 2);
    }

    /**
     * A ledger row that cannot be read is loud, because the alternative is a
     * collector acting on a state or a counter it guessed.
     *
     * @param scalar|null $value
     */
    #[DataProvider('corruptBlobRowProvider')]
    public function aBlobRowThatCannotBeReadIsLoud(string $column, mixed $value, string $message): void
    {
        $this->reserve();
        $this->database->db->createCommand()
            ->update('filestorage_blob', [$column => $value], ['id' => BlobRowId::for($this->blob())])
            ->execute();

        Expect::exception(LedgerException::class)->withMessageContaining($message);

        $this->ledger->find($this->blob());
    }

    /**
     * @return iterable<string, array{string, scalar|null, string}>
     */
    public static function corruptBlobRowProvider(): iterable
    {
        // NOT NULL columns keep the null cases out of reach; what a corrupted
        // row can actually hold is a wrong *value*
        yield 'an unknown state' => ['state', 'confused', 'unknown state'];
        yield 'an empty hash' => ['content_hash', '', 'must hold a non-empty string'];
        yield 'a negative size' => ['size', -3, 'must hold a non-negative integer'];
        yield 'a negative numeric string size' => ['size', '-12', 'must hold a non-negative integer'];
        // Non-numeric text in `size` is unreachable here: `yiisoft/db` typecasts
        // on read from the column schema, so SQLite hands back an int whatever
        // was written. The string branch in the reader is for drivers that
        // return `bigint` as a string; `FileRowMapperTest` covers it directly.
    }

    /**
     * Every ledger statement has to name the blob it means. A guard reduced to
     * state and counters passes every single-blob test here and rewrites the
     * whole ledger the first time two blobs exist — and `['and', [], …]` is
     * not a contradiction in `yiisoft/db`, it is a clause that disappears.
     */
    public function schedulingOneBlobLeavesEveryOtherAlone(): void
    {
        $this->reserveOther();
        $this->ledger->release($this->reserve(), $this->at('01:00'));

        Assert::same($this->ledger->find($this->blob())?->state, BlobState::PendingDelete);
        Assert::same($this->ledger->find($this->otherBlob())?->state, BlobState::Writing);
    }

    /**
     * "Is anything holding this blob?" means *this* blob. A guard that asked
     * whether anything holds *any* blob would refuse to collect anything for
     * as long as the installation had one live file — which is always.
     */
    public function anotherBlobsReferencesAndClaimsDoNotBlockCollection(): void
    {
        $this->ledger->commit($this->reserveOther(), $this->fileOnOtherBlob('other'));
        $this->reserveOther();

        $this->ledger->release($this->reserve(), $this->at('01:00'));

        Assert::same($this->ledger->find($this->blob())?->state, BlobState::PendingDelete);
        Assert::instanceOf(
            $this->ledger->claimForDeletion($this->at('01:00'), $this->at('01:05')),
            BlobLease::class,
        );
    }

    public function claimingOneBlobDoesNotLeaseAnother(): void
    {
        $this->ledger->release($this->reserve(), $this->at('01:00'));
        $this->ledger->release($this->reserveOther(), $this->at('01:00'));

        $this->ledger->claimForDeletion($this->at('01:00'), $this->at('01:05'));

        $states = [
            $this->ledger->find($this->blob())?->state,
            $this->ledger->find($this->otherBlob())?->state,
        ];
        Assert::same(\count(array_filter(
            $states,
            static fn(?BlobState $s): bool => $s === BlobState::Deleting,
        )), 1);
    }

    public function completingADeletionRemovesOnlyItsOwnRow(): void
    {
        $this->ledger->release($this->reserve(), $this->at('01:00'));
        $this->ledger->release($this->reserveOther(), $this->at('01:00'));
        $first = $this->ledger->claimForDeletion($this->at('01:00'), $this->at('01:05'));
        $second = $this->ledger->claimForDeletion($this->at('01:00'), $this->at('01:05'));

        Assert::true($this->ledger->completeDeletion($first ?? $this->forgedLease()));

        Assert::same(
            $this->ledger->find(($second ?? $this->forgedLease())->blob)?->state,
            BlobState::Deleting,
        );
    }

    public function abandoningADeletionTouchesOnlyItsOwnRow(): void
    {
        $this->ledger->release($this->reserve(), $this->at('01:00'));
        $this->ledger->release($this->reserveOther(), $this->at('01:00'));
        $first = $this->ledger->claimForDeletion($this->at('01:00'), $this->at('01:05'));
        $second = $this->ledger->claimForDeletion($this->at('01:00'), $this->at('01:05'));

        Assert::true($this->ledger->abandonDeletion($first ?? $this->forgedLease(), $this->at('02:00')));

        Assert::same(
            $this->ledger->find(($second ?? $this->forgedLease())->blob)?->state,
            BlobState::Deleting,
        );
    }

    /**
     * A commit consumes exactly one reservation. Consuming by token alone, or
     * by blob alone, would take a claim another writer is still holding.
     */
    public function committingConsumesOnlyItsOwnReservation(): void
    {
        $mine = $this->reserve();
        $theirs = $this->reserve();
        $elsewhere = $this->reserveOther();

        $this->ledger->commit($mine, SqliteDatabase::file('a'));

        Assert::same($this->ledger->find($this->blob())?->reservationCount, 1);
        Assert::same($this->ledger->find($this->otherBlob())?->reservationCount, 1);
        // the survivors are still usable, which is what "not consumed" means
        $this->ledger->commit($theirs, SqliteDatabase::file('b'));
        Assert::same($this->ledger->find($this->blob())?->referenceCount, 2);
        Assert::same($elsewhere->blob->key(), $this->otherBlob()->key());
    }

    public function committingOneBlobDoesNotActivateAnother(): void
    {
        $this->reserveOther();

        $this->ledger->commit($this->reserve(), SqliteDatabase::file('a'));

        Assert::same($this->ledger->find($this->otherBlob())?->state, BlobState::Writing);
    }

    public function revivingOneBlobDoesNotTouchAnother(): void
    {
        $this->ledger->release($this->reserveOther(), $this->at('02:00'));
        $this->ledger->release($this->reserve(), $this->at('01:00'));

        $this->reserve();

        Assert::same($this->ledger->find($this->blob())?->state, BlobState::Writing);
        $other = $this->ledger->find($this->otherBlob());
        Assert::same($other?->state, BlobState::PendingDelete);
        Assert::same($other?->deleteAfter?->format('H:i'), '02:00');
    }

    /**
     * The candidate scan and the claim are two statements, so a blob can gain
     * a reference between them. The claim has to notice, and the caller has to
     * read the affected-row count rather than assume the update did something.
     */
    public function aScheduledBlobThatRegainedAReferenceIsNotCollected(): void
    {
        $this->ledger->release($this->reserve(), $this->at('01:00'));
        $this->repository->save(SqliteDatabase::file('a'), $this->blob());

        Assert::null($this->ledger->claimForDeletion($this->at('01:00'), $this->at('01:05')));
        Assert::same($this->ledger->find($this->blob())?->state, BlobState::PendingDelete);
    }

    private function reserve(string $hash = SqliteDatabase::HASH, int $size = 12): BlobReservation
    {
        return $this->ledger->reserve($this->blob(), $hash, $size, $this->at('00:10'));
    }

    private function reserveWith(DbBlobLedger $ledger): BlobReservation
    {
        return $ledger->reserve($this->blob(), SqliteDatabase::HASH, 12, $this->at('00:10'));
    }

    private function reserveOther(): BlobReservation
    {
        return $this->ledger->reserve($this->otherBlob(), SqliteDatabase::OTHER_HASH, 34, $this->at('00:10'));
    }

    private function fileOnOtherBlob(string $id): File
    {
        return SqliteDatabase::file($id, relativePath: $this->otherBlob()->relativePath());
    }

    private function blob(): BlobId
    {
        return BlobId::create('upload', 'sha/e3/b0/original');
    }

    private function otherBlob(): BlobId
    {
        return BlobId::create('upload', 'sha/da/39/original');
    }

    private function forgedReservation(): BlobReservation
    {
        return new BlobReservation($this->blob(), new BlobToken('deadbeefdeadbeef'), $this->at('00:10'));
    }

    private function forgedLease(): BlobLease
    {
        return new BlobLease($this->blob(), new BlobToken('deadbeefdeadbeef'), $this->at('01:05'));
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable("2026-01-01T{$time}:00.000000+00:00");
    }
}
