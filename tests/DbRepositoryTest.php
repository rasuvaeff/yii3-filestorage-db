<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Tests;

use DateTimeImmutable;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Store\BlobId;
use Rasuvaeff\Yii3FilestorageDb\DbRepository;
use Rasuvaeff\Yii3FilestorageDb\Exception\InvalidFileRowException;
use Rasuvaeff\Yii3FilestorageDb\Exception\MissingScopeException;
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

        $first = iterator_to_array($this->repository->files(limit: 2), preserve_keys: false);
        Assert::same(array_map(static fn(File $f): string => $f->id, $first), ['a', 'b']);

        $rest = iterator_to_array($this->repository->files(afterId: 'b'), preserve_keys: false);
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
        Assert::same(\count(iterator_to_array($this->repository->files(), preserve_keys: false)), 1);
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
        Assert::same(iterator_to_array($scoped->files(), preserve_keys: false), []);
    }

    /**
     * The orphan sweep's question: which rows live in these directories. A
     * row in a subdirectory, in another directory, or in another store is
     * not an answer, and a root-level row is its own directory.
     */
    public function referencedPathsAnswersForExactlyTheAskedDirectories(): void
    {
        $rows = [
            'a' => 'g/ab/cd/k1/original',
            'b' => 'g/ab/cd/k1/thumb.webp',
            'c' => 'g/ab/cd/k1/sub/deeper',
            'd' => 'g/ab/cd/k2/original',
            'e' => 'root.txt',
        ];
        foreach ($rows as $id => $path) {
            $this->repository->save(SqliteDatabase::file($id, relativePath: $path));
        }
        $this->repository->save(SqliteDatabase::file('f', relativePath: 'g/ab/cd/k1/elsewhere', storeName: 'other'));

        $paths = $this->repository->referencedPaths('upload', ['g/ab/cd/k1', 'root.txt']);
        sort($paths);

        Assert::same($paths, ['g/ab/cd/k1/original', 'g/ab/cd/k1/thumb.webp', 'root.txt']);
        Assert::same($this->repository->referencedPaths('upload', ['g/ab/cd/k1/sub']), ['g/ab/cd/k1/sub/deeper']);
        Assert::same($this->repository->referencedPaths('upload', ['g/ab/cd/k3']), []);
    }

    /**
     * `_` and `%` in a directory are characters, not wildcards. Unescaped, a
     * directory `a_b` would claim the rows of `axb`, and an orphan sweep would
     * keep bytes nothing references — or, the other way round, a caller would
     * learn about rows it never asked for.
     */
    public function referencedPathsTakesLikeWildcardsLiterally(): void
    {
        $this->repository->save(SqliteDatabase::file('a', relativePath: 'axb/original'));
        $this->repository->save(SqliteDatabase::file('b', relativePath: 'a_b/original'));
        $this->repository->save(SqliteDatabase::file('c', relativePath: '50x/original'));

        Assert::same($this->repository->referencedPaths('upload', ['a_b']), ['a_b/original']);
        Assert::same($this->repository->referencedPaths('upload', ['50%']), []);
    }

    /**
     * SQLite's `LIKE` ignores ASCII case, and MySQL's default collation
     * ignores case in `=` as well. The directory is compared exactly here, not
     * by the database.
     */
    public function referencedPathsComparesTheDirectoryExactly(): void
    {
        $this->repository->save(SqliteDatabase::file('a', relativePath: 'G/AB/original'));
        $this->repository->save(SqliteDatabase::file('b', relativePath: 'g/ab/original'));
        $this->repository->save(SqliteDatabase::file('c', relativePath: 'ROOT'));

        Assert::same($this->repository->referencedPaths('upload', ['g/ab', 'root']), ['g/ab/original']);
    }

    /**
     * Two rows sharing one deduplicated object are one path. And a numeric
     * path stays a string — PHP turns a numeric array key into an int.
     */
    public function referencedPathsListsEachPathOnceAndAsAString(): void
    {
        $this->repository->save(SqliteDatabase::file('a', relativePath: 'shared/original'));
        $this->repository->save(SqliteDatabase::file('b', relativePath: 'shared/original'));
        $this->repository->save(SqliteDatabase::file('c', relativePath: '123'));

        Assert::same($this->repository->referencedPaths('upload', ['shared', 'shared']), ['shared/original']);
        Assert::same($this->repository->referencedPaths('upload', ['123']), ['123']);
    }

    /**
     * A listing page can name more directories than one statement should bind.
     */
    public function referencedPathsAnswersForMoreDirectoriesThanOneBatch(): void
    {
        $directories = [];
        for ($i = 0; $i < 450; $i++) {
            $directories[] = "d{$i}";
            $this->repository->save(SqliteDatabase::file("f{$i}", relativePath: "d{$i}/original"));
        }

        Assert::same(\count($this->repository->referencedPaths('upload', $directories)), 450);
    }

    public function referencedPathsKeepsTheTenantPredicate(): void
    {
        $scope = new FixedScope('tenant-a');
        $scoped = new DbRepository($this->database->db, scopes: $scope);
        $scoped->save(SqliteDatabase::file('a', relativePath: 'g/original'));

        Assert::same($scoped->referencedPaths('upload', ['g']), ['g/original']);

        $scope->switchTo('tenant-b');

        Assert::same($scoped->referencedPaths('upload', ['g']), []);
    }

    /**
     * The probe from the 1.0 review (`nullscope.php`): a bound provider that
     * returns null used to drop the tenant predicate, so `find()` and
     * `delete()` reached every tenant's rows. Bound means multi-tenant; a null
     * there is a tenant that did not resolve, and it is refused.
     */
    public function aBoundProviderReturningNullIsRefusedRatherThanWidened(): void
    {
        $scope = new FixedScope('tenant-a');
        $scoped = new DbRepository($this->database->db, scopes: $scope);
        $scoped->save(SqliteDatabase::file('a', relativePath: 'g/original'));

        $scope->switchTo(null);

        $calls = [
            'find' => static fn(): mixed => $scoped->find('a'),
            'delete' => static fn(): mixed => $scoped->delete('a'),
            'files' => static fn(): mixed => iterator_to_array($scoped->files(), preserve_keys: false),
            'updateContentHash' => static fn(): mixed => $scoped->updateContentHash('a', SqliteDatabase::OTHER_HASH),
            'referencedPaths' => static fn(): mixed => $scoped->referencedPaths('upload', ['g']),
            'findBlobRowId' => static fn(): mixed => $scoped->findBlobRowId('a'),
            'save (update)' => static fn(): mixed => $scoped->save(SqliteDatabase::file('a', relativePath: 'g/original')),
            'save (insert)' => static fn(): mixed => $scoped->save(SqliteDatabase::file('new')),
        ];
        foreach ($calls as $name => $call) {
            Assert::true($this->refusedForMissingScope($call), "{$name} must refuse a null scope");
        }

        $scope->switchTo('tenant-a');
        Assert::same($scoped->find('a')?->id, 'a', 'nothing may have been deleted');
        Assert::null((new DbRepository($this->database->db))->find('new'), 'nothing may have been inserted');
    }

    /**
     * The opt-in for a maintenance entry point that shares the application's
     * provider: a null scope then means every tenant — for reads, updates and
     * deletes. A new row still needs a tenant.
     */
    public function globalAccessIsAnExplicitOptIn(): void
    {
        $scope = new FixedScope('tenant-a');
        (new DbRepository($this->database->db, scopes: $scope))->save(SqliteDatabase::file('a', relativePath: 'g/original'));
        $scope->switchTo('tenant-b');
        (new DbRepository($this->database->db, scopes: $scope))->save(SqliteDatabase::file('b', relativePath: 'g/other'));

        $scope->switchTo(null);
        $maintenance = new DbRepository($this->database->db, scopes: $scope, allowGlobalAccess: true);

        Assert::same(\count(iterator_to_array($maintenance->files(), preserve_keys: false)), 2);
        Assert::same($maintenance->find('a')?->id, 'a');
        $paths = $maintenance->referencedPaths('upload', ['g']);
        sort($paths);
        Assert::same($paths, ['g/original', 'g/other']);
        Assert::true($maintenance->updateContentHash('a', SqliteDatabase::OTHER_HASH));
        $maintenance->save(SqliteDatabase::file('b', relativePath: 'g/other', originalName: 'renamed.txt'));
        Assert::true($this->refusedForMissingScope(
            static fn(): mixed => $maintenance->save(SqliteDatabase::file('new')),
        ), 'a new row without a tenant must still be refused');
        Assert::true($maintenance->delete('a'));

        // the tenant survives a global update, and a resolved scope still filters
        $scope->switchTo('tenant-b');
        Assert::same($maintenance->find('b')?->originalName, 'renamed.txt');
        Assert::same(iterator_to_array($maintenance->files(), preserve_keys: false)[0]->id ?? null, 'b');
    }

    public function aTenantSeesItsOwnRows(): void
    {
        $scope = new FixedScope('tenant-a');
        $scoped = new DbRepository($this->database->db, scopes: $scope);
        $scoped->save(SqliteDatabase::file('a'));

        Assert::same($scoped->find('a')?->id, 'a');
        Assert::same(\count(iterator_to_array($scoped->files(), preserve_keys: false)), 1);
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
        $rows = iterator_to_array($scoped->files(afterId: 'a'), preserve_keys: false);

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

        // A nullable column is where a round trip most often loses: an empty
        // string written back as NULL, or NULL read back as ''. Both shapes
        // have to occur.
        Classify::cover($description === null, 'no description', 25.0);
        Classify::cover($description !== null, 'a description', 25.0);
        Classify::when($size === 0, 'a zero-byte file');
        Classify::when($microseconds === 0, 'a timestamp on the second');

        Assert::same($this->repository->find('round-trip')?->toArray(), $file->toArray());
    }

    /**
     * @return iterable<string, array{string, ?string, int, int}>
     */
    public static function anyFileSurvivesTheRoundTripExamples(): iterable
    {
        // An empty description and an absent one must stay distinguishable
        // across the column, and a zero-microsecond timestamp is where a
        // format string that trims trailing zeros changes the value.
        yield 'no description, zero bytes, on the second' => ['a.txt', null, 0, 0];
        yield 'empty description' => ['a.txt', '', 1, 0];
        yield 'last microsecond of the second' => ['a.txt', null, 1, 999_999];
        yield 'first microsecond of the second' => ['a.txt', null, 1, 1];
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

    /**
     * `RepositoryInterface::save()` takes a File and nothing else, so it is the
     * only way an application can persist an edited description — and writing
     * the whole row there set `blob_id` back to null. A null `blob_id` makes
     * the blob look unreferenced, and the next `filestorage:gc` deletes bytes
     * a live row still points at.
     */
    public function savingAnEditedFileDoesNotDetachItFromItsBlob(): void
    {
        $file = SqliteDatabase::file('a');
        $blob = BlobId::create('upload', $file->relativePath);

        $this->repository->save($file, $blob);
        $this->repository->save(File::create(
            id: $file->id,
            storeName: $file->storeName,
            groupName: $file->groupName,
            relativePath: $file->relativePath,
            originalName: $file->originalName,
            size: $file->size,
            createdAt: $file->createdAt,
            description: 'edited by the application',
            contentHash: $file->contentHash,
        ));

        $row = $this->database->db
            ->createCommand('SELECT blob_id, description FROM filestorage_file WHERE id = :id', [':id' => 'a'])
            ->queryOne();

        Assert::same($row['description'], 'edited by the application', 'the edit landed');
        Assert::true($row['blob_id'] !== null, 'and the blob reference survived it');
    }

    /**
     * The maintenance entry point this package's own commands recommend runs
     * with no scope provider bound. Writing `scope_id` from there would set
     * every touched row's tenant to null — a silent cross-tenant mutation
     * needing no concurrency at all.
     */
    public function savingWithoutAScopeProviderDoesNotClearTheTenant(): void
    {
        $scoped = new DbRepository($this->database->db, scopes: new FixedScope('tenant-a'));
        $scoped->save(SqliteDatabase::file('a'));

        // Unscoped, as a maintenance command is.
        $this->repository->save(SqliteDatabase::file('a', originalName: 'renamed.txt'));

        $row = $this->database->db
            ->createCommand('SELECT scope_id FROM filestorage_file WHERE id = :id', [':id' => 'a'])
            ->queryOne();

        Assert::same($row['scope_id'], 'tenant-a');
    }

    public function reportsWhetherItWritesThroughThatExactConnection(): void
    {
        $other = new SqliteDatabase();

        try {
            Assert::true($this->repository->usesConnection($this->database->db));
            Assert::false($this->repository->usesConnection($other->db));
        } finally {
            $other->close();
        }
    }

    /**
     * @param \Closure(): mixed $call
     */
    private function refusedForMissingScope(\Closure $call): bool
    {
        try {
            $call();
        } catch (MissingScopeException) {
            return true;
        }

        return false;
    }
}
