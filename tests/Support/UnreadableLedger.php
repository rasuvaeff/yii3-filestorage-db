<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Tests\Support;

use DateTimeImmutable;
use Override;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Store\BlobId;
use Rasuvaeff\Yii3Filestorage\Store\BlobLease;
use Rasuvaeff\Yii3Filestorage\Store\BlobLedgerInterface;
use Rasuvaeff\Yii3Filestorage\Store\BlobRecord;
use Rasuvaeff\Yii3Filestorage\Store\BlobReservation;

/**
 * A ledger whose writes work and whose `find()` fails, as a database that
 * drops the connection right after a commit does.
 *
 * @internal
 */
final readonly class UnreadableLedger implements BlobLedgerInterface
{
    public function __construct(private BlobLedgerInterface $inner) {}

    #[Override]
    public function reserve(BlobId $blob, string $contentHash, int $size, DateTimeImmutable $expiresAt): BlobReservation
    {
        return $this->inner->reserve($blob, $contentHash, $size, $expiresAt);
    }

    #[Override]
    public function commit(BlobReservation $reservation, File $file): void
    {
        $this->inner->commit($reservation, $file);
    }

    #[Override]
    public function release(BlobReservation $reservation, DateTimeImmutable $deleteAfter): void
    {
        $this->inner->release($reservation, $deleteAfter);
    }

    #[Override]
    public function releaseFile(string $fileId, DateTimeImmutable $deleteAfter): bool
    {
        return $this->inner->releaseFile($fileId, $deleteAfter);
    }

    #[Override]
    public function find(BlobId $blob): ?BlobRecord
    {
        throw new \RuntimeException('the ledger cannot be read');
    }

    #[Override]
    public function expireReservations(DateTimeImmutable $now, DateTimeImmutable $deleteAfter): int
    {
        return $this->inner->expireReservations($now, $deleteAfter);
    }

    #[Override]
    public function claimForDeletion(DateTimeImmutable $now, DateTimeImmutable $leaseExpiresAt): ?BlobLease
    {
        return $this->inner->claimForDeletion($now, $leaseExpiresAt);
    }

    #[Override]
    public function completeDeletion(BlobLease $lease): bool
    {
        return $this->inner->completeDeletion($lease);
    }

    #[Override]
    public function abandonDeletion(BlobLease $lease, DateTimeImmutable $retryAfter): bool
    {
        return $this->inner->abandonDeletion($lease, $retryAfter);
    }
}
