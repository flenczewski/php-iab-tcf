<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Consent;

use Flenczewski\IabTcf\Gvl\Gvl;
use Flenczewski\IabTcf\Gvl\Vendor;
use Flenczewski\IabTcf\RestrictionType;
use Flenczewski\IabTcf\TcModel;

/**
 * Answers "may this vendor process data for this Purpose?" for one decoded TC
 * String, by combining the signals a TcModel carries with the vendor's
 * declarations in the Global Vendor List.
 *
 * For a vendor and a Purpose, in this order:
 *
 * 1. The vendor must exist in the GVL and not be deleted from it.
 * 2. A publisher restriction of type NOT_ALLOWED denies the Purpose outright.
 * 3. The legal basis is the one the vendor declares for the Purpose (consent
 *    or legitimate interest). A REQUIRE_CONSENT / REQUIRE_LEGITIMATE_INTEREST
 *    restriction switches it, but only for a Purpose the vendor declares as
 *    flexible; otherwise the vendor cannot comply and is denied.
 * 4. Consent needs the Purpose's consent bit and the vendor's consent bit.
 *    Legitimate interest needs the Purpose's LI transparency bit, the
 *    vendor's LI bit, a Purpose the declared policy version allows LI for
 *    (not 1, nor 3 to 6 from TCF v2.2), and — when the string carries a
 *    Disclosed Vendors segment — the vendor listed in it (TCF v2.3: an unset
 *    LI bit for a vendor never shown means "not disclosed", not "objected").
 *
 * The evaluator does not check that the GVL is the version the string names
 * ($model->vendorListVersion); pass the list that matches your deployment.
 * PurposeOneTreatment is informational: when it is set the publisher did not
 * disclose Purpose 1, so its consent bit is normally unset and Purpose 1 is
 * denied like any other Purpose without consent.
 *
 * Build one per decoded TC String; the lookups it precomputes make each check
 * O(1).
 */
final class ConsentEvaluator
{
    /** @var array<int, true> */
    private readonly array $purposesConsent;

    /** @var array<int, true> */
    private readonly array $purposesLITransparency;

    /** @var array<int, true> */
    private readonly array $vendorConsents;

    /** @var array<int, true> */
    private readonly array $vendorLegitimateInterests;

    /** @var array<int, true>|null null when the string carried no Disclosed Vendors segment */
    private readonly ?array $disclosedVendors;

    /** @var array<int, true> */
    private readonly array $specialFeatureOptIns;

    /** @var array<int, true> */
    private readonly array $purposesWithoutLegitimateInterest;

    /** @var array<int, array<int, array<string, RestrictionType>>> purpose => vendor => type name => type */
    private readonly array $restrictions;

    public function __construct(
        public readonly TcModel $model,
        public readonly Gvl $gvl,
    ) {
        $this->purposesConsent = self::lookup($model->purposesConsent);
        $this->purposesLITransparency = self::lookup($model->purposesLITransparency);
        $this->vendorConsents = self::lookup($model->vendorConsents);
        $this->vendorLegitimateInterests = self::lookup($model->vendorLegitimateInterests);
        $this->disclosedVendors = $model->disclosedVendors === null ? null : self::lookup($model->disclosedVendors);
        $this->specialFeatureOptIns = self::lookup($model->specialFeatureOptIns);
        $this->purposesWithoutLegitimateInterest = self::lookup(
            $model->tcfPolicyVersion >= TcModel::TCF_V2_2_POLICY_VERSION
                ? TcModel::PURPOSES_WITHOUT_LEGITIMATE_INTEREST
                : [1],
        );

        $restrictions = [];
        foreach ($model->publisherRestrictions as $restriction) {
            foreach ($restriction->vendorIds as $vendorId) {
                $restrictions[$restriction->purposeId][$vendorId][$restriction->type->name] = $restriction->type;
            }
        }
        $this->restrictions = $restrictions;
    }

    /** Whether the vendor may process data for the Purpose; see {@see self::evaluate()} for why. */
    public function canProcess(int $vendorId, int $purposeId): bool
    {
        return $this->evaluate($vendorId, $purposeId)->allowed;
    }

    public function evaluate(int $vendorId, int $purposeId): Decision
    {
        $vendor = $this->gvl->vendors[$vendorId] ?? null;
        if ($vendor === null) {
            return Decision::deny(DecisionReason::VENDOR_NOT_IN_GVL);
        }
        if ($this->gvl->isDeleted($vendor)) {
            return Decision::deny(DecisionReason::VENDOR_DELETED);
        }

        $restrictionTypes = $this->restrictions[$purposeId][$vendorId] ?? [];
        if (isset($restrictionTypes[RestrictionType::NOT_ALLOWED->name])) {
            return Decision::deny(DecisionReason::PUBLISHER_NOT_ALLOWED);
        }

        $declared = self::declaredBasis($vendor, $purposeId);
        if ($declared === null) {
            return Decision::deny(DecisionReason::PURPOSE_NOT_DECLARED);
        }

        $required = self::requiredBasis($restrictionTypes);
        if ($required === false) {
            return Decision::deny(DecisionReason::PUBLISHER_RESTRICTION_INCOMPATIBLE);
        }
        $basis = $declared;
        if ($required !== null && $required !== $declared) {
            if (!in_array($purposeId, $vendor->flexiblePurposes, true)) {
                return Decision::deny(DecisionReason::PUBLISHER_RESTRICTION_INCOMPATIBLE, $required);
            }
            $basis = $required;
        }

        return $basis === LegalBasis::CONSENT
            ? $this->checkConsent($vendorId, $purposeId)
            : $this->checkLegitimateInterest($vendorId, $purposeId);
    }

    /**
     * Whether the user opted in to the Special Feature and the vendor declares
     * it. Special Features are opt-in only: there is no legitimate interest
     * path and no per-vendor signal in the TC String.
     */
    public function hasSpecialFeatureOptIn(int $vendorId, int $specialFeatureId): bool
    {
        $vendor = $this->gvl->vendors[$vendorId] ?? null;

        return $vendor !== null
            && !$this->gvl->isDeleted($vendor)
            && isset($this->specialFeatureOptIns[$specialFeatureId])
            && in_array($specialFeatureId, $vendor->specialFeatures, true);
    }

    private function checkConsent(int $vendorId, int $purposeId): Decision
    {
        if (!isset($this->purposesConsent[$purposeId])) {
            return Decision::deny(DecisionReason::NO_PURPOSE_CONSENT, LegalBasis::CONSENT);
        }
        if (!isset($this->vendorConsents[$vendorId])) {
            return Decision::deny(DecisionReason::NO_VENDOR_CONSENT, LegalBasis::CONSENT);
        }

        return Decision::allow(LegalBasis::CONSENT);
    }

    private function checkLegitimateInterest(int $vendorId, int $purposeId): Decision
    {
        $li = LegalBasis::LEGITIMATE_INTEREST;
        if (isset($this->purposesWithoutLegitimateInterest[$purposeId])) {
            return Decision::deny(DecisionReason::LEGITIMATE_INTEREST_NOT_PERMITTED, $li);
        }
        if ($this->disclosedVendors !== null && !isset($this->disclosedVendors[$vendorId])) {
            return Decision::deny(DecisionReason::VENDOR_NOT_DISCLOSED, $li);
        }
        if (!isset($this->purposesLITransparency[$purposeId])) {
            return Decision::deny(DecisionReason::NO_PURPOSE_LEGITIMATE_INTEREST, $li);
        }
        if (!isset($this->vendorLegitimateInterests[$vendorId])) {
            return Decision::deny(DecisionReason::NO_VENDOR_LEGITIMATE_INTEREST, $li);
        }

        return Decision::allow($li);
    }

    /**
     * The basis the vendor declares for the Purpose in the GVL. A flexible
     * Purpose is listed under its default basis as well, so flexiblePurposes
     * alone does not declare anything.
     */
    private static function declaredBasis(Vendor $vendor, int $purposeId): ?LegalBasis
    {
        if (in_array($purposeId, $vendor->purposes, true)) {
            return LegalBasis::CONSENT;
        }
        if (in_array($purposeId, $vendor->legIntPurposes, true)) {
            return LegalBasis::LEGITIMATE_INTEREST;
        }

        return null;
    }

    /**
     * The basis the publisher's restrictions require: null for none, false
     * when restrictions require both (a contradiction no vendor can satisfy).
     *
     * @param array<string, RestrictionType> $types
     */
    private static function requiredBasis(array $types): LegalBasis|false|null
    {
        $consent = isset($types[RestrictionType::REQUIRE_CONSENT->name]);
        $li = isset($types[RestrictionType::REQUIRE_LEGITIMATE_INTEREST->name]);

        return match (true) {
            $consent && $li => false,
            $consent => LegalBasis::CONSENT,
            $li => LegalBasis::LEGITIMATE_INTEREST,
            default => null,
        };
    }

    /**
     * @param int[] $ids
     * @return array<int, true>
     */
    private static function lookup(array $ids): array
    {
        return array_fill_keys($ids, true);
    }
}
