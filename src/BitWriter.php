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
        if ($numBits < 0 || $numBits > 63) {
            throw new InvalidArgumentException("Field width must be between 0 and 63 bits, got {$numBits}.");
        }
        if ($value < 0) {
            throw new InvalidArgumentException("Value must be >= 0, got {$value}.");
        }
        // A zero-width field can only carry the value 0, and str_pad() never
        // truncates — so this case must be handled before padding.
        if ($numBits === 0) {
            if ($value !== 0) {
                throw new InvalidArgumentException("Value {$value} does not fit in 0 bits.");
            }

            return $this;
        }
        // Only widths below 63 need a value check: at exactly 63 bits every
        // non-negative int fits (PHP_INT_MAX is 2**63 - 1), and computing
        // 1 << 63 would overflow to a negative number and reject everything.
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
     * Ids outside 1..$width cannot be represented and are rejected rather than
     * dropped — silently losing a purpose or vendor id would be a consent bug.
     *
     * @param int[] $ids
     */
    public function writeIdSet(array $ids, int $width): static
    {
        foreach ($ids as $id) {
            if ($id < 1 || $id > $width) {
                throw new InvalidArgumentException(
                    "Cannot write id {$id} into a {$width}-bit field: ids must be between 1 and {$width}."
                );
            }
        }

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
