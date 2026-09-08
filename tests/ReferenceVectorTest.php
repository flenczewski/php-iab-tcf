<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Tests;

use Flenczewski\IabTcf\Exception\InvalidTcStringException;
use Flenczewski\IabTcf\RestrictionType;
use Flenczewski\IabTcf\TcStringDecoder;
use Flenczewski\IabTcf\TcStringEncoder;
use PHPUnit\Framework\TestCase;

/**
 * Conformance tests against TC Strings produced by *other* implementations.
 *
 * Every other test in this suite is a round trip through this package, so it
 * would pass even if this package misread the spec. These vectors are the only
 * thing that catches that class of bug — never regenerate the expected values
 * with TcStringEncoder. Provenance is documented per test.
 */
final class ReferenceVectorTest extends TestCase
{
    /** A v2.0-era string from a production CMP: no Disclosed Vendors segment. */
    private const CMP_27_VECTOR = 'COvFyGBOvFyGBAbAAAENAPCAAOAAAAAAAAAAAEEUACCKAAA';

    /**
     * From iabtcf-es (the IAB's own JS reference implementation), test
     * "201 unable to decode valid TCF2 String" in
     * modules/core/test/ReportedIssues.test.ts. This string encodes a purpose
     * restriction for the same vendor more than once, so it also exercises
     * range-list de-duplication.
     */
    private const IABTCF_ES_ISSUE_201_VECTOR = 'CO4VGswO4VGswAfZCBDEAzCsAP_AAH_AAAigGUNf_X9fb2vj-_599_t0eY1f9_63t-wzjheMs-8NyZ-X_J4Wv2MyvB34JqQKGRgkunLBAQdtHGncTQgBwIlViTLMY02MjzNKJrJEilsbe2dYGH9vn8XT_ZKZ70-_v__7v3___33_5Ayhr_6_r7e18f3_Pvv9ujzGr_v_W9v2GccLxln3huTPy_5PC1-xmV4O_BNSBQyMEl05YICDto407iaEAOBEqsSZZjGmxkeZpRNZIkUtjb2zrAw_t8_i6f7JTPen39___d-___--__ICgKAOAAcAA4AFAAjgB6AEYALcGACAW0AtoJAHAAOAAcACgARwA9ACMAFuFABALaAW0GgDgAHAAOABQAI4AegBGAC3DgAgFtALaEQBwADgAHAAoAEcAPQAjABbiQAQC2gFtCoA4ABwADgAUACOAHoARgAtxYAIBbQC2hkAcAA4ABwAKABHAD0AIwAW40AEAtoBbQ6AOAAcAA4AFAAjgB6AEYALceACAW0AtohAHAAOAAcACgARwA9ACMAFuRABALaAW0SgDgAHAAOABQAI4AegBGAC3JgAgFtALaKQBwADgAHAAoAEcAPQAjABblQAQC2gFtA';

    /**
     * From iabtcf-es ReportedIssues.test.ts: a three-segment string
     * (core + Disclosed Vendors + Publisher TC).
     */
    private const IABTCF_ES_THREE_SEGMENT_VECTOR = 'CQd924AQd924AASACCENCNFsAP_gAEIAACiQL6QBAAGAAOANmAcAF9IAIADgAA.IL6AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA.YIAAAAAAAAAA';

    /** A TCF v1.1 string, from iabtcf-es test "191 vendorConsents empty...". */
    private const TCF_V1_VECTOR = 'BO2e4qiO2e4qiB9ABADEDS-AAAAxKABgACBiQA';

    public function testDecodesAProductionCmpString(): void
    {
        $model = TcStringDecoder::decode(self::CMP_27_VECTOR);

        self::assertSame(27, $model->cmpId);
        self::assertSame(0, $model->cmpVersion);
        self::assertSame(0, $model->consentScreen);
        self::assertSame('EN', $model->consentLanguage);
        self::assertSame(15, $model->vendorListVersion);
        self::assertSame(2, $model->tcfPolicyVersion);
        self::assertFalse($model->isServiceSpecific);
        self::assertFalse($model->useNonStandardStacks);
        self::assertFalse($model->purposeOneTreatment);
        self::assertSame('AA', $model->publisherCC);
        self::assertSame([], $model->specialFeatureOptIns);
        self::assertSame([1, 2, 3], $model->purposesConsent);
        self::assertSame([], $model->purposesLITransparency);
        self::assertSame([2, 6, 8], $model->vendorConsents);
        self::assertSame([2, 6, 8], $model->vendorLegitimateInterests);
        self::assertSame([], $model->publisherRestrictions);
        self::assertNull($model->disclosedVendors, 'This vector carries no Disclosed Vendors segment.');
        self::assertNull($model->allowedVendors);
    }

    public function testDecodesTheTimestampsToDecisecondPrecision(): void
    {
        $model = TcStringDecoder::decode(self::CMP_27_VECTOR);

        self::assertNotNull($model->created);
        self::assertNotNull($model->lastUpdated);
        self::assertSame('2020-02-20T23:57:39.300000+00:00', $model->created->format('Y-m-d\TH:i:s.uP'));
        self::assertSame('2020-02-20T23:57:39.300000+00:00', $model->lastUpdated->format('Y-m-d\TH:i:s.uP'));
    }

    public function testReEncodesTheVectorBitForBit(): void
    {
        self::assertSame(
            self::CMP_27_VECTOR,
            TcStringEncoder::encode(TcStringDecoder::decode(self::CMP_27_VECTOR)),
            'A decode/encode cycle must reproduce a third-party string exactly.'
        );
    }

    /**
     * The expected vendor list is iabtcf-es's own hardcoded assertion:
     *
     *   expect(tcModel.publisherRestrictions.getVendors(new PurposeRestriction(1, 1)))
     *       .to.deep.equal([7, 20, 71, 122, 140, 183]);
     *
     * RestrictionType 1 is REQUIRE_CONSENT.
     */
    public function testMatchesTheReferenceImplementationOnPublisherRestrictions(): void
    {
        $model = TcStringDecoder::decode(self::IABTCF_ES_ISSUE_201_VECTOR);

        $vendorsForPurposeOneRequireConsent = null;
        foreach ($model->publisherRestrictions as $restriction) {
            if ($restriction->purposeId === 1 && $restriction->type === RestrictionType::REQUIRE_CONSENT) {
                $vendorsForPurposeOneRequireConsent = $restriction->vendorIds;
            }
        }

        self::assertSame([7, 20, 71, 122, 140, 183], $vendorsForPurposeOneRequireConsent);
    }

    /**
     * Structural expectations derived by decoding the segment bit by bit, NOT
     * copied from iabtcf-es: its assertion for this string lives in a
     * commented-out debugging test and is wrong. The Disclosed Vendors segment
     * is 1544 bits — a 20-bit header (type 1, MaxVendorId 1524, bitfield
     * encoding) followed by 1524 payload bits that are all zero — so the
     * correct decode is an empty disclosed-vendor set, not four vendors.
     */
    public function testDecodesAThreeSegmentStringIncludingAnEmptyDisclosedVendorsBitfield(): void
    {
        $model = TcStringDecoder::decode(self::IABTCF_ES_THREE_SEGMENT_VECTOR);

        self::assertSame(141, $model->vendorListVersion);
        self::assertSame([], $model->disclosedVendors, 'Segment present but its bitfield is entirely zero.');
        self::assertNull($model->allowedVendors, 'The third segment is Publisher TC (type 3), not Allowed Vendors.');
    }

    public function testRejectsATcfV1String(): void
    {
        $this->expectException(InvalidTcStringException::class);
        $this->expectExceptionMessage('Only version 2 is supported');

        TcStringDecoder::decode(self::TCF_V1_VECTOR);
    }
}
