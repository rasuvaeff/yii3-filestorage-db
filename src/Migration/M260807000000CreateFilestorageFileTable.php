<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Migration;

use Rasuvaeff\Yii3FilestorageDb\FileTableName;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Creates the logical file table read and written by
 * {@see \Rasuvaeff\Yii3FilestorageDb\DbRepository}.
 *
 * The name comes from {@see FileTableName}, which `config/di.php` builds from
 * params — one source of truth for the migration and the repository alike.
 * Register the migration by namespace:
 *
 * ```php
 * MigrationService::class => [
 *     'setSourceNamespaces()' => [['Rasuvaeff\\Yii3FilestorageDb\\Migration']],
 * ],
 * ```
 *
 * @api
 */
final readonly class M260807000000CreateFilestorageFileTable implements
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
        $index = $this->table->forIndexName();

        $b->createTable($table, [
            // the application assigns the id before the write, so it is a
            // plain string column and never an auto-increment surrogate
            'id' => 'string(64) NOT NULL PRIMARY KEY',
            'store_name' => 'string(64) NOT NULL',
            'external_id' => 'string(255)',
            'group_name' => 'string(64) NOT NULL',
            'relative_path' => 'string(512) NOT NULL',
            'original_name' => 'string(255) NOT NULL',
            'mime_type' => 'string(255)',
            'size' => 'bigint NOT NULL',
            'description' => 'text',
            'content_hash' => 'string(64)',
            // JSON as text: the shape is `array<string, scalar|null>` and the
            // package never queries inside it, so a native JSON column would
            // buy nothing and cost portability
            'metadata' => 'text NOT NULL',
            // RFC 3339 with microseconds, matching File::toArray(). A native
            // timestamp column truncates on some drivers, and a truncated
            // timestamp breaks the round-trip contract File is tested against
            'created_at' => 'string(40) NOT NULL',
            'updated_at' => 'string(40) NOT NULL',
            // nullable on purpose: rows written by the base, non-sharing
            // facade own their object outright and have no ledger entry
            'blob_id' => 'string(64)',
            // set only in tenant mode; a single-tenant application leaves it
            // null and the repository applies no predicate
            'scope_id' => 'string(255)',
        ]);

        // index names follow the table name: in PostgreSQL they are unique per
        // schema, so two installations sharing one schema would collide on a
        // hard-coded name
        $b->createIndex($table, sprintf('idx_%s_scope_id', $index), ['scope_id', 'id']);
        $b->createIndex($table, sprintf('idx_%s_group_created', $index), ['group_name', 'created_at']);
        $b->createIndex($table, sprintf('idx_%s_content_hash', $index), 'content_hash');
        // the ledger asks "does anything still reference this blob?" on every
        // release and every collection pass; without this index that question
        // is a full scan of the largest table in the schema
        $b->createIndex($table, sprintf('idx_%s_blob_id', $index), 'blob_id');
    }

    #[\Override]
    public function down(MigrationBuilder $b): void
    {
        $b->dropTable($this->table->value);
    }
}
