<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf;

/** Decodes a TC String produced by TcStringEncoder (or any spec-compliant TCF v2 encoder) into a TcModel. */
final class TcStringDecoder
{
    private const SUPPORTED_CORE_STRING_VERSION = 2;
    private const SEGMENT_TYPE_DISCLOSED_VENDORS = 1;
    private const SEGMENT_TYPE_ALLOWED_VENDORS = 2;

    public static function decode(string $tcString): TcModel
    {
        $segments = explode('.', $tcString);
        $core = new BitReader(Base64Url::decodeToBits($segments[0]));

        $version = $core->readUint(6);
        if ($version !== self::SUPPORTED_CORE_STRING_VERSION) {
            throw new \InvalidArgumentException(
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

        // Absent Disclosed Vendors segment is tolerated for pre-v2.3 strings
        // and normalized to [] (v2.3 made this segment mandatory going
        // forward, but decoding must stay backward-compatible).
        $disclosedVendors = [];
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
