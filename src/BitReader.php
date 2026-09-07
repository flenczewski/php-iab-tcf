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
