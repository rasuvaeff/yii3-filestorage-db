<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb;

use Override;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Repository\FileScopeProviderInterface;
use Rasuvaeff\Yii3Filestorage\Repository\MaintenanceRepositoryInterface;
use Rasuvaeff\Yii3Filestorage\Store\BlobId;
use Rasuvaeff\Yii3FilestorageDb\Exception\InvalidFileRowException;
use Rasuvaeff\Yii3FilestorageDb\Exception\MissingScopeException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;
use Yiisoft\Db\QueryBuilder\Condition\Like;
use Yiisoft\Db\QueryBuilder\Condition\LikeConjunction;
use Yiisoft\Db\QueryBuilder\Condition\LikeMode;

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
 * A *bound* provider that returns null is a tenant that did not resolve, and
 * every method refuses it with {@see MissingScopeException} rather than
 * dropping the predicate. The one way to act on every tenant with a provider
 * bound is the explicit `allowGlobalAccess` opt-in, meant for maintenance entry
 * points; even then a new row is not written without a tenant. Null is never a
 * wildcard by accident, which is also how {@see DbScopedFileResolver} treats it.
 *
 * @api
 */
final readonly class DbRepository implements MaintenanceRepositoryInterface
{
    /**
     * Directories per statement. Each one binds two parameters, and SQLite
     * builds before 3.32 refuse more than 999.
     */
    private const int DIRECTORY_BATCH = 200;

    private string $table;
    private FileRowMapper $mapper;

    /**
     * @param bool $allowGlobalAccess With a provider bound, let a null scope
     *        mean "every tenant" for reads, updates and deletes instead of
     *        refusing. For a maintenance entry point that shares the
     *        application's provider — never for a request path, where a null
     *        is a tenant that failed to resolve.
     */
    public function __construct(
        private ConnectionInterface $db,
        FileTableName $table = new FileTableName(),
        private ?FileScopeProviderInterface $scopes = null,
        private bool $allowGlobalAccess = false,
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
        $scopeId = $this->scopeId();
        // only reachable under the global opt-in: updating any tenant's row is
        // what it is for, inserting a row that belongs to no tenant is not
        $tenantless = $scopeId === null && $this->scopes instanceof FileScopeProviderInterface;

        $row = $this->mapper->toRow($file, $blob, $scopeId);

        // An update must not touch the two columns a File does not carry.
        //
        // `blob_id` belongs to the ledger. `RepositoryInterface::save()` takes
        // a File and nothing else, so it is the only way an application can
        // persist an edited description or metadata — and writing the full row
        // there set `blob_id` to null, which makes the blob look unreferenced
        // and lets the next `filestorage:gc` delete bytes a live row still
        // points at.
        //
        // `scope_id` is the row's tenant, assigned once on insert. With no
        // scope provider bound — the maintenance entry point this package's
        // own commands recommend — writing it would set every touched row's
        // scope to null.
        $updates = $row;
        unset($updates['scope_id']);
        if ($blob === null) {
            unset($updates['blob_id']);
        }

        $updated = $this->db->createCommand()
            ->update($this->table, $updates, $this->scoped(['id' => $file->id]))
            ->execute();

        if ($updated > 0 || $this->row($file->id) !== null) {
            return;
        }

        if ($tenantless) {
            throw $this->missingScope('insert a new row');
        }

        $this->db->createCommand()->insert($this->table, $row)->execute();
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
    public function files(
        ?string $afterId = null,
        int $limit = 1000,
    ): iterable {
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

    /**
     * One statement per batch of directories: `relative_path LIKE '<dir>/%'`
     * for rows below a directory, `relative_path IN (...)` for root-level
     * rows, which are their own directory.
     *
     * The database only narrows; the answer is decided here. `LIKE 'd/%'` also
     * matches `d/sub/x`, and MySQL's default collation compares both `LIKE`
     * and `=` case- and accent-insensitively — so every row that comes back
     * is checked against the exact store name and the exact directory. No
     * `DISTINCT` either: under that collation it would fold `A/x` and `a/x`
     * into one row, and if the survivor is the one the exact check discards,
     * the real reference is lost and the sweep deletes referenced bytes.
     */
    #[Override]
    public function referencedPaths(string $storeName, array $directories): array
    {
        $wanted = array_fill_keys($directories, value: true);
        $paths = [];
        $seen = [];

        foreach (array_chunk(array_values(array_unique($directories)), self::DIRECTORY_BATCH) as $batch) {
            $prefixes = array_map(static fn(string $directory): string => $directory . '/', $batch);

            /** @var list<array<string, mixed>> $rows */
            $rows = (new Query($this->db))
                ->select(['store_name', 'relative_path'])
                ->from($this->table)
                ->where($this->scoped([
                    'and',
                    ['store_name' => $storeName],
                    [
                        'or',
                        new Like(
                            column: 'relative_path',
                            value: $prefixes,
                            mode: LikeMode::StartsWith,
                            conjunction: LikeConjunction::Or,
                        ),
                        ['in', 'relative_path', $batch],
                    ],
                ]))
                ->all();

            foreach ($rows as $row) {
                $path = isset($row['relative_path']) && \is_string($row['relative_path']) ? $row['relative_path'] : '';
                if (
                    $path !== ''
                    && ($row['store_name'] ?? null) === $storeName
                    && isset($wanted[$this->directoryOf($path)])
                    && !isset($seen[$path])
                ) {
                    // a list beside a set: a numeric path is an int key in PHP
                    $seen[$path] = true;
                    $paths[] = $path;
                }
            }
        }

        return $paths;
    }

    #[Override]
    public function updateContentHash(string $id, string $contentHash): bool
    {
        // MySQL counts changed rows, not matched ones: re-setting the hash a
        // row already holds reports 0, which is "found", not "missing"
        $updated = $this->db->createCommand()
            ->update($this->table, ['content_hash' => $contentHash], $this->scoped(['id' => $id]))
            ->execute();

        return $updated > 0 || $this->row($id) !== null;
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
     * Whether this repository writes through that exact connection.
     *
     * Asked by {@see DeduplicatingStorageFactory}: a ledger and a repository on
     * different connections cannot commit together, and nothing else would
     * notice until a crash landed between the two transactions.
     */
    public function usesConnection(ConnectionInterface $db): bool
    {
        return $this->db === $db;
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
     * {@see File::directory()} for a path that has not been mapped to a File.
     */
    private function directoryOf(string $path): string
    {
        $slash = strrpos($path, '/');

        return $slash === false || $slash === 0 ? $path : substr($path, 0, $slash);
    }

    /**
     * The scope every statement filters by: null when no provider is bound,
     * or under the explicit global opt-in.
     *
     * @return non-empty-string|null
     *
     * @throws MissingScopeException
     */
    private function scopeId(): ?string
    {
        if (!$this->scopes instanceof FileScopeProviderInterface) {
            return null;
        }

        $scopeId = $this->scopes->currentScopeId();
        if ($scopeId === null && !$this->allowGlobalAccess) {
            throw $this->missingScope('run a statement');
        }

        return $scopeId;
    }

    private function missingScope(string $action): MissingScopeException
    {
        return new MissingScopeException(sprintf(
            '%s is bound but returned no scope, so this repository will not %s without a tenant predicate. '
            . 'Establish the tenant first. A maintenance entry point that must see every tenant constructs '
            . '%s with allowGlobalAccess: true, or leaves the provider unbound',
            FileScopeProviderInterface::class,
            $action,
            self::class,
        ));
    }

    /**
     * Adds the mandatory tenant predicate, when there is one.
     *
     * @param array<mixed> $condition
     *
     * @return array<mixed>
     *
     * @throws MissingScopeException
     */
    private function scoped(array $condition): array
    {
        $scopeId = $this->scopeId();
        if ($scopeId === null) {
            return $condition;
        }

        return $condition === []
            ? ['scope_id' => $scopeId]
            : ['and', $condition, ['scope_id' => $scopeId]];
    }
}
