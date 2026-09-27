<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf;

use Flenczewski\IabTcf\Exception\InvalidArgumentException;

/** A publisher's restriction of a Purpose to a specific legal basis for a set of vendors. */
final class PublisherRestriction
{
    /** @var int[] sorted, de-duplicated — the only form the range encoding can carry */
    public readonly array $vendorIds;

    /** @param int[] $vendorIds */
    public function __construct(
        public readonly int $purposeId,
        public readonly RestrictionType $type,
        array $vendorIds,
    ) {
        if ($purposeId < 1 || $purposeId > Spec::MAX_PURPOSE_ID) {
            throw new InvalidArgumentException(
                'purposeId must be between 1 and ' . Spec::MAX_PURPOSE_ID . ", got {$purposeId}."
            );
        }

        $this->vendorIds = IdSet::normalize('vendorIds', $vendorIds, Spec::MIN_VENDOR_ID, Spec::MAX_VENDOR_ID);
    }
}
