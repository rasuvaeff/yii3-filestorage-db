<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Tests\Support;

use Override;
use Rasuvaeff\Yii3Filestorage\Path\ContentAddressedKeyGeneratorInterface;

/**
 * A content key under a fixed directory, ignoring the scope. Proves the
 * injected generator is the one asked, rather than the default the factory
 * would otherwise construct. Each digest still owns its directory, so the pair
 * passes core's layout check under `DirectoryLayout`.
 *
 * @internal
 */
final readonly class FixedKeyGenerator implements ContentAddressedKeyGeneratorInterface
{
    public function __construct(private string $directory) {}

    #[Override]
    public function generate(string $scope, string $sha256, ?string $extension = null): string
    {
        return "{$this->directory}/{$sha256}/original.bin";
    }
}
