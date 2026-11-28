<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Tests\Support;

use DateTimeImmutable;
use Override;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Url\ProxyUrlGeneratorInterface;

/**
 * A proxy URL generator that says which one minted the URL — standing in for
 * `-web`'s `HmacProxyUrlGenerator::forScope($tenantId)`.
 *
 * @internal
 */
final readonly class PinnedProxyUrls implements ProxyUrlGeneratorInterface
{
    public function __construct(private string $scope) {}

    #[Override]
    public function url(File $file, DateTimeImmutable $expiresAt): string
    {
        return "/files/{$this->scope}/{$file->id}";
    }

    #[Override]
    public function variantUrl(File $file, string $variant, DateTimeImmutable $expiresAt): string
    {
        return "/files/{$this->scope}/{$file->id}/{$variant}";
    }
}
