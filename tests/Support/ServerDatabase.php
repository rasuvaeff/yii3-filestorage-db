<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Tests\Support;

use Rasuvaeff\Yii3FilestorageDb\Migration\M260807000000CreateFilestorageFileTable;
use Rasuvaeff\Yii3FilestorageDb\Migration\M260807000001CreateFilestorageBlobTables;
use Rasuvaeff\Yii3FilestorageDb\Migration\M261127000000AddFilestorageFileStorePathIndex;
use Rasuvaeff\Yii3FilestorageDb\Migration\M261128000000MakeFilestorageFileScopeIdCaseSensitive;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Migration\Informer\NullMigrationInformer;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Mysql\Connection as MysqlConnection;
use Yiisoft\Db\Mysql\Driver as MysqlDriver;
use Yiisoft\Db\Pgsql\Connection as PgsqlConnection;
use Yiisoft\Db\Pgsql\Driver as PgsqlDriver;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

/**
 * Connections to the MySQL, MariaDB or PostgreSQL server `FILESTORAGE_TEST_DB`
 * names, for the integration suite. CI's `database-integration` job supplies
 * all three. MariaDB is its own engine here, not "MySQL again": 11.6.2+ fails a
 * REPEATABLE READ select-then-update with error 1020, and its collations pad
 * differently — neither shows on MySQL.
 *
 * @internal
 */
final readonly class ServerDatabase
{
    public const array TABLES = ['filestorage_blob_reservation', 'filestorage_blob', 'filestorage_file'];

    /**
     * A MySQL `sql_mode` that is nothing like the default, for connections
     * that must prove the package does not depend on it: no backslash escapes
     * in literals, double quotes as identifier quotes.
     */
    public const string UNUSUAL_SQL_MODE = 'NO_BACKSLASH_ESCAPES,ANSI_QUOTES';

    /**
     * @return 'mysql'|'mariadb'|'pgsql'|null
     */
    public static function engine(): ?string
    {
        $engine = getenv('FILESTORAGE_TEST_DB');

        return in_array($engine, ['mysql', 'mariadb', 'pgsql'], strict: true) ? $engine : null;
    }

    /**
     * @param 'mysql'|'mariadb'|'pgsql' $engine
     * @param string|null $sqlMode Added to the MySQL-family session `sql_mode`; ignored on PostgreSQL.
     */
    public static function connect(string $engine, ?string $sqlMode = null): ConnectionInterface
    {
        $cache = new SchemaCache(psrCache: new MemorySimpleCache());
        $mysqlPort = $engine === 'mariadb'
            ? (getenv('FILESTORAGE_TEST_MARIADB_PORT') ?: '3307')
            : (getenv('FILESTORAGE_TEST_MYSQL_PORT') ?: '3306');
        $pgsqlPort = getenv('FILESTORAGE_TEST_PGSQL_PORT') ?: '5432';

        $db = $engine !== 'pgsql'
            ? new MysqlConnection(
                driver: new MysqlDriver(
                    dsn: sprintf('mysql:host=127.0.0.1;port=%s;dbname=filestorage;charset=utf8mb4', $mysqlPort),
                    username: 'root',
                    password: 'filestorage',
                ),
                schemaCache: $cache,
            )
            : new PgsqlConnection(
                driver: new PgsqlDriver(
                    dsn: sprintf('pgsql:host=127.0.0.1;port=%s;dbname=filestorage', $pgsqlPort),
                    username: 'postgres',
                    password: 'filestorage',
                ),
                schemaCache: $cache,
            );
        $db->open();

        if ($engine !== 'pgsql' && $sqlMode !== null) {
            $db->createCommand('SET SESSION sql_mode = CONCAT(@@sql_mode, :mode)', [':mode' => ',' . $sqlMode])
                ->execute();
        }

        return $db;
    }

    /**
     * Drops this package's tables and runs every migration.
     */
    public static function reset(ConnectionInterface $db): void
    {
        self::drop($db);

        $builder = new MigrationBuilder(db: $db, informer: new NullMigrationInformer());
        (new M260807000000CreateFilestorageFileTable())->up($builder);
        (new M260807000001CreateFilestorageBlobTables())->up($builder);
        (new M261127000000AddFilestorageFileStorePathIndex())->up($builder);
        (new M261128000000MakeFilestorageFileScopeIdCaseSensitive())->up($builder);
    }

    public static function drop(ConnectionInterface $db): void
    {
        foreach (self::TABLES as $table) {
            $db->createCommand(sprintf('DROP TABLE IF EXISTS %s', $db->getQuoter()->quoteTableName($table)))->execute();
        }
    }
}
