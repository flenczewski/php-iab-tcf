<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Tests;

use Flenczewski\IabTcf\BitReader;
use Flenczewski\IabTcf\BitWriter;
use Flenczewski\IabTcf\Exception\InvalidTcStringException;
use Flenczewski\IabTcf\PublisherRestrictionsCodec;
use Flenczewski\IabTcf\TcModel;
use Flenczewski\IabTcf\TcStringDecoder;
use Flenczewski\IabTcf\TcStringEncoder;
use PHPUnit\Framework\TestCase;

final class MalformedInputTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function malformedStrings(): iterable
    {
        yield 'empty' => [''];
        yield 'single dot' => ['.'];
        yield 'only separators' => ['...'];
        yield 'not base64' => ['!!!!'];
        yield 'whitespace' => ['   '];
        yield 'one character' => ['C'];
        yield 'version 1 core string' => ['BOEFEAyOEFEAyAHABDENAI4AAAB9vABAASA'];
    }

    /** @dataProvider malformedStrings */
    public function testMalformedStringsThrowInvalidTcStringException(string $input): void
    {
        $this->expectException(InvalidTcStringException::class);

        TcStringDecoder::decode($input);
    }

    public function testNoTruncationOfAValidStringEscapesAsAnUnexpectedError(): void
    {
        $valid = TcStringEncoder::encode(new TcModel(
            cmpId: 7,
            cmpVersion: 1,
            purposesConsent: [1, 2, 3],
            vendorConsents: [1, 2, 3],
        ));

        // Every prefix must either decode or fail as InvalidTcStringException.
        // Anything else (TypeError, ValueError, Error) would be a leak.
        for ($length = 1; $length < strlen($valid); $length++) {
            try {
                TcStringDecoder::decode(substr($valid, 0, $length));
            } catch (InvalidTcStringException) {
                continue;
            }
        }

        $this->expectNotToPerformAssertions();
    }

    public function testUnknownSegmentTypesAreIgnoredNotFatal(): void
    {
        $valid = TcStringEncoder::encode(new TcModel(cmpId: 7, cmpVersion: 1));
        // Segment type 3 (Publisher TC) is documented as unsupported and skipped.
        $publisherTcSegment = (new BitWriter())
            ->writeUint(3, 3)
            ->writeUint(0, 13)
            ->toBase64Url();

        self::assertSame(7, TcStringDecoder::decode($valid . '.' . $publisherTcSegment)->cmpId);
    }

    /**
     * Regression test for the cumulative form of the range-list DoS.
     *
     * Each range list is capped at Spec::MAX_VENDOR_ID on its own, but
     * NumPubRestrictions is a 12-bit field, so without a budget shared across
     * the section an attacker multiplies that cap by up to 4095. Before the
     * fix, the 200-restriction payload below expanded to 13,107,000 ids in
     * 2.73s and +201 MB from just 1,769 base64 characters.
     */
    public function testPublisherRestrictionsCannotMultiplyThePerRangeListCap(): void
    {
        $writer = new BitWriter();
        $writer->writeUint(200, 12);
        for ($i = 0; $i < 200; $i++) {
            $writer->writeUint(1, 6);   // purposeId
            $writer->writeUint(1, 2);   // restrictionType
            $writer->writeUint(1, 12);  // NumEntries
            $writer->writeBool(true)->writeUint(1, 16)->writeUint(65535, 16);
        }

        $before = memory_get_usage();
        $start = microtime(true);

        try {
            PublisherRestrictionsCodec::decode(new BitReader($writer->toBitString()));
            self::fail('Expected the cumulative payload to be rejected.');
        } catch (InvalidTcStringException) {
            // expected
        }

        self::assertLessThan(0.5, microtime(true) - $start, 'Rejection must be fast.');
        self::assertLessThan(20_000_000, memory_get_usage() - $before, 'Rejection must stay bounded.');
    }

    public function testAGenuinePublisherRestrictionSetStillDecodes(): void
    {
        $writer = new BitWriter();
        $writer->writeUint(2, 12);
        foreach ([[2, 1, 5, 9], [3, 0, 40, 44]] as [$purposeId, $type, $start, $end]) {
            $writer->writeUint($purposeId, 6);
            $writer->writeUint($type, 2);
            $writer->writeUint(1, 12);
            $writer->writeBool(true)->writeUint($start, 16)->writeUint($end, 16);
        }

        $restrictions = PublisherRestrictionsCodec::decode(new BitReader($writer->toBitString()));

        self::assertCount(2, $restrictions);
        self::assertSame([5, 6, 7, 8, 9], $restrictions[0]->vendorIds);
        self::assertSame([40, 41, 42, 43, 44], $restrictions[1]->vendorIds);
    }

    /** A Disclosed Vendors segment claiming the entire 1..65535 vendor range. */
    private static function fullRangeDisclosedVendorsSegment(): string
    {
        return (new BitWriter())
            ->writeUint(1, 3)       // segment type: Disclosed Vendors
            ->writeUint(65535, 16)  // MaxVendorId
            ->writeBool(true)       // IsRangeEncoding
            ->writeUint(1, 12)      // NumEntries
            ->writeBool(true)->writeUint(1, 16)->writeUint(65535, 16)
            ->toBase64Url();
    }

    /**
     * Regression test for the segment-count form of the amplification.
     *
     * Each vendor section is bounded on its own, but the decoder looped over
     * however many dot-separated segments the input carried, giving every one
     * of them a fresh budget. Before the fix, 1000 repeated segments — 13,044
     * characters — burned 16.57s of CPU. Memory stayed flat, so this was a
     * CPU-exhaustion DoS rather than a memory one.
     */
    public function testRepeatedSegmentsCannotMultiplyTheDecodeCost(): void
    {
        $core = TcStringEncoder::encode(new TcModel(cmpId: 1, cmpVersion: 1, disclosedVendors: null));
        $tcString = $core . str_repeat('.' . self::fullRangeDisclosedVendorsSegment(), 1000);

        $start = microtime(true);

        try {
            TcStringDecoder::decode($tcString);
            self::fail('Expected a string with 1000 segments to be rejected.');
        } catch (InvalidTcStringException) {
            // expected
        }

        self::assertLessThan(0.5, microtime(true) - $start, 'Rejection must not scale with segment count.');
    }

    public function testARepeatedSegmentTypeIsRejectedRatherThanSilentlyOverwriting(): void
    {
        $core = TcStringEncoder::encode(new TcModel(cmpId: 1, cmpVersion: 1, disclosedVendors: null));
        $segment = self::fullRangeDisclosedVendorsSegment();

        $this->expectException(InvalidTcStringException::class);
        $this->expectExceptionMessage('repeats segment type 1');

        TcStringDecoder::decode($core . '.' . $segment . '.' . $segment);
    }

    public function testAllThreeOptionalSegmentsTogetherStillDecode(): void
    {
        $model = new TcModel(
            cmpId: 300,
            cmpVersion: 1,
            vendorConsents: [1, 2, 500],
            disclosedVendors: [1, 2, 500],
            allowedVendors: [3, 4],
        );

        $decoded = TcStringDecoder::decode(TcStringEncoder::encode($model));

        self::assertSame([1, 2, 500], $decoded->disclosedVendors);
        self::assertSame([3, 4], $decoded->allowedVendors);
    }
}
