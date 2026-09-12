<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf;

use Flenczewski\IabTcf\Exception\OutOfRangeException;

/** Reads fixed-width, big-endian (MSB-first) fields from a bit buffer produced by BitWriter. */
final class BitReader
{
    private int $pos = 0;

    public function __construct(private readonly string $bits)
    {
    }

    public function readUint(int $numBits): int
    {
        // Mirrors BitWriter::writeUint()'s bound. Past 63 bits bindec() returns
        // a float and the cast below silently yields a wrong value (64 set bits
        // gave 0), so reject the width rather than hand back a bad integer.
        if ($numBits < 0 || $numBits > 63) {
            throw new OutOfRangeException("Field width must be between 0 and 63 bits, got {$numBits}.");
        }

        return (int) bindec($this->readBits($numBits));
    }

    public function readBool(): bool
    {
        return $this->readUint(1) === 1;
    }

    /**
     * Reads a fixed-width bitfield and returns the 1-based ids whose bit is set.
     *
     * @return int[]
     */
    public function readIdSet(int $width): array
    {
        $bits = $this->readBits($width);
        $ids = [];
        for ($i = 0; $i < $width; $i++) {
            if ($bits[$i] === '1') {
                $ids[] = $i + 1;
            }
        }

        return $ids;
    }

    public function readBits(int $numBits): string
    {
        if ($numBits < 0 || $this->pos + $numBits > strlen($this->bits)) {
            throw new OutOfRangeException('Attempted to read past the end of the bit buffer.');
        }

        $chunk = substr($this->bits, $this->pos, $numBits);
        $this->pos += $numBits;

        return $chunk;
    }

    public function remainingBits(): int
    {
        return strlen($this->bits) - $this->pos;
    }

    public function hasMore(): bool
    {
        return $this->remainingBits() > 0;
    }
}
