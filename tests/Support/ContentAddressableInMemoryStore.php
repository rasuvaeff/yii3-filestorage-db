<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Tests\Support;

use DateTimeImmutable;
use Override;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Filestorage\Exception\StoreException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Path\PathGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Store\ContentAddressableStoreInterface;
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
final readonly class ContentAddressableInMemoryStore implements ContentAddressableStoreInterface
{
    public function __construct(private InMemoryStore $inner) {}

    #[Override]
    public function putIfAbsent(Upload $upload, StoredObjectId $object, int $maxBytes = 0): StoreResult
    {
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
                size: \strlen($existing),
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
        return $this->inner->stream($file);
    }
}
