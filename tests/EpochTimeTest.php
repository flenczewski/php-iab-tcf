<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Tests;

use Flenczewski\IabTcf\EpochTime;
use Flenczewski\IabTcf\Exception\InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EpochTimeTest extends TestCase
{
    public function testRoundTripAtDecisecondResolution(): void
    {
        $original = new \DateTimeImmutable('2024-03-15T10:30:00.700000+00:00');
        $deciseconds = EpochTime::toDeciseconds($original);
        $restored = EpochTime::fromDeciseconds($deciseconds);

        self::assertSame($original->getTimestamp(), $restored->getTimestamp());
        // Sub-decisecond precision is lost by design; compare at 100ms resolution.
        self::assertEqualsWithDelta(
            (float) $original->format('U.u'),
            (float) $restored->format('U.u'),
            0.1
        );
    }

    public function testKnownValueRoundTrip(): void
    {
        // 2020-01-01T00:00:00.000Z == 1577836800 seconds == 15778368000 deciseconds
        $deciseconds = 15778368000;
        $dt = EpochTime::fromDeciseconds($deciseconds);

        self::assertSame(1577836800, $dt->getTimestamp());
        self::assertSame($deciseconds, EpochTime::toDeciseconds($dt));
    }

    public function testFitsInThirtySixBits(): void
    {
        $deciseconds = EpochTime::toDeciseconds(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        self::assertLessThan(2 ** 36, $deciseconds);
    }

    public function testNegativeDecisecondsBeforeEpoch(): void
    {
        // -1 decisecond = 0.1s before epoch = 1969-12-31T23:59:59.9Z, not
        // 1970-01-01T00:00:00.1Z (the sign-flip bug this guards against).
        $dt = EpochTime::fromDeciseconds(-1);

        self::assertSame('1969-12-31T23:59:59.900000+00:00', $dt->format('Y-m-d\TH:i:s.uP'));
    }

    public function testRoundTripBeforeEpoch(): void
    {
        $original = new \DateTimeImmutable('1969-06-15T08:00:00.300000+00:00');
        $deciseconds = EpochTime::toDeciseconds($original);
        $restored = EpochTime::fromDeciseconds($deciseconds);

        self::assertSame($original->getTimestamp(), $restored->getTimestamp());
        self::assertEqualsWithDelta(
            (float) $original->format('U.u'),
            (float) $restored->format('U.u'),
            0.1
        );
    }

    public function testTruncatesRatherThanRoundingIntoTheFuture(): void
    {
        // .19s past the second must become 1 decisecond, never 2.
        $dt = new \DateTimeImmutable('2024-03-15T10:30:00.190000+00:00');

        self::assertSame(
            $dt->getTimestamp() * 10 + 1,
            EpochTime::toDeciseconds($dt)
        );
    }

    public function testTruncationNeverProducesATimeAfterTheInput(): void
    {
        $dt = new \DateTimeImmutable('2024-03-15T10:30:00.990000+00:00');
        $restored = EpochTime::fromDeciseconds(EpochTime::toDeciseconds($dt));

        self::assertLessThanOrEqual($dt, $restored);
    }

    public function testAFutureTimestampThatWouldOverflowTheMicrosecondArithmeticIsRejected(): void
    {
        // Seconds times a million stops being an integer past ~9.2e12 seconds,
        // and intdiv() then fails with a TypeError; rejecting the input keeps
        // that from surfacing as a non-package exception.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outside the range this converter can represent');

        EpochTime::toDeciseconds(new \DateTimeImmutable('@1700000000000000'));
    }

    public function testAPastTimestampThatWouldOverflowTheMicrosecondArithmeticIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outside the range this converter can represent');

        EpochTime::toDeciseconds(new \DateTimeImmutable('@-1700000000000000'));
    }

    public function testTruncationIsTowardsNegativeInfinityBeforeTheEpoch(): void
    {
        $dt = new \DateTimeImmutable('1969-06-15T08:00:00.150000+00:00');
        $restored = EpochTime::fromDeciseconds(EpochTime::toDeciseconds($dt));

        self::assertLessThanOrEqual($dt, $restored);
    }
}
