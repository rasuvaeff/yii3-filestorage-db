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
 * A working ledger with chosen methods failing, as a database that drops the
 * connection at that moment does.
 *
 * @internal
 */
final readonly class FaultyLedger implements BlobLedgerInterface
{
    public function __construct(
        private BlobLedgerInterface $inner,
        private bool $commit = false,
        private bool $release = false,
        private bool $find = false,
    ) {}

    #[Override]
    public function reserve(BlobId $blob, string $contentHash, int $size, DateTimeImmutable $expiresAt): BlobReservation
    {
        return $this->inner->reserve($blob, $contentHash, $size, $expiresAt);
    }

    #[Override]
    public function commit(BlobReservation $reservation, File $file): void
    {
        if ($this->commit) {
            throw new \RuntimeException('commit failed');
        }

        $this->inner->commit($reservation, $file);
    }

    #[Override]
    public function release(BlobReservation $reservation, DateTimeImmutable $deleteAfter): void
    {
        if ($this->release) {
            throw new \RuntimeException('release failed');
        }

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
        if ($this->find) {
            throw new \RuntimeException('the ledger cannot be read');
        }

        return $this->inner->find($blob);
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
