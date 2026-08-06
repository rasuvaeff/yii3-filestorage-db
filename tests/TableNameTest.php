<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Tests;

use InvalidArgumentException;
use Rasuvaeff\Yii3Filestorage\Store\BlobId;
use Rasuvaeff\Yii3FilestorageDb\BlobReservationTableName;
use Rasuvaeff\Yii3FilestorageDb\BlobRowId;
use Rasuvaeff\Yii3FilestorageDb\BlobTableName;
use Rasuvaeff\Yii3FilestorageDb\FileTableName;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(FileTableName::class)]
#[Covers(BlobTableName::class)]
#[Covers(BlobReservationTableName::class)]
#[Covers(BlobRowId::class)]
final class TableNameTest
{
    public function eachTableHasItsDefault(): void
    {
        Assert::same((new FileTableName())->value, 'filestorage_file');
        Assert::same((new BlobTableName())->value, 'filestorage_blob');
        Assert::same((new BlobReservationTableName())->value, 'filestorage_blob_reservation');
    }

    public function stringifiesToItsValue(): void
    {
        Assert::same((string) new FileTableName('files'), 'files');
        Assert::same((string) new BlobTableName('blobs'), 'blobs');
        Assert::same((string) new BlobReservationTableName('claims'), 'claims');
    }

    /**
     * PostgreSQL index names are unique per schema, so they are derived from
     * the table name — and a schema-qualified name cannot appear verbatim in
     * one.
     */
    public function schemaQualifiedNamesFlattenForIndexNames(): void
    {
        Assert::same((new FileTableName('app.files'))->forIndexName(), 'app_files');
        Assert::same((new BlobTableName('app.blobs'))->forIndexName(), 'app_blobs');
        Assert::same((new BlobReservationTableName('app.claims'))->forIndexName(), 'app_claims');
    }

    #[DataProvider('invalidNameProvider')]
    public function rejectsAnythingThatIsNotAnIdentifier(string $name): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('Invalid table name');

        new FileTableName($name);
    }

    #[DataProvider('invalidNameProvider')]
    public function everyTableValidatesTheSameWay(string $name): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('Invalid table name');

        new BlobTableName($name);
    }

    #[DataProvider('invalidNameProvider')]
    public function includingTheReservationTable(string $name): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('Invalid table name');

        new BlobReservationTableName($name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNameProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'a space' => ['my table'];
        yield 'a quote' => ["files'; DROP TABLE users; --"];
        yield 'a leading digit' => ['1files'];
        yield 'two dots' => ['a.b.c'];
        yield 'a trailing newline' => ["files\n"];
        yield 'a semicolon' => ['files;'];
    }

    /**
     * A blob's key is `(store, path)`, and the row id is a hash of it: the
     * pair itself is too long for a MySQL index. What matters is that
     * different pairs give different ids and the same pair always gives the
     * same one.
     */
    public function theRowIdentifierIsDeterministicAndPairSpecific(): void
    {
        $id = BlobRowId::for(BlobId::create('upload', 'a/b/original'));

        Assert::same($id, BlobRowId::for(BlobId::create('upload', 'a/b/original')));
        Assert::same(preg_match('/^[a-f0-9]{64}\z/', $id), 1);
        Assert::true($id !== BlobRowId::for(BlobId::create('archive', 'a/b/original')));
        Assert::true($id !== BlobRowId::for(BlobId::create('upload', 'a/b/other')));
    }
}
