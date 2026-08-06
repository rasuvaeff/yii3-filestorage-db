<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Tests\Support;

use Override;
use Rasuvaeff\Yii3Filestorage\Repository\FileScopeProviderInterface;

/**
 * A tenant that a test can move between requests.
 *
 * @internal
 */
final class FixedScope implements FileScopeProviderInterface
{
    public function __construct(private ?string $scopeId = null) {}

    #[Override]
    public function currentScopeId(): ?string
    {
        return $this->scopeId;
    }

    public function switchTo(?string $scopeId): void
    {
        $this->scopeId = $scopeId;
    }
}
