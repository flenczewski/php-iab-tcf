<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf;

use Flenczewski\IabTcf\Exception\IabTcfException;
use Flenczewski\IabTcf\Exception\InvalidArgumentException;
use Flenczewski\IabTcf\Exception\InvalidTcStringException;

/** Decodes a TC String produced by TcStringEncoder (or any spec-compliant TCF v2 encoder) into a TcModel. */
final class TcStringDecoder
{
    private const SUPPORTED_CORE_STRING_VERSION = Spec::CORE_STRING_VERSION;
    private const SEGMENT_TYPE_DISCLOSED_VENDORS = 1;
    private const SEGMENT_TYPE_ALLOWED_VENDORS = 2;
    private const SEGMENT_TYPE_PUBLISHER_TC = 3;

    /**
     * @param int $maxLength longest input accepted, in characters. The default
     *                       admits anything this package can encode; pass a
     *                       tighter bound (a few kilobytes) for strings read
     *                       from cookies or query parameters.
     *
     * @throws InvalidTcStringException if the string is not a decodable TCF v2 TC String
     * @throws InvalidArgumentException if $maxLength is below 1
     */
    public static function decode(string $tcString, int $maxLength = Spec::MAX_TC_STRING_LENGTH): TcModel
    {
        if ($maxLength < 1) {
            throw new InvalidArgumentException("maxLength must be at least 1, got {$maxLength}.");
        }
        if ($tcString === '') {
            throw new InvalidTcStringException('TC String is empty.');
        }
        // Checked before any decoding, so rejecting an oversized string costs
        // nothing that grows with its length.
        if (strlen($tcString) > $maxLength) {
            throw new InvalidTcStringException(sprintf(
                'TC String is %d characters, longer than the %d-character limit.',
                strlen($tcString),
                $maxLength,
            ));
        }

        try {
            return self::decodeSegments($tcString);
        } catch (InvalidTcStringException $e) {
            // Already the right type and message — do not double-wrap it.
            throw $e;
        } catch (IabTcfException | \ValueError $e) {
            throw new InvalidTcStringException(
                "Could not decode TC String: {$e->getMessage()}",
                0,
                $e,
            );
        }
    }

    private static function decodeSegments(string $tcString): TcModel
    {
        // Cap the split itself: without the limit, a megabyte of separators
        // allocates a million-element array before the count check below can
        // reject it. The extra element is what makes an over-long string
        // detectable at all.
        $segments = explode('.', $tcString, Spec::MAX_SEGMENTS + 1);
        // Before the core is decoded, so an over-long string is rejected for
        // what is wrong with it, without first paying for the core. The count
        // is not reported: the capped explode() cannot know the real one.
        if (count($segments) > Spec::MAX_SEGMENTS) {
            throw new InvalidTcStringException(sprintf(
                'TC String has more than %d segments; a valid one has at most %d (core, plus at most one each of '
                . 'Disclosed Vendors, Allowed Vendors and Publisher TC).',
                Spec::MAX_SEGMENTS,
                Spec::MAX_SEGMENTS,
            ));
        }

        $core = new BitReader(Base64Url::decodeToBits($segments[0]));

        $version = $core->readUint(6);
        if ($version !== self::SUPPORTED_CORE_STRING_VERSION) {
            throw new InvalidTcStringException(
                "Unsupported TC String Core segment version: {$version}. Only version 2 is supported."
            );
        }

        $created = EpochTime::fromDeciseconds($core->readUint(36));
        $lastUpdated = EpochTime::fromDeciseconds($core->readUint(36));
        $cmpId = $core->readUint(12);
        $cmpVersion = $core->readUint(12);
        $consentScreen = $core->readUint(6);
        $consentLanguage = Alpha2Code::decode($core);
        $vendorListVersion = $core->readUint(12);
        $tcfPolicyVersion = $core->readUint(6);
        $isServiceSpecific = $core->readBool();
        $useNonStandardStacks = $core->readBool();
        $specialFeatureOptIns = $core->readIdSet(12);
        $purposesConsent = $core->readIdSet(24);
        $purposesLITransparency = $core->readIdSet(24);
        $purposeOneTreatment = $core->readBool();
        $publisherCC = Alpha2Code::decode($core);
        $vendorConsents = RangeSection::decode($core);
        $vendorLegitimateInterests = RangeSection::decode($core);
        $publisherRestrictions = PublisherRestrictionsCodec::decode($core);

        // Absent Disclosed Vendors segment stays null so that decode()->encode()
        // does not silently append an empty one, changing its meaning from
        // "unknown" to "zero vendors disclosed". TCF v2.3 made the segment
        // mandatory for *new* strings (TcModel defaults to []), but decoding
        // must stay backward compatible with v2.0-v2.2 strings.
        //
        // Note this preserves the segment's *presence*, not the input verbatim:
        // re-encoding is canonical, so a non-canonical input comes back
        // normalised. See README "Round-tripping" for the exact guarantee.
        $disclosedVendors = null;
        $allowedVendors = null;

        $seenSegmentTypes = [];
        for ($i = 1; $i < count($segments); $i++) {
            $reader = new BitReader(Base64Url::decodeToBits($segments[$i]));
            $segmentType = $reader->readUint(3);
            // Segment order is not preserved on re-encode: the encoder always
            // emits Disclosed Vendors before Allowed Vendors. The spec does not
            // fix an order, so accepting either here is deliberate.

            // Each type may appear once. Repeats used to silently overwrite the
            // previous value, and let a caller multiply the decode cost of a
            // vendor section by the number of times they repeated it.
            if (isset($seenSegmentTypes[$segmentType])) {
                throw new InvalidTcStringException("TC String repeats segment type {$segmentType}.");
            }
            $seenSegmentTypes[$segmentType] = true;

            match ($segmentType) {
                self::SEGMENT_TYPE_DISCLOSED_VENDORS => $disclosedVendors = RangeSection::decode($reader),
                self::SEGMENT_TYPE_ALLOWED_VENDORS => $allowedVendors = RangeSection::decode($reader),
                // Publisher TC is intentionally unsupported — skipped.
                self::SEGMENT_TYPE_PUBLISHER_TC => null,
                // 0 would be a second core segment and 4..7 are undefined;
                // skipping them used to hide a corrupted string.
                default => throw new InvalidTcStringException(
                    "TC String segment {$i} has unknown segment type {$segmentType}; only 1..3 are defined."
                ),
            };
        }

        return new TcModel(
            cmpId: $cmpId,
            cmpVersion: $cmpVersion,
            consentScreen: $consentScreen,
            consentLanguage: $consentLanguage,
            vendorListVersion: $vendorListVersion,
            tcfPolicyVersion: $tcfPolicyVersion,
            isServiceSpecific: $isServiceSpecific,
            useNonStandardStacks: $useNonStandardStacks,
            purposeOneTreatment: $purposeOneTreatment,
            publisherCC: $publisherCC,
            specialFeatureOptIns: $specialFeatureOptIns,
            purposesConsent: $purposesConsent,
            purposesLITransparency: $purposesLITransparency,
            vendorConsents: $vendorConsents,
            vendorLegitimateInterests: $vendorLegitimateInterests,
            publisherRestrictions: $publisherRestrictions,
            disclosedVendors: $disclosedVendors,
            allowedVendors: $allowedVendors,
            created: $created,
            lastUpdated: $lastUpdated,
        );
    }
}
