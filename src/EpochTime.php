<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf;

use Flenczewski\IabTcf\Exception\InvalidArgumentException;

/**
 * Converts between DateTimeImmutable and the "deciseconds since Unix epoch"
 * (tenths of a second) integer format used by the Created/LastUpdated Core
 * String fields.
 */
final class EpochTime
{
    public static function toDeciseconds(\DateTimeImmutable $dateTime): int
    {
        // Reject the multiplication's overflow rather than performing it: past
        // ~9.2e12 seconds the product below becomes a float and intdiv() then
        // dies with a TypeError under strict_types. A microsecond epoch passed
        // where seconds were meant lands squarely in that range.
        $seconds = $dateTime->getTimestamp();
        // Minus the microsecond offset added below, which would otherwise push a
        // timestamp sitting exactly on the boundary back over PHP_INT_MAX.
        $maxSafeSeconds = intdiv(\PHP_INT_MAX - 999_999, 1_000_000);
        if ($seconds > $maxSafeSeconds || $seconds < -$maxSafeSeconds) {
            throw new InvalidArgumentException(sprintf(
                '%s is outside the range this converter can represent; deciseconds since the epoch '
                . 'must fit in a PHP integer.',
                $dateTime->format(\DATE_ATOM),
            ));
        }

        // format('U.u') is unsafe here: 'u' (microseconds) is always a
        // non-negative offset *after* the 'U' second, but string-concatenating
        // it onto a negative 'U' and casting to float silently subtracts that
        // offset instead of adding it for any pre-1970 timestamp.
        $totalMicroseconds = $seconds * 1_000_000 + (int) $dateTime->format('u');

        // Truncate toward negative infinity rather than rounding: a Created or
        // LastUpdated stamp must never land after the moment it describes.
        $deciseconds = intdiv($totalMicroseconds, 100_000);
        if ($totalMicroseconds < 0 && $totalMicroseconds % 100_000 !== 0) {
            $deciseconds--;
        }

        return $deciseconds;
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
