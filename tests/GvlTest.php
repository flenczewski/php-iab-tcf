<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Tests;

use Flenczewski\IabTcf\Gvl\Gvl;
use Flenczewski\IabTcf\TcModel;
use PHPUnit\Framework\TestCase;

final class GvlTest extends TestCase
{
    private static function fixtureJson(): string
    {
        return file_get_contents(__DIR__ . '/fixtures/vendor-list-sample.json');
    }

    public function testFromJsonParsesMetadataAndVendors(): void
    {
        $gvl = Gvl::fromJson(self::fixtureJson());

        self::assertSame(3, $gvl->gvlSpecificationVersion);
        self::assertSame(175, $gvl->vendorListVersion);
        self::assertSame(5, $gvl->tcfPolicyVersion);
        self::assertSame('2026-09-03', $gvl->lastUpdated->format('Y-m-d'));
        self::assertCount(6, $gvl->vendors);
        self::assertSame('Exponential Interactive, Inc d/b/a VDX.tv', $gvl->vendors[1]->name);
    }

    public function testGetVendorsWithConsentPurpose(): void
    {
        $gvl = Gvl::fromJson(self::fixtureJson());

        // Purpose 5 ("Personalised advertising profile") is only declared by vendor 9 in the fixture.
        $vendors = $gvl->getVendorsWithConsentPurpose(5);

        self::assertCount(1, $vendors);
        self::assertSame(9, $vendors[0]->id);
    }

    public function testGetVendorsWithLegIntPurpose(): void
    {
        $gvl = Gvl::fromJson(self::fixtureJson());

        // Only vendor 8 declares legIntPurposes in the fixture.
        $vendors = $gvl->getVendorsWithLegIntPurpose(2);

        self::assertCount(1, $vendors);
        self::assertSame(8, $vendors[0]->id);
    }

    public function testGetVendorsWithFeature(): void
    {
        $gvl = Gvl::fromJson(self::fixtureJson());

        $vendors = $gvl->getVendorsWithFeature(3);
        $ids = array_map(static fn ($v) => $v->id, $vendors);
        sort($ids);

        self::assertSame([1, 4, 6, 9], $ids);
    }

    public function testGetVendorsWithSpecialFeature(): void
    {
        $gvl = Gvl::fromJson(self::fixtureJson());

        $vendors = $gvl->getVendorsWithSpecialFeature(2);
        $ids = array_map(static fn ($v) => $v->id, $vendors);
        sort($ids);

        self::assertSame([2, 9], $ids);
    }

    public function testGetVendorsWithSpecialPurpose(): void
    {
        $gvl = Gvl::fromJson(self::fixtureJson());

        $vendors = $gvl->getVendorsWithSpecialPurpose(3);
        $ids = array_map(static fn ($v) => $v->id, $vendors);
        sort($ids);

        self::assertSame([2, 4, 8], $ids);
    }

    public function testNarrowVendorsTo(): void
    {
        $gvl = Gvl::fromJson(self::fixtureJson());

        $narrowed = $gvl->narrowVendorsTo([1, 4]);

        self::assertCount(2, $narrowed->vendors);
        self::assertArrayHasKey(1, $narrowed->vendors);
        self::assertArrayHasKey(4, $narrowed->vendors);
        self::assertArrayNotHasKey(2, $narrowed->vendors);
        // Metadata is preserved.
        self::assertSame($gvl->vendorListVersion, $narrowed->vendorListVersion);
    }

    public function testValidateConsentsFlagsUnknownVendor(): void
    {
        $gvl = Gvl::fromJson(self::fixtureJson());
        $model = new TcModel(cmpId: 1, cmpVersion: 1, vendorConsents: [1, 99999]);

        $problems = $gvl->validateConsents($model);

        self::assertCount(1, $problems);
        self::assertStringContainsString('99999', $problems[0]);
        self::assertStringContainsString('does not exist', $problems[0]);
    }

    public function testValidateConsentsFlagsVendorWithNoDeclaredConsentPurposes(): void
    {
        $gvl = Gvl::fromJson(self::fixtureJson());
        // Vendor 8 has purposes=[1,3,4] (non-empty) so it should NOT be flagged for consent.
        $model = new TcModel(cmpId: 1, cmpVersion: 1, vendorConsents: [8]);

        self::assertSame([], $gvl->validateConsents($model));
    }

    public function testValidateConsentsFlagsVendorWithNoLegitimateInterestPurposes(): void
    {
        $gvl = Gvl::fromJson(self::fixtureJson());
        // Vendor 1 declares legIntPurposes=[] and flexiblePurposes=[2,7,8,9,10] (non-empty) -> not flagged.
        $model = new TcModel(cmpId: 1, cmpVersion: 1, vendorLegitimateInterests: [1]);
        self::assertSame([], $gvl->validateConsents($model));

        // Vendor 6 declares legIntPurposes=[] and flexiblePurposes=[] -> flagged.
        $model = new TcModel(cmpId: 1, cmpVersion: 1, vendorLegitimateInterests: [6]);
        $problems = $gvl->validateConsents($model);
        self::assertCount(1, $problems);
        self::assertStringContainsString('legitimate-interest-based purposes', $problems[0]);
    }

    public function testBundledParsesRealPackagedVendorList(): void
    {
        $gvl = Gvl::bundled();

        self::assertGreaterThan(0, $gvl->vendorListVersion);
        self::assertGreaterThan(100, count($gvl->vendors));
    }
}
