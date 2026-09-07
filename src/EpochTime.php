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
        // format('U.u') is unsafe here: 'u' (microseconds) is always a
        // non-negative offset *after* the 'U' second, but string-concatenating
        // it onto a negative 'U' and casting to float silently subtracts that
        // offset instead of adding it for any pre-1970 timestamp.
        $totalMicroseconds = $dateTime->getTimestamp() * 1_000_000 + (int) $dateTime->format('u');

        return (int) round($totalMicroseconds / 100_000);
    }

    public static function fromDeciseconds(int $deciseconds): \DateTimeImmutable
    {
        $totalMicroseconds = $deciseconds * 100_000;

        // Floor division (not intdiv's truncate-toward-zero) so $microseconds
        // always lands in [0, 999999], even for negative (pre-1970) input.
        $seconds = intdiv($totalMicroseconds, 1_000_000);
        $microseconds = $totalMicroseconds % 1_000_000;
        if ($microseconds < 0) {
            $seconds--;
            $microseconds += 1_000_000;
        }

        return (new \DateTimeImmutable('@' . $seconds))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->modify(sprintf('+%d microseconds', $microseconds));
    }
}
