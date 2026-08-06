<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Tests;

use Rasuvaeff\Yii3FilestorageDb\DbRepository;
use Rasuvaeff\Yii3FilestorageDb\DbScopedFileResolver;
use Rasuvaeff\Yii3FilestorageDb\FileTableName;
use Rasuvaeff\Yii3FilestorageDb\Tests\Support\FixedScope;
use Rasuvaeff\Yii3FilestorageDb\Tests\Support\SqliteDatabase;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(DbScopedFileResolver::class)]
final class DbScopedFileResolverTest
{
    private SqliteDatabase $database;
    private DbScopedFileResolver $resolver;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->database = new SqliteDatabase();
        $this->resolver = new DbScopedFileResolver($this->database->db);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->database->close();
    }

    public function resolvesAFileInItsOwnScope(): void
    {
        $this->saveAs('tenant-a', 'a');

        Assert::same($this->resolver->findInScope('a', 'tenant-a')?->id, 'a');
    }

    /**
     * The whole reason this class exists. A token minted for one tenant must
     * not resolve another's file, and the answer has to be indistinguishable
     * from "no such file" — a different error would confirm the id exists.
     */
    public function aTokenFromAnotherScopeResolvesToNothing(): void
    {
        $this->saveAs('tenant-a', 'a');

        Assert::null($this->resolver->findInScope('a', 'tenant-b'));
    }

    /**
     * `null` is a scope, not the absence of one: it matches `scope_id IS NULL`,
     * which is what an unscoped application's rows carry. A token with no
     * scope must not become a wildcard.
     */
    public function anUnscopedTokenMatchesOnlyUnscopedRows(): void
    {
        $this->saveAs(null, 'unscoped');
        $this->saveAs('tenant-a', 'scoped');

        Assert::same($this->resolver->findInScope('unscoped', null)?->id, 'unscoped');
        Assert::null($this->resolver->findInScope('scoped', null));
    }

    public function aScopedTokenDoesNotMatchAnUnscopedRow(): void
    {
        $this->saveAs(null, 'unscoped');

        Assert::null($this->resolver->findInScope('unscoped', 'tenant-a'));
    }

    public function anUnknownIdentifierResolvesToNothing(): void
    {
        Assert::null($this->resolver->findInScope('nope', 'tenant-a'));
    }

    public function theTableNameComesFromTheValueObject(): void
    {
        $this->database->db->createCommand(
            'CREATE TABLE other_files AS SELECT * FROM filestorage_file',
        )->execute();
        (new DbRepository($this->database->db, new FileTableName('other_files')))
            ->save(SqliteDatabase::file('a'));

        $other = new DbScopedFileResolver($this->database->db, new FileTableName('other_files'));

        Assert::same($other->findInScope('a', null)?->id, 'a');
        Assert::null($this->resolver->findInScope('a', null));
    }

    private function saveAs(?string $scopeId, string $id): void
    {
        (new DbRepository($this->database->db, scopes: new FixedScope($scopeId)))
            ->save(SqliteDatabase::file($id));
    }
}
