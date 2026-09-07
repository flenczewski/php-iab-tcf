<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Tests;

use Flenczewski\IabTcf\Alpha2Code;
use Flenczewski\IabTcf\BitReader;
use PHPUnit\Framework\TestCase;

final class Alpha2CodeTest extends TestCase
{
    public function testRoundTripUppercase(): void
    {
        $bits = Alpha2Code::encode('EN');
        self::assertSame('EN', Alpha2Code::decode(new BitReader($bits)));
    }

    public function testRoundTripLowercaseIsNormalizedToUppercase(): void
    {
        $bits = Alpha2Code::encode('pl');
        self::assertSame('PL', Alpha2Code::decode(new BitReader($bits)));
    }

    public function testRoundTripBoundaryLetters(): void
    {
        $bits = Alpha2Code::encode('AZ');
        self::assertSame('AZ', Alpha2Code::decode(new BitReader($bits)));
    }

    public function testRejectsWrongLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Alpha2Code::encode('ENG');
    }

    public function testRejectsNonAlphaCharacters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Alpha2Code::encode('E1');
    }
}
