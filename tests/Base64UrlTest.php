<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Tests;

use Flenczewski\IabTcf\Base64Url;
use Flenczewski\IabTcf\Exception\InvalidArgumentException;
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

    /** Every byte value must map to its own 8-bit, MSB-first spelling — in both directions. */
    public function testEveryByteValueRoundTrips(): void
    {
        $bytes = '';
        $bits = '';
        for ($byte = 0; $byte < 256; $byte++) {
            $bytes .= chr($byte);
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }
        $encoded = rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

        self::assertSame($bits, Base64Url::decodeToBits($encoded));
        self::assertSame($encoded, Base64Url::encodeBits($bits));
    }

    public function testEmptyInputRoundTrips(): void
    {
        self::assertSame('', Base64Url::encodeBits(''));
        self::assertSame('', Base64Url::decodeToBits(''));
    }

    /** One leftover character is under a byte; no encoder emits it, so it is trailing junk. */
    public function testASingleDanglingCharacterIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('single dangling character');

        Base64Url::decodeToBits('AAAAB');
    }

    /** The segment is attacker-controlled and the message typically ends up in a log. */
    public function testTheErrorMessageQuotesOnlyAnEscapedPrefixOfTheSegment(): void
    {
        $segment = "AB\nforged log line" . str_repeat('!', 10_000);

        try {
            Base64Url::decodeToBits($segment);
            self::fail('Expected an exception.');
        } catch (InvalidArgumentException $e) {
            self::assertStringNotContainsString("\n", $e->getMessage());
            self::assertStringContainsString('"AB\\nforged log line', $e->getMessage());
            self::assertStringContainsString('(10018 characters)', $e->getMessage());
            self::assertLessThan(120, strlen($e->getMessage()));
        }
    }
}
