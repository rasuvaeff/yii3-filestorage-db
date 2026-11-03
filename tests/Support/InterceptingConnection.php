<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Tests\Support;

use Closure;
use Override;
use Yiisoft\Db\Command\CommandInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\ExpressionInterface;
use Yiisoft\Db\Query\BatchQueryResultInterface;
use Yiisoft\Db\Query\QueryInterface;
use Yiisoft\Db\QueryBuilder\QueryBuilderInterface;
use Yiisoft\Db\Schema\Column\ColumnFactoryInterface;
use Yiisoft\Db\Schema\QuoterInterface;
use Yiisoft\Db\Schema\SchemaInterface;
use Yiisoft\Db\Schema\TableSchemaInterface;
use Yiisoft\Db\Transaction\TransactionInterface;

/**
 * Delegates to a real connection and lets a test step in around `transaction()`
 * and `createCommand()` — the two seams where a concurrent writer would land.
 * A race between two statements cannot be produced any other way inside one
 * process on an in-memory database.
 *
 * @internal
 */
final class InterceptingConnection implements ConnectionInterface
{
    /**
     * @var (Closure(Closure(): mixed): mixed)|null Receives a callable that runs the real transaction
     */
    public ?Closure $onTransaction = null;

    /**
     * @var (Closure(?string, Closure(): CommandInterface): CommandInterface)|null Receives the SQL (null for a
     *      builder-style command) and a callable that creates the real command
     */
    public ?Closure $onCreateCommand = null;

    public function __construct(private readonly ConnectionInterface $inner) {}

    #[Override]
    public function transaction(Closure $closure, ?string $isolationLevel = null): mixed
    {
        $proceed = fn(): mixed => $this->inner->transaction($closure, $isolationLevel);

        return $this->onTransaction instanceof Closure ? ($this->onTransaction)($proceed) : $proceed();
    }

    #[Override]
    public function createCommand(?string $sql = null, array $params = []): CommandInterface
    {
        $proceed = fn(): CommandInterface => $this->inner->createCommand($sql, $params);

        return $this->onCreateCommand instanceof Closure ? ($this->onCreateCommand)($sql, $proceed) : $proceed();
    }

    #[Override]
    public function beginTransaction(?string $isolationLevel = null): TransactionInterface
    {
        return $this->inner->beginTransaction($isolationLevel);
    }

    #[Override]
    public function createBatchQueryResult(QueryInterface $query): BatchQueryResultInterface
    {
        return $this->inner->createBatchQueryResult($query);
    }

    #[Override]
    public function createQuery(): QueryInterface
    {
        return $this->inner->createQuery();
    }

    #[Override]
    public function createTransaction(): TransactionInterface
    {
        return $this->inner->createTransaction();
    }

    #[Override]
    public function close(): void
    {
        $this->inner->close();
    }

    #[Override]
    public function getColumnBuilderClass(): string
    {
        return $this->inner->getColumnBuilderClass();
    }

    #[Override]
    public function getColumnFactory(): ColumnFactoryInterface
    {
        return $this->inner->getColumnFactory();
    }

    #[Override]
    public function getDriverName(): string
    {
        return $this->inner->getDriverName();
    }

    #[Override]
    public function getLastInsertId(?string $sequenceName = null): string
    {
        return $this->inner->getLastInsertId($sequenceName);
    }

    #[Override]
    public function getQueryBuilder(): QueryBuilderInterface
    {
        return $this->inner->getQueryBuilder();
    }

    #[Override]
    public function getQuoter(): QuoterInterface
    {
        return $this->inner->getQuoter();
    }

    #[Override]
    public function getSchema(): SchemaInterface
    {
        return $this->inner->getSchema();
    }

    #[Override]
    public function getServerInfo(): \Yiisoft\Db\Connection\ServerInfoInterface
    {
        return $this->inner->getServerInfo();
    }

    #[Override]
    public function getTablePrefix(): string
    {
        return $this->inner->getTablePrefix();
    }

    #[Override]
    public function getTableSchema(string $name, bool $refresh = false): ?TableSchemaInterface
    {
        return $this->inner->getTableSchema($name, $refresh);
    }

    #[Override]
    public function getTransaction(): ?TransactionInterface
    {
        return $this->inner->getTransaction();
    }

    #[Override]
    public function isActive(): bool
    {
        return $this->inner->isActive();
    }

    #[Override]
    public function isSavepointEnabled(): bool
    {
        return $this->inner->isSavepointEnabled();
    }

    #[Override]
    public function open(): void
    {
        $this->inner->open();
    }

    #[Override]
    public function quoteValue(mixed $value): mixed
    {
        return $this->inner->quoteValue($value);
    }

    #[Override]
    public function setEnableSavepoint(bool $value): void
    {
        $this->inner->setEnableSavepoint($value);
    }

    #[Override]
    public function select(
        array|bool|float|int|string|ExpressionInterface $columns = [],
        ?string $option = null,
    ): QueryInterface {
        return $this->inner->select($columns, $option);
    }

    #[Override]
    public function setTablePrefix(string $value): void
    {
        $this->inner->setTablePrefix($value);
    }
}
