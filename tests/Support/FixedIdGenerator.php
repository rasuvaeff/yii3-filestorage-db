<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Tests\Support;

use Override;
use Rasuvaeff\Yii3Filestorage\Id\IdGeneratorInterface;

/**
 * Hands out one id, so a test can make an insert collide on the primary key.
 *
 * @internal
 */
final readonly class FixedIdGenerator implements IdGeneratorInterface
{
    /**
     * @param non-empty-string $id
     */
    public function __construct(private string $id) {}

    #[Override]
    public function generate(): string
    {
        return $this->id;
    }
}
