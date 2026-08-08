<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb;

use InvalidArgumentException;

/**
 * The table's name, as a type.
 *
 * A plain `string $table` constructor argument cannot be configured:
 * `yiisoft/db-migration` builds migrations through `Injector::make()`, which
 * resolves arguments by name or by type from the container and never reads a
 * container definition keyed by the migration's own class. A scalar has no
 * type to resolve, so the documented `M...::class => ['__construct()' => ...]`
 * recipe silently did nothing. A typed value object does resolve — and when
 * the container has no binding, `Injector` falls back to this parameter's
 * default, so the package still works unconfigured.
 *
 * @api
 */
final readonly class BlobReservationTableName implements \Stringable
{
    private const string PATTERN = '/^[A-Za-z_]\w*(\.[A-Za-z_]\w*)?\z/';

    public function __construct(
        public string $value = 'filestorage_blob_reservation',
    ) {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid table name "%s"', $value));
        }
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * Base for index names: a schema-qualified table cannot appear verbatim in
     * an index name, so `schema.table` becomes `schema_table`.
     */
    public function forIndexName(): string
    {
        return str_replace('.', '_', $this->value);
    }
}
