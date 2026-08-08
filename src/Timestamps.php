<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Rasuvaeff\Yii3FilestorageDb\Exception\InvalidFileRowException;

/**
 * How this package writes and reads instants in text columns.
 *
 * Two decisions, both load-bearing.
 *
 * **Microseconds are kept.** `File::toArray()` serialises with them because
 * PSR-20 clocks report them and the round-trip contract would otherwise fail;
 * a column that truncated to whole seconds would reintroduce exactly that bug
 * one layer down. That also rules out most drivers' native timestamp types,
 * which differ in how much sub-second precision survives.
 *
 * **Everything is normalised to UTC.** The ledger compares deadlines with
 * `<=` against a stored string, and a lexicographic comparison only agrees
 * with a chronological one when every row carries the same offset. One row
 * written from a `+03:00` request would otherwise sort before an earlier row
 * written from UTC, and a collector would skip or grab the wrong blob.
 *
 * @internal
 */
final readonly class Timestamps
{
    public const string FORMAT = 'Y-m-d\TH:i:s.uP';

    /**
     * @return non-empty-string
     */
    public static function toStorage(DateTimeInterface $value): string
    {
        return DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(self::FORMAT);
    }

    /**
     * @throws InvalidFileRowException
     */
    public static function fromStorage(mixed $value, string $column): DateTimeImmutable
    {
        $parsed = self::parse($value);
        if (!$parsed instanceof DateTimeImmutable) {
            throw new InvalidFileRowException(
                sprintf('Column "%s" does not hold a %s timestamp', $column, self::FORMAT),
            );
        }

        return $parsed;
    }

    /**
     * @throws InvalidFileRowException
     */
    public static function fromStorageOrNull(mixed $value, string $column): ?DateTimeImmutable
    {
        return $value === null ? null : self::fromStorage($value, $column);
    }

    private static function parse(mixed $value): ?DateTimeImmutable
    {
        if (!\is_string($value)) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat(self::FORMAT, $value);

        // Round-tripping the parse is the whole check, and it subsumes
        // `getLastErrors()`: an error makes `createFromFormat()` return false,
        // and a warning means the value was rolled over — `2026-02-31` becomes
        // `2026-03-03` — so it cannot render back to what came in. It also
        // catches what neither errors nor warnings report: `+0000`, `Z` and
        // `2026-1-1` all parse cleanly and are *different text* for the same
        // instant, which is exactly what a column the ledger compares as text
        // must not contain.
        if ($parsed === false || $parsed->format(self::FORMAT) !== $value) {
            return null;
        }

        return $parsed;
    }
}
