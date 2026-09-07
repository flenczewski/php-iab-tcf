<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf;

use Flenczewski\IabTcf\Exception\InvalidArgumentException;

/**
 * Appends fixed-width, big-endian (MSB-first) fields to an in-memory bit buffer,
 * as required by the IAB TCF v2 Consent String and Vendor List Formats spec.
 */
final class BitWriter
{
    private string $bits = '';

    public function writeUint(int $value, int $numBits): static
    {
        if ($value < 0) {
            throw new InvalidArgumentException("Value must be >= 0, got {$value}.");
        }
        if ($numBits < 63 && $value >= (1 << $numBits)) {
            throw new InvalidArgumentException("Value {$value} does not fit in {$numBits} bits.");
        }

        $this->bits .= str_pad(decbin($value), $numBits, '0', STR_PAD_LEFT);

        return $this;
    }

    public function writeBool(bool $value): static
    {
        $this->bits .= $value ? '1' : '0';

        return $this;
    }

    /**
     * Writes a fixed-width bitfield where each bit position (1-based id) is set
     * to 1 if present in $ids. Used for Purposes, Special Features, etc.
     *
     * @param int[] $ids
     */
    public function writeIdSet(array $ids, int $width): static
    {
        $set = array_flip($ids);
        for ($id = 1; $id <= $width; $id++) {
            $this->bits .= isset($set[$id]) ? '1' : '0';
        }

        return $this;
    }

    /** Appends a raw string of '0'/'1' characters produced by another encoder. */
    public function writeBits(string $bits): static
    {
        $this->bits .= $bits;

        return $this;
    }

    public function toBitString(): string
    {
        return $this->bits;
    }

    public function toBase64Url(): string
    {
        return Base64Url::encodeBits($this->bits);
    }
}
