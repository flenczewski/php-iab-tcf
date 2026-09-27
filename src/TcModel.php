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
     * Purposes a vendor may not process under legitimate interest: purpose 1
     * never allowed it, and TCF v2.2 withdrew it for purposes 3 to 6.
     */
    public const PURPOSES_WITHOUT_LEGITIMATE_INTEREST = [1, 3, 4, 5, 6];

    /** Upper-case, as the wire format stores it. */
    public readonly string $consentLanguage;

    /** Upper-case, as the wire format stores it. */
    public readonly string $publisherCC;

    /** @var int[] sorted, de-duplicated */
    public readonly array $specialFeatureOptIns;

    /** @var int[] sorted, de-duplicated */
    public readonly array $purposesConsent;

    /** @var int[] sorted, de-duplicated */
    public readonly array $purposesLITransparency;

    /** @var int[] sorted, de-duplicated */
    public readonly array $vendorConsents;

    /** @var int[] sorted, de-duplicated */
    public readonly array $vendorLegitimateInterests;

    /** @var int[]|null sorted, de-duplicated; see the constructor for what null means */
    public readonly ?array $disclosedVendors;

    /** @var int[]|null sorted, de-duplicated; null omits the segment */
    public readonly ?array $allowedVendors;

    /**
     * Id lists are stored sorted and de-duplicated, and the two-letter codes
     * upper-cased — the only forms the wire format can carry — so a model
     * equals its own decode/encode cycle.
     *
     * @param int[] $specialFeatureOptIns 1-based Special Feature ids opted into
     * @param int[] $purposesConsent 1-based Purpose ids with consent
     * @param int[] $purposesLITransparency 1-based Purpose ids with legitimate interest established
     * @param int[] $vendorConsents vendor ids with consent
     * @param int[] $vendorLegitimateInterests vendor ids with legitimate interest
     * @param bool $useNonStandardStacks the Core String's UseNonStandardTexts bit (named UseNonStandardStacks
     *                                   before TCF v2.2)
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
        string $consentLanguage = 'EN',
        public readonly int $vendorListVersion = 0,
        public readonly int $tcfPolicyVersion = 5,
        public readonly bool $isServiceSpecific = false,
        public readonly bool $useNonStandardStacks = false,
        public readonly bool $purposeOneTreatment = false,
        string $publisherCC = 'AA',
        array $specialFeatureOptIns = [],
        array $purposesConsent = [],
        array $purposesLITransparency = [],
        array $vendorConsents = [],
        array $vendorLegitimateInterests = [],
        public readonly array $publisherRestrictions = [],
        ?array $disclosedVendors = [],
        ?array $allowedVendors = null,
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
        $this->consentLanguage = strtoupper($consentLanguage);
        $this->publisherCC = strtoupper($publisherCC);

        $this->specialFeatureOptIns = self::idSet(
            'specialFeatureOptIns',
            $specialFeatureOptIns,
            1,
            Spec::MAX_SPECIAL_FEATURE_ID,
        );
        $this->purposesConsent = self::idSet('purposesConsent', $purposesConsent, 1, Spec::MAX_PURPOSE_ID);
        $this->purposesLITransparency = self::idSet(
            'purposesLITransparency',
            $purposesLITransparency,
            1,
            Spec::MAX_PURPOSE_ID,
        );
        $this->vendorConsents = self::idSet(
            'vendorConsents',
            $vendorConsents,
            Spec::MIN_VENDOR_ID,
            Spec::MAX_VENDOR_ID,
        );
        $this->vendorLegitimateInterests = self::idSet(
            'vendorLegitimateInterests',
            $vendorLegitimateInterests,
            Spec::MIN_VENDOR_ID,
            Spec::MAX_VENDOR_ID,
        );
        $this->disclosedVendors = $disclosedVendors === null
            ? null
            : self::idSet('disclosedVendors', $disclosedVendors, Spec::MIN_VENDOR_ID, Spec::MAX_VENDOR_ID);
        $this->allowedVendors = $allowedVendors === null
            ? null
            : self::idSet('allowedVendors', $allowedVendors, Spec::MIN_VENDOR_ID, Spec::MAX_VENDOR_ID);

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

    /**
     * Validates an id list and returns it sorted and de-duplicated.
     *
     * @param array<mixed> $ids
     * @return int[]
     */
    private static function idSet(string $field, array $ids, int $min, int $max): array
    {
        $set = [];
        foreach ($ids as $id) {
            if (!is_int($id)) {
                throw new InvalidArgumentException("{$field} must contain only integers.");
            }
            if ($id < $min || $id > $max) {
                throw new InvalidArgumentException(
                    "{$field} contains id {$id}, which is outside the valid range {$min}..{$max}."
                );
            }
            $set[$id] = true;
        }

        $ids = array_keys($set);
        sort($ids);

        return $ids;
    }

    /**
     * Reports what the TCF policy forbids but the wire format can still
     * express. The constructor only enforces field bounds, so that any string
     * a CMP produced — conformant or not — can be decoded and inspected; call
     * this before encoding a model of your own.
     *
     * Checks: legitimate interest (established, or required by a publisher
     * restriction) for a purpose in {@see self::PURPOSES_WITHOUT_LEGITIMATE_INTEREST};
     * the reserved RestrictionType::UNDEFINED; a vendor given two restriction
     * types for the same purpose; and Created later than LastUpdated.
     *
     * @return string[] human-readable violations; empty if none were found
     */
    public function policyViolations(): array
    {
        $violations = [];

        $forbiddenLi = array_values(array_intersect(
            $this->purposesLITransparency,
            self::PURPOSES_WITHOUT_LEGITIMATE_INTEREST,
        ));
        if ($forbiddenLi !== []) {
            $violations[] = 'purposesLITransparency establishes legitimate interest for purposes '
                . implode(', ', $forbiddenLi) . ', which the TCF policy does not allow under that legal basis.';
        }

        $typesByPurposeAndVendor = [];
        foreach ($this->publisherRestrictions as $index => $restriction) {
            if ($restriction->type === RestrictionType::UNDEFINED) {
                $violations[] = "Publisher restriction {$index} uses the reserved restriction type UNDEFINED.";
            }
            if (
                $restriction->type === RestrictionType::REQUIRE_LEGITIMATE_INTEREST
                && in_array($restriction->purposeId, self::PURPOSES_WITHOUT_LEGITIMATE_INTEREST, true)
            ) {
                $violations[] = "Publisher restriction {$index} requires legitimate interest for purpose "
                    . "{$restriction->purposeId}, which the TCF policy does not allow under that legal basis.";
            }
            foreach ($restriction->vendorIds as $vendorId) {
                $typesByPurposeAndVendor[$restriction->purposeId][$vendorId][$restriction->type->name] = true;
            }
        }

        foreach ($typesByPurposeAndVendor as $purposeId => $typesByVendor) {
            foreach ($typesByVendor as $vendorId => $types) {
                if (count($types) > 1) {
                    $violations[] = "Vendor {$vendorId} has conflicting publisher restrictions for purpose "
                        . "{$purposeId}: " . implode(', ', array_keys($types)) . '.';
                }
            }
        }

        if ($this->created !== null && $this->lastUpdated !== null && $this->created > $this->lastUpdated) {
            $violations[] = sprintf(
                'created (%s) is after lastUpdated (%s).',
                $this->created->format(\DATE_ATOM),
                $this->lastUpdated->format(\DATE_ATOM),
            );
        }

        return $violations;
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
