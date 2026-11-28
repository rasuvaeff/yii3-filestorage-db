<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Tests\Support;

use DateTimeImmutable;
use Override;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\StorageInterface;
use Rasuvaeff\Yii3Filestorage\Upload;

/**
 * Some other `StorageInterface` an application might wrap: it forwards
 * everything and has no `withProxyUrls()`.
 *
 * @internal
 */
final readonly class ForwardingStorage implements StorageInterface
{
    public function __construct(private StorageInterface $inner) {}

    #[Override]
    public function add(
        Upload $upload,
        ?string $groupName = null,
        ?string $storeName = null,
        ?string $description = null,
        array $metadata = [],
    ): File {
        return $this->inner->add($upload, $groupName, $storeName, $description, $metadata);
    }

    #[Override]
    public function find(string $id): ?File
    {
        return $this->inner->find($id);
    }

    #[Override]
    public function remove(string $id): bool
    {
        return $this->inner->remove($id);
    }

    #[Override]
    public function exists(File $file): bool
    {
        return $this->inner->exists($file);
    }

    #[Override]
    public function url(File $file): ?string
    {
        return $this->inner->url($file);
    }

    #[Override]
    public function temporaryUrl(File $file, DateTimeImmutable $expiresAt): ?string
    {
        return $this->inner->temporaryUrl($file, $expiresAt);
    }

    #[Override]
    public function urlFor(File $file, ?DateTimeImmutable $expiresAt = null): ?string
    {
        return $this->inner->urlFor($file, $expiresAt);
    }

    #[Override]
    public function stream(File $file): ?StreamInterface
    {
        return $this->inner->stream($file);
    }

    #[Override]
    public function content(File $file): ?string
    {
        return $this->inner->content($file);
    }
}
