<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Tests;

use Flenczewski\IabTcf\Consent\ConsentEvaluator;
use Flenczewski\IabTcf\Consent\DecisionReason;
use Flenczewski\IabTcf\Consent\LegalBasis;
use Flenczewski\IabTcf\Gvl\Gvl;
use Flenczewski\IabTcf\Gvl\Vendor;
use Flenczewski\IabTcf\PublisherRestriction;
use Flenczewski\IabTcf\RestrictionType;
use Flenczewski\IabTcf\TcModel;
use Flenczewski\IabTcf\TcStringDecoder;
use Flenczewski\IabTcf\TcStringEncoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConsentEvaluatorTest extends TestCase
{
    private const CONSENT_VENDOR = 1;      // consent for 1-4, LI for 7; 2 and 7 flexible
    private const LI_VENDOR = 2;           // LI for 2 and 8, not flexible
    private const DELETED_VENDOR = 3;
    private const UNKNOWN_VENDOR = 99;

    private static function gvl(): Gvl
    {
        return new Gvl(3, 100, 5, new \DateTimeImmutable('2026-06-01T00:00:00Z'), [
            1 => new Vendor(1, 'Consent vendor', purposes: [1, 2, 3, 4], legIntPurposes: [7], flexiblePurposes: [2, 7], specialFeatures: [1]),
            2 => new Vendor(2, 'LI vendor', legIntPurposes: [2, 8]),
            3 => new Vendor(3, 'Deleted vendor', purposes: [1], deletedDate: new \DateTimeImmutable('2026-01-01T00:00:00Z')),
        ]);
    }

    /**
     * @param int[] $purposesConsent
     * @param int[] $purposesLITransparency
     * @param int[] $vendorConsents
     * @param int[] $vendorLegitimateInterests
     * @param int[]|null $disclosedVendors
     * @param PublisherRestriction[] $publisherRestrictions
     * @param int[] $specialFeatureOptIns
     */
    private static function model(
        array $purposesConsent = [1, 2, 3, 4],
        array $purposesLITransparency = [2, 7, 8],
        array $vendorConsents = [1, 3],
        array $vendorLegitimateInterests = [1, 2],
        ?array $disclosedVendors = [1, 2, 3],
        array $publisherRestrictions = [],
        array $specialFeatureOptIns = [1],
        int $tcfPolicyVersion = 5,
        int $vendorListVersion = 100,
    ): TcModel {
        return new TcModel(
            cmpId: 1,
            cmpVersion: 1,
            vendorListVersion: $vendorListVersion,
            tcfPolicyVersion: $tcfPolicyVersion,
            isServiceSpecific: true,
            specialFeatureOptIns: $specialFeatureOptIns,
            purposesConsent: $purposesConsent,
            purposesLITransparency: $purposesLITransparency,
            vendorConsents: $vendorConsents,
            vendorLegitimateInterests: $vendorLegitimateInterests,
            publisherRestrictions: $publisherRestrictions,
            disclosedVendors: $disclosedVendors,
        );
    }

    /** @return iterable<string, array{\Closure(): TcModel, int, int, DecisionReason, LegalBasis|null}> */
    public static function scenarios(): iterable
    {
        yield 'consent purpose with both consent bits' => [fn (): TcModel => self::model(), self::CONSENT_VENDOR, 1, DecisionReason::ALLOWED, LegalBasis::CONSENT];
        yield 'legitimate interest purpose with both LI bits' => [fn (): TcModel => self::model(), self::LI_VENDOR, 2, DecisionReason::ALLOWED, LegalBasis::LEGITIMATE_INTEREST];
        yield 'vendor missing from the GVL' => [fn (): TcModel => self::model(), self::UNKNOWN_VENDOR, 1, DecisionReason::VENDOR_NOT_IN_GVL, null];
        yield 'vendor deleted from the GVL' => [fn (): TcModel => self::model(), self::DELETED_VENDOR, 1, DecisionReason::VENDOR_DELETED, null];
        yield 'purpose the vendor does not declare' => [fn (): TcModel => self::model(), self::CONSENT_VENDOR, 9, DecisionReason::PURPOSE_NOT_DECLARED, null];
        yield 'no purpose consent' => [fn (): TcModel => self::model(purposesConsent: [2]), self::CONSENT_VENDOR, 1, DecisionReason::NO_PURPOSE_CONSENT, LegalBasis::CONSENT];
        yield 'no vendor consent' => [fn (): TcModel => self::model(vendorConsents: []), self::CONSENT_VENDOR, 1, DecisionReason::NO_VENDOR_CONSENT, LegalBasis::CONSENT];
        yield 'no purpose LI transparency' => [fn (): TcModel => self::model(purposesLITransparency: []), self::LI_VENDOR, 2, DecisionReason::NO_PURPOSE_LEGITIMATE_INTEREST, LegalBasis::LEGITIMATE_INTEREST];
        yield 'vendor LI objected' => [fn (): TcModel => self::model(vendorLegitimateInterests: [1]), self::LI_VENDOR, 2, DecisionReason::NO_VENDOR_LEGITIMATE_INTEREST, LegalBasis::LEGITIMATE_INTEREST];
        yield 'TCF v2.3: vendor never disclosed' => [fn (): TcModel => self::model(disclosedVendors: [1]), self::LI_VENDOR, 2, DecisionReason::VENDOR_NOT_DISCLOSED, LegalBasis::LEGITIMATE_INTEREST];
        yield 'pre-v2.3 string without the segment' => [fn (): TcModel => self::model(disclosedVendors: null), self::LI_VENDOR, 2, DecisionReason::ALLOWED, LegalBasis::LEGITIMATE_INTEREST];
        yield 'publisher disallows the purpose' => [
            fn (): TcModel => self::model(publisherRestrictions: [new PublisherRestriction(1, RestrictionType::NOT_ALLOWED, [1])]),
            self::CONSENT_VENDOR, 1, DecisionReason::PUBLISHER_NOT_ALLOWED, null,
        ];
        yield 'NOT_ALLOWED wins over a conflicting restriction' => [
            fn (): TcModel => self::model(publisherRestrictions: [
                new PublisherRestriction(2, RestrictionType::REQUIRE_CONSENT, [2]),
                new PublisherRestriction(2, RestrictionType::NOT_ALLOWED, [2]),
            ]),
            self::LI_VENDOR, 2, DecisionReason::PUBLISHER_NOT_ALLOWED, null,
        ];
        yield 'restriction for another vendor is ignored' => [
            fn (): TcModel => self::model(publisherRestrictions: [new PublisherRestriction(1, RestrictionType::NOT_ALLOWED, [2])]),
            self::CONSENT_VENDOR, 1, DecisionReason::ALLOWED, LegalBasis::CONSENT,
        ];
        yield 'flexible purpose switched to LI' => [
            fn (): TcModel => self::model(publisherRestrictions: [new PublisherRestriction(2, RestrictionType::REQUIRE_LEGITIMATE_INTEREST, [1])]),
            self::CONSENT_VENDOR, 2, DecisionReason::ALLOWED, LegalBasis::LEGITIMATE_INTEREST,
        ];
        yield 'flexible purpose switched to consent, consent missing' => [
            fn (): TcModel => self::model(publisherRestrictions: [new PublisherRestriction(7, RestrictionType::REQUIRE_CONSENT, [1])], purposesConsent: [1]),
            self::CONSENT_VENDOR, 7, DecisionReason::NO_PURPOSE_CONSENT, LegalBasis::CONSENT,
        ];
        yield 'non-flexible purpose cannot switch basis' => [
            fn (): TcModel => self::model(publisherRestrictions: [new PublisherRestriction(2, RestrictionType::REQUIRE_CONSENT, [2])]),
            self::LI_VENDOR, 2, DecisionReason::PUBLISHER_RESTRICTION_INCOMPATIBLE, LegalBasis::CONSENT,
        ];
        yield 'restriction requiring the declared basis changes nothing' => [
            fn (): TcModel => self::model(publisherRestrictions: [new PublisherRestriction(1, RestrictionType::REQUIRE_CONSENT, [1])]),
            self::CONSENT_VENDOR, 1, DecisionReason::ALLOWED, LegalBasis::CONSENT,
        ];
        yield 'restrictions requiring both bases' => [
            fn (): TcModel => self::model(publisherRestrictions: [
                new PublisherRestriction(2, RestrictionType::REQUIRE_CONSENT, [1]),
                new PublisherRestriction(2, RestrictionType::REQUIRE_LEGITIMATE_INTEREST, [1]),
            ]),
            self::CONSENT_VENDOR, 2, DecisionReason::PUBLISHER_RESTRICTION_INCOMPATIBLE, null,
        ];
        yield 'LI required on a consent purpose that is not flexible' => [
            fn (): TcModel => self::model(publisherRestrictions: [new PublisherRestriction(3, RestrictionType::REQUIRE_LEGITIMATE_INTEREST, [1])]),
            self::CONSENT_VENDOR, 3, DecisionReason::PUBLISHER_RESTRICTION_INCOMPATIBLE, LegalBasis::LEGITIMATE_INTEREST,
        ];
    }

    /** @param \Closure(): TcModel $model */
    #[DataProvider('scenarios')]
    public function testEvaluate(\Closure $model, int $vendorId, int $purposeId, DecisionReason $reason, ?LegalBasis $basis): void
    {
        $decision = (new ConsentEvaluator($model(), self::gvl()))->evaluate($vendorId, $purposeId);

        self::assertSame($reason, $decision->reason);
        self::assertSame($reason === DecisionReason::ALLOWED, $decision->allowed);
        self::assertSame($basis, $decision->legalBasis);
    }

    public function testLegitimateInterestIsDeniedForAPurposeThePolicyForbidsItFor(): void
    {
        $gvl = new Gvl(3, 1, 5, new \DateTimeImmutable('2026-06-01'), [
            5 => new Vendor(5, 'Legacy LI vendor', legIntPurposes: [3]),
        ]);
        $current = new ConsentEvaluator(
            self::model(purposesLITransparency: [3], vendorLegitimateInterests: [5], disclosedVendors: [5]),
            $gvl,
        );
        self::assertSame(DecisionReason::LEGITIMATE_INTEREST_NOT_PERMITTED, $current->evaluate(5, 3)->reason);

        $beforeTcf22 = new ConsentEvaluator(
            self::model(purposesLITransparency: [3], vendorLegitimateInterests: [5], disclosedVendors: [5], tcfPolicyVersion: 3),
            $gvl,
        );
        self::assertTrue($beforeTcf22->canProcess(5, 3));
    }

    public function testSpecialFeatureOptIn(): void
    {
        $evaluator = new ConsentEvaluator(self::model(), self::gvl());

        self::assertTrue($evaluator->hasSpecialFeatureOptIn(self::CONSENT_VENDOR, 1));
        self::assertFalse($evaluator->hasSpecialFeatureOptIn(self::CONSENT_VENDOR, 2), 'Not opted in.');
        self::assertFalse($evaluator->hasSpecialFeatureOptIn(self::LI_VENDOR, 1), 'Vendor does not declare it.');
        self::assertFalse($evaluator->hasSpecialFeatureOptIn(self::UNKNOWN_VENDOR, 1));

        $optedOut = new ConsentEvaluator(self::model(specialFeatureOptIns: []), self::gvl());
        self::assertFalse($optedOut->hasSpecialFeatureOptIn(self::CONSENT_VENDOR, 1));
    }

    public function testWorksOnADecodedStringAgainstTheBundledList(): void
    {
        $gvl = Gvl::bundled();
        $vendor = null;
        foreach ($gvl->getVendorsWithConsentPurpose(1) as $candidate) {
            $vendor = $candidate;
            break;
        }
        self::assertNotNull($vendor);

        $string = TcStringEncoder::encode(self::model(
            vendorConsents: [$vendor->id],
            disclosedVendors: [$vendor->id],
            vendorListVersion: $gvl->vendorListVersion,
        ));
        $evaluator = new ConsentEvaluator(TcStringDecoder::decode($string), $gvl);

        self::assertTrue($evaluator->canProcess($vendor->id, 1));
    }
}
