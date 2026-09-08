<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Gvl;

use Flenczewski\IabTcf\Exception\GvlException;

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
        foreach (['id', 'name'] as $required) {
            if (!isset($data[$required])) {
                throw new GvlException("Vendor entry is missing the required \"{$required}\" field.");
            }
        }

        return new self(
            id: (int) $data['id'],
            name: (string) $data['name'],
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

        return array_values(array_map(intval(...), $value));
    }
}
