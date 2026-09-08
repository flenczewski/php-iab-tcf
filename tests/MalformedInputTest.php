<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Tests;

use Flenczewski\IabTcf\BitWriter;
use Flenczewski\IabTcf\Exception\InvalidTcStringException;
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
}
