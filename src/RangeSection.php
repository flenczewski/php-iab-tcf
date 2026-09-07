<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf;

/**
 * Encodes/decodes a set of vendor ids using the IAB TCF v2 "Vendor" section
 * format: a MaxVendorId(16) + IsRangeEncoding(1) preamble, followed by either
 * a fixed-width BitField or a list of id Ranges — whichever is smaller.
 *
 * Also exposes the underlying range-list codec (no preamble) reused by
 * PublisherRestrictionsCodec, which always uses range encoding.
 */
final class RangeSection
{
    /** @param int[] $vendorIds */
    public static function encode(array $vendorIds): string
    {
        $vendorIds = self::normalize($vendorIds);
        $maxVendorId = $vendorIds === [] ? 0 : max($vendorIds);

        $bitfieldBits = self::encodeBitfield($vendorIds, $maxVendorId);
        $rangeBits = self::encodeRangeList($vendorIds);
        $useRange = strlen($rangeBits) < strlen($bitfieldBits);

        $writer = new BitWriter();
        $writer->writeUint($maxVendorId, 16);
        $writer->writeBool($useRange);
        $writer->writeBits($useRange ? $rangeBits : $bitfieldBits);

        return $writer->toBitString();
    }

    /** @return int[] */
    public static function decode(BitReader $reader): array
    {
        $maxVendorId = $reader->readUint(16);
        $isRange = $reader->readBool();

        if (!$isRange) {
            return $reader->readIdSet($maxVendorId);
        }

        return self::decodeRangeList($reader);
    }

    /**
     * NumEntries(12) + repeated range entries. No MaxVendorId/IsRangeEncoding
     * preamble — used standalone by PublisherRestrictionsCodec.
     *
     * @param int[] $vendorIds
     */
    public static function encodeRangeList(array $vendorIds): string
    {
        $entries = self::toRanges(self::normalize($vendorIds));

        $writer = new BitWriter();
        $writer->writeUint(count($entries), 12);
        foreach ($entries as [$start, $end]) {
            $isRange = $start !== $end;
            $writer->writeBool($isRange);
            $writer->writeUint($start, 16);
            if ($isRange) {
                $writer->writeUint($end, 16);
            }
        }

        return $writer->toBitString();
    }

    /** @return int[] */
    public static function decodeRangeList(BitReader $reader): array
    {
        $numEntries = $reader->readUint(12);
        $ids = [];
        for ($i = 0; $i < $numEntries; $i++) {
            $isRange = $reader->readBool();
            $start = $reader->readUint(16);
            $end = $isRange ? $reader->readUint(16) : $start;
            for ($id = $start; $id <= $end; $id++) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** @param int[] $vendorIds */
    private static function encodeBitfield(array $vendorIds, int $maxVendorId): string
    {
        return (new BitWriter())->writeIdSet($vendorIds, $maxVendorId)->toBitString();
    }

    /**
     * @param int[] $ids
     * @return int[]
     */
    private static function normalize(array $ids): array
    {
        $ids = array_values(array_unique(array_map(static fn (int|string $id): int => (int) $id, $ids)));
        sort($ids);

        return $ids;
    }

    /**
     * @param int[] $sortedUniqueIds
     * @return array<int, array{0: int, 1: int}>
     */
    private static function toRanges(array $sortedUniqueIds): array
    {
        $ranges = [];
        $start = null;
        $prev = null;

        foreach ($sortedUniqueIds as $id) {
            if ($start === null) {
                $start = $id;
            } elseif ($id !== $prev + 1) {
                $ranges[] = [$start, $prev];
                $start = $id;
            }
            $prev = $id;
        }

        if ($start !== null) {
            $ranges[] = [$start, $prev];
        }

        return $ranges;
    }
}
