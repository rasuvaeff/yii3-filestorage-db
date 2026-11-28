<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb;

/**
 * The MySQL-family collation that compares identifiers byte for byte.
 *
 * Binary *and* NO PAD: `utf8mb4_bin` is PAD SPACE on both servers and makes
 * `acme ` equal to `acme` — on MySQL 8, whose default `utf8mb4_0900_ai_ci` is
 * NO PAD, a match that did not exist before. MariaDB names its NO PAD binary
 * collation differently; MySQL before 8.0.17 has none, and there the choice
 * is refused rather than quietly weakened.
 *
 * A separate pure function so the decision is tested without a server; the
 * migrations only ask the server the two questions.
 *
 * @internal
 */
final readonly class ExactCollation
{
    private function __construct() {}

    /**
     * @param mixed $serverVersion `SELECT VERSION()`, as the driver returns it
     * @param mixed $collationCount How many rows of `information_schema.COLLATIONS`
     *        are named `utf8mb4_0900_bin` — a string or an int, by driver
     *
     * @throws \RuntimeException On MySQL without `utf8mb4_0900_bin`.
     */
    public static function choose(mixed $serverVersion, mixed $collationCount): string
    {
        $version = \is_scalar($serverVersion) ? (string) $serverVersion : '';
        if (stripos($version, 'mariadb') !== false) {
            return 'utf8mb4_nopad_bin';
        }

        if (!is_numeric($collationCount) || (int) $collationCount < 1) {
            throw new \RuntimeException(sprintf(
                'MySQL %s has no NO PAD binary collation (utf8mb4_0900_bin needs MySQL 8.0.17 or later)',
                $version,
            ));
        }

        return 'utf8mb4_0900_bin';
    }
}
