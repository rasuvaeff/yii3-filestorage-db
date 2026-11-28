<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Tests\Integration;

use DateTimeImmutable;
use Rasuvaeff\Yii3Filestorage\Exception\BlobBusyException;
use Rasuvaeff\Yii3Filestorage\Store\BlobId;
use Rasuvaeff\Yii3Filestorage\Store\BlobState;
use Rasuvaeff\Yii3FilestorageDb\DbBlobLedger;
use Rasuvaeff\Yii3FilestorageDb\DbRepository;
use Rasuvaeff\Yii3FilestorageDb\Tests\Support\InterleavingScope;
use Rasuvaeff\Yii3FilestorageDb\Tests\Support\ServerDatabase;
use Rasuvaeff\Yii3FilestorageDb\Tests\Support\SqliteDatabase;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Test\Support\Clock\StaticClock;

/**
 * The ledger with two connections open on one database.
 *
 * Every guarantee this package makes is about what happens when two writers,
 * or a writer and a collector, act on the same blob at once — and the unit
 * suite cannot express any of it. `:memory:` gives each connection its own
 * empty database, so a "second process" there shares nothing; a file does.
 *
 * These are the §16 barrier cases. The invariant they all check is one
 * sentence: **no committed file row may reference bytes that are not there.**
 * Everything else — a leaked object, a blob collected a cycle late — is
 * recoverable. That one is not.
 *
 * A separate suite because it is slower and because `composer test` runs Unit
 * only; CI invokes it explicitly.
 *
 * On SQLite by default, which serialises every write on the whole database —
 * so the snapshot rules that actually differ between engines (PostgreSQL READ
 * COMMITTED, InnoDB locking reads in DML subqueries) are only exercised when
 * `FILESTORAGE_TEST_DB` names a server: then the two workers are two
 * connections to MySQL or PostgreSQL, and on MySQL both run under an unusual
 * `sql_mode`. The cases stay sequential interleavings of committed
 * statements; one worker blocking on the other's lock would hang a
 * single-process suite.
 */
#[Test]
#[CoversNothing]
final class LedgerConcurrencyTest
{
    private const string HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    private ?SqliteDatabase $database = null;
    private ConnectionInterface $first;
    private ConnectionInterface $second;

    /** Two ledgers, two connections, one database — as if two workers. */
    private DbBlobLedger $alice;
    private DbBlobLedger $bob;
    private DbRepository $aliceFiles;
    private DbRepository $bobFiles;

    #[BeforeTest]
    public function setUp(): void
    {
        $engine = ServerDatabase::engine();
        if ($engine === null) {
            $this->database = new SqliteDatabase(shared: true);
            $this->first = $this->database->db;
            $this->second = $this->database->connect();
        } else {
            $this->first = ServerDatabase::connect($engine, ServerDatabase::UNUSUAL_SQL_MODE);
            $this->second = ServerDatabase::connect($engine, ServerDatabase::UNUSUAL_SQL_MODE);
            ServerDatabase::reset($this->first);
        }

        $this->aliceFiles = new DbRepository($this->first);
        $this->bobFiles = new DbRepository($this->second);
        $this->alice = $this->ledgerOn($this->first, $this->aliceFiles);
        $this->bob = $this->ledgerOn($this->second, $this->bobFiles);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        if ($this->database === null) {
            ServerDatabase::drop($this->first);
            $this->first->close();
        } else {
            $this->database->close();
        }
        $this->second->close();
    }

    /**
     * Two first adds. Both reserve, both publish the same bytes, both commit —
     * and the result is one blob with two references, not two blobs or one
     * writer's row silently lost.
     */
    public function twoSimultaneousFirstAddsConvergeOnOneBlob(): void
    {
        $aliceReservation = $this->alice->reserve($this->blob(), self::HASH, 12, $this->at('00:10'));
        $bobReservation = $this->bob->reserve($this->blob(), self::HASH, 12, $this->at('00:10'));

        // Interleaved on purpose: each commits after the other has reserved.
        $this->alice->commit($aliceReservation, SqliteDatabase::file('a'));
        $this->bob->commit($bobReservation, SqliteDatabase::file('b'));

        $record = $this->alice->find($this->blob());
        Assert::same($record?->state, BlobState::Active);
        Assert::same($record?->referenceCount, 2);
        Assert::same($record?->reservationCount, 0);
        Assert::same($this->blobRowCount(), 1);
    }

    /**
     * The race the whole design exists to survive: one writer arrives while
     * the last reference is being removed. Either it joins the revived blob or
     * it is told the blob is busy — never a committed row over deleted bytes.
     */
    public function anAddRacingTheLastRemoveNeverCommitsOverMissingBytes(): void
    {
        $this->alice->commit(
            $this->alice->reserve($this->blob(), self::HASH, 12, $this->at('00:10')),
            SqliteDatabase::file('a'),
        );

        // Alice removes the last reference; the blob is scheduled, not deleted.
        Assert::true($this->alice->releaseFile('a', $this->at('01:00')));
        Assert::same($this->alice->find($this->blob())?->state, BlobState::PendingDelete);

        // Bob arrives before the grace period ends and revives it.
        $bobReservation = $this->bob->reserve($this->blob(), self::HASH, 12, $this->at('00:20'));
        $this->bob->commit($bobReservation, SqliteDatabase::file('b'));

        // And now the collector runs. It must not take a blob Bob references.
        Assert::null($this->alice->claimForDeletion($this->at('02:00'), $this->at('02:05')));
        Assert::same($this->alice->find($this->blob())?->state, BlobState::Active);
        Assert::same($this->bobFiles->find('b')?->id, 'b');
    }

    /**
     * A collector holding a lease and a writer arriving for the same content.
     * The writer is refused rather than joining bytes that are being deleted —
     * the one case where "come back later" is the safe answer.
     */
    public function anAddRacingTheCollectorIsToldToRetry(): void
    {
        $this->alice->release(
            $this->alice->reserve($this->blob(), self::HASH, 12, $this->at('00:10')),
            $this->at('01:00'),
        );
        $lease = $this->alice->claimForDeletion($this->at('01:00'), $this->at('01:05'));
        Assert::true($lease instanceof \Rasuvaeff\Yii3Filestorage\Store\BlobLease);

        $refused = false;

        try {
            $this->bob->reserve($this->blob(), self::HASH, 12, $this->at('01:10'));
        } catch (BlobBusyException) {
            $refused = true;
        }

        Assert::true($refused, 'a writer must not join a blob under a deletion lease');

        // Once the collector finishes, the same call succeeds — and creates a
        // fresh blob rather than resurrecting the deleted row.
        Assert::true($this->alice->completeDeletion($lease));
        $this->bob->reserve($this->blob(), self::HASH, 12, $this->at('01:20'));
        Assert::same($this->bob->find($this->blob())?->state, BlobState::Writing);
    }

    /**
     * The other order: the collector claims, and a writer commits before it
     * gets to delete. The conditional delete has to notice.
     */
    public function aCollectorCannotDeleteABlobThatWasReferencedAfterItsClaim(): void
    {
        $this->alice->release(
            $this->alice->reserve($this->blob(), self::HASH, 12, $this->at('00:10')),
            $this->at('01:00'),
        );
        $lease = $this->alice->claimForDeletion($this->at('01:00'), $this->at('01:05'));

        // Bob's row lands between the claim and the delete.
        $this->bobFiles->save(SqliteDatabase::file('b'), $this->blob());

        Assert::false($this->alice->completeDeletion($lease ?? $this->fail()));
        Assert::true($this->alice->find($this->blob()) instanceof \Rasuvaeff\Yii3Filestorage\Store\BlobRecord, 'the blob must survive');
        Assert::same($this->bobFiles->find('b')?->id, 'b');
    }

    /**
     * Two collectors, one blob. Exactly one gets the lease, and only that one
     * can finish — otherwise both would delete the same object and the second
     * would remove a row the first had already replaced.
     */
    public function twoCollectorsCannotHoldTheSameBlob(): void
    {
        $this->alice->release(
            $this->alice->reserve($this->blob(), self::HASH, 12, $this->at('00:10')),
            $this->at('01:00'),
        );

        $first = $this->alice->claimForDeletion($this->at('01:00'), $this->at('01:05'));
        $second = $this->bob->claimForDeletion($this->at('01:01'), $this->at('01:06'));

        Assert::true($first instanceof \Rasuvaeff\Yii3Filestorage\Store\BlobLease);
        Assert::null($second, 'the second collector must find nothing while the lease is live');
        Assert::true($this->alice->completeDeletion($first));
    }

    /**
     * Process death after every transition. Each case kills the writer at a
     * different point and checks that the ledger converges — through lease
     * expiry or the reservation sweep — without ever having left a committed
     * row without bytes.
     */
    public function deathAfterReserveIsReclaimedBySweeping(): void
    {
        // Alice reserves and vanishes.
        $this->alice->reserve($this->blob(), self::HASH, 12, $this->at('00:10'));

        // Bob's collector runs later and finds an abandoned claim.
        Assert::same($this->bob->expireReservations($this->at('00:30'), $this->at('01:00')), 1);
        Assert::same($this->bob->find($this->blob())?->state, BlobState::PendingDelete);

        $lease = $this->bob->claimForDeletion($this->at('01:00'), $this->at('01:05'));
        Assert::true($lease instanceof \Rasuvaeff\Yii3Filestorage\Store\BlobLease);
        Assert::true($this->bob->completeDeletion($lease));
        Assert::same($this->blobRowCount(), 0);
    }

    public function deathAfterPublishButBeforeCommitLeavesNoRow(): void
    {
        // Reserve, "write the bytes", die. The reservation is all that is left.
        $this->alice->reserve($this->blob(), self::HASH, 12, $this->at('00:10'));

        $this->bob->expireReservations($this->at('00:30'), $this->at('01:00'));

        Assert::same(\count(iterator_to_array($this->bobFiles->files(), preserve_keys: false)), 0);
        Assert::same($this->bob->find($this->blob())?->state, BlobState::PendingDelete);
    }

    public function deathAfterCommitLeavesAUsableFile(): void
    {
        $this->alice->commit(
            $this->alice->reserve($this->blob(), self::HASH, 12, $this->at('00:10')),
            SqliteDatabase::file('a'),
        );

        // A sweep and a collection pass by another worker must leave it alone.
        $this->bob->expireReservations($this->at('02:00'), $this->at('03:00'));

        Assert::null($this->bob->claimForDeletion($this->at('04:00'), $this->at('04:05')));
        Assert::same($this->bobFiles->find('a')?->id, 'a');
        Assert::same($this->bob->find($this->blob())?->referenceCount, 1);
    }

    /**
     * The collector dying mid-delete: the bytes may or may not be gone, and
     * the row is still marked `deleting`. The next collector steals the lease
     * after it expires and repeats an idempotent delete.
     */
    public function deathUnderALeaseIsRecoveredByTheNextCollector(): void
    {
        $this->alice->release(
            $this->alice->reserve($this->blob(), self::HASH, 12, $this->at('00:10')),
            $this->at('01:00'),
        );
        $abandoned = $this->alice->claimForDeletion($this->at('01:00'), $this->at('01:05'));

        // Alice never finishes. Bob comes along after the lease runs out.
        $stolen = $this->bob->claimForDeletion($this->at('01:05'), $this->at('01:10'));

        Assert::true($stolen instanceof \Rasuvaeff\Yii3Filestorage\Store\BlobLease);
        Assert::false(
            $this->alice->completeDeletion($abandoned ?? $this->fail()),
            'the abandoned lease must no longer be able to delete',
        );
        Assert::true($this->bob->completeDeletion($stolen));
        Assert::same($this->blobRowCount(), 0);
    }

    /**
     * A collector died under its lease and the lease ran out before the next
     * `gc`. A writer on the other connection joins instead of being told to
     * retry until then, and the dead collector's lease can no longer delete.
     */
    public function aWriterJoinsOnceADeadCollectorsLeaseRanOut(): void
    {
        $this->alice->release(
            $this->alice->reserve($this->blob(), self::HASH, 12, $this->at('00:10')),
            $this->at('01:00'),
        );
        $abandoned = $this->alice->claimForDeletion($this->at('01:00'), $this->at('01:05'));

        $bob = $this->ledgerOn($this->second, $this->bobFiles, '01:06');
        $bob->commit($bob->reserve($this->blob(), self::HASH, 12, $this->at('01:20')), SqliteDatabase::file('b'));

        Assert::false($this->alice->completeDeletion($abandoned ?? $this->fail()));
        Assert::same($this->alice->find($this->blob())?->state, BlobState::Active);
        Assert::same($this->bobFiles->find('b')?->id, 'b');
    }

    /**
     * The race the grace period alone used to cover, on the engine where it
     * is reachable: PostgreSQL READ COMMITTED. Bob joins an active blob and
     * has not committed his reservation; Alice removes the last reference
     * (no grace) and her collector, which cannot see Bob's uncommitted
     * reservation, claims the blob. Bob's commit must then refuse rather than
     * activate a blob under a deletion lease.
     *
     * Not reachable on SQLite (one writer per database) or InnoDB, whose
     * `NOT EXISTS` in an `UPDATE` is a locking read that waits for Bob — a
     * single-process test would hang there instead of racing.
     */
    public function aCommitRefusesABlobClaimedBehindAnUncommittedReservation(): void
    {
        if (ServerDatabase::engine() !== 'pgsql') {
            Assert::true(actual: true, message: 'reachable on PostgreSQL only');

            return;
        }

        $this->alice->commit(
            $this->alice->reserve($this->blob(), self::HASH, 12, $this->at('00:10')),
            SqliteDatabase::file('a'),
        );

        $transaction = $this->second->beginTransaction();

        try {
            $reservation = $this->bob->reserve($this->blob(), self::HASH, 12, $this->at('00:10'));

            Assert::true($this->alice->releaseFile('a', $this->at('00:00')));
            $lease = $this->alice->claimForDeletion($this->at('00:00'), $this->at('00:05'));
            Assert::true($lease instanceof \Rasuvaeff\Yii3Filestorage\Store\BlobLease, 'the collector cannot see the reservation yet');

            $transaction->commit();
        } finally {
            // an assertion failing above must not leave Bob's locks behind:
            // tearDown's DROP TABLE would wait on them and hang the job
            if ($transaction->isActive()) {
                $transaction->rollBack();
            }
        }

        $refused = false;

        try {
            $this->bob->commit($reservation, SqliteDatabase::file('b'));
        } catch (\Rasuvaeff\Yii3Filestorage\Exception\LedgerException) {
            $refused = true;
        }

        Assert::true($refused, 'a commit must not activate a blob under a deletion lease');
        Assert::null($this->aliceFiles->find('b'));
        Assert::same($this->alice->find($this->blob())?->state, BlobState::Deleting);
    }

    /**
     * Bob commits the same blob while Alice's commit is between its first read
     * and its activation `UPDATE`. MariaDB 11.6.2+ under REPEATABLE READ
     * (`innodb_snapshot_isolation=ON`) failed Alice with error 1020 — the
     * second of two simultaneous uploads of one file was an `AddException`.
     * The ledger runs its own transactions at READ COMMITTED on the MySQL
     * driver, so both commits land.
     */
    public function aCommitOverlappingAnotherCommitOfTheSameBlobLands(): void
    {
        [$alice, $aliceScope, $bob] = $this->interleavedWorkers();
        if ($alice === null) {
            Assert::true(actual: true, message: 'needs a server: SQLite serialises the two writers');

            return;
        }

        $aliceReservation = $alice->reserve($this->blob(), self::HASH, 12, $this->at('00:10'));
        $bobReservation = $bob->reserve($this->blob(), self::HASH, 12, $this->at('00:10'));

        // call 1 is the read of the row's previous blob, call 2 the save
        $aliceScope->runOnCall(2, fn() => $bob->commit($bobReservation, SqliteDatabase::file('b')));
        $alice->commit($aliceReservation, SqliteDatabase::file('a'));

        $record = $this->alice->find($this->blob());
        Assert::same($record?->state, BlobState::Active);
        Assert::same($record?->referenceCount, 2);
        Assert::same($record?->reservationCount, 0);
    }

    /**
     * Alice removes a file while Bob commits another upload of the same bytes
     * between her read of the blob and her scheduling `UPDATE`. On MariaDB
     * under REPEATABLE READ that was error 1020, a `RemoveException` to the
     * user. Now the removal lands and Bob's reference keeps the blob active.
     */
    public function releasingAFileWhileTheSameBlobIsCommittedLands(): void
    {
        [$alice, $aliceScope, $bob] = $this->interleavedWorkers();
        if ($alice === null) {
            Assert::true(actual: true, message: 'needs a server: SQLite serialises the two writers');

            return;
        }

        $alice->commit(
            $alice->reserve($this->blob(), self::HASH, 12, $this->at('00:10')),
            SqliteDatabase::file('a'),
        );
        $bobReservation = $bob->reserve($this->blob(), self::HASH, 12, $this->at('00:10'));

        // call 1 reads the file's blob, call 2 is the delete of its row
        $aliceScope->runOnCall(2, fn() => $bob->commit($bobReservation, SqliteDatabase::file('b')));
        Assert::true($alice->releaseFile('a', $this->at('01:00')));

        $record = $this->alice->find($this->blob());
        Assert::same($record?->state, BlobState::Active);
        Assert::same($record?->referenceCount, 1);
    }

    /**
     * Inside a transaction the application opened, the ledger cannot choose
     * the isolation level — `yiisoft/db` only sets it on the outermost one.
     * On MariaDB 11.6.2+ under REPEATABLE READ the overlapping commit then
     * fails, and must fail as a `LedgerException` naming the cause, not as a
     * driver `IntegrityException` (which `reserve()` would mistake for a
     * duplicate-insert race). Elsewhere it lands.
     */
    public function anOverlappingCommitInsideAnApplicationTransactionFailsAsALedgerException(): void
    {
        [$alice, $aliceScope, $bob] = $this->interleavedWorkers();
        if ($alice === null) {
            Assert::true(actual: true, message: 'needs a server: SQLite serialises the two writers');

            return;
        }

        $aliceReservation = $alice->reserve($this->blob(), self::HASH, 12, $this->at('00:10'));
        $bobReservation = $bob->reserve($this->blob(), self::HASH, 12, $this->at('00:10'));

        $transaction = $this->first->beginTransaction();
        $failure = null;

        try {
            $aliceScope->runOnCall(2, fn() => $bob->commit($bobReservation, SqliteDatabase::file('b')));
            $alice->commit($aliceReservation, SqliteDatabase::file('a'));
            $transaction->commit();
        } catch (\Rasuvaeff\Yii3Filestorage\Exception\LedgerException $e) {
            $failure = $e;
        } finally {
            if ($transaction->isActive()) {
                $transaction->rollBack();
            }
        }

        if (!$this->snapshotIsolated()) {
            Assert::null($failure);
            Assert::same($this->alice->find($this->blob())?->referenceCount, 2);

            return;
        }

        Assert::true($failure instanceof \Rasuvaeff\Yii3Filestorage\Exception\LedgerException, 'expected a LedgerException');
        Assert::string($failure?->getMessage() ?? '')->contains('READ COMMITTED');
        Assert::same($this->alice->find($this->blob())?->referenceCount, 1, 'Bob\'s commit stands');
    }

    /**
     * Interleaved adds and removes on one blob, run through both connections.
     * The property being checked is the invariant itself: at no point does a
     * committed row exist while the blob it names does not.
     */
    public function noSequenceOfInterleavedOperationsLeavesARowWithoutABlob(): void
    {
        $ledgers = [$this->alice, $this->bob];
        $files = [$this->aliceFiles, $this->bobFiles];

        for ($round = 0; $round < 12; $round++) {
            $writer = $ledgers[$round % 2];
            $id = "f{$round}";

            $writer->commit(
                $writer->reserve($this->blob(), self::HASH, 12, $this->at('00:10')),
                SqliteDatabase::file($id),
            );

            // Every other round, the *other* worker removes an older row and
            // runs a collection pass in the same breath.
            if ($round % 2 === 1) {
                $other = $ledgers[($round + 1) % 2];
                $other->releaseFile('f' . ($round - 1), $this->at('00:00'));
                $other->expireReservations($this->at('00:30'), $this->at('00:00'));

                while ($lease = $other->claimForDeletion($this->at('01:00'), $this->at('01:05'))) {
                    $other->completeDeletion($lease);
                }
            }

            $this->assertEveryRowHasItsBlob($files[0]);
        }
    }

    private function assertEveryRowHasItsBlob(DbRepository $repository): void
    {
        foreach ($repository->files() as $file) {
            $blobRowId = $repository->findBlobRowId($file->id);
            if ($blobRowId === null) {
                continue;
            }

            Assert::true(
                $this->alice->find(BlobId::create($file->storeName, $file->relativePath)) instanceof \Rasuvaeff\Yii3Filestorage\Store\BlobRecord,
                "file \"{$file->id}\" references a blob that no longer exists",
            );
        }
    }

    /**
     * Alice and Bob under one tenant, with a hook inside Alice's statements;
     * nulls on SQLite, where the second writer would wait on the first.
     *
     * @return array{0: DbBlobLedger|null, 1: InterleavingScope, 2: DbBlobLedger}
     */
    private function interleavedWorkers(): array
    {
        $scope = new InterleavingScope();
        if ($this->database !== null) {
            return [null, $scope, $this->bob];
        }

        return [
            $this->ledgerOn($this->first, new DbRepository($this->first, scopes: $scope)),
            $scope,
            $this->ledgerOn($this->second, new DbRepository($this->second, scopes: new InterleavingScope()), '00:01'),
        ];
    }

    /**
     * Whether the server fails a REPEATABLE READ update of a row changed after
     * the snapshot: MariaDB 11.6.2+ with its default `innodb_snapshot_isolation`.
     */
    private function snapshotIsolated(): bool
    {
        if (ServerDatabase::engine() !== 'mariadb') {
            return false;
        }

        return (int) $this->first->createCommand('SELECT @@innodb_snapshot_isolation')->queryScalar() === 1;
    }

    private function ledgerOn(ConnectionInterface $db, DbRepository $repository, string $time = '00:00'): DbBlobLedger
    {
        return new DbBlobLedger(
            db: $db,
            repository: $repository,
            clock: new StaticClock($this->at($time)),
        );
    }

    private function blobRowCount(): int
    {
        return (int) (new \Yiisoft\Db\Query\Query($this->first))->from('filestorage_blob')->count();
    }

    private function blob(): BlobId
    {
        return BlobId::create('upload', 'sha/e3/b0/original');
    }

    /**
     * Only reached if an `Assert` above already failed, and only there so the
     * null-safety reads without `?->` on something the test just proved.
     */
    private function fail(): never
    {
        throw new \RuntimeException('unreachable: the assertion above should have stopped the test');
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable("2026-01-01T{$time}:00.000000+00:00");
    }
}
