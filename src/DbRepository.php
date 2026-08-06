<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb;

use Override;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Repository\FileScopeProviderInterface;
use Rasuvaeff\Yii3Filestorage\Repository\MaintenanceRepositoryInterface;
use Rasuvaeff\Yii3Filestorage\Store\BlobId;
use Rasuvaeff\Yii3FilestorageDb\Exception\InvalidFileRowException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

/**
 * File metadata in a database table.
 *
 * Every statement this class issues carries the tenant predicate when a
 * {@see FileScopeProviderInterface} is bound. There is no method that skips
 * it — not for downloads, not for maintenance. A signed download that needs to
 * read outside the current request's scope goes through
 * {@see DbScopedFileResolver}, which matches an *authenticated* scope rather
 * than turning the filter off.
 *
 * With no scope provider bound the package is single-tenant: no predicate is
 * added and `scope_id` stays null. That is a deliberate absence rather than a
 * predicate on null, so a single-tenant installation pays nothing.
 *
 * @api
 */
final readonly class DbRepository implements MaintenanceRepositoryInterface
{
    private string $table;
    private FileRowMapper $mapper;

    public function __construct(
        private ConnectionInterface $db,
        FileTableName $table = new FileTableName(),
        private ?FileScopeProviderInterface $scopes = null,
    ) {
        $this->table = $table->value;
        $this->mapper = new FileRowMapper();
    }

    #[Override]
    public function find(string $id): ?File
    {
        $row = $this->row($id);

        return $row === null ? null : $this->mapper->fromRow($row);
    }

    /**
     * Replaces the row with this id, or inserts it.
     *
     * Deliberately not an `upsert`. An upsert matches on the primary key
     * alone, so a row belonging to another tenant would be silently taken
     * over — ids are generated and unguessable, but "unguessable" is not a
     * boundary. A scoped update that affects nothing falls through to an
     * insert, where a foreign id collides on the primary key and fails loudly.
     *
     * @param BlobId|null $blob Set only by the ledger, for a row that shares bytes.
     *
     * @throws \JsonException
     */
    #[Override]
    public function save(File $file, ?BlobId $blob = null): void
    {
        $row = $this->mapper->toRow($file, $blob, $this->scopes?->currentScopeId());

        $updated = $this->db->createCommand()
            ->update($this->table, $row, $this->scoped(['id' => $file->id]))
            ->execute();

        if ($updated === 0) {
            $this->db->createCommand()->insert($this->table, $row)->execute();
        }
    }

    #[Override]
    public function delete(string $id): bool
    {
        return $this->db->createCommand()
            ->delete($this->table, $this->scoped(['id' => $id]))
            ->execute() > 0;
    }

    /**
     * @return iterable<int, File>
     *
     * @throws InvalidFileRowException
     */
    #[Override]
    public function files(?string $afterId = null, int $limit = 1000): iterable
    {
        $condition = $afterId === null ? [] : ['>', 'id', $afterId];
        $condition = $this->scoped($condition);

        /** @var array<array-key, array<string, mixed>> $rows */
        $rows = (new Query($this->db))
            ->from($this->table)
            ->where($condition === [] ? null : $condition)
            ->orderBy(['id' => \SORT_ASC])
            ->limit($limit)
            ->all();

        foreach ($rows as $row) {
            yield $this->mapper->fromRow($row);
        }
    }

    #[Override]
    public function updateContentHash(string $id, string $contentHash): bool
    {
        return $this->db->createCommand()
            ->update($this->table, ['content_hash' => $contentHash], $this->scoped(['id' => $id]))
            ->execute() > 0;
    }

    /**
     * The ledger row this file references, or null when it owns its object.
     *
     * Scoped like everything else: the ledger asks through the repository
     * precisely so that a cross-tenant id cannot be used to discover, or
     * decrement, a blob belonging to somebody else.
     *
     * @param non-empty-string $id
     *
     * @return non-empty-string|null
     */
    public function findBlobRowId(string $id): ?string
    {
        $row = $this->row($id);

        return $row === null ? null : $this->mapper->blobRowId($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function row(string $id): ?array
    {
        /** @var array<string, mixed>|null $row */
        $row = (new Query($this->db))
            ->from($this->table)
            ->where($this->scoped(['id' => $id]))
            ->one();

        return $row;
    }

    /**
     * Adds the mandatory tenant predicate, when there is one.
     *
     * @param array<mixed> $condition
     *
     * @return array<mixed>
     */
    private function scoped(array $condition): array
    {
        $scopeId = $this->scopes?->currentScopeId();
        if ($scopeId === null) {
            return $condition;
        }

        return $condition === []
            ? ['scope_id' => $scopeId]
            : ['and', $condition, ['scope_id' => $scopeId]];
    }
}
