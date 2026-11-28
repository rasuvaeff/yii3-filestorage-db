<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Migration;

use Rasuvaeff\Yii3FilestorageDb\ExactCollation;
use Rasuvaeff\Yii3FilestorageDb\FileTableName;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Compares the tenant column byte for byte on MySQL and MariaDB.
 *
 * Their default collations (`utf8mb4_0900_ai_ci`, `utf8mb4_general_ci`,
 * `utf8mb4_uca1400_ai_ci`) ignore case and accents, so `Acme`, `acme` and
 * `Acmé` were one tenant to every scoped statement: one could read, and
 * delete, the other's files. This changes `scope_id` to a binary NO PAD
 * collation — `utf8mb4_0900_bin` on MySQL 8.0.17+, `utf8mb4_nopad_bin` on
 * MariaDB — so trailing spaces count too (`utf8mb4_bin` would ignore them).
 * Older MySQL is refused with a `RuntimeException`. SQLite and PostgreSQL
 * already compare exactly, and there it does nothing.
 *
 * Changing the collation of an indexed column rebuilds the table
 * (`ALGORITHM=COPY`): writes to it wait for the rebuild, so run it in a
 * maintenance window on a large table. The rebuilt index needs the
 * `DYNAMIC` row format (the default since MySQL 5.7.9 and MariaDB 10.2.2);
 * on `COMPACT` a 255-character `utf8mb4` key exceeds 767 bytes.
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

        $this->modify($b, sprintf(
            'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE %s NULL',
            $this->exactCollation($b->getDb()),
        ));
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

    /**
     * See {@see ExactCollation}: the server is asked, the choice is made there.
     */
    private function exactCollation(ConnectionInterface $db): string
    {
        return ExactCollation::choose(
            serverVersion: $db->createCommand('SELECT VERSION()')->queryScalar(),
            collationCount: $db->createCommand(
                "SELECT COUNT(*) FROM information_schema.COLLATIONS WHERE COLLATION_NAME = 'utf8mb4_0900_bin'",
            )->queryScalar(),
        );
    }
}
