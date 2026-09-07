<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf;

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
            $type = RestrictionType::from($reader->readUint(2));
            $vendorIds = RangeSection::decodeRangeList($reader);
            $restrictions[] = new PublisherRestriction($purposeId, $type, $vendorIds);
        }

        return $restrictions;
    }
}
