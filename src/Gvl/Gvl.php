<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Gvl;

use Flenczewski\IabTcf\TcModel;

/**
 * A parsed Global Vendor List: metadata plus queryable Vendor entries.
 *
 * Build one from a raw vendor-list.json payload with {@see self::fromJson()},
 * or fetch one over the network with {@see GvlFetcher}.
 */
final class Gvl
{
    /** @param array<int,Vendor> $vendors keyed by vendor id */
    public function __construct(
        public readonly int $gvlSpecificationVersion,
        public readonly int $vendorListVersion,
        public readonly int $tcfPolicyVersion,
        public readonly \DateTimeImmutable $lastUpdated,
        public readonly array $vendors,
    ) {
    }

    /**
     * Parses the copy of the Global Vendor List bundled with this package
     * (`resources/vendor-list.json`) — fast, deterministic, no network
     * dependency at runtime. The bundle is refreshed periodically by CI (see
     * README "Bundled GVL vs. live fetch"), so it may lag the live list by up
     * to a week; use {@see GvlFetcher} instead if you need the freshest data.
     */
    public static function bundled(): self
    {
        return self::fromJson(file_get_contents(__DIR__ . '/../../resources/vendor-list.json'));
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        $vendors = [];
        foreach ($data['vendors'] ?? [] as $vendorData) {
            $vendor = Vendor::fromArray($vendorData);
            $vendors[$vendor->id] = $vendor;
        }

        return new self(
            gvlSpecificationVersion: (int) $data['gvlSpecificationVersion'],
            vendorListVersion: (int) $data['vendorListVersion'],
            tcfPolicyVersion: (int) $data['tcfPolicyVersion'],
            lastUpdated: new \DateTimeImmutable((string) $data['lastUpdated']),
            vendors: $vendors,
        );
    }

    /** @return Vendor[] */
    public function getVendorsWithConsentPurpose(int $purposeId): array
    {
        return array_values(array_filter(
            $this->vendors,
            static fn (Vendor $v): bool => in_array($purposeId, $v->purposes, true),
        ));
    }

    /** @return Vendor[] */
    public function getVendorsWithLegIntPurpose(int $purposeId): array
    {
        return array_values(array_filter(
            $this->vendors,
            static fn (Vendor $v): bool => in_array($purposeId, $v->legIntPurposes, true),
        ));
    }

    /** @return Vendor[] */
    public function getVendorsWithFeature(int $featureId): array
    {
        return array_values(array_filter(
            $this->vendors,
            static fn (Vendor $v): bool => in_array($featureId, $v->features, true),
        ));
    }

    /** @return Vendor[] */
    public function getVendorsWithSpecialFeature(int $specialFeatureId): array
    {
        return array_values(array_filter(
            $this->vendors,
            static fn (Vendor $v): bool => in_array($specialFeatureId, $v->specialFeatures, true),
        ));
    }

    /** @return Vendor[] */
    public function getVendorsWithSpecialPurpose(int $specialPurposeId): array
    {
        return array_values(array_filter(
            $this->vendors,
            static fn (Vendor $v): bool => in_array($specialPurposeId, $v->specialPurposes, true),
        ));
    }

    /** Returns a new Gvl containing only the given vendor ids. */
    public function narrowVendorsTo(array $vendorIds): self
    {
        $wanted = array_flip($vendorIds);

        return new self(
            $this->gvlSpecificationVersion,
            $this->vendorListVersion,
            $this->tcfPolicyVersion,
            $this->lastUpdated,
            array_intersect_key($this->vendors, $wanted),
        );
    }

    /**
     * Cross-checks a TcModel's vendor consents/legitimate interests against
     * this GVL and returns a list of human-readable inconsistencies. This is
     * a lightweight sanity check, not a formal/exhaustive TCF validator.
     *
     * @return string[]
     */
    public function validateConsents(TcModel $model): array
    {
        $problems = [];

        foreach ($model->vendorConsents as $vendorId) {
            if (!isset($this->vendors[$vendorId])) {
                $problems[] = "Vendor {$vendorId} has consent in the TcModel but does not exist in this GVL.";
                continue;
            }

            if ($this->vendors[$vendorId]->purposes === [] && $this->vendors[$vendorId]->flexiblePurposes === []) {
                $problems[] = "Vendor {$vendorId} has consent in the TcModel but declares no consent-based "
                    . 'purposes in the GVL.';
            }
        }

        foreach ($model->vendorLegitimateInterests as $vendorId) {
            if (!isset($this->vendors[$vendorId])) {
                $problems[] = "Vendor {$vendorId} has legitimate interest in the TcModel but does not exist "
                    . 'in this GVL.';
                continue;
            }

            if (
                $this->vendors[$vendorId]->legIntPurposes === []
                && $this->vendors[$vendorId]->flexiblePurposes === []
            ) {
                $problems[] = "Vendor {$vendorId} has legitimate interest in the TcModel but declares no "
                    . 'legitimate-interest-based purposes in the GVL.';
            }
        }

        return $problems;
    }
}
