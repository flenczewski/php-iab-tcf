<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Tests;

use Flenczewski\IabTcf\BitReader;
use Flenczewski\IabTcf\Exception\InvalidArgumentException;
use Flenczewski\IabTcf\RangeSection;
use Flenczewski\IabTcf\Spec;
use Flenczewski\IabTcf\TcModel;
use Flenczewski\IabTcf\TcStringDecoder;
use Flenczewski\IabTcf\TcStringEncoder;
use PHPUnit\Framework\TestCase;

final class SpecBoundaryTest extends TestCase
{
    public function testHighestVendorIdRoundTrips(): void
    {
        $model = new TcModel(cmpId: 1, cmpVersion: 1, vendorConsents: [1, Spec::MAX_VENDOR_ID]);

        self::assertSame(
            [1, Spec::MAX_VENDOR_ID],
            TcStringDecoder::decode(TcStringEncoder::encode($model))->vendorConsents,
        );
    }

    public function testAllPurposesAndSpecialFeaturesRoundTripAtTheirMaxima(): void
    {
        $model = new TcModel(
            cmpId: Spec::MAX_CMP_ID,
            cmpVersion: Spec::MAX_CMP_VERSION,
            consentScreen: Spec::MAX_CONSENT_SCREEN,
            vendorListVersion: Spec::MAX_VENDOR_LIST_VERSION,
            tcfPolicyVersion: Spec::MAX_TCF_POLICY_VERSION,
            specialFeatureOptIns: range(1, Spec::MAX_SPECIAL_FEATURE_ID),
            purposesConsent: range(1, Spec::MAX_PURPOSE_ID),
            purposesLITransparency: range(1, Spec::MAX_PURPOSE_ID),
        );

        $decoded = TcStringDecoder::decode(TcStringEncoder::encode($model));

        self::assertSame(range(1, Spec::MAX_PURPOSE_ID), $decoded->purposesConsent);
        self::assertSame(range(1, Spec::MAX_SPECIAL_FEATURE_ID), $decoded->specialFeatureOptIns);
        self::assertSame(Spec::MAX_CMP_ID, $decoded->cmpId);
        self::assertSame(Spec::MAX_TCF_POLICY_VERSION, $decoded->tcfPolicyVersion);
    }

    public function testMaximumRangeEntryCountEncodes(): void
    {
        // MAX_RANGE_ENTRIES disjoint single-id ranges is the largest range list
        // the 12-bit NumEntries field can describe.
        $ids = [];
        for ($i = 0; $i < Spec::MAX_RANGE_ENTRIES; $i++) {
            $ids[] = $i * 2 + 1;
        }

        $bits = RangeSection::encodeRangeList($ids);

        self::assertSame($ids, RangeSection::decodeRangeList(new BitReader($bits)));
    }

    public function testOneRangeEntryTooManyIsRejectedWithAnExplanation(): void
    {
        $ids = [];
        for ($i = 0; $i < Spec::MAX_RANGE_ENTRIES + 1; $i++) {
            $ids[] = $i * 2 + 1;
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('NumEntries');

        RangeSection::encodeRangeList($ids);
    }
}
