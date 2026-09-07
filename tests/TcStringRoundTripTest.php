<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Tests;

use Flenczewski\IabTcf\PublisherRestriction;
use Flenczewski\IabTcf\RestrictionType;
use Flenczewski\IabTcf\TcModel;
use Flenczewski\IabTcf\TcStringDecoder;
use Flenczewski\IabTcf\TcStringEncoder;
use PHPUnit\Framework\TestCase;

final class TcStringRoundTripTest extends TestCase
{
    public function testMinimalModelRoundTrip(): void
    {
        $model = new TcModel(cmpId: 12, cmpVersion: 1);

        $tcString = TcStringEncoder::encode($model);
        $decoded = TcStringDecoder::decode($tcString);

        self::assertSame(12, $decoded->cmpId);
        self::assertSame(1, $decoded->cmpVersion);
        self::assertSame(0, $decoded->consentScreen);
        self::assertSame('EN', $decoded->consentLanguage);
        self::assertSame('AA', $decoded->publisherCC);
        self::assertFalse($decoded->isServiceSpecific);
        self::assertFalse($decoded->useNonStandardStacks);
        self::assertFalse($decoded->purposeOneTreatment);
        self::assertSame([], $decoded->specialFeatureOptIns);
        self::assertSame([], $decoded->purposesConsent);
        self::assertSame([], $decoded->purposesLITransparency);
        self::assertSame([], $decoded->vendorConsents);
        self::assertSame([], $decoded->vendorLegitimateInterests);
        self::assertSame([], $decoded->publisherRestrictions);
        self::assertNull($decoded->disclosedVendors);
        self::assertNull($decoded->allowedVendors);
        // Core string only: no dots.
        self::assertStringNotContainsString('.', $tcString);
    }

    public function testFullyPopulatedModelRoundTrip(): void
    {
        $created = new \DateTimeImmutable('2023-06-01T12:00:00.000000+00:00');
        $lastUpdated = new \DateTimeImmutable('2024-01-15T08:30:00.000000+00:00');

        $model = new TcModel(
            cmpId: 300,
            cmpVersion: 7,
            consentScreen: 5,
            consentLanguage: 'pl',
            vendorListVersion: 145,
            tcfPolicyVersion: 4,
            isServiceSpecific: true,
            useNonStandardStacks: true,
            purposeOneTreatment: true,
            publisherCC: 'pl',
            specialFeatureOptIns: [1, 2],
            purposesConsent: [1, 2, 3, 4, 9, 10],
            purposesLITransparency: [2, 7],
            vendorConsents: [1, 2, 3, 500, 501, 502, 50000],
            vendorLegitimateInterests: [3, 4, 5],
            publisherRestrictions: [
                new PublisherRestriction(2, RestrictionType::REQUIRE_CONSENT, [1, 2, 3]),
                new PublisherRestriction(4, RestrictionType::NOT_ALLOWED, [500]),
            ],
            disclosedVendors: [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
            allowedVendors: [1, 2, 3],
            created: $created,
            lastUpdated: $lastUpdated,
        );

        $tcString = TcStringEncoder::encode($model);
        $decoded = TcStringDecoder::decode($tcString);

        self::assertSame(300, $decoded->cmpId);
        self::assertSame(7, $decoded->cmpVersion);
        self::assertSame(5, $decoded->consentScreen);
        self::assertSame('PL', $decoded->consentLanguage);
        self::assertSame(145, $decoded->vendorListVersion);
        self::assertSame(4, $decoded->tcfPolicyVersion);
        self::assertTrue($decoded->isServiceSpecific);
        self::assertTrue($decoded->useNonStandardStacks);
        self::assertTrue($decoded->purposeOneTreatment);
        self::assertSame('PL', $decoded->publisherCC);
        self::assertSame([1, 2], $decoded->specialFeatureOptIns);
        self::assertSame([1, 2, 3, 4, 9, 10], $decoded->purposesConsent);
        self::assertSame([2, 7], $decoded->purposesLITransparency);
        self::assertSame([1, 2, 3, 500, 501, 502, 50000], $decoded->vendorConsents);
        self::assertSame([3, 4, 5], $decoded->vendorLegitimateInterests);

        self::assertCount(2, $decoded->publisherRestrictions);
        self::assertSame(2, $decoded->publisherRestrictions[0]->purposeId);
        self::assertSame(RestrictionType::REQUIRE_CONSENT, $decoded->publisherRestrictions[0]->type);
        self::assertSame([1, 2, 3], $decoded->publisherRestrictions[0]->vendorIds);
        self::assertSame(4, $decoded->publisherRestrictions[1]->purposeId);
        self::assertSame(RestrictionType::NOT_ALLOWED, $decoded->publisherRestrictions[1]->type);
        self::assertSame([500], $decoded->publisherRestrictions[1]->vendorIds);

        self::assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], $decoded->disclosedVendors);
        self::assertSame([1, 2, 3], $decoded->allowedVendors);

        self::assertSame($created->getTimestamp(), $decoded->created?->getTimestamp());
        self::assertSame($lastUpdated->getTimestamp(), $decoded->lastUpdated?->getTimestamp());

        // Core + Disclosed Vendors + Allowed Vendors = 3 dot-separated segments.
        self::assertSame(2, substr_count($tcString, '.'));
    }

    public function testDefaultsToNowWhenCreatedAndLastUpdatedAreNull(): void
    {
        $before = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $model = new TcModel(cmpId: 1, cmpVersion: 1);
        $tcString = TcStringEncoder::encode($model);
        $after = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $decoded = TcStringDecoder::decode($tcString);

        self::assertGreaterThanOrEqual($before->getTimestamp() - 1, $decoded->created->getTimestamp());
        self::assertLessThanOrEqual($after->getTimestamp() + 1, $decoded->created->getTimestamp());
    }

    public function testRejectsUnsupportedVersion(): void
    {
        // Hand-craft a core segment with version=1 (TCF v1, unsupported).
        $writer = new \Flenczewski\IabTcf\BitWriter();
        $writer->writeUint(1, 6);
        $tcString = $writer->toBase64Url();

        $this->expectException(\InvalidArgumentException::class);
        TcStringDecoder::decode($tcString);
    }
}
