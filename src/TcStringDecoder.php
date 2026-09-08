<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf;

use Flenczewski\IabTcf\Exception\IabTcfException;
use Flenczewski\IabTcf\Exception\InvalidTcStringException;

/** Decodes a TC String produced by TcStringEncoder (or any spec-compliant TCF v2 encoder) into a TcModel. */
final class TcStringDecoder
{
    private const SUPPORTED_CORE_STRING_VERSION = Spec::CORE_STRING_VERSION;
    private const SEGMENT_TYPE_DISCLOSED_VENDORS = 1;
    private const SEGMENT_TYPE_ALLOWED_VENDORS = 2;

    /**
     * @throws InvalidTcStringException if the string is not a decodable TCF v2 TC String
     */
    public static function decode(string $tcString): TcModel
    {
        if ($tcString === '') {
            throw new InvalidTcStringException('TC String is empty.');
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
        $segments = explode('.', $tcString);
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
        // reproduces the input byte-for-byte. TCF v2.3 made the segment
        // mandatory for *new* strings (TcModel defaults to []), but decoding
        // must stay backward compatible with v2.0-v2.2 strings.
        $disclosedVendors = null;
        $allowedVendors = null;

        for ($i = 1; $i < count($segments); $i++) {
            $reader = new BitReader(Base64Url::decodeToBits($segments[$i]));
            $segmentType = $reader->readUint(3);

            match ($segmentType) {
                self::SEGMENT_TYPE_DISCLOSED_VENDORS => $disclosedVendors = RangeSection::decode($reader),
                self::SEGMENT_TYPE_ALLOWED_VENDORS => $allowedVendors = RangeSection::decode($reader),
                // Segment type 3 (Publisher TC) is intentionally unsupported — skipped.
                default => null,
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
