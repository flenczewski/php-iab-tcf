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

        for ($i = 0; $i < $numRestrictions; $i++) {
            $purposeId = $reader->readUint(6);
            $typeValue = $reader->readUint(2);
            $type = RestrictionType::tryFrom($typeValue)
                ?? throw new InvalidTcStringException("Unknown publisher restriction type {$typeValue}.");
            $vendorIds = RangeSection::decodeRangeList($reader);
            $restrictions[] = new PublisherRestriction($purposeId, $type, $vendorIds);
        }

        return $restrictions;
    }
}
