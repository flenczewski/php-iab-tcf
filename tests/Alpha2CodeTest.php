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

    /**
     * PCRE's $ also matches immediately before a trailing newline, so an
     * anchor of $ rather than \z let "EN\n" validate and then be silently
     * truncated to "EN" by encode(). Consent data must never be repaired
     * behind the caller's back.
     *
     * @dataProvider codesWithTrailingWhitespace
     */
    public function testRejectsATrailingNewline(string $code): void
    {
        self::assertFalse(Alpha2Code::isValid($code));

        $this->expectException(\InvalidArgumentException::class);
        Alpha2Code::encode($code);
    }

    /** @return iterable<string,array{string}> */
    public static function codesWithTrailingWhitespace(): iterable
    {
        yield 'trailing newline' => ["EN\n"];
        yield 'trailing carriage return' => ["EN\r"];
        yield 'leading newline' => ["\nEN"];
        yield 'embedded newline' => ["E\nN"];
    }
}
