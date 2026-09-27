<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf;

use Flenczewski\IabTcf\Exception\InvalidArgumentException;

/**
 * Validation of id lists, shared by {@see TcModel} and
 * {@see PublisherRestriction} so both reject bad ids the same way.
 *
 * @internal
 */
final class IdSet
{
    /**
     * Validates an id list and returns it sorted and de-duplicated — the only
     * form the range encoding can carry.
     *
     * Non-integers are rejected here rather than left to fail at encode time,
     * where a numeric string or float would escape as a raw TypeError.
     *
     * @param array<mixed> $ids
     * @return int[]
     */
    public static function normalize(string $field, array $ids, int $min, int $max): array
    {
        $set = [];
        foreach ($ids as $id) {
            if (!is_int($id)) {
                throw new InvalidArgumentException("{$field} must contain only integers.");
            }
            if ($id < $min || $id > $max) {
                throw new InvalidArgumentException(
                    "{$field} contains id {$id}, which is outside the valid range {$min}..{$max}."
                );
            }
            $set[$id] = true;
        }

        $ids = array_keys($set);
        sort($ids);

        return $ids;
    }
}
