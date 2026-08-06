<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Migration;

use Rasuvaeff\Yii3FilestorageDb\BlobReservationTableName;
use Rasuvaeff\Yii3FilestorageDb\BlobTableName;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Creates the deduplication ledger read and written by
 * {@see \Rasuvaeff\Yii3FilestorageDb\DbBlobLedger}.
 *
 * Two tables, not one. A blob may be claimed by several writers at once —
 * uploading identical content is the normal case — and each claim has to be
 * expirable on its own, so that one writer's process dying does not release
 * another's. A counter column cannot express that: two writers incrementing
 * the same number are indistinguishable, and recovery becomes guesswork.
 *
 * There is deliberately **no** `reference_count` column. References are
 * `filestorage_file` rows carrying this blob's id, and the ledger asks about
 * them with `NOT EXISTS`. A denormalised counter would be a second source of
 * truth that can drift from the first, and guarding a counter against
 * underflow is strictly harder than not having one.
 *
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
final readonly class M260807000001CreateFilestorageBlobTables implements
    RevertibleMigrationInterface,
    TransactionalMigrationInterface
{
    public function __construct(
        private BlobTableName $blobTable = new BlobTableName(),
        private BlobReservationTableName $reservationTable = new BlobReservationTableName(),
    ) {}

    #[\Override]
    public function up(MigrationBuilder $b): void
    {
        $blobs = $this->blobTable->value;
        $blobIndex = $this->blobTable->forIndexName();

        $b->createTable($blobs, [
            // derived from (store_name, relative_path), so two writers racing
            // to create the same blob collide on the primary key rather than
            // producing two rows for one object. A unique index over the pair
            // would say the same thing but can exceed the key-length limit on
            // MySQL, because a relative path is up to 512 characters
            'id' => 'string(64) NOT NULL PRIMARY KEY',
            'store_name' => 'string(64) NOT NULL',
            'relative_path' => 'string(512) NOT NULL',
            'content_hash' => 'string(64) NOT NULL',
            'size' => 'bigint NOT NULL',
            'state' => 'string(16) NOT NULL',
            'delete_after' => 'string(40)',
            'lease_token' => 'string(64)',
            'lease_expires_at' => 'string(40)',
            'created_at' => 'string(40) NOT NULL',
            'updated_at' => 'string(40) NOT NULL',
        ]);

        // the collection pass selects on exactly this pair
        $b->createIndex($blobs, sprintf('idx_%s_state_delete_after', $blobIndex), ['state', 'delete_after']);
        $b->createIndex($blobs, sprintf('idx_%s_content_hash', $blobIndex), 'content_hash');

        $reservations = $this->reservationTable->value;
        $reservationIndex = $this->reservationTable->forIndexName();

        $b->createTable($reservations, [
            'token' => 'string(64) NOT NULL PRIMARY KEY',
            'blob_id' => 'string(64) NOT NULL',
            'expires_at' => 'string(40) NOT NULL',
            'created_at' => 'string(40) NOT NULL',
        ]);

        $b->createIndex($reservations, sprintf('idx_%s_blob_id', $reservationIndex), 'blob_id');
        $b->createIndex($reservations, sprintf('idx_%s_expires_at', $reservationIndex), 'expires_at');
    }

    #[\Override]
    public function down(MigrationBuilder $b): void
    {
        $b->dropTable($this->reservationTable->value);
        $b->dropTable($this->blobTable->value);
    }
}
