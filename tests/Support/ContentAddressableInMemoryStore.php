<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Tests\Support;

use Closure;
use DateTimeImmutable;
use Override;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Filestorage\Exception\StoreException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Path\PathGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Store\ContentAddressableStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\Layout\ObjectLayoutInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Rasuvaeff\Yii3Filestorage\Store\StoreResult;
use Rasuvaeff\Yii3Filestorage\Test\InMemoryStore;
use Rasuvaeff\Yii3Filestorage\Upload;

/**
 * `InMemoryStore` plus put-if-absent.
 *
 * Core ships the double without the dedup capability on purpose — that is how
 * a consumer tests what happens when a store cannot share. This adds it, so
 * the dedup path can be exercised without a database or a bucket.
 *
 * @internal
 */
final class ContentAddressableInMemoryStore implements ContentAddressableStoreInterface
{
    private bool $failNextPut = false;

    private bool $vanishAfterFirstStream = false;

    private int $streamCalls = 0;

    /** @var (Closure(StreamInterface): StreamInterface)|null */
    private ?Closure $nextStreamWrapper = null;

    /** @var int<0, max>|null */
    private ?int $reuseSize = null;

    /** @var array{non-empty-string, non-empty-string|null}|null */
    private ?array $nextAnswer = null;

    public function __construct(private readonly InMemoryStore $inner) {}

    /**
     * Makes the next publish throw. A store that is briefly unavailable mid
     * protocol is the case the reservation and its release exist for, and it
     * cannot be produced any other way in a single process.
     */
    /**
     * Reports this size for an existing object instead of its real one — a
     * real store that skips the size check on reuse when the upload declared
     * none would hand back whatever a truncated object measures.
     *
     * @param int<0, max> $size
     */
    public function misreportReuseSize(int $size): void
    {
        $this->reuseSize = $size;
    }

    /**
     * Writes the next publish as usual but answers with this path and
     * external id — a store that files the object somewhere else than asked,
     * or that hands back its own id for it (a versioned bucket).
     *
     * @param non-empty-string $relativePath
     * @param non-empty-string|null $externalId
     */
    public function answerNextPutWith(string $relativePath, ?string $externalId = null): void
    {
        $this->nextAnswer = [$relativePath, $externalId];
    }

    public function failNextPut(): void
    {
        $this->failNextPut = true;
    }

    /**
     * The first `stream()` answers, every later one reports the object gone —
     * a body deleted between the hashing pass and the publish.
     */
    public function vanishAfterFirstStream(): void
    {
        $this->vanishAfterFirstStream = true;
    }

    /**
     * Wraps the stream the next `stream()` call returns, once.
     *
     * @param Closure(StreamInterface): StreamInterface $wrapper
     */
    public function wrapNextStream(Closure $wrapper): void
    {
        $this->nextStreamWrapper = $wrapper;
    }

    #[Override]
    public function putIfAbsent(Upload $upload, StoredObjectId $object, int $maxBytes = 0): StoreResult
    {
        if ($this->failNextPut) {
            $this->failNextPut = false;

            throw new StoreException("Store is unavailable while writing \"{$object->relativePath}\"");
        }

        if ($this->nextAnswer !== null) {
            [$relativePath, $externalId] = $this->nextAnswer;
            $this->nextAnswer = null;
            $written = $this->putIfAbsent($upload, $object, $maxBytes);

            return new StoreResult(
                relativePath: $relativePath,
                size: $written->size,
                externalId: $externalId,
                created: $written->created,
            );
        }

        $existing = $this->inner->bytesAt($object->relativePath);
        $contents = $upload->stream()->getContents();

        if ($existing !== null) {
            if (\strlen($existing) !== \strlen($contents)) {
                throw new StoreException(
                    "Content-addressed object \"{$object->relativePath}\" holds different bytes",
                );
            }

            return new StoreResult(
                relativePath: $object->relativePath,
                size: $this->reuseSize ?? \strlen($existing),
                created: false,
            );
        }

        return $this->inner->write(
            upload: $upload,
            groupName: 'content-addressed',
            pathGenerator: new FixedPathGenerator($object->relativePath),
            maxBytes: $maxBytes,
        );
    }

    public function writeCount(): int
    {
        return $this->inner->writeCount();
    }

    public function bytesAt(string $relativePath): ?string
    {
        return $this->inner->bytesAt($relativePath);
    }

    #[Override]
    public function name(): string
    {
        return $this->inner->name();
    }

    #[Override]
    public function write(
        Upload $upload,
        string $groupName,
        PathGeneratorInterface $pathGenerator,
        ?string $mediaType = null,
        int $maxBytes = 0,
    ): StoreResult {
        return $this->inner->write($upload, $groupName, $pathGenerator, $mediaType, $maxBytes);
    }

    #[Override]
    public function layout(): ObjectLayoutInterface
    {
        return $this->inner->layout();
    }

    #[Override]
    public function delete(File $file): void
    {
        $this->inner->delete($file);
    }

    #[Override]
    public function exists(File $file): bool
    {
        return $this->inner->exists($file);
    }

    #[Override]
    public function size(File $file): ?int
    {
        return $this->inner->size($file);
    }

    #[Override]
    public function lastModified(File $file): ?DateTimeImmutable
    {
        return $this->inner->lastModified($file);
    }

    #[Override]
    public function stream(File $file): ?StreamInterface
    {
        if ($this->vanishAfterFirstStream && ++$this->streamCalls > 1) {
            return null;
        }

        $stream = $this->inner->stream($file);

        if ($stream instanceof StreamInterface && $this->nextStreamWrapper instanceof Closure) {
            $stream = ($this->nextStreamWrapper)($stream);
            $this->nextStreamWrapper = null;
        }

        return $stream;
    }
}
