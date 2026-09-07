<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Tests;

use Flenczewski\IabTcf\Exception\InvalidArgumentException;
use Flenczewski\IabTcf\PublisherRestriction;
use Flenczewski\IabTcf\RestrictionType;
use Flenczewski\IabTcf\TcModel;
use PHPUnit\Framework\TestCase;

final class TcModelValidationTest extends TestCase
{
    /** @return iterable<string, array{\Closure, string}> */
    public static function invalidModels(): iterable
    {
        yield 'cmpId above 12 bits' => [
            static fn () => new TcModel(cmpId: 4096, cmpVersion: 1),
            'cmpId',
        ];
        yield 'negative cmpVersion' => [
            static fn () => new TcModel(cmpId: 1, cmpVersion: -1),
            'cmpVersion',
        ];
        yield 'consentScreen above 6 bits' => [
            static fn () => new TcModel(cmpId: 1, cmpVersion: 1, consentScreen: 64),
            'consentScreen',
        ];
        yield 'purpose id 25' => [
            static fn () => new TcModel(cmpId: 1, cmpVersion: 1, purposesConsent: [1, 25]),
            'purposesConsent',
        ];
        yield 'special feature id 13' => [
            static fn () => new TcModel(cmpId: 1, cmpVersion: 1, specialFeatureOptIns: [13]),
            'specialFeatureOptIns',
        ];
        yield 'vendor id 0' => [
            static fn () => new TcModel(cmpId: 1, cmpVersion: 1, vendorConsents: [0, 5]),
            'vendorConsents',
        ];
        yield 'vendor id above 16 bits' => [
            static fn () => new TcModel(cmpId: 1, cmpVersion: 1, vendorLegitimateInterests: [65536]),
            'vendorLegitimateInterests',
        ];
        yield 'three-letter language' => [
            static fn () => new TcModel(cmpId: 1, cmpVersion: 1, consentLanguage: 'ENG'),
            'consentLanguage',
        ];
        yield 'numeric publisher country' => [
            static fn () => new TcModel(cmpId: 1, cmpVersion: 1, publisherCC: 'D1'),
            'publisherCC',
        ];
        yield 'disclosed vendor id 0' => [
            static fn () => new TcModel(cmpId: 1, cmpVersion: 1, disclosedVendors: [0]),
            'disclosedVendors',
        ];
    }

    /**
     * @param \Closure(): TcModel $build
     * @dataProvider invalidModels
     */
    public function testRejectsOutOfSpecValues(\Closure $build, string $expectedFieldInMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedFieldInMessage);

        $build();
    }

    public function testAcceptsBoundaryValues(): void
    {
        $model = new TcModel(
            cmpId: 4095,
            cmpVersion: 4095,
            consentScreen: 63,
            consentLanguage: 'pl',
            vendorListVersion: 4095,
            tcfPolicyVersion: 63,
            publisherCC: 'PL',
            specialFeatureOptIns: [1, 12],
            purposesConsent: [1, 24],
            vendorConsents: [1, 65535],
        );

        self::assertSame(4095, $model->cmpId);
        self::assertSame([1, 65535], $model->vendorConsents);
    }

    public function testPublisherRestrictionRejectsPurposeIdZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('purposeId');

        new PublisherRestriction(0, RestrictionType::REQUIRE_CONSENT, [1]);
    }

    public function testPublisherRestrictionRejectsVendorIdZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('vendorIds');

        new PublisherRestriction(1, RestrictionType::REQUIRE_CONSENT, [0]);
    }
}
