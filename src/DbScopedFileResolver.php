<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb;

use Override;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Repository\ScopedFileResolverInterface;
use Rasuvaeff\Yii3FilestorageDb\Exception\InvalidFileRowException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

/**
 * Resolves a file for a signed download, against the scope the token carries.
 *
 * A signed URL is served with no session — that is what signing it is for —
 * so {@see DbRepository} cannot answer: it reads the scope from the current
 * request, and there is none. The shortcut everyone reaches for is a lookup
 * by id with the tenant filter disabled, which reads any file whose id leaks.
 *
 * This class is the alternative. The scope was authenticated when the token
 * was minted and travels inside the HMAC, so it can be used as a predicate
 * rather than trusted as a lookup key. Both values are matched in one query;
 * there is no code path here that queries by id alone.
 *
 * @api
 */
final readonly class DbScopedFileResolver implements ScopedFileResolverInterface
{
    private string $table;
    private FileRowMapper $mapper;

    public function __construct(
        private ConnectionInterface $db,
        FileTableName $table = new FileTableName(),
    ) {
        $this->table = $table->value;
        $this->mapper = new FileRowMapper();
    }

    /**
     * @throws InvalidFileRowException
     */
    #[Override]
    public function findInScope(string $id, ?string $scopeId): ?File
    {
        // `['scope_id' => null]` builds `scope_id IS NULL`, which is what an
        // unscoped application's rows carry. Matching null against null is the
        // correct answer here, not a missing predicate.
        /** @var array<string, mixed>|null $row */
        $row = (new Query($this->db))
            ->from($this->table)
            ->where(['id' => $id, 'scope_id' => $scopeId])
            ->one();

        return $row === null ? null : $this->mapper->fromRow($row);
    }
}
