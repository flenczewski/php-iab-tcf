<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf;

use Flenczewski\IabTcf\Exception\InvalidArgumentException;
use Flenczewski\IabTcf\Exception\InvalidTcStringException;

/**
 * Encodes/decodes the Publisher Restrictions section of the Core String:
 * NumPubRestrictions(12), then per entry PurposeId(6) + RestrictionType(2) +
 * a vendor id range list (always range-encoded, never a bitfield).
 */
final class PublisherRestrictionsCodec
{
    /** @param PublisherRestriction[] $restrictions */
    public static function encode(array $restrictions): string
    {
        if (count($restrictions) > Spec::MAX_RANGE_ENTRIES) {
            throw new InvalidArgumentException(sprintf(
                'NumPubRestrictions holds at most %d restrictions, got %d.',
                Spec::MAX_RANGE_ENTRIES,
                count($restrictions),
            ));
        }

        $writer = new BitWriter();
        $writer->writeUint(count($restrictions), 12);

        foreach ($restrictions as $restriction) {
            $writer->writeUint($restriction->purposeId, 6);
            $writer->writeUint($restriction->type->value, 2);
            $writer->writeBits(RangeSection::encodeRangeList($restriction->vendorIds));
        }

        return $writer->toBitString();
    }

    /** @return PublisherRestriction[] */
    public static function decode(BitReader $reader): array
    {
        $numRestrictions = $reader->readUint(12);
        $restrictions = [];

        // Each range list is individually capped, but NumPubRestrictions is a
        // 12-bit field — so the budget has to be shared across the whole
        // section, otherwise 4095 restrictions multiply the per-list cap.
        $remainingIds = Spec::MAX_PUBLISHER_RESTRICTION_VENDOR_IDS;

        for ($i = 0; $i < $numRestrictions; $i++) {
            // PurposeId is a 6-bit field but only 1..24 are defined, so a
            // malformed value is a decode error. Left unchecked it reached
            // PublisherRestriction's constructor and surfaced as a plain
            // InvalidArgumentException, unlike every other malformed field here.
            $purposeId = $reader->readUint(6);
            if ($purposeId < 1 || $purposeId > Spec::MAX_PURPOSE_ID) {
                throw new InvalidTcStringException(sprintf(
                    'Publisher restriction %d names purpose %d; only 1..%d are defined.',
                    $i,
                    $purposeId,
                    Spec::MAX_PURPOSE_ID,
                ));
            }
            // RestrictionType defines all four 2-bit values, so tryFrom() cannot
            // currently fail — the guard is kept (and is therefore not covered)
            // so that removing a case from the enum surfaces as a decode error
            // rather than an uncaught ValueError.
            $typeValue = $reader->readUint(2);
            $type = RestrictionType::tryFrom($typeValue)
                ?? throw new InvalidTcStringException("Unknown publisher restriction type {$typeValue}.");
            $vendorIds = RangeSection::decodeRangeList($reader, $remainingIds);
            $remainingIds -= count($vendorIds);
            $restrictions[] = new PublisherRestriction($purposeId, $type, $vendorIds);
        }

        return $restrictions;
    }
}
