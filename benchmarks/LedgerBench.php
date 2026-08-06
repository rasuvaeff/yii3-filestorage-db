<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Benchmarks;

use DateTimeImmutable;
use DateTimeZone;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Store\BlobId;
use Rasuvaeff\Yii3FilestorageDb\BlobRowId;
use Rasuvaeff\Yii3FilestorageDb\FileRowMapper;
use Rasuvaeff\Yii3FilestorageDb\Timestamps;
use Testo\Bench;

/**
 * The CPU-bound work this package adds around a query.
 *
 * The database is deliberately absent: SQLite in memory would measure SQLite
 * and a real server would measure the network. What is left is what this
 * package computes per row — mapping, validating, deriving a blob key — which
 * is the part that scales with how many rows a maintenance walk touches.
 *
 * Each benchmark is paired with the cheapest thing that could possibly do the
 * job, so the cost of a guarantee is visible rather than assumed.
 *
 * @internal
 */
final class LedgerBench
{
    private static ?File $file = null;

    /** @var array<string, mixed>|null */
    private static ?array $row = null;

    /**
     * Reading is where the checking happens: a type check per column, a JSON
     * decode, two timestamp parses, then `File::create()`'s own validation.
     * The baseline is the same row decoded and handed over unchecked.
     */
    #[Bench(
        callables: ['unchecked' => [self::class, 'readWithoutChecking']],
        calls: 500,
        iterations: 10,
    )]
    public static function readARow(): File
    {
        return (new FileRowMapper())->fromRow(self::row());
    }

    /**
     * @return array<string, mixed>
     */
    public static function readWithoutChecking(): array
    {
        $row = self::row();
        $row['metadata'] = json_decode((string) $row['metadata'], true);

        return $row;
    }

    /**
     * @return array<string, scalar|null>
     */
    #[Bench(
        callables: ['File::toArray()' => [self::class, 'writeWithoutMapping']],
        calls: 500,
        iterations: 10,
    )]
    public static function writeARow(): array
    {
        return (new FileRowMapper())->toRow(self::file());
    }

    /**
     * @return array<string, mixed>
     */
    public static function writeWithoutMapping(): array
    {
        return self::file()->toArray();
    }

    /**
     * One SHA-256 per ledger call, against the raw key it hashes. The gap is
     * what a MySQL-safe primary key costs; if it ever stops being noise, the
     * ledger is calling this in a loop it should not be.
     */
    #[Bench(
        callables: ['the raw key' => [self::class, 'rawBlobKey']],
        calls: 2_000,
        iterations: 10,
    )]
    public static function deriveTheBlobRowIdentifier(): string
    {
        return BlobRowId::for(BlobId::create('upload', 'sha/e3/b0/original'));
    }

    public static function rawBlobKey(): string
    {
        return BlobId::create('upload', 'sha/e3/b0/original')->key();
    }

    /**
     * Every stored instant goes through this, and the ledger compares the
     * results as text — so the timezone normalisation happens on every write.
     * The baseline formats without converting.
     */
    #[Bench(
        callables: ['no normalisation' => [self::class, 'formatWithoutNormalising']],
        calls: 2_000,
        iterations: 10,
    )]
    public static function normaliseAnInstantForStorage(): string
    {
        return Timestamps::toStorage(self::instant());
    }

    public static function formatWithoutNormalising(): string
    {
        return self::instant()->format(Timestamps::FORMAT);
    }

    private static function instant(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-01T03:00:00.123456', new DateTimeZone('+03:00'));
    }

    private static function file(): File
    {
        return self::$file ??= File::create(
            id: 'bench',
            storeName: 'upload',
            groupName: 'common',
            relativePath: 'sha/e3/b0/original',
            originalName: 'thing.txt',
            size: 12,
            createdAt: new DateTimeImmutable('2026-01-01T00:00:00.000000+00:00'),
            mimeType: 'text/plain',
            contentHash: 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            metadata: ['width' => 1024, 'height' => 768, 'alt' => 'a cat'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(): array
    {
        return self::$row ??= (new FileRowMapper())->toRow(self::file());
    }
}
