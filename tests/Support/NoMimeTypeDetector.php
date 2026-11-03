<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Tests\Support;

use Override;
use Rasuvaeff\Yii3Filestorage\Mime\MimeTypeDetectorInterface;
use Rasuvaeff\Yii3Filestorage\Upload;

/**
 * Detects nothing and never touches the stream, so a test can watch exactly
 * the reads and rewinds the code under test makes.
 *
 * @internal
 */
final readonly class NoMimeTypeDetector implements MimeTypeDetectorInterface
{
    #[Override]
    public function detect(Upload $upload): ?string
    {
        return null;
    }
}
