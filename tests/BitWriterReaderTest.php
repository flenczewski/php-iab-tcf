<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Tests;

use Flenczewski\IabTcf\BitReader;
use Flenczewski\IabTcf\BitWriter;
use PHPUnit\Framework\TestCase;

final class BitWriterReaderTest extends TestCase
{
    public function testUintRoundTripAcrossVariousWidths(): void
    {
        $writer = new BitWriter();
        $writer->writeUint(0, 1);
        $writer->writeUint(1, 1);
        $writer->writeUint(42, 6);
        $writer->writeUint(4095, 12);
        $writer->writeUint(16777215, 24);
        $writer->writeUint(68719476735, 36); // max 36-bit value

        $reader = new BitReader($writer->toBitString());
        self::assertSame(0, $reader->readUint(1));
        self::assertSame(1, $reader->readUint(1));
        self::assertSame(42, $reader->readUint(6));
        self::assertSame(4095, $reader->readUint(12));
        self::assertSame(16777215, $reader->readUint(24));
        self::assertSame(68719476735, $reader->readUint(36));
        self::assertFalse($reader->hasMore());
    }

    public function testBoolRoundTrip(): void
    {
        $writer = new BitWriter();
        $writer->writeBool(true);
        $writer->writeBool(false);

        $reader = new BitReader($writer->toBitString());
        self::assertTrue($reader->readBool());
        self::assertFalse($reader->readBool());
    }

    public function testWriteUintRejectsOutOfRangeValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new BitWriter())->writeUint(64, 6); // 6 bits max value is 63
    }

    public function testWriteUintRejectsNegativeValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new BitWriter())->writeUint(-1, 6);
    }

    public function testIdSetRoundTrip(): void
    {
        $writer = new BitWriter();
        $writer->writeIdSet([1, 3, 5], 6);

        $reader = new BitReader($writer->toBitString());
        self::assertSame([1, 3, 5], $reader->readIdSet(6));
    }

    public function testIdSetRoundTripEmpty(): void
    {
        $writer = new BitWriter();
        $writer->writeIdSet([], 12);

        $reader = new BitReader($writer->toBitString());
        self::assertSame([], $reader->readIdSet(12));
    }

    public function testReadPastEndThrows(): void
    {
        $reader = new BitReader('101');
        $reader->readUint(3);

        $this->expectException(\OutOfRangeException::class);
        $reader->readUint(1);
    }

    public function testWriteIdSetRejectsIdAboveWidth(): void
    {
        $this->expectException(\Flenczewski\IabTcf\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('id 25');

        (new BitWriter())->writeIdSet([1, 25], 24);
    }

    public function testWriteIdSetRejectsIdBelowOne(): void
    {
        $this->expectException(\Flenczewski\IabTcf\Exception\InvalidArgumentException::class);

        (new BitWriter())->writeIdSet([0, 5], 24);
    }

    public function testWriteUintWithZeroWidthWritesNothing(): void
    {
        self::assertSame('', (new BitWriter())->writeUint(0, 0)->toBitString());
    }

    public function testWriteUintRejectsNonZeroValueInZeroWidth(): void
    {
        $this->expectException(\Flenczewski\IabTcf\Exception\InvalidArgumentException::class);

        (new BitWriter())->writeUint(1, 0);
    }

    public function testWriteUintRejectsImpossibleWidth(): void
    {
        $this->expectException(\Flenczewski\IabTcf\Exception\InvalidArgumentException::class);

        (new BitWriter())->writeUint(1, 64);
    }
}
