<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Tests\Http;

use Psr\Http\Message\StreamInterface;

/** A body whose read() is scripted by the test; it reports eof() only after $eofAfterReads reads, if given. */
final class ScriptedReadStream implements StreamInterface
{
    public int $reads = 0;

    /** @param \Closure(int): string $read called with the 1-based read count */
    public function __construct(private readonly \Closure $read, private readonly ?int $eofAfterReads = null)
    {
    }

    public function read(int $length): string
    {
        return ($this->read)(++$this->reads);
    }

    public function eof(): bool
    {
        return $this->eofAfterReads !== null && $this->reads >= $this->eofAfterReads;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function __toString(): string
    {
        return '';
    }

    public function close(): void
    {
    }

    public function detach()
    {
        return null;
    }

    public function tell(): int
    {
        return 0;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        throw new \RuntimeException('Not seekable.');
    }

    public function rewind(): void
    {
        throw new \RuntimeException('Not seekable.');
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
        throw new \RuntimeException('Read it in chunks.');
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $key === null ? [] : null;
    }
}
