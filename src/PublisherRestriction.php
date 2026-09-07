<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf;

/** A publisher's restriction of a Purpose to a specific legal basis for a set of vendors. */
final class PublisherRestriction
{
    /** @param int[] $vendorIds */
    public function __construct(
        public readonly int $purposeId,
        public readonly RestrictionType $type,
        public readonly array $vendorIds,
    ) {
    }
}
