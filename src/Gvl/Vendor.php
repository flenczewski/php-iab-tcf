<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Gvl;

use Flenczewski\IabTcf\Exception\GvlException;
use Flenczewski\IabTcf\Spec;

/** A single vendor entry from the Global Vendor List, limited to fields relevant to consent validation. */
final class Vendor
{
    /**
     * @param int[] $purposes Purpose ids the vendor processes data for under consent
     * @param int[] $legIntPurposes Purpose ids the vendor relies on legitimate interest for
     * @param int[] $flexiblePurposes Purpose ids the vendor can declare under either legal basis
     * @param int[] $specialPurposes Special Purpose ids the vendor uses
     * @param int[] $features Feature ids the vendor uses
     * @param int[] $specialFeatures Special Feature ids the vendor requires opt-in for
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly array $purposes = [],
        public readonly array $legIntPurposes = [],
        public readonly array $flexiblePurposes = [],
        public readonly array $specialPurposes = [],
        public readonly array $features = [],
        public readonly array $specialFeatures = [],
    ) {
    }

    /** @param array<string,mixed> $data one entry from the GVL's "vendors" map */
    public static function fromArray(array $data): self
    {
        // array_key_exists, not isset: a vendor carrying "name": null is
        // present-but-wrong, and should be reported as such by toString()
        // rather than as a missing field.
        foreach (['id', 'name'] as $required) {
            if (!array_key_exists($required, $data)) {
                throw new GvlException("Vendor entry is missing the required \"{$required}\" field.");
            }
        }

        $id = self::toInt($data['id'], 'id');
        // Vendor ids are 1-based, so 0 or a negative id is corrupt input rather
        // than an unusual vendor. The upper end is deliberately not capped at
        // Spec::MAX_VENDOR_ID: a list naming an id beyond the 16-bit TC String
        // field is unusable for consent checks, but that is not a reason to
        // refuse to parse the rest of the list.
        if ($id < Spec::MIN_VENDOR_ID) {
            throw new GvlException("Vendor entry declares id {$id}; vendor ids start at 1.");
        }

        return new self(
            id: $id,
            name: self::toString($data['name'], 'name'),
            purposes: self::intList($data, 'purposes'),
            legIntPurposes: self::intList($data, 'legIntPurposes'),
            flexiblePurposes: self::intList($data, 'flexiblePurposes'),
            specialPurposes: self::intList($data, 'specialPurposes'),
            features: self::intList($data, 'features'),
            specialFeatures: self::intList($data, 'specialFeatures'),
        );
    }

    /**
     * @param array<string,mixed> $data
     * @return int[]
     */
    private static function intList(array $data, string $key): array
    {
        $value = $data[$key] ?? [];
        if (!is_array($value)) {
            throw new GvlException(
                "Vendor field \"{$key}\" must be an array, got " . get_debug_type($value) . '.'
            );
        }

        $ids = [];
        foreach ($value as $entry) {
            $ids[] = self::toInt($entry, $key);
        }

        return $ids;
    }

    private static function toInt(mixed $value, string $field): int
    {
        // \z, not $: PCRE's $ also matches before a trailing newline, which
        // would let "3\n" through and cast to 3, hiding corrupt input.
        if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+\z/', $value) === 1)) {
            throw new GvlException(
                "Vendor field \"{$field}\" must be an integer, got " . get_debug_type($value) . '.'
            );
        }

        // A digit string longer than PHP_INT_MAX saturates on cast rather than
        // failing, so "99999999999999999999999" would become a vendor keyed by
        // PHP_INT_MAX. The same value written as a JSON number is already
        // rejected (it arrives as a float); the string spelling must not be the
        // way around that check.
        if (is_string($value) && (string) (int) $value !== $value) {
            throw new GvlException(
                "Vendor field \"{$field}\" is {$value}, which is not representable as an integer."
            );
        }

        return (int) $value;
    }

    private static function toString(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new GvlException(
                "Vendor field \"{$field}\" must be a string, got " . get_debug_type($value) . '.'
            );
        }

        return $value;
    }
}
