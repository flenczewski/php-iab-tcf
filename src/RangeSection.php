<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf;

use Flenczewski\IabTcf\Exception\InvalidArgumentException;
use Flenczewski\IabTcf\Exception\InvalidTcStringException;

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
    /** NumEntries is a 12-bit field — a range list beyond this many entries cannot be encoded. */
    private const MAX_RANGE_ENTRIES = Spec::MAX_RANGE_ENTRIES;

    /** @param int[] $vendorIds */
    public static function encode(array $vendorIds): string
    {
        $vendorIds = self::normalize($vendorIds);
        $maxVendorId = $vendorIds === [] ? 0 : max($vendorIds);

        $bitfieldBits = self::encodeBitfield($vendorIds, $maxVendorId);
        $entries = self::toRanges($vendorIds);

        $useRange = false;
        $rangeBits = '';
        if (count($entries) <= self::MAX_RANGE_ENTRIES) {
            $rangeBits = self::buildRangeListBits($entries);
            $useRange = strlen($rangeBits) < strlen($bitfieldBits);
        }

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
        if (count($entries) > self::MAX_RANGE_ENTRIES) {
            throw new InvalidArgumentException(sprintf(
                'This vendor id set needs %d range entries but NumEntries holds at most %d. '
                . 'Publisher restrictions are always range-encoded, so this set cannot be expressed; '
                . 'split it across restrictions or use fewer, more contiguous vendor ids.',
                count($entries),
                self::MAX_RANGE_ENTRIES,
            ));
        }

        return self::buildRangeListBits($entries);
    }

    /** @param array<int, array{0: int, 1: int}> $entries */
    private static function buildRangeListBits(array $entries): string
    {
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

    /**
     * Decodes NumEntries(12) + range entries into a sorted, de-duplicated id list.
     *
     * Entries are validated against the hard 16-bit vendor id space *before*
     * being expanded: a valid list holds distinct ids, so it can never exceed
     * Spec::MAX_VENDOR_ID of them. Without that check a few KB of attacker
     * input expands to hundreds of millions of array elements.
     *
     * @param int $maxIds ceiling on how many ids this call may expand to.
     *                    Callers that decode several range lists from one
     *                    string (see PublisherRestrictionsCodec) pass their
     *                    remaining budget so the totals cannot be multiplied.
     * @return int[]
     */
    public static function decodeRangeList(BitReader $reader, int $maxIds = Spec::MAX_VENDOR_ID): array
    {
        $numEntries = $reader->readUint(12);
        $entries = [];
        $total = 0;
        // A conformant range list is ascending and non-overlapping, so its
        // expansion is already sorted and unique. Noticing that here lets the
        // common path skip a sort + array_unique that costs far more than the
        // expansion itself (35 ms vs 3.5 ms for a full 65535-id range).
        $isOrdered = true;
        $previousEnd = 0;

        for ($i = 0; $i < $numEntries; $i++) {
            $isRange = $reader->readBool();
            $start = $reader->readUint(16);
            $end = $isRange ? $reader->readUint(16) : $start;

            if ($start < Spec::MIN_VENDOR_ID) {
                throw new InvalidTcStringException(
                    "Range entry {$i} starts at vendor id {$start}; ids start at " . Spec::MIN_VENDOR_ID . '.'
                );
            }
            if ($end < $start) {
                throw new InvalidTcStringException(
                    "Range entry {$i} ends at {$end}, before its start {$start}."
                );
            }
            if ($end > Spec::MAX_VENDOR_ID) {
                throw new InvalidTcStringException(
                    "Range entry {$i} ends at vendor id {$end}, above the maximum of " . Spec::MAX_VENDOR_ID . '.'
                );
            }

            $total += $end - $start + 1;
            if ($total > $maxIds) {
                throw new InvalidTcStringException(sprintf(
                    'Range entry %d would expand this section past its remaining budget of %d vendor ids; '
                    . 'no valid TC String needs that many.',
                    $i,
                    $maxIds,
                ));
            }

            if ($start <= $previousEnd) {
                $isOrdered = false;
            }
            $previousEnd = $end;

            $entries[] = [$start, $end];
        }

        if ($entries === []) {
            return [];
        }

        $chunks = [];
        foreach ($entries as [$start, $end]) {
            $chunks[] = $start === $end ? [$start] : range($start, $end);
        }
        $ids = array_merge(...$chunks);

        return $isOrdered ? $ids : self::normalize($ids);
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
        $ids = array_values(array_unique($ids));
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
