<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Tests;

use Flenczewski\IabTcf\BitReader;
use Flenczewski\IabTcf\BitWriter;
use Flenczewski\IabTcf\Exception\InvalidTcStringException;
use Flenczewski\IabTcf\RangeSection;
use PHPUnit\Framework\TestCase;

final class RangeSectionTest extends TestCase
{
    public function testEmptySetRoundTrip(): void
    {
        $bits = RangeSection::encode([]);
        $reader = new BitReader($bits);
        self::assertSame([], RangeSection::decode($reader));
        self::assertFalse($reader->hasMore());
    }

    public function testSingleIdRoundTrip(): void
    {
        $bits = RangeSection::encode([7]);
        self::assertSame([7], RangeSection::decode(new BitReader($bits)));
    }

    public function testSparseIdsRoundTrip(): void
    {
        $ids = [1, 3, 5, 9, 500];
        $bits = RangeSection::encode($ids);
        self::assertSame($ids, RangeSection::decode(new BitReader($bits)));
    }

    public function testContiguousRangeRoundTrip(): void
    {
        $ids = range(10, 25);
        $bits = RangeSection::encode($ids);
        self::assertSame($ids, RangeSection::decode(new BitReader($bits)));
    }

    /**
     * The spec defines MaxVendorId as the largest id represented in the
     * section, so a range entry above it makes the section self-contradictory.
     * It also used to be silently "repaired": re-encoding the decoded ids
     * emitted a different MaxVendorId than the input carried.
     */
    public function testRejectsARangeEntryAboveTheDeclaredMaxVendorId(): void
    {
        $bits = (new BitWriter())
            ->writeUint(10, 16)     // MaxVendorId
            ->writeBool(true)       // IsRangeEncoding
            ->writeUint(1, 12)      // NumEntries
            ->writeBool(false)->writeUint(60000, 16)
            ->toBitString();

        $this->expectException(InvalidTcStringException::class);
        $this->expectExceptionMessage("above the section's maximum of 10");

        RangeSection::decode(new BitReader($bits));
    }

    public function testRejectsARangeWhoseEndCrossesTheDeclaredMaxVendorId(): void
    {
        $bits = (new BitWriter())
            ->writeUint(10, 16)
            ->writeBool(true)
            ->writeUint(1, 12)
            ->writeBool(true)->writeUint(8, 16)->writeUint(12, 16)
            ->toBitString();

        $this->expectException(InvalidTcStringException::class);

        RangeSection::decode(new BitReader($bits));
    }

    public function testAcceptsARangeEntryExactlyAtTheDeclaredMaxVendorId(): void
    {
        $bits = (new BitWriter())
            ->writeUint(10, 16)
            ->writeBool(true)
            ->writeUint(1, 12)
            ->writeBool(true)->writeUint(8, 16)->writeUint(10, 16)
            ->toBitString();

        self::assertSame([8, 9, 10], RangeSection::decode(new BitReader($bits)));
    }

    /**
     * Publisher restriction range lists carry no MaxVendorId preamble, so the
     * standalone codec must keep defaulting to the full 16-bit space.
     */
    public function testStandaloneRangeListStillAllowsTheFullVendorSpace(): void
    {
        $bits = (new BitWriter())
            ->writeUint(1, 12)
            ->writeBool(false)->writeUint(65535, 16)
            ->toBitString();

        self::assertSame([65535], RangeSection::decodeRangeList(new BitReader($bits)));
    }

    public function testDuplicatesAndUnsortedInputAreNormalized(): void
    {
        $bits = RangeSection::encode([5, 3, 5, 1, 3]);
        self::assertSame([1, 3, 5], RangeSection::decode(new BitReader($bits)));
    }

    public function testChoosesBitfieldForDenseHighVendorSet(): void
    {
        // Nearly every id from 1..20 present: bitfield (20 bits) is smaller
        // than range encoding (many short ranges), so bitfield must be chosen.
        $ids = range(1, 20);
        $bits = RangeSection::encode($ids);

        $reader = new BitReader($bits);
        $reader->readUint(16); // MaxVendorId
        $isRange = $reader->readBool();

        self::assertFalse($isRange);
    }

    public function testChoosesRangeForSparseHighVendorSet(): void
    {
        // A single high id: range encoding (1+16+16=33 bits) is far smaller
        // than a bitfield of MaxVendorId bits (50000), so range must be chosen.
        $bits = RangeSection::encode([50000]);

        $reader = new BitReader($bits);
        $reader->readUint(16); // MaxVendorId
        $isRange = $reader->readBool();

        self::assertTrue($isRange);
    }

    public function testRangeListCodecWithoutPreambleRoundTrip(): void
    {
        $ids = [2, 3, 4, 10, 20, 21];
        $bits = RangeSection::encodeRangeList($ids);
        self::assertSame($ids, RangeSection::decodeRangeList(new BitReader($bits)));
    }

    public function testFallsBackToBitfieldWhenRangeListWouldOverflowNumEntriesField(): void
    {
        // Every other id from 1..10000 produces 5000 single-id ranges, which
        // does not fit in the 12-bit NumEntries field (max 4095) — encode()
        // must fall back to the bitfield representation instead of crashing.
        $ids = [];
        for ($id = 1; $id <= 10000; $id += 2) {
            $ids[] = $id;
        }

        $bits = RangeSection::encode($ids);
        self::assertSame($ids, RangeSection::decode(new BitReader($bits)));
    }

    private static function hostileRangeList(int $entries): string
    {
        // Each entry claims the full 1..65535 span, so a naive decoder expands
        // to entries * 65535 array elements from a payload of a few KB.
        $writer = new \Flenczewski\IabTcf\BitWriter();
        $writer->writeUint($entries, 12);
        for ($i = 0; $i < $entries; $i++) {
            $writer->writeBool(true)->writeUint(1, 16)->writeUint(65535, 16);
        }

        return $writer->toBitString();
    }

    public function testRejectsRangeListThatWouldExpandBeyondTheVendorIdSpace(): void
    {
        $this->expectException(\Flenczewski\IabTcf\Exception\InvalidTcStringException::class);
        $this->expectExceptionMessage('budget of 65535 vendor ids');

        RangeSection::decodeRangeList(new BitReader(self::hostileRangeList(500)));
    }

    public function testHostileRangeListIsRejectedQuicklyAndCheaply(): void
    {
        $before = memory_get_usage();
        $start = microtime(true);

        try {
            RangeSection::decodeRangeList(new BitReader(self::hostileRangeList(500)));
            self::fail('Expected the hostile payload to be rejected.');
        } catch (\Flenczewski\IabTcf\Exception\InvalidTcStringException) {
            // expected
        }

        self::assertLessThan(0.1, microtime(true) - $start, 'Rejection must be fast.');
        self::assertLessThan(2_000_000, memory_get_usage() - $before, 'Rejection must not allocate.');
    }

    public function testRejectsRangeWithStartAboveEnd(): void
    {
        $writer = new \Flenczewski\IabTcf\BitWriter();
        $writer->writeUint(1, 12)->writeBool(true)->writeUint(9, 16)->writeUint(2, 16);

        $this->expectException(\Flenczewski\IabTcf\Exception\InvalidTcStringException::class);

        RangeSection::decodeRangeList(new BitReader($writer->toBitString()));
    }

    public function testRejectsVendorIdZero(): void
    {
        $writer = new \Flenczewski\IabTcf\BitWriter();
        $writer->writeUint(1, 12)->writeBool(false)->writeUint(0, 16);

        $this->expectException(\Flenczewski\IabTcf\Exception\InvalidTcStringException::class);

        RangeSection::decodeRangeList(new BitReader($writer->toBitString()));
    }

    public function testOverlappingRangesDecodeToASortedUniqueList(): void
    {
        $writer = new \Flenczewski\IabTcf\BitWriter();
        $writer->writeUint(2, 12);
        $writer->writeBool(true)->writeUint(1, 16)->writeUint(5, 16);
        $writer->writeBool(true)->writeUint(3, 16)->writeUint(7, 16);

        self::assertSame(
            [1, 2, 3, 4, 5, 6, 7],
            RangeSection::decodeRangeList(new BitReader($writer->toBitString()))
        );
    }
}
