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

        $set = [];
        foreach ($vendorIds as $vendorId) {
            // Checked here, as TcModel does for its own id lists: otherwise a
            // numeric string or float only fails at encode time, as a TypeError.
            if (!is_int($vendorId)) {
                throw new InvalidArgumentException('vendorIds must contain only integers.');
            }
            if ($vendorId < Spec::MIN_VENDOR_ID || $vendorId > Spec::MAX_VENDOR_ID) {
                throw new InvalidArgumentException(
                    'vendorIds must be between ' . Spec::MIN_VENDOR_ID . ' and ' . Spec::MAX_VENDOR_ID
                    . ", got {$vendorId}."
                );
            }
            $set[$vendorId] = true;
        }

        $ids = array_keys($set);
        sort($ids);
        $this->vendorIds = $ids;
    }
}
