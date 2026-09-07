<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Gvl;

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
        return new self(
            id: (int) $data['id'],
            name: (string) $data['name'],
            purposes: array_map(intval(...), $data['purposes'] ?? []),
            legIntPurposes: array_map(intval(...), $data['legIntPurposes'] ?? []),
            flexiblePurposes: array_map(intval(...), $data['flexiblePurposes'] ?? []),
            specialPurposes: array_map(intval(...), $data['specialPurposes'] ?? []),
            features: array_map(intval(...), $data['features'] ?? []),
            specialFeatures: array_map(intval(...), $data['specialFeatures'] ?? []),
        );
    }
}
