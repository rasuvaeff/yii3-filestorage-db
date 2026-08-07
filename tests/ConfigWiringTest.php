<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Tests;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Filestorage\Repository\FileScopeProviderInterface;
use Rasuvaeff\Yii3Filestorage\Repository\MaintenanceRepositoryInterface;
use Rasuvaeff\Yii3Filestorage\Repository\RepositoryInterface;
use Rasuvaeff\Yii3Filestorage\Repository\ScopedFileResolverInterface;
use Rasuvaeff\Yii3Filestorage\StorageInterface;
use Rasuvaeff\Yii3Filestorage\Store\BlobLedgerInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreInterface;
use Rasuvaeff\Yii3FilestorageDb\BlobReservationTableName;
use Rasuvaeff\Yii3FilestorageDb\BlobTableName;
use Rasuvaeff\Yii3FilestorageDb\DbBlobLedger;
use Rasuvaeff\Yii3FilestorageDb\DbRepository;
use Rasuvaeff\Yii3FilestorageDb\DbScopedFileResolver;
use Rasuvaeff\Yii3FilestorageDb\DeduplicatingStorageFactory;
use Rasuvaeff\Yii3FilestorageDb\FileTableName;
use Rasuvaeff\Yii3FilestorageDb\Tests\Support\FixedScope;
use Rasuvaeff\Yii3FilestorageDb\Tests\Support\SqliteDatabase;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;
use Yiisoft\Test\Support\Clock\StaticClock;

/**
 * `config/di.php` is covered by neither cs, nor psalm, nor the unit suite — it
 * is not in `src`. Without this test a mistake there surfaces at deploy time,
 * so it is exercised through a real container rather than by reading the array.
 *
 * @internal
 */
#[Test]
#[CoversNothing]
final class ConfigWiringTest
{
    private SqliteDatabase $database;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->database = new SqliteDatabase();
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->database->close();
    }

    public function everyServiceThisPackageOwnsResolves(): void
    {
        $container = $this->container();

        Assert::instanceOf($container->get(RepositoryInterface::class), DbRepository::class);
        Assert::instanceOf($container->get(MaintenanceRepositoryInterface::class), DbRepository::class);
        Assert::instanceOf($container->get(ScopedFileResolverInterface::class), DbScopedFileResolver::class);
        Assert::instanceOf($container->get(BlobLedgerInterface::class), DbBlobLedger::class);
        Assert::instanceOf($container->get(FileTableName::class), FileTableName::class);
        Assert::instanceOf($container->get(BlobTableName::class), BlobTableName::class);
        Assert::instanceOf($container->get(BlobReservationTableName::class), BlobReservationTableName::class);
    }

    /**
     * Both repository contracts must be the same object: M5's commands resolve
     * the maintenance one, and a second instance would be a second connection
     * with its own transaction — the ledger's joint commit depends on there
     * being exactly one.
     */
    public function bothRepositoryContractsResolveToOneInstance(): void
    {
        $container = $this->container();

        Assert::same(
            $container->get(RepositoryInterface::class),
            $container->get(MaintenanceRepositoryInterface::class),
        );
    }

    public function theWiredRepositoryActuallyReachesTheDatabase(): void
    {
        $repository = $this->container()->get(RepositoryInterface::class);
        $repository->save(SqliteDatabase::file('a'));

        Assert::same($repository->find('a')?->id, 'a');
    }

    public function theWiredLedgerCommitsThroughTheWiredRepository(): void
    {
        $container = $this->container();
        $ledger = $container->get(BlobLedgerInterface::class);
        $blob = \Rasuvaeff\Yii3Filestorage\Store\BlobId::create('upload', 'sha/e3/b0/original');

        $reservation = $ledger->reserve(
            $blob,
            SqliteDatabase::HASH,
            12,
            new DateTimeImmutable('2026-01-01T00:10:00.000000+00:00'),
        );
        $ledger->commit($reservation, SqliteDatabase::file('a'));

        Assert::same($container->get(RepositoryInterface::class)->find('a')?->id, 'a');
        Assert::same($ledger->find($blob)?->referenceCount, 1);
    }

    /**
     * The factory is bound, the deduplicating facade is not. Replacing
     * `StorageInterface` is a root-application decision — core owns that key,
     * and this package claiming it too would be the `Duplicate key` error the
     * whole arrangement exists to prevent.
     */
    public function dedupIsOfferedAsAFactoryRatherThanABinding(): void
    {
        $definitions = $this->definitions();

        Assert::true(\array_key_exists(DeduplicatingStorageFactory::class, $definitions));
        Assert::false(\array_key_exists(StorageInterface::class, $definitions));
    }

    /**
     * The one-source rule. Core binds the facade; this package binds the
     * metadata half. Either side claiming the other's key makes installing
     * both a `yiisoft/config` `Duplicate key` error.
     */
    public function thisPackageBindsNeitherTheFacadeNorTheStore(): void
    {
        $definitions = $this->definitions();

        Assert::false(\array_key_exists(StorageInterface::class, $definitions));
        Assert::false(\array_key_exists(StoreInterface::class, $definitions));
    }

    /**
     * Only the application knows what a tenant is. A backend package binding
     * this would force one tenancy library on everybody and be wrong in every
     * installation that does it differently.
     */
    public function theScopeProviderIsLeftToTheApplication(): void
    {
        Assert::false(\array_key_exists(FileScopeProviderInterface::class, $this->definitions()));
    }

    /**
     * Unbound is the single-tenant case, and it has to resolve rather than
     * explode — this is the nullable-constructor-default shape that has
     * bitten this monorepo before.
     */
    public function theRepositoryResolvesWithNoScopeProviderBound(): void
    {
        $repository = $this->container()->get(RepositoryInterface::class);
        $repository->save(SqliteDatabase::file('a'));

        Assert::same($repository->find('a')?->id, 'a');
    }

    public function bindingAScopeProviderMakesTheRepositoryScoped(): void
    {
        $container = $this->container([
            FileScopeProviderInterface::class => static fn(): FileScopeProviderInterface
                => new FixedScope('tenant-a'),
        ]);
        $repository = $container->get(RepositoryInterface::class);
        $repository->save(SqliteDatabase::file('a'));

        Assert::null(
            $container->get(ScopedFileResolverInterface::class)->findInScope('a', 'tenant-b'),
        );
        Assert::same(
            $container->get(ScopedFileResolverInterface::class)->findInScope('a', 'tenant-a')?->id,
            'a',
        );
    }

    /**
     * `params.php` has to carry every key `di.php` reads, or the package fails
     * to boot against its own defaults.
     */
    public function everyParameterTheWiringReadsIsShipped(): void
    {
        $own = $this->params()['rasuvaeff/yii3-filestorage-db'];

        foreach (['fileTable', 'blobTable', 'blobReservationTable', 'tablePrefix'] as $key) {
            Assert::true(\array_key_exists($key, $own), "params is missing \"{$key}\"");
        }
    }

    /**
     * The prefix has to reach all three names, or a prefixed installation gets
     * two of its tables namespaced and one not.
     */
    public function theTablePrefixReachesEveryName(): void
    {
        $container = $this->container(params: ['tablePrefix' => 'app_']);

        Assert::same($container->get(FileTableName::class)->value, 'app_filestorage_file');
        Assert::same($container->get(BlobTableName::class)->value, 'app_filestorage_blob');
        Assert::same(
            $container->get(BlobReservationTableName::class)->value,
            'app_filestorage_blob_reservation',
        );
    }

    /**
     * @param array<string, mixed> $extra
     * @param array<string, mixed> $params
     */
    private function container(array $extra = [], array $params = []): Container
    {
        $definitions = $this->definitions($params);

        $definitions[ConnectionInterface::class] = fn(): ConnectionInterface => $this->database->db;
        $definitions[ClockInterface::class] = static fn(): ClockInterface => new StaticClock(
            new DateTimeImmutable('2026-01-01T00:00:00.000000+00:00'),
        );

        return new Container(ContainerConfig::create()->withDefinitions($definitions + $extra));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function definitions(array $overrides = []): array
    {
        $params = $this->params();
        $params['rasuvaeff/yii3-filestorage-db'] = [
            ...$params['rasuvaeff/yii3-filestorage-db'],
            ...$overrides,
        ];

        return require __DIR__ . '/../config/di.php';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function params(): array
    {
        return require __DIR__ . '/../config/params.php';
    }
}
