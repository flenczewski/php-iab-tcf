<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Tests;

use Flenczewski\IabTcf\BitReader;
use Flenczewski\IabTcf\BitWriter;
use Flenczewski\IabTcf\Exception\GvlException;
use Flenczewski\IabTcf\Exception\InvalidArgumentException;
use Flenczewski\IabTcf\Exception\InvalidTcStringException;
use Flenczewski\IabTcf\Gvl\Gvl;
use Flenczewski\IabTcf\Gvl\Vendor;
use Flenczewski\IabTcf\PublisherRestriction;
use Flenczewski\IabTcf\PublisherRestrictionsCodec;
use Flenczewski\IabTcf\RestrictionType;
use Flenczewski\IabTcf\Spec;
use Flenczewski\IabTcf\TcModel;
use Flenczewski\IabTcf\TcStringEncoder;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the validation branches that reject bad input.
 *
 * These are the paths that only ever run when something is wrong, so they are
 * the easiest place for a typo or a wrong exception type to hide unnoticed.
 */
final class ErrorPathTest extends TestCase
{
    private const VALID_GVL_HEAD = '"gvlSpecificationVersion":3,"vendorListVersion":1,'
        . '"tcfPolicyVersion":4,"lastUpdated":"2026-01-01T00:00:00Z"';

    public function testVendorsEntryThatIsNotAnObjectIsRejected(): void
    {
        $this->expectException(GvlException::class);
        $this->expectExceptionMessage('Every entry in "vendors" must be an object');

        Gvl::fromJson('{' . self::VALID_GVL_HEAD . ',"vendors":{"1":"not-an-object"}}');
    }

    public function testNonIntegerGvlMetadataIsRejected(): void
    {
        $this->expectException(GvlException::class);
        $this->expectExceptionMessage('"vendorListVersion" must be an integer, got string');

        Gvl::fromJson('{"gvlSpecificationVersion":3,"vendorListVersion":"not a number",'
            . '"tcfPolicyVersion":4,"lastUpdated":"2026-01-01T00:00:00Z","vendors":{}}');
    }

    public function testNumericStringGvlMetadataIsAccepted(): void
    {
        $gvl = Gvl::fromJson('{"gvlSpecificationVersion":"3","vendorListVersion":"175",'
            . '"tcfPolicyVersion":"4","lastUpdated":"2026-01-01T00:00:00Z","vendors":{}}');

        self::assertSame(175, $gvl->vendorListVersion);
    }

    public function testVendorPurposeListThatIsNotAnArrayIsRejected(): void
    {
        $this->expectException(GvlException::class);
        $this->expectExceptionMessage('Vendor field "purposes" must be an array, got int');

        Vendor::fromArray(['id' => 1, 'name' => 'Acme', 'purposes' => 7]);
    }

    public function testVendorPurposeEntryThatIsNotAnIntegerIsRejected(): void
    {
        $this->expectException(GvlException::class);
        $this->expectExceptionMessage('Vendor field "purposes" must be an integer, got string');

        Vendor::fromArray(['id' => 1, 'name' => 'Acme', 'purposes' => ['not-a-number']]);
    }

    public function testVendorNameThatIsNotAStringIsRejected(): void
    {
        $this->expectException(GvlException::class);
        $this->expectExceptionMessage('Vendor field "name" must be a string, got array');

        Vendor::fromArray(['id' => 1, 'name' => ['nested']]);
    }

    public function testValidateConsentsFlagsAVendorDeclaringNoConsentPurposes(): void
    {
        $gvl = Gvl::fromJson('{' . self::VALID_GVL_HEAD
            . ',"vendors":{"1":{"id":1,"name":"Acme","legIntPurposes":[2]}}}');

        $problems = $gvl->validateConsents(new TcModel(cmpId: 1, cmpVersion: 1, vendorConsents: [1]));

        self::assertCount(1, $problems);
        self::assertStringContainsString('declares no consent-based purposes', $problems[0]);
    }

    public function testValidateConsentsFlagsAnUnknownVendorOnTheLegitimateInterestSide(): void
    {
        $gvl = Gvl::fromJson('{' . self::VALID_GVL_HEAD . ',"vendors":{}}');

        $problems = $gvl->validateConsents(
            new TcModel(cmpId: 1, cmpVersion: 1, vendorLegitimateInterests: [42])
        );

        self::assertCount(1, $problems);
        self::assertStringContainsString('does not exist', $problems[0]);
    }

    public function testEncodingMoreRestrictionsThanNumPubRestrictionsCanHoldIsRejected(): void
    {
        $restrictions = array_fill(
            0,
            Spec::MAX_RANGE_ENTRIES + 1,
            new PublisherRestriction(1, RestrictionType::REQUIRE_CONSENT, [1]),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('NumPubRestrictions holds at most');

        PublisherRestrictionsCodec::encode($restrictions);
    }

    public function testPublisherRestrictionsMustContainOnlyPublisherRestrictionInstances(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must contain only PublisherRestriction instances');

        /** @phpstan-ignore argument.type (deliberately passing the wrong element type) */
        new TcModel(cmpId: 1, cmpVersion: 1, publisherRestrictions: ['not a restriction']);
    }

    public function testIdSetsMustContainOnlyIntegers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('purposesConsent must contain only integers');

        /** @phpstan-ignore argument.type (deliberately passing the wrong element type) */
        new TcModel(cmpId: 1, cmpVersion: 1, purposesConsent: ['1']);
    }

    public function testPreEpochTimestampsAreRejectedWithADateAwareMessage(): void
    {
        $model = new TcModel(
            cmpId: 1,
            cmpVersion: 1,
            created: new \DateTimeImmutable('1969-01-01', new \DateTimeZone('UTC')),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('before the Unix epoch');

        TcStringEncoder::encode($model);
    }

    public function testTimestampsPastTheCeilingAreRejectedWithADateAwareMessage(): void
    {
        $model = new TcModel(
            cmpId: 1,
            cmpVersion: 1,
            created: new \DateTimeImmutable('2200-01-01', new \DateTimeZone('UTC')),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('too far in the future');

        TcStringEncoder::encode($model);
    }

    public function testAMicrosecondEpochMistakenForSecondsIsStillAPackageException(): void
    {
        // '@1700000000000000' overflows EpochTime's microsecond arithmetic to a
        // float, which used to escape as a raw TypeError past the ceiling guard
        // rather than as something a caller catching IabTcfException would see.
        $model = new TcModel(
            cmpId: 1,
            cmpVersion: 1,
            created: new \DateTimeImmutable('@1700000000000000'),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('too far in the future');

        TcStringEncoder::encode($model);
    }

    public function testRestrictionTypeThreeDecodesAsUndefined(): void
    {
        // RestrictionType covers all four 2-bit values, so the tryFrom() guard
        // in the decoder is currently unreachable. What is worth pinning down
        // is that value 3 is a real case (UNDEFINED) and decodes as one,
        // rather than being mistaken for an unknown type.
        $writer = new BitWriter();
        $writer->writeUint(1, 12);
        $writer->writeUint(1, 6);
        $writer->writeUint(3, 2);
        $writer->writeUint(0, 12);

        $restrictions = PublisherRestrictionsCodec::decode(new BitReader($writer->toBitString()));

        self::assertSame(RestrictionType::UNDEFINED, $restrictions[0]->type);
    }

    public function testBundledListParsesWhenItsResourceIsReadable(): void
    {
        // The unreadable-file guard cannot be triggered without breaking the
        // installed package, so this asserts the other half of that contract:
        // with the resource present, bundled() returns a usable list.
        self::assertGreaterThan(0, Gvl::bundled()->vendorListVersion);
    }

    public function testRangeListRejectsAnEntryWhoseStartIsBelowOne(): void
    {
        $writer = new BitWriter();
        $writer->writeUint(1, 12)->writeBool(false)->writeUint(0, 16);

        $this->expectException(InvalidTcStringException::class);
        $this->expectExceptionMessage('ids start at 1');

        \Flenczewski\IabTcf\RangeSection::decodeRangeList(new BitReader($writer->toBitString()));
    }
}
