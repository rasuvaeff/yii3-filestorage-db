<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Tests\Support;

use InvalidArgumentException;
use Override;
use Rasuvaeff\Yii3Filestorage\Path\ContentAddressedKeyGeneratorInterface;

/**
 * Accepts a single-segment scope and refuses the `sha/<tenant>/<group>` ones
 * `DedupScope::keyFor()` builds — passes the layout probe, fails the tenant one.
 *
 * @internal
 */
final readonly class SlashRefusingKeyGenerator implements ContentAddressedKeyGeneratorInterface
{
    #[Override]
    public function generate(string $scope, string $sha256, ?string $extension = null): string
    {
        if (str_contains($scope, '/')) {
            throw new InvalidArgumentException("Nested scope \"{$scope}\"");
        }

        return "{$scope}/{$sha256}/original.bin";
    }
}
