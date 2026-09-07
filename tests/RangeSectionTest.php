<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Tests;

use Flenczewski\IabTcf\BitReader;
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
}
