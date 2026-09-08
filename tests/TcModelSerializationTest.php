<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Tests;

use Flenczewski\IabTcf\PublisherRestriction;
use Flenczewski\IabTcf\RestrictionType;
use Flenczewski\IabTcf\TcModel;
use PHPUnit\Framework\TestCase;

final class TcModelSerializationTest extends TestCase
{
    public function testSerializesEveryPublicField(): void
    {
        $model = new TcModel(
            cmpId: 42,
            cmpVersion: 3,
            purposesConsent: [1, 2],
            publisherRestrictions: [new PublisherRestriction(2, RestrictionType::REQUIRE_CONSENT, [7])],
            created: new \DateTimeImmutable('2024-01-02T03:04:05+00:00'),
            lastUpdated: new \DateTimeImmutable('2024-01-02T03:04:05+00:00'),
        );

        $data = $model->jsonSerialize();

        self::assertSame(2, $data['version']);
        self::assertSame(42, $data['cmpId']);
        self::assertSame('2024-01-02T03:04:05+00:00', $data['created']);
        self::assertSame([1, 2], $data['purposesConsent']);
        self::assertSame(
            [['purposeId' => 2, 'type' => 'REQUIRE_CONSENT', 'vendorIds' => [7]]],
            $data['publisherRestrictions'],
        );
        self::assertSame([], $data['disclosedVendors']);
        self::assertNull($data['allowedVendors']);
    }

    public function testEncodesDirectlyWithJsonEncode(): void
    {
        $json = json_encode(new TcModel(cmpId: 1, cmpVersion: 1), JSON_THROW_ON_ERROR);

        self::assertStringContainsString('"cmpId":1', $json);
    }
}
