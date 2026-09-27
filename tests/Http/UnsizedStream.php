<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Tests\Http;

use Psr\Http\Message\StreamInterface;

/** Wraps a stream so it reports no size, like a live network body, and counts what was read. */
final class UnsizedStream implements StreamInterface
{
    public int $bytesRead = 0;

    public function __construct(private readonly StreamInterface $inner)
    {
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function read(int $length): string
    {
        $chunk = $this->inner->read($length);
        $this->bytesRead += strlen($chunk);

        return $chunk;
    }

    public function __toString(): string
    {
        return (string) $this->inner;
    }

    public function close(): void
    {
        $this->inner->close();
    }

    public function detach()
    {
        return $this->inner->detach();
    }

    public function tell(): int
    {
        return $this->inner->tell();
    }

    public function eof(): bool
    {
        return $this->inner->eof();
    }

    public function isSeekable(): bool
    {
        return $this->inner->isSeekable();
    }

    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        $this->inner->seek($offset, $whence);
    }

    public function rewind(): void
    {
        $this->inner->rewind();
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('Read-only test stream.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function getContents(): string
    {
        return $this->inner->getContents();
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $this->inner->getMetadata($key);
    }
}
