<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Tests\Support;

use Override;
use Psr\Http\Message\StreamInterface;

/**
 * A stream that logs `rewind` and `read` calls and can misreport itself: a
 * declared size that is not the real one, or an `eof()` that stays false until
 * the reader rewinds after reading (a socket that stalls instead of closing).
 *
 * @internal
 */
final class SpyStream implements StreamInterface
{
    /** @var list<string> */
    public array $events = [];

    private bool $hasRead = false;

    private bool $stallEnded = false;

    public function __construct(
        private readonly StreamInterface $inner,
        private readonly ?int $reportedSize = null,
        private readonly bool $neverEof = false,
    ) {}

    #[Override]
    public function __toString(): string
    {
        return $this->inner->__toString();
    }

    #[Override]
    public function close(): void
    {
        $this->inner->close();
    }

    #[Override]
    public function detach()
    {
        return $this->inner->detach();
    }

    #[Override]
    public function getSize(): ?int
    {
        return $this->reportedSize;
    }

    #[Override]
    public function tell(): int
    {
        return $this->inner->tell();
    }

    #[Override]
    public function eof(): bool
    {
        return ($this->stallEnded || !$this->neverEof) && $this->inner->eof();
    }

    #[Override]
    public function isSeekable(): bool
    {
        return $this->inner->isSeekable();
    }

    #[Override]
    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        $this->inner->seek($offset, $whence);
    }

    #[Override]
    public function rewind(): void
    {
        $this->events[] = 'rewind';
        $this->stallEnded = $this->stallEnded || $this->hasRead;
        $this->inner->rewind();
    }

    #[Override]
    public function isWritable(): bool
    {
        return $this->inner->isWritable();
    }

    #[Override]
    public function write(string $string): int
    {
        return $this->inner->write($string);
    }

    #[Override]
    public function isReadable(): bool
    {
        return $this->inner->isReadable();
    }

    #[Override]
    public function read(int $length): string
    {
        $this->events[] = 'read';
        $this->hasRead = true;

        return $this->inner->read($length);
    }

    #[Override]
    public function getContents(): string
    {
        return $this->inner->getContents();
    }

    #[Override]
    public function getMetadata(?string $key = null)
    {
        return $this->inner->getMetadata($key);
    }
}
