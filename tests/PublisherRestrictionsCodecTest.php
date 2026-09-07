<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Tests;

use Flenczewski\IabTcf\BitReader;
use Flenczewski\IabTcf\PublisherRestriction;
use Flenczewski\IabTcf\PublisherRestrictionsCodec;
use Flenczewski\IabTcf\RestrictionType;
use PHPUnit\Framework\TestCase;

final class PublisherRestrictionsCodecTest extends TestCase
{
    public function testEmptyListRoundTrip(): void
    {
        $bits = PublisherRestrictionsCodec::encode([]);
        self::assertSame([], PublisherRestrictionsCodec::decode(new BitReader($bits)));
    }

    public function testSingleRestrictionRoundTrip(): void
    {
        $restriction = new PublisherRestriction(4, RestrictionType::REQUIRE_CONSENT, [1, 2, 3]);
        $bits = PublisherRestrictionsCodec::encode([$restriction]);

        $decoded = PublisherRestrictionsCodec::decode(new BitReader($bits));

        self::assertCount(1, $decoded);
        self::assertSame(4, $decoded[0]->purposeId);
        self::assertSame(RestrictionType::REQUIRE_CONSENT, $decoded[0]->type);
        self::assertSame([1, 2, 3], $decoded[0]->vendorIds);
    }

    public function testMultipleRestrictionsWithDifferentTypesRoundTrip(): void
    {
        $restrictions = [
            new PublisherRestriction(1, RestrictionType::NOT_ALLOWED, [10, 11, 12]),
            new PublisherRestriction(2, RestrictionType::REQUIRE_LEGITIMATE_INTEREST, [500]),
            new PublisherRestriction(7, RestrictionType::UNDEFINED, []),
        ];

        $bits = PublisherRestrictionsCodec::encode($restrictions);
        $decoded = PublisherRestrictionsCodec::decode(new BitReader($bits));

        self::assertCount(3, $decoded);
        foreach ($restrictions as $i => $expected) {
            self::assertSame($expected->purposeId, $decoded[$i]->purposeId);
            self::assertSame($expected->type, $decoded[$i]->type);
            self::assertSame($expected->vendorIds, $decoded[$i]->vendorIds);
        }
    }
}
