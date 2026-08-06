<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Exception;

use Rasuvaeff\Yii3Filestorage\Exception\FilestorageException;
use RuntimeException;

/**
 * A stored row cannot be turned back into a `File`.
 *
 * Loud on purpose. The alternative — coercing whatever came back into
 * something `File::create()` accepts — turns a corrupted or hand-edited row
 * into a plausible-looking file record pointing at the wrong object, and the
 * next thing that happens is a delete against a path nobody meant.
 *
 * Implements the core marker so a consumer catching `FilestorageException`
 * catches this too.
 *
 * @api
 */
final class InvalidFileRowException extends RuntimeException implements FilestorageException {}
