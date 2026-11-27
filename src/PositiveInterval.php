<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Refuses a grace period or a time to live that does not move time forward.
 *
 * Zero is the dangerous one. A zero delete grace makes "scheduled" and
 * "collectable" the same instant, and a zero reservation TTL lets the sweep
 * remove a writer's claim while it is still uploading — both turn races the
 * ledger only tolerates under a delay into ordinary events.
 *
 * @internal
 */
final readonly class PositiveInterval
{
    /**
     * @param non-empty-string $name
     *
     * @throws InvalidArgumentException
     */
    public static function require(DateInterval $interval, string $name): DateInterval
    {
        $reference = new DateTimeImmutable('2000-01-01T00:00:00+00:00');
        if ($reference->add($interval) <= $reference) {
            throw new InvalidArgumentException(sprintf('%s must be a positive interval', $name));
        }

        return $interval;
    }
}
