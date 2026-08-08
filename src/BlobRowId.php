<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb;

use Rasuvaeff\Yii3Filestorage\Store\BlobId;

/**
 * The primary key a `BlobId` gets in the ledger.
 *
 * A blob is identified by `(store_name, relative_path)`, and the obvious
 * schema is a unique index over that pair. It does not fit: a relative path is
 * up to 512 characters, and MySQL's index key length would reject the pair
 * outright on several collations. Hashing the pair into a fixed 64-character
 * primary key says the same thing in a size every driver accepts — and makes
 * two writers racing to create the same blob collide on the primary key
 * instead of producing two rows for one object.
 *
 * SHA-256 rather than something shorter because a collision here would merge
 * two unrelated objects' reference accounting, and the cost of the wider
 * column is nothing next to that.
 *
 * @internal
 */
final readonly class BlobRowId
{
    /**
     * @return non-empty-string
     */
    public static function for(BlobId $blob): string
    {
        return hash('sha256', $blob->key());
    }
}
