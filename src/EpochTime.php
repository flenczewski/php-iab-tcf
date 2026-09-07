<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf;

/**
 * Converts between DateTimeImmutable and the "deciseconds since Unix epoch"
 * (tenths of a second) integer format used by the Created/LastUpdated Core
 * String fields.
 */
final class EpochTime
{
    public static function toDeciseconds(\DateTimeImmutable $dateTime): int
    {
        $millis = (int) round(((float) $dateTime->format('U.u')) * 1000);

        return (int) round($millis / 100);
    }

    public static function fromDeciseconds(int $deciseconds): \DateTimeImmutable
    {
        $millis = $deciseconds * 100;
        $seconds = intdiv($millis, 1000);
        $microseconds = ($millis % 1000) * 1000;

        return (new \DateTimeImmutable('@' . $seconds))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->modify(sprintf('+%d microseconds', $microseconds));
    }
}
