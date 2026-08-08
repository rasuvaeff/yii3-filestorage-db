<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Tests\Support;

use Override;
use Rasuvaeff\Yii3Filestorage\Path\ContentAddressedKeyGeneratorInterface;

/**
 * A content key that ignores the content. Proves the injected generator is the
 * one asked, rather than the default the factory would otherwise construct.
 *
 * @internal
 */
final readonly class FixedKeyGenerator implements ContentAddressedKeyGeneratorInterface
{
    public function __construct(private string $key) {}

    #[Override]
    public function generate(string $scope, string $sha256): string
    {
        return $this->key;
    }
}
