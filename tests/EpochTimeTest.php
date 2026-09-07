<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Tests;

use Flenczewski\IabTcf\EpochTime;
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
}
