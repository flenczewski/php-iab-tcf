<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf;

/**
 * Plain data object representing the fields of a TCF v2 Core String, plus the
 * Disclosed/Allowed Vendors segments. See TcStringEncoder/Decoder.
 *
 * TCF v2.3 made the Disclosed Vendors segment (type 1) mandatory (previously
 * optional in v2.0-v2.2) to remove ambiguity around Legitimate Interest
 * signalling — see README "TCF v2.3". Accordingly $disclosedVendors defaults
 * to an empty array (segment always emitted); pass `null` explicitly only if
 * you deliberately need pre-2.3 wire compatibility that omits the segment.
 *
 * Publisher TC segment (segment type 3) is intentionally not represented —
 * see README "Known limitations".
 */
final class TcModel
{
    /**
     * @param int[] $specialFeatureOptIns 1-based Special Feature ids opted into
     * @param int[] $purposesConsent 1-based Purpose ids with consent
     * @param int[] $purposesLITransparency 1-based Purpose ids with legitimate interest established
     * @param int[] $vendorConsents vendor ids with consent
     * @param int[] $vendorLegitimateInterests vendor ids with legitimate interest
     * @param PublisherRestriction[] $publisherRestrictions
     * @param int[]|null $disclosedVendors vendor ids disclosed to the user (segment type 1). Defaults to `[]`
     *                                     (segment emitted, v2.3-compliant); pass `null` to omit the segment
     *                                     entirely for pre-v2.3 wire compatibility.
     * @param int[]|null $allowedVendors vendor ids allowed by the publisher (segment type 2); null omits the segment
     */
    public function __construct(
        public readonly int $cmpId,
        public readonly int $cmpVersion,
        public readonly int $consentScreen = 0,
        public readonly string $consentLanguage = 'EN',
        public readonly int $vendorListVersion = 0,
        public readonly int $tcfPolicyVersion = 5,
        public readonly bool $isServiceSpecific = false,
        public readonly bool $useNonStandardStacks = false,
        public readonly bool $purposeOneTreatment = false,
        public readonly string $publisherCC = 'AA',
        public readonly array $specialFeatureOptIns = [],
        public readonly array $purposesConsent = [],
        public readonly array $purposesLITransparency = [],
        public readonly array $vendorConsents = [],
        public readonly array $vendorLegitimateInterests = [],
        public readonly array $publisherRestrictions = [],
        public readonly ?array $disclosedVendors = [],
        public readonly ?array $allowedVendors = null,
        public readonly ?\DateTimeImmutable $created = null,
        public readonly ?\DateTimeImmutable $lastUpdated = null,
    ) {
    }
}
