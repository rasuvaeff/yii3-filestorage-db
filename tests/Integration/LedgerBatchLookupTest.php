<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Tests\Integration;

use DateTimeImmutable;
use Rasuvaeff\Yii3Filestorage\Store\BlobId;
use Rasuvaeff\Yii3Filestorage\Store\BlobState;
use Rasuvaeff\Yii3FilestorageDb\DbBlobLedger;
use Rasuvaeff\Yii3FilestorageDb\DbRepository;
use Rasuvaeff\Yii3FilestorageDb\Tests\Support\ServerDatabase;
use Rasuvaeff\Yii3FilestorageDb\Tests\Support\SqliteDatabase;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Test\Support\Clock\StaticClock;

/**
 * `DbBlobLedger::findMany()` on the configured database
 * (`FILESTORAGE_TEST_DB`=mysql|mariadb|pgsql; SQLite otherwise). Its one
 * statement carries correlated `COUNT(*)` subqueries and table aliases — SQL
 * each driver quotes and plans differently, and the MySQL leg runs under
 * `ANSI_QUOTES,NO_BACKSLASH_ESCAPES`.
 *
 * @internal
 */
#[Test]
#[CoversNothing]
final class LedgerBatchLookupTest
{
    private ?SqliteDatabase $database = null;
    private ConnectionInterface $db;
    private DbBlobLedger $ledger;

    #[BeforeTest]
    public function setUp(): void
    {
        $engine = ServerDatabase::engine();
        if ($engine === null) {
            $this->database = new SqliteDatabase();
            $this->db = $this->database->db;
        } else {
            $this->db = ServerDatabase::connect($engine, ServerDatabase::UNUSUAL_SQL_MODE);
            ServerDatabase::reset($this->db);
        }

        $this->ledger = new DbBlobLedger(
            db: $this->db,
            repository: new DbRepository($this->db),
            clock: new StaticClock($this->at('00:00')),
        );
    }

    #[AfterTest]
    public function tearDown(): void
    {
        if ($this->database === null) {
            ServerDatabase::drop($this->db);
            $this->db->close();
        } else {
            $this->database->close();
        }
    }

    public function findManyAnswersWhatFindAnswersOnTheConfiguredDatabase(): void
    {
        $active = BlobId::create('upload', 'sha/e3/b0/original');
        $pending = BlobId::create('upload', 'sha/da/39/original');
        $writing = BlobId::create('upload', 'sha/aa/bb/original');
        $this->ledger->commit(
            $this->ledger->reserve($active, SqliteDatabase::HASH, 12, $this->at('00:10')),
            SqliteDatabase::file('a'),
        );
        $this->ledger->commit(
            $this->ledger->reserve($active, SqliteDatabase::HASH, 12, $this->at('00:10')),
            SqliteDatabase::file('b'),
        );
        $this->ledger->reserve($active, SqliteDatabase::HASH, 12, $this->at('00:10'));
        $this->ledger->commit(
            $this->ledger->reserve($pending, SqliteDatabase::OTHER_HASH, 34, $this->at('00:10')),
            SqliteDatabase::file('c', relativePath: $pending->relativePath()),
        );
        $this->ledger->releaseFile('c', $this->at('01:00'));
        $this->ledger->reserve($writing, SqliteDatabase::HASH, 5, $this->at('00:10'));

        $records = $this->ledger->findMany([
            $writing,
            BlobId::create('upload', 'sha/ff/ff/original'),
            $active,
            $pending,
        ]);

        Assert::same(array_keys($records), [$writing->key(), $active->key(), $pending->key()]);
        foreach ([$writing, $active, $pending] as $blob) {
            Assert::equals($records[$blob->key()], $this->ledger->find($blob));
        }
        Assert::same($records[$active->key()]->referenceCount, 2);
        Assert::same($records[$active->key()]->reservationCount, 1);
        Assert::same($records[$pending->key()]->state, BlobState::PendingDelete);
        Assert::same($records[$pending->key()]->referenceCount, 0);
        Assert::same($records[$writing->key()]->reservationCount, 1);
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable("2026-01-01T{$time}:00.000000+00:00");
    }
}
