<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf;

use Flenczewski\IabTcf\Exception\InvalidArgumentException;

/**
 * Encodes a TcModel into a TC String: a Core segment (segment 0, no
 * SegmentType prefix) followed by the Disclosed Vendors segment (segment
 * type 1, mandatory as of TCF v2.3 — see README "TCF v2.3") and optionally
 * Allowed Vendors (segment type 2), dot-separated and each base64url-encoded.
 */
final class TcStringEncoder
{
    private const CORE_STRING_VERSION = 2;
    private const SEGMENT_TYPE_DISCLOSED_VENDORS = 1;
    private const SEGMENT_TYPE_ALLOWED_VENDORS = 2;

    public static function encode(TcModel $model): string
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $created = $model->created ?? $now;
        $lastUpdated = $model->lastUpdated ?? $now;

        $core = new BitWriter();
        $core->writeUint(self::CORE_STRING_VERSION, 6);
        $core->writeUint(self::decisecondsFor('created', $created), 36);
        $core->writeUint(self::decisecondsFor('lastUpdated', $lastUpdated), 36);
        $core->writeUint($model->cmpId, 12);
        $core->writeUint($model->cmpVersion, 12);
        $core->writeUint($model->consentScreen, 6);
        $core->writeBits(Alpha2Code::encode($model->consentLanguage));
        $core->writeUint($model->vendorListVersion, 12);
        $core->writeUint($model->tcfPolicyVersion, 6);
        $core->writeBool($model->isServiceSpecific);
        $core->writeBool($model->useNonStandardStacks);
        $core->writeIdSet($model->specialFeatureOptIns, 12);
        $core->writeIdSet($model->purposesConsent, 24);
        $core->writeIdSet($model->purposesLITransparency, 24);
        $core->writeBool($model->purposeOneTreatment);
        $core->writeBits(Alpha2Code::encode($model->publisherCC));
        $core->writeBits(RangeSection::encode($model->vendorConsents));
        $core->writeBits(RangeSection::encode($model->vendorLegitimateInterests));
        $core->writeBits(PublisherRestrictionsCodec::encode($model->publisherRestrictions));

        $segments = [$core->toBase64Url()];

        if ($model->disclosedVendors !== null) {
            $segments[] = self::encodeVendorSegment(self::SEGMENT_TYPE_DISCLOSED_VENDORS, $model->disclosedVendors);
        }

        if ($model->allowedVendors !== null) {
            $segments[] = self::encodeVendorSegment(self::SEGMENT_TYPE_ALLOWED_VENDORS, $model->allowedVendors);
        }

        return implode('.', $segments);
    }

    /** The Created/LastUpdated fields are unsigned, so pre-epoch dates cannot be represented. */
    private static function decisecondsFor(string $field, \DateTimeImmutable $dateTime): int
    {
        $deciseconds = EpochTime::toDeciseconds($dateTime);
        if ($deciseconds < 0) {
            throw new InvalidArgumentException(sprintf(
                '%s is %s, before the Unix epoch; the TC String Created/LastUpdated fields are unsigned '
                . 'and cannot represent dates before 1970-01-01T00:00:00Z.',
                $field,
                $dateTime->format(\DATE_ATOM),
            ));
        }

        return $deciseconds;
    }

    /** @param int[] $vendorIds */
    private static function encodeVendorSegment(int $segmentType, array $vendorIds): string
    {
        $writer = new BitWriter();
        $writer->writeUint($segmentType, 3);
        $writer->writeBits(RangeSection::encode($vendorIds));

        return $writer->toBase64Url();
    }
}
