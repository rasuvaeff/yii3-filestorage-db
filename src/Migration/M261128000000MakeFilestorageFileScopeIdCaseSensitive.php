<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Migration;

use Rasuvaeff\Yii3FilestorageDb\FileTableName;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Compares the tenant column byte for byte on MySQL and MariaDB.
 *
 * Their default collations (`utf8mb4_0900_ai_ci`, `utf8mb4_general_ci`) ignore
 * case and accents, so `Acme`, `acme` and `Acmé` were one tenant to every
 * scoped statement: one could read, and delete, the other's files. This
 * changes `scope_id` to `utf8mb4_bin`. SQLite and PostgreSQL already compare
 * exactly, and there it does nothing.
 *
 * A collation that only gets stricter cannot fail on existing rows: values
 * that were distinct stay distinct. `down()` returns the column to the table's
 * default collation.
 *
 * Not transactional: MySQL commits implicitly on `ALTER TABLE`.
 *
 * @api
 */
final readonly class M261128000000MakeFilestorageFileScopeIdCaseSensitive implements RevertibleMigrationInterface
{
    public function __construct(
        private FileTableName $table = new FileTableName(),
    ) {}

    #[\Override]
    public function up(MigrationBuilder $b): void
    {
        if ($b->getDb()->getDriverName() !== 'mysql') {
            return;
        }

        $this->modify($b, 'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL');
    }

    #[\Override]
    public function down(MigrationBuilder $b): void
    {
        if ($b->getDb()->getDriverName() !== 'mysql') {
            return;
        }

        $this->modify($b, 'VARCHAR(255) NULL');
    }

    private function modify(MigrationBuilder $b, string $definition): void
    {
        $quoter = $b->getDb()->getQuoter();

        $b->execute(sprintf(
            'ALTER TABLE %s MODIFY %s %s',
            $quoter->quoteTableName($this->table->value),
            $quoter->quoteColumnName('scope_id'),
            $definition,
        ));
    }
}
