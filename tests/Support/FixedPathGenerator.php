<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Tests\Support;

use Override;
use Rasuvaeff\Yii3Filestorage\Path\PathGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Upload;

/**
 * Writes to a path somebody else chose — a content key, in practice.
 *
 * @internal
 */
final readonly class FixedPathGenerator implements PathGeneratorInterface
{
    public function __construct(private string $path) {}

    #[Override]
    public function generate(string $groupName, Upload $upload, ?string $mediaType): string
    {
        return $this->path;
    }
}
