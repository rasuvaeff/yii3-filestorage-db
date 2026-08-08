<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Tests;

use DateTime;
use DateTimeImmutable;
use Rasuvaeff\Yii3FilestorageDb\Exception\InvalidFileRowException;
use Rasuvaeff\Yii3FilestorageDb\Timestamps;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(Timestamps::class)]
final class TimestampsTest
{
    public function keepsMicroseconds(): void
    {
        Assert::same(
            Timestamps::toStorage(new DateTimeImmutable('2026-01-01T00:00:00.123456+00:00')),
            '2026-01-01T00:00:00.123456+00:00',
        );
    }

    /**
     * The ledger compares deadlines as strings. Two rows written from
     * different offsets would sort by their text, not by their instant, and a
     * collector would skip or grab the wrong blob.
     */
    public function normalisesEveryOffsetToUtc(): void
    {
        Assert::same(
            Timestamps::toStorage(new DateTimeImmutable('2026-01-01T03:00:00.000000+03:00')),
            '2026-01-01T00:00:00.000000+00:00',
        );
        Assert::same(
            Timestamps::toStorage(new DateTimeImmutable('2025-12-31T19:00:00.000000-05:00')),
            '2026-01-01T00:00:00.000000+00:00',
        );
    }

    /**
     * Once normalised, the text order is the chronological order. That
     * equivalence is what every `<=` in the ledger relies on.
     */
    public function normalisedTextSortsChronologically(): void
    {
        $earlier = Timestamps::toStorage(new DateTimeImmutable('2026-01-01T03:00:00.000000+03:00'));
        $later = Timestamps::toStorage(new DateTimeImmutable('2026-01-01T00:00:01.000000+00:00'));

        Assert::true($earlier < $later);
    }

    public function acceptsAMutableDateTimeToo(): void
    {
        Assert::same(
            Timestamps::toStorage(new DateTime('2026-01-01T00:00:00.000000+00:00')),
            '2026-01-01T00:00:00.000000+00:00',
        );
    }

    public function readsBackWhatItWrote(): void
    {
        $value = Timestamps::toStorage(new DateTimeImmutable('2026-06-15T12:34:56.789012+00:00'));

        Assert::same(Timestamps::fromStorage($value, 'created_at')->format(Timestamps::FORMAT), $value);
    }

    public function nullReadsBackAsNullOnlyWhereItIsAllowed(): void
    {
        Assert::null(Timestamps::fromStorageOrNull(null, 'delete_after'));
        Assert::same(
            Timestamps::fromStorageOrNull('2026-01-01T00:00:00.000000+00:00', 'delete_after')?->format('H:i'),
            '00:00',
        );
    }

    #[DataProvider('badValueProvider')]
    public function rejectsAnythingThatIsNotTheStoredFormat(mixed $value): void
    {
        Expect::exception(InvalidFileRowException::class)
            ->withMessageContaining('Column "created_at" does not hold a Y-m-d\TH:i:s.uP timestamp');

        Timestamps::fromStorage($value, 'created_at');
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function badValueProvider(): iterable
    {
        yield 'null' => [null];
        yield 'not a string' => [1_767_225_600];
        yield 'empty' => [''];
        yield 'no microseconds' => ['2026-01-01T00:00:00+00:00'];
        yield 'a space instead of T' => ['2026-01-01 00:00:00.000000+00:00'];
        yield 'no offset' => ['2026-01-01T00:00:00.000000'];
        yield 'a trailing newline' => ["2026-01-01T00:00:00.000000+00:00\n"];
        yield 'an impossible date' => ['2026-02-31T00:00:00.000000+00:00'];
        // The three below parse cleanly — no warnings, no errors — and are
        // caught only by re-rendering and comparing. They are the reason that
        // check exists: each is a *different text* for an instant the ledger
        // then compares as text, and one of them in a column silently breaks
        // the ordering every `<=` depends on.
        yield 'an offset without a colon' => ['2026-01-01T00:00:00.000000+0000'];
        yield 'Z instead of an offset' => ['2026-01-01T00:00:00.000000Z'];
        yield 'unpadded month and day' => ['2026-1-1T00:00:00.000000+00:00'];
        yield 'an out-of-range hour' => ['2026-01-01T25:00:00.000000+00:00'];
    }
}
