<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Tests;

use Flenczewski\IabTcf\Exception\GvlException;
use Flenczewski\IabTcf\Gvl\Gvl;
use Flenczewski\IabTcf\Gvl\Vendor;
use Flenczewski\IabTcf\TcModel;
use PHPUnit\Framework\TestCase;

final class GvlTest extends TestCase
{
    private static function fixtureJson(): string
    {
        $json = file_get_contents(__DIR__ . '/fixtures/vendor-list-sample.json');
        self::assertIsString($json);

        return $json;
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

    /**
     * Vendors 8 and 9 in the fixture carry a deletedDate before the list's
     * lastUpdated, so the queries leave them out unless asked not to.
     *
     * @param \Closure(Gvl, bool): Vendor[] $query
     * @param list<int> $expected
     * @param list<int> $expectedWithDeleted
     *
     * @dataProvider vendorQueries
     */
    public function testVendorQueriesSkipDeletedVendorsUnlessAskedTo(
        \Closure $query,
        array $expected,
        array $expectedWithDeleted,
    ): void {
        $gvl = Gvl::fromJson(self::fixtureJson());

        self::assertSame($expected, self::sortedIds($query($gvl, false)));
        self::assertSame($expectedWithDeleted, self::sortedIds($query($gvl, true)));
    }

    /**
     * @param Vendor[] $vendors
     * @return list<int>
     */
    private static function sortedIds(array $vendors): array
    {
        $ids = [];
        foreach ($vendors as $vendor) {
            $ids[] = $vendor->id;
        }
        sort($ids);

        return $ids;
    }

    /** @return iterable<string,array{\Closure(Gvl, bool): Vendor[],list<int>,list<int>}> */
    public static function vendorQueries(): iterable
    {
        // Purpose 5 ("Personalised advertising profile") is only declared by deleted vendor 9.
        yield 'consent purpose' => [
            static fn (Gvl $gvl, bool $deleted): array => $gvl->getVendorsWithConsentPurpose(5, $deleted),
            [],
            [9],
        ];
        yield 'consent purpose shared with live vendors' => [
            static fn (Gvl $gvl, bool $deleted): array => $gvl->getVendorsWithConsentPurpose(1, $deleted),
            [1, 2, 4, 6],
            [1, 2, 4, 6, 8, 9],
        ];
        // Only deleted vendor 8 declares legIntPurposes in the fixture.
        yield 'legitimate interest purpose' => [
            static fn (Gvl $gvl, bool $deleted): array => $gvl->getVendorsWithLegIntPurpose(2, $deleted),
            [],
            [8],
        ];
        yield 'feature' => [
            static fn (Gvl $gvl, bool $deleted): array => $gvl->getVendorsWithFeature(3, $deleted),
            [1, 4, 6],
            [1, 4, 6, 9],
        ];
        yield 'special feature' => [
            static fn (Gvl $gvl, bool $deleted): array => $gvl->getVendorsWithSpecialFeature(2, $deleted),
            [2],
            [2, 9],
        ];
        yield 'special purpose' => [
            static fn (Gvl $gvl, bool $deleted): array => $gvl->getVendorsWithSpecialPurpose(3, $deleted),
            [2, 4],
            [2, 4, 8],
        ];
    }

    public function testVendorDeletedDateIsParsed(): void
    {
        $gvl = Gvl::fromJson(self::fixtureJson());

        self::assertNull($gvl->vendors[1]->deletedDate);
        self::assertFalse($gvl->isDeleted($gvl->vendors[1]));
        self::assertSame('2025-05-13', $gvl->vendors[8]->deletedDate?->format('Y-m-d'));
        self::assertTrue($gvl->isDeleted($gvl->vendors[8]));
    }

    /** A deletion scheduled after the list's own lastUpdated has not happened yet as far as this list knows. */
    public function testAVendorDeletedAfterTheListWasPublishedIsStillLive(): void
    {
        $gvl = Gvl::fromJson('{"gvlSpecificationVersion":3,"vendorListVersion":1,"tcfPolicyVersion":5,'
            . '"lastUpdated":"2026-01-01T00:00:00Z","vendors":{"1":{"id":1,"name":"A","purposes":[1],'
            . '"deletedDate":"2026-06-01T00:00:00Z"}}}');

        self::assertFalse($gvl->isDeleted($gvl->vendors[1]));
        self::assertCount(1, $gvl->getVendorsWithConsentPurpose(1));
    }

    public function testValidateConsentsFlagsDeletedVendors(): void
    {
        $gvl = Gvl::fromJson(self::fixtureJson());
        $model = new TcModel(cmpId: 1, cmpVersion: 1, vendorConsents: [9], vendorLegitimateInterests: [8]);

        $problems = $gvl->validateConsents($model);

        self::assertCount(2, $problems);
        self::assertStringContainsString('Vendor 9 has consent', $problems[0]);
        self::assertStringContainsString('deleted', $problems[0]);
        self::assertStringContainsString('Vendor 8 has legitimate interest', $problems[1]);
        self::assertStringContainsString('deleted', $problems[1]);
    }

    /** @dataProvider invalidDates */
    public function testRejectsADeletedDateThatIsNotAnAbsoluteDate(string $date): void
    {
        $this->expectException(GvlException::class);
        $this->expectExceptionMessage('"deletedDate" is not a valid date');

        Vendor::fromArray(['id' => 1, 'name' => 'A', 'deletedDate' => $date]);
    }

    /**
     * DateTimeImmutable accepts relative formats, so "tomorrow" used to make a
     * corrupt list look current — the very thing the empty-string check guards.
     *
     * @dataProvider invalidDates
     */
    public function testRejectsALastUpdatedThatIsNotAnAbsoluteDate(string $date): void
    {
        $this->expectException(GvlException::class);
        $this->expectExceptionMessage('"lastUpdated" is not a valid date');

        Gvl::fromJson('{"gvlSpecificationVersion":3,"vendorListVersion":1,"tcfPolicyVersion":5,'
            . '"lastUpdated":' . json_encode($date) . ',"vendors":{}}');
    }

    /** @return iterable<string,array{string}> */
    public static function invalidDates(): iterable
    {
        yield 'relative: now' => ['now'];
        yield 'relative: tomorrow' => ['tomorrow'];
        yield 'relative with a date prefix' => ['2026-01-01 +1 week'];
        yield 'day that rolls over into the next month' => ['2026-02-31T00:00:00Z'];
        yield 'trailing newline' => ["2026-01-01T00:00:00Z\n"];
    }

    /** @dataProvider validDates */
    public function testAcceptsTheIso8601SpellingsTheGvlUses(string $date, string $expectedUtc): void
    {
        $gvl = Gvl::fromJson('{"gvlSpecificationVersion":3,"vendorListVersion":1,"tcfPolicyVersion":5,'
            . '"lastUpdated":' . json_encode($date) . ',"vendors":{}}');

        self::assertSame(
            $expectedUtc,
            $gvl->lastUpdated->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.v'),
        );
    }

    /** @return iterable<string,array{string,string}> */
    public static function validDates(): iterable
    {
        yield 'Zulu' => ['2026-09-10T16:00:18Z', '2026-09-10T16:00:18.000'];
        yield 'fractional seconds' => ['2025-05-13T15:30:44.66Z', '2025-05-13T15:30:44.660'];
        yield 'offset' => ['2026-09-10T18:00:18+02:00', '2026-09-10T16:00:18.000'];
        yield 'date only' => ['2026-09-10', '2026-09-10T00:00:00.000'];
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
        $model = new TcModel(cmpId: 1, cmpVersion: 1, vendorConsents: [1, 65535]);

        $problems = $gvl->validateConsents($model);

        self::assertCount(1, $problems);
        self::assertStringContainsString('65535', $problems[0]);
        self::assertStringContainsString('does not exist', $problems[0]);
    }

    public function testValidateConsentsFlagsVendorWithNoDeclaredConsentPurposes(): void
    {
        $gvl = Gvl::fromJson(self::fixtureJson());
        // Vendor 2 has purposes=[1,2,3,4,7,9,10] (non-empty) so it should NOT be flagged for consent.
        $model = new TcModel(cmpId: 1, cmpVersion: 1, vendorConsents: [2]);

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

    /** @return iterable<string, array{string}> */
    public static function malformedPayloads(): iterable
    {
        yield 'null literal' => ['null'];
        yield 'empty array' => ['[]'];
        yield 'empty object' => ['{}'];
        yield 'scalar' => ['42'];
        yield 'not json at all' => ['<html>404</html>'];
        yield 'missing lastUpdated' => ['{"gvlSpecificationVersion":3,"vendorListVersion":1,"tcfPolicyVersion":4,"vendors":{}}'];
        yield 'empty lastUpdated' => ['{"gvlSpecificationVersion":3,"vendorListVersion":1,"tcfPolicyVersion":4,"lastUpdated":"","vendors":{}}'];
        yield 'unparseable lastUpdated' => ['{"gvlSpecificationVersion":3,"vendorListVersion":1,"tcfPolicyVersion":4,"lastUpdated":"not-a-date","vendors":{}}'];
        yield 'vendors not an object' => ['{"gvlSpecificationVersion":3,"vendorListVersion":1,"tcfPolicyVersion":4,"lastUpdated":"2026-01-01T00:00:00Z","vendors":7}'];
        yield 'vendor without a name' => ['{"gvlSpecificationVersion":3,"vendorListVersion":1,"tcfPolicyVersion":4,"lastUpdated":"2026-01-01T00:00:00Z","vendors":{"1":{"id":1}}}'];
    }

    /** @dataProvider malformedPayloads */
    public function testFromJsonRejectsMalformedPayloads(string $json): void
    {
        $this->expectException(\Flenczewski\IabTcf\Exception\GvlException::class);

        Gvl::fromJson($json);
    }

    public function testBundledIsMemoized(): void
    {
        self::assertSame(Gvl::bundled(), Gvl::bundled());
    }

    public function testResetBundledCacheForcesAReparse(): void
    {
        $first = Gvl::bundled();
        Gvl::resetBundledCache();
        $second = Gvl::bundled();

        self::assertNotSame($first, $second, 'The cache should have been dropped.');
        self::assertSame($first->vendorListVersion, $second->vendorListVersion);
    }

    /**
     * The vendor map is keyed by each entry's own id, so two entries claiming
     * the same id used to overwrite one another and silently drop a vendor.
     */
    public function testRejectsDuplicateVendorIds(): void
    {
        $this->expectException(\Flenczewski\IabTcf\Exception\GvlException::class);
        $this->expectExceptionMessage('declares vendor id 1 more than once');

        Gvl::fromJson(
            '{"gvlSpecificationVersion":3,"vendorListVersion":1,"tcfPolicyVersion":4,'
            . '"lastUpdated":"2026-01-01T00:00:00Z",'
            . '"vendors":{"1":{"id":1,"name":"A"},"2":{"id":1,"name":"B"}}}'
        );
    }

    /**
     * A digit string past PHP_INT_MAX saturates on cast instead of failing, so
     * the vendor used to land in the map keyed by PHP_INT_MAX and every consent
     * check for it silently answered "not on the list". The same value spelled
     * as a JSON number is rejected as a float, so the string spelling must not
     * be a way around that.
     */
    public function testRejectsAnIntegerStringThatWouldSaturateOnCast(): void
    {
        $this->expectException(\Flenczewski\IabTcf\Exception\GvlException::class);
        $this->expectExceptionMessage('not representable as an integer');

        Gvl::fromJson(
            '{"gvlSpecificationVersion":3,"vendorListVersion":1,"tcfPolicyVersion":4,'
            . '"lastUpdated":"2026-01-01T00:00:00Z",'
            . '"vendors":{"1":{"id":"99999999999999999999999","name":"A"}}}'
        );
    }

    public function testRejectsANonPositiveVendorId(): void
    {
        $this->expectException(\Flenczewski\IabTcf\Exception\GvlException::class);
        $this->expectExceptionMessage('declares id 0; vendor ids start at 1');

        Gvl::fromJson(
            '{"gvlSpecificationVersion":3,"vendorListVersion":1,"tcfPolicyVersion":4,'
            . '"lastUpdated":"2026-01-01T00:00:00Z","vendors":{"0":{"id":0,"name":"A"}}}'
        );
    }

    public function testAVendorWithANullNameIsReportedAsAWrongTypeNotAMissingField(): void
    {
        $this->expectException(\Flenczewski\IabTcf\Exception\GvlException::class);
        $this->expectExceptionMessage('"name" must be a string, got null');

        Gvl::fromJson(
            '{"gvlSpecificationVersion":3,"vendorListVersion":1,"tcfPolicyVersion":4,'
            . '"lastUpdated":"2026-01-01T00:00:00Z","vendors":{"1":{"id":1,"name":null}}}'
        );
    }

    /**
     * PCRE's $ matches before a trailing newline, so the numeric-string check
     * used to accept "3\n" and cast it to 3.
     */
    public function testRejectsANumericFieldWithATrailingNewline(): void
    {
        $this->expectException(\Flenczewski\IabTcf\Exception\GvlException::class);
        $this->expectExceptionMessage('"gvlSpecificationVersion" must be an integer');

        $json = json_encode([
            'gvlSpecificationVersion' => "3\n",
            'vendorListVersion' => 1,
            'tcfPolicyVersion' => 4,
            'lastUpdated' => '2026-01-01T00:00:00Z',
            'vendors' => new \stdClass(),
        ]);
        self::assertIsString($json);

        Gvl::fromJson($json);
    }

    /**
     * Parsing the full list costs tens of milliseconds, mostly json_decode(),
     * which a PHP-FPM worker pays on every request. The README recommends
     * caching the parsed object instead, which only works if it survives
     * serialize()/unserialize() intact.
     */
    public function testAParsedListSurvivesSerialisationForCaching(): void
    {
        $gvl = Gvl::fromJson(self::fixtureJson());

        $restored = unserialize(serialize($gvl));

        self::assertInstanceOf(Gvl::class, $restored);
        self::assertEquals($gvl, $restored);
        self::assertTrue($restored->isDeleted($restored->vendors[8]));
    }
}
