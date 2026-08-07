<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Tests\Support;

use Override;
use Psr\Http\Message\StreamInterface;

/**
 * A stream that will not say how long it is — a chunked HTTP body, or a pipe.
 *
 * The deduplication cap has to be enforced *during* the read for exactly this
 * input: there is no declared length to check beforehand.
 *
 * @internal
 */
final readonly class UnsizedStream implements StreamInterface
{
    public function __construct(private StreamInterface $inner) {}

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
        return null;
    }

    #[Override]
    public function tell(): int
    {
        return $this->inner->tell();
    }

    #[Override]
    public function eof(): bool
    {
        return $this->inner->eof();
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
