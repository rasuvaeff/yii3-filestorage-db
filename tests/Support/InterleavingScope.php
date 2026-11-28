<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Tests\Support;

use Closure;
use Override;
use Rasuvaeff\Yii3Filestorage\Repository\FileScopeProviderInterface;

/**
 * A fixed tenant that runs another worker's step on its n-th call.
 *
 * `DbRepository` asks for the scope once per statement, so the n-th call is a
 * precise point inside a ledger transaction — after its first read, before
 * its next write. That is the window a second process commits into in
 * production, made deterministic for a single-process test.
 *
 * @internal
 */
final class InterleavingScope implements FileScopeProviderInterface
{
    private int $calls = 0;
    private ?Closure $step = null;
    private int $at = 0;

    public function __construct(private readonly string $scopeId = 'acme') {}

    public function runOnCall(int $at, Closure $step): void
    {
        $this->calls = 0;
        $this->at = $at;
        $this->step = $step;
    }

    #[Override]
    public function currentScopeId(): string
    {
        $this->calls++;
        if ($this->step !== null && $this->calls === $this->at) {
            $step = $this->step;
            $this->step = null;
            $step();
        }

        return $this->scopeId;
    }
}
