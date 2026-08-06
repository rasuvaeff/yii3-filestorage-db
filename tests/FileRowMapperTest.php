<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Tests;

use Rasuvaeff\Yii3Filestorage\Store\BlobId;
use Rasuvaeff\Yii3FilestorageDb\BlobRowId;
use Rasuvaeff\Yii3FilestorageDb\Exception\InvalidFileRowException;
use Rasuvaeff\Yii3FilestorageDb\FileRowMapper;
use Rasuvaeff\Yii3FilestorageDb\Tests\Support\SqliteDatabase;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(FileRowMapper::class)]
final class FileRowMapperTest
{
    private FileRowMapper $mapper;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->mapper = new FileRowMapper();
    }

    /**
     * The whole row at once, not column by column. A missing or mistyped key
     * is a column the insert silently omits, and asserting a handful of them
     * lets the rest drift — which is exactly the shape of bug that reaches
     * production as "the media type is null on some files".
     */
    public function writesEveryColumnAFileCarries(): void
    {
        $row = $this->mapper->toRow(
            SqliteDatabase::file('a', metadata: ['k' => 'v']),
            BlobId::create('upload', 'sha/e3/b0/original'),
            'tenant-a',
        );

        Assert::same($row, [
            'id' => 'a',
            'store_name' => 'upload',
            'external_id' => null,
            'group_name' => 'common',
            'relative_path' => 'sha/e3/b0/original',
            'original_name' => 'thing.txt',
            'mime_type' => 'text/plain',
            'size' => 12,
            'description' => null,
            'content_hash' => SqliteDatabase::HASH,
            'metadata' => '{"k":"v"}',
            'created_at' => '2026-01-01T00:00:00.000000+00:00',
            'updated_at' => '2026-01-01T00:00:00.000000+00:00',
            'blob_id' => BlobRowId::for(BlobId::create('upload', 'sha/e3/b0/original')),
            'scope_id' => 'tenant-a',
        ]);
    }

    public function aRowWithNoBlobAndNoScopeCarriesNulls(): void
    {
        $row = $this->mapper->toRow(SqliteDatabase::file('a'));

        Assert::null($row['blob_id']);
        Assert::null($row['scope_id']);
    }

    /**
     * `bigint` arrives as a string on some drivers and as an int on others.
     * Accepting both is the actual contract, not laxity.
     */
    #[DataProvider('sizeProvider')]
    public function acceptsASizeInWhicheverShapeTheDriverReturns(mixed $stored, int $expected): void
    {
        Assert::same($this->mapper->fromRow($this->row(['size' => $stored]))->size, $expected);
    }

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function sizeProvider(): iterable
    {
        yield 'integer' => [12, 12];
        yield 'numeric string' => ['12', 12];
        yield 'zero' => [0, 0];
        yield 'zero as a string' => ['0', 0];
    }

    #[DataProvider('badSizeProvider')]
    public function rejectsASizeThatIsNotANonNegativeInteger(mixed $stored): void
    {
        Expect::exception(InvalidFileRowException::class)->withMessageContaining('"size" must hold a non-negative');

        $this->mapper->fromRow($this->row(['size' => $stored]));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function badSizeProvider(): iterable
    {
        yield 'negative' => [-1];
        yield 'negative string' => ['-1'];
        yield 'not numeric' => ['big'];
        yield 'float' => [1.5];
        yield 'null' => [null];
        yield 'a numeric string with a newline' => ["12\n"];
        yield 'wider than a signed 64-bit integer' => [str_repeat('9', 20)];
    }

    public function decodesMetadataAndTreatsAnEmptyColumnAsNoMetadata(): void
    {
        Assert::same($this->mapper->fromRow($this->row(['metadata' => '{"a":1,"b":null}']))->metadata, ['a' => 1, 'b' => null]);
        Assert::same($this->mapper->fromRow($this->row(['metadata' => '{}']))->metadata, []);
        Assert::same($this->mapper->fromRow($this->row(['metadata' => '']))->metadata, []);
        Assert::same($this->mapper->fromRow($this->row(['metadata' => null]))->metadata, []);
    }

    #[DataProvider('badMetadataProvider')]
    public function rejectsMetadataThatIsNotAFlatObject(mixed $stored, string $message): void
    {
        Expect::exception(InvalidFileRowException::class)->withMessageContaining($message);

        $this->mapper->fromRow($this->row(['metadata' => $stored]));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function badMetadataProvider(): iterable
    {
        yield 'not JSON' => ['{oops', 'does not hold valid JSON'];
        yield 'a scalar' => ['12', 'must hold a JSON object'];
        yield 'not a string' => [12, 'must hold a JSON object'];
        yield 'nested' => ['{"a":{"b":1}}', 'must hold a flat JSON object'];
        yield 'a list' => ['[1,2]', 'must hold a flat JSON object'];
        yield 'an empty key' => ['{"":1}', 'must hold a flat JSON object'];
    }

    #[DataProvider('missingColumnProvider')]
    public function rejectsARowMissingAMandatoryColumn(string $column): void
    {
        Expect::exception(InvalidFileRowException::class)->withMessageContaining("\"{$column}\" must hold a non-empty");

        $this->mapper->fromRow($this->row([$column => null]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function missingColumnProvider(): iterable
    {
        yield 'id' => ['id'];
        yield 'store_name' => ['store_name'];
        yield 'group_name' => ['group_name'];
        yield 'relative_path' => ['relative_path'];
        yield 'original_name' => ['original_name'];
    }

    /**
     * The column is nullable, so "absent" already has a spelling. An empty
     * string is a row something else wrote, and guessing which it meant is
     * how a file ends up with a media type of `""`.
     */
    #[DataProvider('nullableColumnProvider')]
    public function rejectsAnEmptyStringInANullableColumn(string $column): void
    {
        Expect::exception(InvalidFileRowException::class)->withMessageContaining('null or a non-empty string');

        $this->mapper->fromRow($this->row([$column => '']));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nullableColumnProvider(): iterable
    {
        yield 'external_id' => ['external_id'];
        yield 'mime_type' => ['mime_type'];
        yield 'content_hash' => ['content_hash'];
    }

    public function rejectsADescriptionThatIsNotAString(): void
    {
        Expect::exception(InvalidFileRowException::class)->withMessageContaining('"description" must hold null or a string');

        $this->mapper->fromRow($this->row(['description' => 12]));
    }

    /**
     * A value that passes the column checks and is still not a `File`: the
     * exception has to name the row, not leak an argument error from a
     * constructor the caller never invoked.
     */
    public function rejectsARowFileItselfWouldRefuse(): void
    {
        Expect::exception(InvalidFileRowException::class)
            ->withMessageContaining('Stored file row is invalid: Invalid relative path');

        $this->mapper->fromRow($this->row(['relative_path' => '../escape']));
    }

    public function rejectsATimestampThatIsNotTheStoredFormat(): void
    {
        Expect::exception(InvalidFileRowException::class)
            ->withMessageContaining('Column "created_at" does not hold a Y-m-d\TH:i:s.uP timestamp');

        $this->mapper->fromRow($this->row(['created_at' => '2026-01-01 00:00:00']));
    }

    public function readsTheBlobIdentifierOffARow(): void
    {
        Assert::same($this->mapper->blobRowId(['blob_id' => 'abc']), 'abc');
        Assert::null($this->mapper->blobRowId(['blob_id' => null]));
        Assert::null($this->mapper->blobRowId(['blob_id' => '']));
        Assert::null($this->mapper->blobRowId([]));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return [
            'id' => 'a',
            'store_name' => 'upload',
            'external_id' => null,
            'group_name' => 'common',
            'relative_path' => 'sha/e3/b0/original',
            'original_name' => 'thing.txt',
            'mime_type' => 'text/plain',
            'size' => 12,
            'description' => null,
            'content_hash' => SqliteDatabase::HASH,
            'metadata' => '{}',
            'created_at' => '2026-01-01T00:00:00.000000+00:00',
            'updated_at' => '2026-01-01T00:00:00.000000+00:00',
            ...$overrides,
        ];
    }
}
