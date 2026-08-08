<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Command;

/**
 * What happened to one row during a deduplication pass.
 *
 * A four-way answer rather than a boolean because the counters an operator
 * reads at the end have to distinguish "nothing to do" from "could not do it":
 * a run that skips a million rows and a run that fails a million rows both
 * migrate nothing.
 *
 * @internal
 */
enum Outcome
{
    case Moved;
    case AlreadyShared;
    case Skipped;
    case Failed;
}
