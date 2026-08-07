<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Tests;

use DateTimeImmutable;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3FilestorageDb\DbRepository;
use Rasuvaeff\Yii3FilestorageDb\Exception\InvalidFileRowException;
use Rasuvaeff\Yii3FilestorageDb\FileTableName;
use Rasuvaeff\Yii3FilestorageDb\Tests\Support\FixedScope;
use Rasuvaeff\Yii3FilestorageDb\Tests\Support\SqliteDatabase;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Db\Query\Query;

#[Test]
#[Covers(DbRepository::class)]
final class DbRepositoryTest
{
    private SqliteDatabase $database;
    private DbRepository $repository;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->database = new SqliteDatabase();
        $this->repository = new DbRepository($this->database->db);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->database->close();
    }

    public function savesAndReadsBackEveryColumn(): void
    {
        $file = SqliteDatabase::file('a', metadata: ['width' => 1024, 'alt' => 'cat', 'draft' => false]);

        $this->repository->save($file);

        $found = $this->repository->find('a');
        Assert::same($found?->id, 'a');
        Assert::same($found?->storeName, 'upload');
        Assert::same($found?->relativePath, 'sha/e3/b0/original');
        Assert::same($found?->originalName, 'thing.txt');
        Assert::same($found?->mimeType, 'text/plain');
        Assert::same($found?->size, 12);
        Assert::same($found?->contentHash, SqliteDatabase::HASH);
        Assert::same($found?->metadata, ['width' => 1024, 'alt' => 'cat', 'draft' => false]);
    }

    public function findReturnsNullForAnUnknownIdentifier(): void
    {
        Assert::null($this->repository->find('nope'));
    }

    public function savingTheSameIdentifierReplacesTheRow(): void
    {
        $this->repository->save(SqliteDatabase::file('a', originalName: 'first.txt'));
        $this->repository->save(SqliteDatabase::file('a', originalName: 'second.txt'));

        Assert::same($this->repository->find('a')?->originalName, 'second.txt');
        Assert::same((new Query($this->database->db))->from('filestorage_file')->count(), 1);
    }

    public function savingAnUnchangedFileIsIdempotent(): void
    {
        $file = SqliteDatabase::file('same');
        $this->repository->save($file);

        $this->repository->save($file);

        Assert::same($this->repository->find($file->id)?->id, $file->id);
    }

    public function deleteReportsWhetherThereWasAnything(): void
    {
        $this->repository->save(SqliteDatabase::file('a'));

        Assert::true($this->repository->delete('a'));
        Assert::false($this->repository->delete('a'));
    }

    public function filesPagesInIdentifierOrderAndResumes(): void
    {
        foreach (['c', 'a', 'b'] as $id) {
            $this->repository->save(SqliteDatabase::file($id));
        }

        $first = iterator_to_array($this->repository->files(limit: 2), false);
        Assert::same(array_map(static fn(File $f): string => $f->id, $first), ['a', 'b']);

        $rest = iterator_to_array($this->repository->files(afterId: 'b'), false);
        Assert::same(array_map(static fn(File $f): string => $f->id, $rest), ['c']);
    }

    public function updateContentHashRewritesOnlyTheHash(): void
    {
        $this->repository->save(SqliteDatabase::file('a'));

        Assert::true($this->repository->updateContentHash('a', SqliteDatabase::OTHER_HASH));

        $found = $this->repository->find('a');
        Assert::same($found?->contentHash, SqliteDatabase::OTHER_HASH);
        Assert::same($found?->originalName, 'thing.txt');
    }

    public function updateContentHashReportsAMissingRow(): void
    {
        Assert::false($this->repository->updateContentHash('nope', SqliteDatabase::OTHER_HASH));
    }

    /**
     * Microseconds survive. A column that truncated them would reintroduce
     * one layer down the bug `File`'s timestamp format exists to avoid.
     */
    public function microsecondsSurviveTheRoundTrip(): void
    {
        $createdAt = new DateTimeImmutable('2026-01-01T00:00:00.123456+00:00');
        $this->repository->save(SqliteDatabase::file('a', createdAt: $createdAt));

        Assert::same($this->repository->find('a')?->createdAt->format('u'), '123456');
    }

    /**
     * Written from a non-UTC offset, stored as UTC. The ledger compares
     * deadlines as strings, and mixed offsets would sort wrongly.
     */
    public function timestampsAreNormalisedToUtc(): void
    {
        $this->repository->save(
            SqliteDatabase::file('a', createdAt: new DateTimeImmutable('2026-01-01T03:00:00.000000+03:00')),
        );

        /** @var array<string, mixed>|null $row */
        $row = (new Query($this->database->db))->from('filestorage_file')->where(['id' => 'a'])->one();
        Assert::same($row['created_at'] ?? null, '2026-01-01T00:00:00.000000+00:00');
    }

    /**
     * Every statement has to name the row it means. A predicate reduced to the
     * tenant alone still passes any single-row test — and wipes the tenant's
     * whole table the first time it runs in production.
     */
    public function everyStatementTouchesOnlyItsOwnRow(): void
    {
        $this->repository->save(SqliteDatabase::file('a', originalName: 'a.txt'));
        $this->repository->save(SqliteDatabase::file('b', originalName: 'b.txt'));

        Assert::same($this->repository->find('a')?->originalName, 'a.txt');
        Assert::true($this->repository->updateContentHash('a', SqliteDatabase::OTHER_HASH));
        Assert::same($this->repository->find('b')?->contentHash, SqliteDatabase::HASH);
        Assert::true($this->repository->delete('a'));
        Assert::same($this->repository->find('b')?->originalName, 'b.txt');
    }

    /**
     * The same, with a tenant bound: the predicate is `id AND scope`, never
     * one of the two.
     */
    public function aScopedStatementStillTouchesOnlyItsOwnRow(): void
    {
        $scoped = new DbRepository($this->database->db, scopes: new FixedScope('tenant-a'));
        $scoped->save(SqliteDatabase::file('a', originalName: 'a.txt'));
        $scoped->save(SqliteDatabase::file('b', originalName: 'b.txt'));

        Assert::same($scoped->find('a')?->originalName, 'a.txt');
        Assert::true($scoped->updateContentHash('a', SqliteDatabase::OTHER_HASH));
        Assert::same($scoped->find('b')?->contentHash, SqliteDatabase::HASH);
        Assert::true($scoped->delete('a'));
        Assert::same($scoped->find('b')?->originalName, 'b.txt');
    }

    /**
     * No provider bound means no predicate at all, not a predicate on null.
     * A repository that quietly filtered on `scope_id IS NULL` would hide
     * every row a tenant-aware writer had already created.
     */
    public function withNoScopeProviderEveryRowIsVisible(): void
    {
        (new DbRepository($this->database->db, scopes: new FixedScope('tenant-a')))
            ->save(SqliteDatabase::file('a'));

        Assert::same($this->repository->find('a')?->id, 'a');
        Assert::same(\count(iterator_to_array($this->repository->files(), false)), 1);
    }

    public function aRowWrittenByAnotherTenantIsInvisible(): void
    {
        $scope = new FixedScope('tenant-a');
        $scoped = new DbRepository($this->database->db, scopes: $scope);
        $scoped->save(SqliteDatabase::file('a'));

        $scope->switchTo('tenant-b');

        Assert::null($scoped->find('a'));
        Assert::false($scoped->delete('a'));
        Assert::false($scoped->updateContentHash('a', SqliteDatabase::OTHER_HASH));
        Assert::same(iterator_to_array($scoped->files(), false), []);
    }

    public function aTenantSeesItsOwnRows(): void
    {
        $scope = new FixedScope('tenant-a');
        $scoped = new DbRepository($this->database->db, scopes: $scope);
        $scoped->save(SqliteDatabase::file('a'));

        Assert::same($scoped->find('a')?->id, 'a');
        Assert::same(\count(iterator_to_array($scoped->files(), false)), 1);
    }

    /**
     * The cursor and the tenant predicate have to compose. An `afterId` that
     * dropped the scope would page one tenant's rows into another's listing.
     */
    public function theCursorKeepsTheTenantPredicate(): void
    {
        $scope = new FixedScope('tenant-a');
        $scoped = new DbRepository($this->database->db, scopes: $scope);
        $scoped->save(SqliteDatabase::file('a'));
        $scoped->save(SqliteDatabase::file('c'));

        $scope->switchTo('tenant-b');
        $scoped->save(SqliteDatabase::file('b'));

        $scope->switchTo('tenant-a');
        $rows = iterator_to_array($scoped->files(afterId: 'a'), false);

        Assert::same(array_map(static fn(File $f): string => $f->id, $rows), ['c']);
    }

    /**
     * An upsert matches on the primary key alone, so it would have handed the
     * row over. This is the test that keeps `save()` from becoming one.
     */
    public function anotherTenantCannotTakeOverAnExistingRow(): void
    {
        $scope = new FixedScope('tenant-a');
        $scoped = new DbRepository($this->database->db, scopes: $scope);
        $scoped->save(SqliteDatabase::file('a', originalName: 'mine.txt'));

        $scope->switchTo('tenant-b');
        Expect::exception(\Throwable::class);

        $scoped->save(SqliteDatabase::file('a', originalName: 'theirs.txt'));
    }

    public function aCorruptedRowIsAnExceptionRatherThanAPlausibleFile(): void
    {
        $this->database->db->createCommand()->insert('filestorage_file', [
            'id' => 'a',
            'store_name' => 'upload',
            'group_name' => 'common',
            'relative_path' => 'a/original',
            'original_name' => 'thing.txt',
            'size' => -1,
            'metadata' => '{}',
            'created_at' => '2026-01-01T00:00:00.000000+00:00',
            'updated_at' => '2026-01-01T00:00:00.000000+00:00',
        ])->execute();

        Expect::exception(InvalidFileRowException::class)->withMessageContaining('"size" must hold a non-negative');

        $this->repository->find('a');
    }

    public function findBlobRowIdIsNullForARowTheBaseFacadeWrote(): void
    {
        $this->repository->save(SqliteDatabase::file('a'));

        Assert::null($this->repository->findBlobRowId('a'));
        Assert::null($this->repository->findBlobRowId('nope'));
    }

    public function theTableNameComesFromTheValueObject(): void
    {
        $this->database->db->createCommand(
            'CREATE TABLE other_files AS SELECT * FROM filestorage_file',
        )->execute();

        $other = new DbRepository($this->database->db, new FileTableName('other_files'));
        $other->save(SqliteDatabase::file('a'));

        Assert::same($other->find('a')?->id, 'a');
        Assert::null($this->repository->find('a'));
    }

    /**
     * The round trip is a contract, not a happy path: every value a `File` can
     * carry has to survive a column. Names, descriptions and metadata are the
     * parts users control, so they are the parts generated here.
     */
    #[Property(runs: 120)]
    public function anyFileSurvivesTheRoundTrip(
        string $originalName,
        ?string $description,
        int $size,
        int $microseconds,
    ): void {
        $file = File::create(
            id: 'round-trip',
            storeName: 'upload',
            groupName: 'common',
            relativePath: 'a/b/original',
            originalName: $originalName === '' ? 'file' : $originalName,
            size: $size,
            createdAt: new DateTimeImmutable(
                sprintf('2026-01-01T00:00:00.%06d+00:00', $microseconds),
            ),
            description: $description,
            metadata: ['n' => $size, 'name' => $originalName, 'flag' => $size % 2 === 0, 'nothing' => null],
        );

        $this->repository->save($file);

        Assert::same($this->repository->find('round-trip')?->toArray(), $file->toArray());
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function anyFileSurvivesTheRoundTripGenerators(): array
    {
        return [
            'originalName' => Gen::stringAscii(),
            'description' => Gen::nullable(Gen::stringAscii()),
            'size' => Gen::intBetween(0, 1_000_000_000),
            'microseconds' => Gen::intBetween(0, 999_999),
        ];
    }
}
