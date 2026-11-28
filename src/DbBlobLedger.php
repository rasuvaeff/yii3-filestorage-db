<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb;

use Closure;
use DateInterval;
use DateTimeImmutable;
use Override;
use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Filestorage\Exception\BlobBusyException;
use Rasuvaeff\Yii3Filestorage\Exception\LedgerException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Store\BlobId;
use Rasuvaeff\Yii3Filestorage\Store\BlobLease;
use Rasuvaeff\Yii3Filestorage\Store\BlobLedgerBatchLookupInterface;
use Rasuvaeff\Yii3Filestorage\Store\BlobRecord;
use Rasuvaeff\Yii3Filestorage\Store\BlobReservation;
use Rasuvaeff\Yii3Filestorage\Store\BlobState;
use Rasuvaeff\Yii3Filestorage\Store\BlobToken;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Exception\Exception as DbException;
use Yiisoft\Db\Exception\IntegrityException;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;
use Yiisoft\Db\Transaction\TransactionInterface;

/**
 * The blob ledger, in a database.
 *
 * Two rules shape every method here.
 *
 * **The counters are never read into PHP and then acted on.** Every transition
 * that could lose data — claiming a blob for deletion, deleting its row —
 * carries its guards in the same statement that performs it, and the decision
 * is the affected-row count. A `SELECT` followed by an `UPDATE` is two moments
 * with a gap between them, and that gap is where a committed file row ends up
 * pointing at bytes another process has already removed.
 *
 * **File rows are written through {@see DbRepository}, never here.** The
 * mandatory tenant predicate lives there. A ledger issuing its own
 * `DELETE FROM filestorage_file WHERE id = :id` would be a cross-tenant delete
 * by id — the exact hole the scoped repository exists to close. The ledger owns
 * the transaction; the repository owns the row.
 *
 * **Its own transactions run at READ COMMITTED on the MySQL driver.** Each
 * reads a row and then updates it under a guard that re-checks the row at
 * update time, so none relies on its snapshot. MariaDB 11.6.2+ with
 * `innodb_snapshot_isolation=ON` (its default) fails such an `UPDATE` under
 * REPEATABLE READ with error 1020 as soon as another connection changed the
 * row after the snapshot — two simultaneous uploads of one file, or a removal
 * during an upload of the same bytes. Called inside a transaction the
 * application opened, the ledger cannot set the level (`yiisoft/db` sets it
 * only on the outermost one); there error 1020 becomes a `LedgerException`
 * naming the cause, and the application retries its transaction or runs it at
 * READ COMMITTED. PostgreSQL already defaults to READ COMMITTED; SQLite
 * serialises writers and accepts no other level.
 *
 * **Lookups batch.** It implements core's optional
 * {@see BlobLedgerBatchLookupInterface}: `filestorage:gc` asks about every
 * object of a quarantine page in one call, answered by one statement per
 * {@see self::FIND_MANY_CHUNK} blobs — rows and both counts together — instead
 * of three statements per blob.
 *
 * There is no reference counter. A reference *is* a `filestorage_file` row
 * carrying this blob's id, and the guards ask `NOT EXISTS`. A counter would be
 * a second source of truth that can drift from the first, and every underflow
 * guard in the world only detects the drift after it has happened.
 *
 * @api
 */
final readonly class DbBlobLedger implements BlobLedgerBatchLookupInterface
{
    /**
     * Blobs looked up by one {@see self::findMany()} statement. A longer page
     * is split, so no driver meets an unbounded `IN` list (SQLite before 3.32
     * allows 999 bound parameters).
     */
    public const int FIND_MANY_CHUNK = 500;

    private string $files;
    private string $blobs;
    private string $reservations;

    /**
     * @param DateInterval $deleteGracePeriod How long a blob that `commit()`
     *        repointed a row away from waits before a collector may take it —
     *        readers may still hold its old path. Must be positive.
     *
     * @throws \InvalidArgumentException When the grace period is not positive.
     */
    public function __construct(
        private ConnectionInterface $db,
        private DbRepository $repository,
        private ClockInterface $clock,
        FileTableName $fileTable = new FileTableName(),
        BlobTableName $blobTable = new BlobTableName(),
        BlobReservationTableName $reservationTable = new BlobReservationTableName(),
        private DateInterval $deleteGracePeriod = new DateInterval('PT1H'),
    ) {
        PositiveInterval::require($deleteGracePeriod, 'deleteGracePeriod');
        $this->files = $fileTable->value;
        $this->blobs = $blobTable->value;
        $this->reservations = $reservationTable->value;
    }

    /**
     * Whether this ledger and that repository would share one transaction.
     *
     * `commit()` puts a file row and a blob reference in the same transaction,
     * which is only true if both speak to the same connection object. Two
     * connections to the same database look identical in every test and are
     * two transactions in production.
     */
    public function sharesConnectionWith(DbRepository $repository): bool
    {
        return $repository->usesConnection($this->db);
    }

    #[Override]
    public function reserve(
        BlobId $blob,
        string $contentHash,
        int $size,
        DateTimeImmutable $expiresAt,
    ): BlobReservation {
        $id = BlobRowId::for($blob);
        $token = BlobToken::random();

        try {
            $this->claimBlob($id, $blob, $token, $contentHash, $size, $expiresAt);
        } catch (IntegrityException) {
            // Another writer created the same content key between our read and
            // our insert — the expected outcome of two people uploading the
            // same file, not an error. Retry once: the row exists now, so the
            // second attempt takes the join path.
            //
            // The retry is deliberately *outside* the transaction. PostgreSQL
            // aborts a whole transaction on any failed statement, so catching
            // the violation inside it and re-reading would only produce
            // "current transaction is aborted" for everything after. SQLite and
            // MySQL are more forgiving, which is exactly why that shape passes
            // a SQLite test suite and fails in production.
            try {
                $this->claimBlob($id, $blob, $token, $contentHash, $size, $expiresAt);
            } catch (IntegrityException $retried) {
                // Once is a race; twice is the row appearing and vanishing
                // under us, or a token collision. Either way this method
                // promises only BlobBusyException and LedgerException, so a
                // raw driver exception must not be what the caller sees.
                throw new LedgerException("Blob \"{$blob->key()}\" could not be reserved", 0, $retried);
            }
        }

        return new BlobReservation($blob, $token, $expiresAt);
    }

    #[Override]
    public function commit(BlobReservation $reservation, File $file): void
    {
        $id = BlobRowId::for($reservation->blob);

        if (
            $file->storeName !== $reservation->blob->storeName
            || $file->relativePath !== $reservation->blob->relativePath()
        ) {
            throw new LedgerException(
                "File \"{$file->id}\" does not point at blob \"{$reservation->blob->key()}\"",
            );
        }

        $this->transaction(function () use ($id, $reservation, $file): void {
            $released = $this->db->createCommand()
                ->delete($this->reservations, ['token' => $reservation->token->value, 'blob_id' => $id])
                ->execute();

            // Deleting the reservation is how the commit claims it: if the row
            // is gone the reservation was never issued, or the sweep already
            // took it, and committing against it would produce a file row the
            // ledger is not protecting.
            if ($released === 0) {
                throw new LedgerException("Unknown reservation for blob \"{$reservation->blob->key()}\"");
            }

            // The blob this row pointed at before, read through the scoped
            // repository like everything else. A row repointed to another
            // content key — `filestorage:deduplicate` with another scope, key
            // generator or extension map — leaves that blob behind, and
            // nothing else would ever schedule it: it stayed `active` with no
            // reference, invisible to the collector and to the orphan sweep.
            $previous = $this->repository->findBlobRowId($file->id);

            $this->repository->save($file, $reservation->blob);

            // Guarded and counted, like every other transition here. Without
            // it this update cannot tell "activated" from "the blob row is
            // gone" — and if a collector completed its deletion in between,
            // the file row would be inserted anyway. The blob would then never
            // be scheduled again (scheduleIfUnused() no-ops on a missing row)
            // and the object would leak for good.
            //
            // Not from `deleting` either. The join reads the state with a plain
            // SELECT, so a collector whose snapshot did not yet see this
            // writer's reservation can have claimed the blob since; activating
            // it then erased the lease while the collector deleted the bytes.
            //
            // The lease columns are cleared too: an active blob holding a
            // stale lease makes find() report a lease nobody holds.
            $activated = $this->db->createCommand()->update(
                $this->blobs,
                [
                    'state' => BlobState::Active->value,
                    'delete_after' => null,
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'updated_at' => Timestamps::toStorage($this->clock->now()),
                ],
                ['and', ['id' => $id], ['not', ['state' => BlobState::Deleting->value]]],
            )->execute();

            // MySQL reports *changed* rows, not matched ones (CLIENT_FOUND_ROWS
            // is off by default, and the connection is the application's). A
            // second commit joining an active blob at the same instant writes
            // only values the row already holds and reads 0 there. So a 0 is
            // settled by reading the row again — with a locking read: inside an
            // application's REPEATABLE READ transaction a plain SELECT answers
            // from the snapshot that transaction started with, which still
            // shows the state the guard just failed on.
            if ($activated === 0) {
                $current = $this->currentBlobRow($id);
                if ($current === null) {
                    throw new LedgerException(
                        "Blob \"{$reservation->blob->key()}\" disappeared while its reservation was being committed",
                    );
                }
                if ($this->state($current) === BlobState::Deleting) {
                    throw new LedgerException(
                        "Blob \"{$reservation->blob->key()}\" is being deleted; its reservation cannot be committed",
                    );
                }
            }

            if ($previous !== null && $previous !== $id) {
                $this->scheduleIfUnused($previous, $this->clock->now()->add($this->deleteGracePeriod));
            }
        });
    }

    #[Override]
    public function release(BlobReservation $reservation, DateTimeImmutable $deleteAfter): void
    {
        $id = BlobRowId::for($reservation->blob);

        $this->transaction(function () use ($id, $reservation, $deleteAfter): void {
            $this->db->createCommand()
                ->delete($this->reservations, ['token' => $reservation->token->value, 'blob_id' => $id])
                ->execute();

            $this->scheduleIfUnused($id, $deleteAfter);
        });
    }

    #[Override]
    public function releaseFile(string $fileId, DateTimeImmutable $deleteAfter): bool
    {
        return $this->transaction(function () use ($fileId, $deleteAfter): bool {
            // read the blob through the scoped repository, before the row goes
            $blobRowId = $this->repository->findBlobRowId($fileId);

            if (!$this->repository->delete($fileId)) {
                return false;
            }
            if ($blobRowId !== null) {
                $this->scheduleIfUnused($blobRowId, $deleteAfter);
            }

            return true;
        });
    }

    #[Override]
    public function find(BlobId $blob): ?BlobRecord
    {
        $id = BlobRowId::for($blob);
        $row = $this->blobRow($id);
        if ($row === null) {
            return null;
        }

        return new BlobRecord(
            blob: $blob,
            contentHash: $this->string($row, 'content_hash'),
            size: $this->integer($row, 'size'),
            state: $this->state($row),
            referenceCount: $this->count($this->files, $id),
            reservationCount: $this->count($this->reservations, $id),
            deleteAfter: Timestamps::fromStorageOrNull($row['delete_after'] ?? null, 'delete_after'),
            leaseExpiresAt: Timestamps::fromStorageOrNull($row['lease_expires_at'] ?? null, 'lease_expires_at'),
        );
    }

    #[Override]
    public function findMany(array $blobs): array
    {
        /** @var array<non-empty-string, BlobId> $byRowId */
        $byRowId = [];
        foreach ($blobs as $blob) {
            $byRowId[BlobRowId::for($blob)] = $blob;
        }

        $found = [];
        foreach (array_chunk(array_keys($byRowId), self::FIND_MANY_CHUNK) as $ids) {
            foreach ($this->blobRowsWithCounts($ids) as $row) {
                $found[$this->string($row, 'id')] = $row;
            }
        }

        // In the order asked, as find() called blob by blob would answer.
        $records = [];
        foreach ($byRowId as $id => $blob) {
            $row = $found[$id] ?? null;
            if ($row === null) {
                continue;
            }

            $records[$blob->key()] = new BlobRecord(
                blob: $blob,
                contentHash: $this->string($row, 'content_hash'),
                size: $this->integer($row, 'size'),
                state: $this->state($row),
                referenceCount: max(0, (int) ($row['reference_count'] ?? 0)),
                reservationCount: max(0, (int) ($row['reservation_count'] ?? 0)),
                deleteAfter: Timestamps::fromStorageOrNull($row['delete_after'] ?? null, 'delete_after'),
                leaseExpiresAt: Timestamps::fromStorageOrNull($row['lease_expires_at'] ?? null, 'lease_expires_at'),
            );
        }

        return $records;
    }

    #[Override]
    public function expireReservations(DateTimeImmutable $now, DateTimeImmutable $deleteAfter): int
    {
        $at = Timestamps::toStorage($now);

        $removed = $this->transaction(function () use ($at, $deleteAfter): int {
            /** @var list<array<string, mixed>> $rows */
            $rows = (new Query($this->db))
                ->select('blob_id')
                ->distinct()
                ->from($this->reservations)
                ->where(['<=', 'expires_at', $at])
                ->all();

            $count = $this->db->createCommand()
                ->delete($this->reservations, ['<=', 'expires_at', $at])
                ->execute();

            foreach ($rows as $row) {
                if (isset($row['blob_id']) && \is_string($row['blob_id']) && $row['blob_id'] !== '') {
                    $this->scheduleIfUnused($row['blob_id'], $deleteAfter);
                }
            }

            return $count;
        });

        return max(0, $removed);
    }

    #[Override]
    public function claimForDeletion(DateTimeImmutable $now, DateTimeImmutable $leaseExpiresAt): ?BlobLease
    {
        $at = Timestamps::toStorage($now);
        $until = Timestamps::toStorage($leaseExpiresAt);

        /** @var list<array<string, mixed>> $candidates */
        $candidates = (new Query($this->db))
            ->select(['id', 'store_name', 'relative_path'])
            ->from($this->blobs)
            ->where($this->collectableCondition($at))
            ->orderBy(['id' => \SORT_ASC])
            ->limit(self::CLAIM_CANDIDATES)
            ->all();

        foreach ($candidates as $candidate) {
            $id = $this->string($candidate, 'id');
            $token = BlobToken::random();

            // The claim is one statement: the collectable test, both
            // emptiness guards and the lease are evaluated and written
            // together, so a writer that reserved in between loses the race
            // rather than being overwritten by it.
            $claimed = $this->db->createCommand()->update(
                $this->blobs,
                [
                    'state' => BlobState::Deleting->value,
                    'lease_token' => $token->value,
                    'lease_expires_at' => $until,
                    'updated_at' => $at,
                ],
                [
                    'and',
                    ['id' => $id],
                    $this->collectableCondition($at),
                    $this->unusedCondition($id),
                ],
            )->execute();

            if ($claimed > 0) {
                return new BlobLease(
                    blob: BlobId::create(
                        $this->string($candidate, 'store_name'),
                        $this->string($candidate, 'relative_path'),
                    ),
                    token: $token,
                    expiresAt: $leaseExpiresAt,
                );
            }
        }

        return null;
    }

    #[Override]
    public function completeDeletion(BlobLease $lease): bool
    {
        $id = BlobRowId::for($lease->blob);

        return $this->db->createCommand()->delete(
            $this->blobs,
            [
                'and',
                ['id' => $id, 'state' => BlobState::Deleting->value, 'lease_token' => $lease->token->value],
                $this->unusedCondition($id),
            ],
        )->execute() > 0;
    }

    #[Override]
    public function abandonDeletion(BlobLease $lease, DateTimeImmutable $retryAfter): bool
    {
        $id = BlobRowId::for($lease->blob);

        return $this->db->createCommand()->update(
            $this->blobs,
            [
                'state' => BlobState::PendingDelete->value,
                'delete_after' => Timestamps::toStorage($retryAfter),
                'lease_token' => null,
                'lease_expires_at' => null,
                'updated_at' => Timestamps::toStorage($this->clock->now()),
            ],
            ['id' => $id, 'state' => BlobState::Deleting->value, 'lease_token' => $lease->token->value],
        )->execute() > 0;
    }

    /**
     * @param non-empty-string $id
     * @param array<string, mixed> $row
     */
    private function joinBlob(
        string $id,
        BlobId $blob,
        array $row,
        string $contentHash,
        int $size,
        string $now,
    ): void {
        $state = $this->state($row);

        $storedHash = $this->string($row, 'content_hash');
        $storedSize = $this->integer($row, 'size');
        if ($storedHash !== $contentHash || $storedSize !== $size) {
            throw new LedgerException(
                "Blob \"{$blob->key()}\" already holds different content"
                . " (hash {$storedHash}, {$storedSize} bytes)",
            );
        }

        if ($state === BlobState::Deleting) {
            $this->joinExpiredDeletion($id, $blob, $now);

            return;
        }

        if ($state !== BlobState::PendingDelete) {
            return;
        }

        // Revived. Whether it goes back to active or to writing depends on
        // whether anything committed is still holding it — a blob scheduled
        // for deletion while its last reference was being removed can have
        // gained one again in between.
        //
        // Guarded on the state we read, and the affected count is checked.
        // The joinability decision above comes from a plain SELECT, so a
        // collector can claim the blob between that read and this write: its
        // `NOT EXISTS reservations` passes because this writer's reservation
        // is not inserted yet, it moves the row to `deleting` and takes a
        // lease. An unguarded update here would drag the row back out of
        // `deleting`, the writer would commit a file row, and the collector —
        // already past its own guard — would delete the bytes underneath it.
        // That is the one outcome this class exists to make impossible.
        $revived = $this->db->createCommand()->update(
            $this->blobs,
            [
                'state' => $this->count($this->files, $id) > 0
                    ? BlobState::Active->value
                    : BlobState::Writing->value,
                'delete_after' => null,
                'updated_at' => $now,
            ],
            ['id' => $id, 'state' => BlobState::PendingDelete->value],
        )->execute();

        if ($revived === 0) {
            // Somebody moved it while we were deciding. `deleting` is the case
            // that matters and the only one reachable from `pending_delete`;
            // either way the caller retries against a fresh read.
            throw new BlobBusyException(
                "Blob \"{$blob->key()}\" changed state while it was being joined. Retry",
            );
        }
    }

    /**
     * Takes a `deleting` blob back from a collector whose lease has run out.
     *
     * A collector that died between its claim and its delete leaves the row
     * `deleting` until the next `filestorage:gc`, and refusing every writer of
     * that content until then turned one crashed run into an outage of the
     * content. Joining is safe since the collector deletes through the
     * quarantine: the writer re-publishes with `putIfAbsent()`, a stale
     * collector's `completeDeletion()` is refused because its token is gone,
     * and it restores — keeping the bytes the writer put back.
     *
     * Guarded on the expiry in the statement itself; a live lease, or a row
     * another writer or collector changed first, is still busy.
     *
     * @param non-empty-string $id
     */
    private function joinExpiredDeletion(string $id, BlobId $blob, string $now): void
    {
        $joined = $this->db->createCommand()->update(
            $this->blobs,
            [
                // a row saved against the blob after the claim keeps it active,
                // exactly as on the revival from `pending_delete`
                'state' => $this->count($this->files, $id) > 0
                    ? BlobState::Active->value
                    : BlobState::Writing->value,
                'delete_after' => null,
                'lease_token' => null,
                'lease_expires_at' => null,
                'updated_at' => $now,
            ],
            [
                'and',
                ['id' => $id, 'state' => BlobState::Deleting->value],
                ['<=', 'lease_expires_at', $now],
            ],
        )->execute();

        if ($joined === 0) {
            throw new BlobBusyException(
                "Blob \"{$blob->key()}\" is being deleted. Retry once the deletion lease expires",
            );
        }
    }

    /**
     * Creates or joins the blob row and records this writer's claim, in one
     * transaction. Raises {@see IntegrityException} when another writer got
     * there first; {@see reserve()} retries the whole thing.
     *
     * @param non-empty-string $id
     */
    private function claimBlob(
        string $id,
        BlobId $blob,
        BlobToken $token,
        string $contentHash,
        int $size,
        DateTimeImmutable $expiresAt,
    ): void {
        $now = Timestamps::toStorage($this->clock->now());

        $this->transaction(function () use ($id, $blob, $token, $contentHash, $size, $expiresAt, $now): void {
            $row = $this->blobRow($id);

            if ($row === null) {
                $this->insertBlob($id, $blob, $contentHash, $size, $now);
            } else {
                $this->joinBlob($id, $blob, $row, $contentHash, $size, $now);
            }

            $this->db->createCommand()->insert($this->reservations, [
                'token' => $token->value,
                'blob_id' => $id,
                'expires_at' => Timestamps::toStorage($expiresAt),
                'created_at' => $now,
            ])->execute();
        });
    }

    /**
     * @param non-empty-string $id
     */
    private function insertBlob(string $id, BlobId $blob, string $contentHash, int $size, string $now): void
    {
        $this->db->createCommand()->insert($this->blobs, [
            'id' => $id,
            'store_name' => $blob->storeName,
            'relative_path' => $blob->relativePath(),
            'content_hash' => $contentHash,
            'size' => $size,
            'state' => BlobState::Writing->value,
            'delete_after' => null,
            'lease_token' => null,
            'lease_expires_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    /**
     * Runs one of the ledger's own transactions.
     *
     * READ COMMITTED on the MySQL driver, see the class comment. A nested call
     * keeps the enclosing transaction's level, and on MariaDB error 1020 there
     * is reported for what it is rather than as the driver's
     * `IntegrityException` — which {@see reserve()} would otherwise retry as a
     * duplicate-insert race, against the same stale snapshot.
     *
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    private function transaction(Closure $work): mixed
    {
        try {
            return $this->db->transaction(
                static fn(): mixed => $work(),
                $this->db->getDriverName() === 'mysql' ? TransactionInterface::READ_COMMITTED : null,
            );
        } catch (DbException $e) {
            // yiisoft/db copies the driver's errorInfo onto the exception it
            // converts to: [SQLSTATE, driver code, message]
            if (($e->errorInfo[1] ?? null) !== self::SNAPSHOT_CONFLICT) {
                throw $e;
            }

            throw new LedgerException(
                'A blob row changed after this transaction\'s snapshot (MariaDB error 1020 under REPEATABLE READ):'
                . ' the ledger was called inside a transaction it does not own. Retry that transaction, or run it'
                . ' at READ COMMITTED',
                0,
                $e,
            );
        }
    }

    /**
     * Marks a blob collectable when nothing is holding it any more.
     *
     * Conditional in SQL rather than in PHP for the usual reason, plus one
     * more: a blob under a deletion lease must keep it. Only the holder, or
     * the expiry, decides what happens to that one next.
     *
     * @param non-empty-string $id
     */
    private function scheduleIfUnused(string $id, DateTimeImmutable $deleteAfter): void
    {
        $this->db->createCommand()->update(
            $this->blobs,
            [
                'state' => BlobState::PendingDelete->value,
                'delete_after' => Timestamps::toStorage($deleteAfter),
                'updated_at' => Timestamps::toStorage($this->clock->now()),
            ],
            [
                'and',
                ['id' => $id],
                ['not', ['state' => BlobState::Deleting->value]],
                $this->unusedCondition($id),
            ],
        )->execute();
    }

    /**
     * `NOT EXISTS` over both holders: a committed file row and a live writer
     * claim keep a blob alive in exactly the same way.
     *
     * @param non-empty-string $id
     *
     * @return array<mixed>
     */
    private function unusedCondition(string $id): array
    {
        // an Expression, not '1': yiisoft/db quotes a string select as a column
        // name, and MySQL and PostgreSQL reject a column named "1" (SQLite
        // accepts it, which is how this shipped)
        return [
            'and',
            ['not exists', (new Query($this->db))->select(new Expression('1'))->from($this->files)->where(['blob_id' => $id])],
            ['not exists', (new Query($this->db))->select(new Expression('1'))->from($this->reservations)->where(['blob_id' => $id])],
        ];
    }

    /**
     * @return array<mixed>
     */
    private function collectableCondition(string $at): array
    {
        return [
            'or',
            ['and', ['state' => BlobState::PendingDelete->value], ['<=', 'delete_after', $at]],
            // an expired lease is stealable: that is how a collector that died
            // mid-delete stops holding the blob forever
            ['and', ['state' => BlobState::Deleting->value], ['<=', 'lease_expires_at', $at]],
        ];
    }

    /**
     * @param non-empty-string $id
     *
     * @return array<string, mixed>|null
     */
    private function blobRow(string $id): ?array
    {
        /** @var array<string, mixed>|null $row */
        $row = (new Query($this->db))->from($this->blobs)->where(['id' => $id])->one();

        return $row;
    }

    /**
     * Blob rows with their reference and reservation counts, in one statement:
     * correlated `COUNT(*)` subqueries, which every supported driver runs.
     *
     * @param list<non-empty-string> $ids
     *
     * @return list<array<string, mixed>>
     */
    private function blobRowsWithCounts(array $ids): array
    {
        $references = (new Query($this->db))
            ->select(new Expression('COUNT(*)'))
            ->from(['f' => $this->files])
            ->where(new Expression('[[f]].[[blob_id]] = [[b]].[[id]]'));
        $reservations = (new Query($this->db))
            ->select(new Expression('COUNT(*)'))
            ->from(['r' => $this->reservations])
            ->where(new Expression('[[r]].[[blob_id]] = [[b]].[[id]]'));

        /** @var list<array<string, mixed>> $rows */
        $rows = (new Query($this->db))
            ->select(['b.*', 'reference_count' => $references, 'reservation_count' => $reservations])
            ->from(['b' => $this->blobs])
            ->where(['b.id' => $ids])
            ->all();

        return $rows;
    }

    /**
     * The blob row as it is now, not as this transaction's snapshot saw it.
     *
     * `FOR UPDATE` on MySQL and PostgreSQL: on MySQL under REPEATABLE READ a
     * plain SELECT inside a transaction answers from the snapshot taken at its
     * first read. SQLite has no `FOR` clause and needs none — it serialises
     * writers on the whole database.
     *
     * @param non-empty-string $id
     *
     * @return array<string, mixed>|null
     */
    private function currentBlobRow(string $id): ?array
    {
        $query = (new Query($this->db))->from($this->blobs)->where(['id' => $id]);
        if ($this->db->getDriverName() !== 'sqlite') {
            $query->for('UPDATE');
        }

        /** @var array<string, mixed>|null $row */
        $row = $query->one();

        return $row;
    }

    /**
     * @param non-empty-string $id
     *
     * @return int<0, max>
     */
    private function count(string $table, string $id): int
    {
        return max(0, (int) (new Query($this->db))->from($table)->where(['blob_id' => $id])->count());
    }

    /**
     * @param array<string, mixed> $row
     */
    private function state(array $row): BlobState
    {
        $state = isset($row['state']) && \is_string($row['state'])
            ? BlobState::tryFrom($row['state'])
            : null;

        return $state ?? throw new LedgerException('Blob row holds an unknown state');
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return non-empty-string
     */
    private function string(array $row, string $column): string
    {
        return isset($row[$column]) && \is_string($row[$column]) && $row[$column] !== ''
            ? $row[$column]
            : throw new LedgerException("Blob column \"{$column}\" must hold a non-empty string");
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return int<0, max>
     */
    private function integer(array $row, string $column): int
    {
        // `bigint` comes back as a string on several drivers, as an int on others
        if (
            isset($row[$column])
            && \is_string($row[$column])
            && preg_match('/^\d{1,19}\z/', $row[$column]) === 1
        ) {
            $parsed = (int) $row[$column];

            return $parsed >= 0
                ? $parsed
                : throw new LedgerException("Blob column \"{$column}\" must hold a non-negative integer");
        }

        return isset($row[$column]) && \is_int($row[$column]) && $row[$column] >= 0
            ? $row[$column]
            : throw new LedgerException("Blob column \"{$column}\" must hold a non-negative integer");
    }

    /**
     * How many collectable rows one pass considers before giving up. Bounded
     * because every candidate costs one conditional update, and a collector
     * losing every race to live writers should return rather than spin.
     */
    private const int CLAIM_CANDIDATES = 16;

    /** MariaDB's ER_CHECKREAD: "Record has changed since last read". */
    private const int SNAPSHOT_CONFLICT = 1020;
}
