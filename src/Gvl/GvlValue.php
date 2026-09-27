<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Gvl;

use Flenczewski\IabTcf\Exception\GvlException;

/**
 * Strict conversions for scalar values read from a Global Vendor List payload,
 * shared by {@see Gvl} and {@see Vendor} so both reject corrupt input the same way.
 *
 * Every $label is the complete subject of the error message, e.g.
 * `Global Vendor List "vendorListVersion"` or `Vendor field "purposes"`.
 *
 * @internal
 */
final class GvlValue
{
    /**
     * An absolute ISO 8601 date, optionally with a time and zone. Anything else
     * (relative formats such as "now" or "tomorrow" in particular) is rejected:
     * DateTimeImmutable would accept them and make a corrupt list look current.
     */
    private const ISO_8601_DATE = '/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?)?\z/';

    public static function toInt(mixed $value, string $label): int
    {
        // \z, not $: PCRE's $ also matches before a trailing newline, which
        // would let "3\n" through and cast to 3, hiding corrupt input.
        if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+\z/', $value) === 1)) {
            throw new GvlException("{$label} must be an integer, got " . get_debug_type($value) . '.');
        }

        // A digit string longer than PHP_INT_MAX saturates on cast rather than
        // failing, so "99999999999999999999999" would become a vendor keyed by
        // PHP_INT_MAX. The same value written as a JSON number is already
        // rejected (it arrives as a float); the string spelling must not be the
        // way around that check.
        if (is_string($value) && (string) (int) $value !== $value) {
            throw new GvlException("{$label} is {$value}, which is not representable as an integer.");
        }

        return (int) $value;
    }

    public static function toDate(mixed $value, string $label): \DateTimeImmutable
    {
        // new DateTimeImmutable('') silently means "now", which would make a
        // corrupt list look freshly updated — reject empty input explicitly.
        if (!is_string($value) || trim($value) === '') {
            throw new GvlException("{$label} must be a non-empty date string.");
        }

        if (preg_match(self::ISO_8601_DATE, $value) !== 1) {
            throw new GvlException("{$label} is not a valid date: \"{$value}\".");
        }

        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new GvlException("{$label} is not a valid date: \"{$value}\".", 0, $e);
        }

        // The parser rolls impossible dates over instead of failing, so
        // "2026-02-31" silently became 3 March. It records a warning when it
        // does; a date that needed repairing is corrupt input.
        $errors = \DateTimeImmutable::getLastErrors();
        if ($errors !== false && $errors['warning_count'] > 0) {
            throw new GvlException("{$label} is not a valid date: \"{$value}\".");
        }

        return $date;
    }
}
