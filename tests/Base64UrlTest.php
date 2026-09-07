<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Tests;

use Flenczewski\IabTcf\Base64Url;
use PHPUnit\Framework\TestCase;

final class Base64UrlTest extends TestCase
{
    public function testRoundTripByteAligned(): void
    {
        $bits = '0100100001100101011011000110110001101111'; // "Hello" minus padding, arbitrary
        $encoded = Base64Url::encodeBits($bits);
        $decoded = Base64Url::decodeToBits($encoded);

        // Decoded will be padded to a multiple of 8; compare the original prefix.
        self::assertSame($bits, substr($decoded, 0, strlen($bits)));
    }

    public function testRoundTripNonByteAlignedPadsWithZeros(): void
    {
        $bits = '101'; // 3 bits, needs 5 zero-bits of padding
        $encoded = Base64Url::encodeBits($bits);
        $decoded = Base64Url::decodeToBits($encoded);

        self::assertSame('10100000', $decoded);
    }

    public function testOutputHasNoPaddingOrUnsafeCharacters(): void
    {
        $encoded = Base64Url::encodeBits(str_repeat('1', 40));
        self::assertStringNotContainsString('=', $encoded);
        self::assertStringNotContainsString('+', $encoded);
        self::assertStringNotContainsString('/', $encoded);
    }

    public function testInvalidBase64Throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Base64Url::decodeToBits('not-valid-base64-!!!');
    }
}
