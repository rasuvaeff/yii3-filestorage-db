<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Tests;

use Rasuvaeff\Yii3FilestorageDb\ExactCollation;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

/**
 * Review pass 2 N-A1: the collation the case-sensitivity migration picks. The
 * live servers in the integration suite prove each choice is NO PAD; this pins
 * the choice itself, which mutation testing (SQLite only) cannot reach there.
 */
#[Test]
#[Covers(ExactCollation::class)]
final class ExactCollationTest
{
    #[DataProvider('serversProvider')]
    public function theNoPadBinaryCollationOfTheServerIsChosen(mixed $version, mixed $count, string $expected): void
    {
        Assert::same(ExactCollation::choose(serverVersion: $version, collationCount: $count), $expected);
    }

    public static function serversProvider(): iterable
    {
        yield 'MySQL 8.4, count as a string' => ['8.4.13', '1', 'utf8mb4_0900_bin'];
        yield 'MySQL 8.4, count as an int' => ['8.4.13', 1, 'utf8mb4_0900_bin'];
        yield 'MariaDB 11.8, even if it listed a 0900 name' => ['11.8.9-MariaDB-ubu2404', '1', 'utf8mb4_nopad_bin'];
        yield 'MariaDB 10.6' => ['10.6.21-MariaDB', '0', 'utf8mb4_nopad_bin'];
        yield 'lower-case vendor string' => ['10.11.0-mariadb-log', 0, 'utf8mb4_nopad_bin'];
    }

    #[DataProvider('withoutTheCollationProvider')]
    public function mysqlWithoutTheNoPadBinaryCollationIsRefused(mixed $version, mixed $count, string $message): void
    {
        Expect::exception(\RuntimeException::class)->withMessage($message);

        ExactCollation::choose(serverVersion: $version, collationCount: $count);
    }

    public static function withoutTheCollationProvider(): iterable
    {
        $tail = ' has no NO PAD binary collation (utf8mb4_0900_bin needs MySQL 8.0.17 or later)';

        yield 'MySQL 5.7' => ['5.7.44', '0', 'MySQL 5.7.44' . $tail];
        yield 'count as an int' => ['5.7.44', 0, 'MySQL 5.7.44' . $tail];
        yield 'no answer' => [null, null, 'MySQL ' . $tail];
        yield 'not a number' => ['8.0.16', 'n/a', 'MySQL 8.0.16' . $tail];
    }
}
