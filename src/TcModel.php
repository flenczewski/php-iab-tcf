<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf;

use Flenczewski\IabTcf\Exception\InvalidArgumentException;

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
final class TcModel implements \JsonSerializable
{
    /**
     * @param int[] $specialFeatureOptIns 1-based Special Feature ids opted into
     * @param int[] $purposesConsent 1-based Purpose ids with consent
     * @param int[] $purposesLITransparency 1-based Purpose ids with legitimate interest established
     * @param int[] $vendorConsents vendor ids with consent
     * @param int[] $vendorLegitimateInterests vendor ids with legitimate interest
     * @param PublisherRestriction[] $publisherRestrictions
     * @param int[]|null $disclosedVendors vendor ids disclosed to the user (segment type 1). Defaults to `[]`
     *                                     for newly constructed models (segment emitted, v2.3-compliant); pass
     *                                     `null` to omit the segment entirely for pre-v2.3 wire compatibility.
     *                                     TcStringDecoder sets this to `null` when the decoded string carried no
     *                                     such segment, so `null` means "absent" and `[]` means "present but
     *                                     empty" — which is what keeps a decode/encode cycle from inventing a
     *                                     segment the input never had. See README "Round-tripping" for the
     *                                     limits of that guarantee.
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
        self::assertInRange('cmpId', $cmpId, 0, Spec::MAX_CMP_ID);
        self::assertInRange('cmpVersion', $cmpVersion, 0, Spec::MAX_CMP_VERSION);
        self::assertInRange('consentScreen', $consentScreen, 0, Spec::MAX_CONSENT_SCREEN);
        self::assertInRange('vendorListVersion', $vendorListVersion, 0, Spec::MAX_VENDOR_LIST_VERSION);
        self::assertInRange('tcfPolicyVersion', $tcfPolicyVersion, 0, Spec::MAX_TCF_POLICY_VERSION);

        foreach (['consentLanguage' => $consentLanguage, 'publisherCC' => $publisherCC] as $field => $code) {
            if (!Alpha2Code::isValid($code)) {
                throw new InvalidArgumentException(
                    "{$field} must be a 2-letter alphabetic code, got \"{$code}\"."
                );
            }
        }

        self::assertIdSet('specialFeatureOptIns', $specialFeatureOptIns, 1, Spec::MAX_SPECIAL_FEATURE_ID);
        self::assertIdSet('purposesConsent', $purposesConsent, 1, Spec::MAX_PURPOSE_ID);
        self::assertIdSet('purposesLITransparency', $purposesLITransparency, 1, Spec::MAX_PURPOSE_ID);
        self::assertIdSet('vendorConsents', $vendorConsents, Spec::MIN_VENDOR_ID, Spec::MAX_VENDOR_ID);
        self::assertIdSet(
            'vendorLegitimateInterests',
            $vendorLegitimateInterests,
            Spec::MIN_VENDOR_ID,
            Spec::MAX_VENDOR_ID
        );

        if ($disclosedVendors !== null) {
            self::assertIdSet('disclosedVendors', $disclosedVendors, Spec::MIN_VENDOR_ID, Spec::MAX_VENDOR_ID);
        }
        if ($allowedVendors !== null) {
            self::assertIdSet('allowedVendors', $allowedVendors, Spec::MIN_VENDOR_ID, Spec::MAX_VENDOR_ID);
        }

        foreach ($publisherRestrictions as $restriction) {
            if (!$restriction instanceof PublisherRestriction) {
                throw new InvalidArgumentException(
                    'publisherRestrictions must contain only PublisherRestriction instances.'
                );
            }
        }
    }

    private static function assertInRange(string $field, int $value, int $min, int $max): void
    {
        if ($value < $min || $value > $max) {
            throw new InvalidArgumentException("{$field} must be between {$min} and {$max}, got {$value}.");
        }
    }

    /** @param int[] $ids */
    private static function assertIdSet(string $field, array $ids, int $min, int $max): void
    {
        foreach ($ids as $id) {
            if (!is_int($id)) {
                throw new InvalidArgumentException("{$field} must contain only integers.");
            }
            if ($id < $min || $id > $max) {
                throw new InvalidArgumentException(
                    "{$field} contains id {$id}, which is outside the valid range {$min}..{$max}."
                );
            }
        }
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'version' => Spec::CORE_STRING_VERSION,
            'created' => $this->created?->format(\DATE_ATOM),
            'lastUpdated' => $this->lastUpdated?->format(\DATE_ATOM),
            'cmpId' => $this->cmpId,
            'cmpVersion' => $this->cmpVersion,
            'consentScreen' => $this->consentScreen,
            'consentLanguage' => $this->consentLanguage,
            'vendorListVersion' => $this->vendorListVersion,
            'tcfPolicyVersion' => $this->tcfPolicyVersion,
            'isServiceSpecific' => $this->isServiceSpecific,
            'useNonStandardStacks' => $this->useNonStandardStacks,
            'purposeOneTreatment' => $this->purposeOneTreatment,
            'publisherCC' => $this->publisherCC,
            'specialFeatureOptIns' => $this->specialFeatureOptIns,
            'purposesConsent' => $this->purposesConsent,
            'purposesLITransparency' => $this->purposesLITransparency,
            'vendorConsents' => $this->vendorConsents,
            'vendorLegitimateInterests' => $this->vendorLegitimateInterests,
            'publisherRestrictions' => array_map(
                static fn (PublisherRestriction $r): array => [
                    'purposeId' => $r->purposeId,
                    'type' => $r->type->name,
                    'vendorIds' => $r->vendorIds,
                ],
                $this->publisherRestrictions,
            ),
            'disclosedVendors' => $this->disclosedVendors,
            'allowedVendors' => $this->allowedVendors,
        ];
    }
}
