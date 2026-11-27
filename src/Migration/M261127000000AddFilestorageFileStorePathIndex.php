<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Migration;

use Rasuvaeff\Yii3FilestorageDb\FileTableName;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Indexes the file table on `(store_name, relative_path)`.
 *
 * `filestorage:gc --orphans` asks {@see \Rasuvaeff\Yii3FilestorageDb\DbRepository::referencedPaths()}
 * once per listing page, with `relative_path LIKE '<dir>/%'`. Without this
 * index every page is a full scan of the largest table in the schema.
 *
 * The column definition differs per engine, and both differences matter:
 *
 * - **MySQL** indexes a prefix of the path. `utf8mb4` counts four bytes a
 *   character, and a 512-character path is 2048 bytes — over the 767 bytes a
 *   column may take in an index under the `COMPACT` row format. 191 characters
 *   fit everywhere and still separate every key the package generates.
 * - **PostgreSQL** uses `varchar_pattern_ops`. Under any collation other than
 *   `C` a plain btree is not used for `LIKE 'x%'`, so the index would exist and
 *   do nothing. The operator class serves `=` as well.
 *
 * Register the migration by namespace, with the other two:
 *
 * ```php
 * MigrationService::class => [
 *     'setSourceNamespaces()' => [['Rasuvaeff\\Yii3FilestorageDb\\Migration']],
 * ],
 * ```
 *
 * @api
 */
final readonly class M261127000000AddFilestorageFileStorePathIndex implements
    RevertibleMigrationInterface,
    TransactionalMigrationInterface
{
    public function __construct(
        private FileTableName $table = new FileTableName(),
    ) {}

    #[\Override]
    public function up(MigrationBuilder $b): void
    {
        $table = $this->table->value;
        $index = $this->indexName();

        match ($b->getDb()->getDriverName()) {
            'mysql' => $b->createIndex($table, $index, ['store_name', 'relative_path(191)']),
            'pgsql' => $b->execute(sprintf(
                'CREATE INDEX %s ON %s (%s, %s varchar_pattern_ops)',
                $b->getDb()->getQuoter()->quoteTableName($index),
                $b->getDb()->getQuoter()->quoteTableName($table),
                $b->getDb()->getQuoter()->quoteColumnName('store_name'),
                $b->getDb()->getQuoter()->quoteColumnName('relative_path'),
            )),
            default => $b->createIndex($table, $index, ['store_name', 'relative_path']),
        };
    }

    #[\Override]
    public function down(MigrationBuilder $b): void
    {
        $b->dropIndex($this->table->value, $this->indexName());
    }

    private function indexName(): string
    {
        return sprintf('idx_%s_store_path', $this->table->forIndexName());
    }
}
