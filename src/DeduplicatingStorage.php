<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb;

use DateInterval;
use DateTimeImmutable;
use Override;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Filestorage\Exception\AddException;
use Rasuvaeff\Yii3Filestorage\Exception\BlobBusyException;
use Rasuvaeff\Yii3Filestorage\Exception\RemoveException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Id\IdGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Mime\MimeTypeDetectorInterface;
use Rasuvaeff\Yii3Filestorage\Path\ContentAddressedKeyGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Policy\PolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Repository\FileScopeProviderInterface;
use Rasuvaeff\Yii3Filestorage\StorageInterface;
use Rasuvaeff\Yii3Filestorage\Store\BlobId;
use Rasuvaeff\Yii3Filestorage\Store\BlobLedgerInterface;
use Rasuvaeff\Yii3Filestorage\Store\ContentAddressableStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Rasuvaeff\Yii3Filestorage\Upload;
use Throwable;

/**
 * Storage that lets identical uploads share one physical object.
 *
 * The same consumer API as the base facade, a different lifecycle underneath.
 * Every `add()` still produces its own `File` — its own id, group, description,
 * metadata and tenant scope — but two uploads of the same bytes end up pointing
 * at one object, and that object outlives the first row to be deleted.
 *
 * The protocol, and why each step is where it is:
 *
 * 1. **Hash first, within a limit.** The content key *is* the hash, so it
 *    cannot be chosen before the whole body has been read. Above
 *    `dedupMaxBytes` the upload takes the ordinary unique path instead — an
 *    unbounded second read of a multi-gigabyte upload is not a trade worth
 *    making for a deduplication that may not hit.
 * 2. **Reserve.** The ledger row is created or joined *before* any bytes are
 *    written, so a collector cannot remove the object between the write and
 *    the commit.
 * 3. **Publish.** `putIfAbsent()` writes immutable bytes, or reports that the
 *    same bytes were already there.
 * 4. **Commit.** One transaction turns the reservation into a committed
 *    reference and inserts the file row.
 * 5. **Release on any failure.** Which schedules the blob rather than deleting
 *    it: another writer may be holding the same bytes right now.
 *
 * `remove()` never deletes bytes either. It drops the row and the reference;
 * the collector does the rest, after a grace period. That asymmetry — writes
 * are eager, deletes are deferred — is the whole safety argument.
 *
 * @api
 */
final readonly class DeduplicatingStorage implements StorageInterface
{
    private const int CHUNK = 262_144;
    private const string NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/';

    /**
     * @param StorageInterface $unique The base facade. Handles everything this
     *        class does not share: reads, URLs, and uploads too large to hash.
     * @param int $dedupMaxBytes Above this an upload is stored uniquely. Zero
     *        would mean "hash everything", which is why it is not the default.
     * @param DateInterval $reservationTtl How long an uncommitted write keeps
     *        the blob alive. Long enough for the slowest upload you accept.
     * @param DateInterval $deleteGracePeriod How long an unreferenced blob
     *        waits before a collector may take it.
     */
    public function __construct(
        private StorageInterface $unique,
        private BlobLedgerInterface $ledger,
        private ContentAddressableStoreInterface $store,
        private ContentAddressedKeyGeneratorInterface $keys,
        private MimeTypeDetectorInterface $mimeTypeDetector,
        private IdGeneratorInterface $idGenerator,
        private PolicyRegistry $policies,
        private ClockInterface $clock,
        private DedupScope $scope = DedupScope::TenantGroup,
        private int $dedupMaxBytes = 104_857_600,
        private DateInterval $reservationTtl = new DateInterval('PT15M'),
        private DateInterval $deleteGracePeriod = new DateInterval('PT1H'),
        private string $defaultGroup = 'common',
        private ?FileScopeProviderInterface $scopes = null,
    ) {}

    #[Override]
    public function add(
        Upload $upload,
        ?string $groupName = null,
        ?string $storeName = null,
        ?string $description = null,
        array $metadata = [],
    ): File {
        $groupName ??= $this->defaultGroup;
        if ($groupName === '' || preg_match(self::NAME_PATTERN, $groupName) !== 1) {
            throw new \InvalidArgumentException("Invalid group name \"{$groupName}\"");
        }

        // A store name that is not this one means the caller wants a specific
        // physical target, and this class only knows how to share into its own.
        if ($storeName !== null && $storeName !== $this->store->name()) {
            return $this->unique->add($upload, $groupName, $storeName, $description, $metadata);
        }

        $policy = $this->policies->for($groupName);
        $mimeType = $this->mimeTypeDetector->detect($upload);
        $policy->assertAcceptable($upload, $mimeType);

        $digest = $this->digestWithinLimit($upload);
        if ($digest === null) {
            // Too large to address by content. A unique object is the honest
            // outcome, and the caller cannot tell the difference. The store
            // name is this one or null, both of which the base facade resolves
            // to the same physical target.
            return $this->unique->add($upload, $groupName, null, $description, $metadata);
        }

        [$hash, $size] = $digest;
        $blob = BlobId::create(
            $this->store->name(),
            $this->keys->generate($this->scope->keyFor($groupName, $this->scopes?->currentScopeId()), $hash),
        );

        return $this->share(
            $upload,
            $blob,
            $hash,
            $size,
            $groupName,
            $mimeType,
            $description,
            $metadata,
            $policy->maxBytes,
        );
    }

    #[Override]
    public function remove(string $id): bool
    {
        try {
            return $this->ledger->releaseFile($id, $this->clock->now()->add($this->deleteGracePeriod));
        } catch (Throwable $e) {
            throw new RemoveException("Failed to release file \"{$id}\"", 0, $e);
        }
    }

    #[Override]
    public function find(string $id): ?File
    {
        return $this->unique->find($id);
    }

    #[Override]
    public function exists(File $file): bool
    {
        return $this->unique->exists($file);
    }

    #[Override]
    public function url(File $file): ?string
    {
        return $this->unique->url($file);
    }

    #[Override]
    public function temporaryUrl(File $file, DateTimeImmutable $expiresAt): ?string
    {
        return $this->unique->temporaryUrl($file, $expiresAt);
    }

    #[Override]
    public function urlFor(File $file, ?DateTimeImmutable $expiresAt = null): ?string
    {
        return $this->unique->urlFor($file, $expiresAt);
    }

    #[Override]
    public function stream(File $file): ?StreamInterface
    {
        return $this->unique->stream($file);
    }

    #[Override]
    public function content(File $file): ?string
    {
        return $this->unique->content($file);
    }

    /**
     * @param non-empty-string $hash
     * @param int<0, max> $size Counted while hashing. `Upload::size()` is null
     *        for a body that will not declare its length, and reserving zero
     *        there while committing the real size makes every later add of the
     *        same content fail on the ledger's size check.
     * @param non-empty-string $groupName
     * @param array<array-key, mixed> $metadata Narrowed by `File::create()`, which validates it.
     */
    private function share(
        Upload $upload,
        BlobId $blob,
        string $hash,
        int $size,
        string $groupName,
        ?string $mimeType,
        ?string $description,
        array $metadata,
        int $maxBytes,
    ): File {
        $now = $this->clock->now();

        $reservation = $this->ledger->reserve(
            blob: $blob,
            contentHash: $hash,
            size: $size,
            expiresAt: $now->add($this->reservationTtl),
        );

        try {
            $result = $this->store->putIfAbsent($upload, new StoredObjectId($blob->relativePath()), $maxBytes);

            $file = File::create(
                id: $this->idGenerator->generate(),
                storeName: $blob->storeName,
                groupName: $groupName,
                relativePath: $result->relativePath,
                originalName: $upload->originalName,
                size: $result->size,
                createdAt: $now,
                externalId: $result->externalId,
                mimeType: $mimeType,
                description: $description,
                contentHash: $hash,
                metadata: $metadata,
            );

            $this->ledger->commit($reservation, $file);
        } catch (Throwable $e) {
            // Releasing, never deleting. The bytes at this content key may
            // belong to a writer that committed a microsecond ago, and the
            // grace period plus the collector are what tell the difference.
            $this->ledger->release($reservation, $this->clock->now()->add($this->deleteGracePeriod));

            throw $e instanceof BlobBusyException
                ? $e
                : new AddException("Failed to store \"{$blob->key()}\"", 0, $e);
        }

        return $file;
    }

    /**
     * The complete SHA-256 and the byte count, or null when the upload is
     * larger than dedup is willing to read twice.
     *
     * Stops at the limit rather than hashing to the end and then discarding
     * the answer: the point of the cap is not to read the tail at all. The size
     * comes from this pass because `Upload::size()` is null for a body that
     * never declares its length.
     *
     * @return array{non-empty-string, int<0, max>}|null
     */
    private function digestWithinLimit(Upload $upload): ?array
    {
        $declared = $upload->size();
        if ($declared !== null && $declared > $this->dedupMaxBytes) {
            return null;
        }

        $stream = $upload->stream();
        $context = hash_init('sha256');
        $read = 0;

        while (!$stream->eof()) {
            $chunk = $stream->read(self::CHUNK);
            if ($chunk === '') {
                break;
            }


            $read += \strlen($chunk);
            if ($read > $this->dedupMaxBytes) {
                $stream->rewind();

                return null;
            }

            hash_update($context, $chunk);
        }

        $stream->rewind();

        return [hash_final($context), $read];
    }
}
