<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf;

use Flenczewski\IabTcf\Exception\InvalidArgumentException;

/** A publisher's restriction of a Purpose to a specific legal basis for a set of vendors. */
final class PublisherRestriction
{
    /** @param int[] $vendorIds */
    public function __construct(
        public readonly int $purposeId,
        public readonly RestrictionType $type,
        public readonly array $vendorIds,
    ) {
        if ($purposeId < 1 || $purposeId > Spec::MAX_PURPOSE_ID) {
            throw new InvalidArgumentException(
                "purposeId must be between 1 and " . Spec::MAX_PURPOSE_ID . ", got {$purposeId}."
            );
        }

        foreach ($vendorIds as $vendorId) {
            if ($vendorId < Spec::MIN_VENDOR_ID || $vendorId > Spec::MAX_VENDOR_ID) {
                throw new InvalidArgumentException(
                    "vendorIds must be between " . Spec::MIN_VENDOR_ID . ' and ' . Spec::MAX_VENDOR_ID
                    . ", got {$vendorId}."
                );
            }
        }
    }
}
