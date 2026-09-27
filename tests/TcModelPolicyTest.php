<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Tests;

use Flenczewski\IabTcf\PublisherRestriction;
use Flenczewski\IabTcf\RestrictionType;
use Flenczewski\IabTcf\TcModel;
use PHPUnit\Framework\TestCase;

final class TcModelPolicyTest extends TestCase
{
    public function testAConformantModelHasNoViolations(): void
    {
        $model = new TcModel(
            cmpId: 1,
            cmpVersion: 1,
            purposesConsent: [1, 2, 3, 4],
            purposesLITransparency: [2, 7, 8, 9, 10],
            publisherRestrictions: [
                new PublisherRestriction(2, RestrictionType::REQUIRE_LEGITIMATE_INTEREST, [1, 2]),
                new PublisherRestriction(3, RestrictionType::NOT_ALLOWED, [1]),
                new PublisherRestriction(3, RestrictionType::NOT_ALLOWED, [2]),
            ],
            created: new \DateTimeImmutable('2026-01-01T00:00:00Z'),
            lastUpdated: new \DateTimeImmutable('2026-02-01T00:00:00Z'),
        );

        self::assertSame([], $model->policyViolations());
    }

    /** Purpose 1 never allowed legitimate interest; TCF v2.2 withdrew it for purposes 3 to 6. */
    public function testLegitimateInterestForAPurposeThatCannotUseItIsReported(): void
    {
        $model = new TcModel(cmpId: 1, cmpVersion: 1, purposesLITransparency: [1, 2, 3, 6, 7]);

        $violations = $model->policyViolations();

        self::assertCount(1, $violations);
        self::assertStringContainsString('purposes 1, 3, 6', $violations[0]);
    }

    public function testRequiringLegitimateInterestForSuchAPurposeIsReported(): void
    {
        $model = new TcModel(cmpId: 1, cmpVersion: 1, publisherRestrictions: [
            new PublisherRestriction(4, RestrictionType::REQUIRE_LEGITIMATE_INTEREST, [7]),
        ]);

        $violations = $model->policyViolations();

        self::assertCount(1, $violations);
        self::assertStringContainsString('requires legitimate interest for purpose 4', $violations[0]);
    }

    /** A string is judged by the policy it declares: before TCF v2.2, purposes 3 to 6 could use legitimate interest. */
    public function testPurposes3To6MayUseLegitimateInterestUnderPolicyVersionsBeforeTcf22(): void
    {
        $model = new TcModel(
            cmpId: 1,
            cmpVersion: 1,
            tcfPolicyVersion: 3,
            purposesLITransparency: [1, 2, 3, 4, 5, 6, 7],
            publisherRestrictions: [
                new PublisherRestriction(4, RestrictionType::REQUIRE_LEGITIMATE_INTEREST, [7]),
                new PublisherRestriction(1, RestrictionType::REQUIRE_LEGITIMATE_INTEREST, [7]),
            ],
        );

        $violations = $model->policyViolations();

        self::assertCount(2, $violations, 'Purpose 1 never allowed legitimate interest.');
        self::assertStringContainsString('for purposes 1, which TCF policy version 3', $violations[0]);
        self::assertStringContainsString('requires legitimate interest for purpose 1,', $violations[1]);
    }

    public function testTcf22IsThePolicyVersionThatWithdrewLegitimateInterestForPurposes3To6(): void
    {
        $model = new TcModel(cmpId: 1, cmpVersion: 1, tcfPolicyVersion: 4, purposesLITransparency: [3]);

        self::assertCount(1, $model->policyViolations());
    }

    public function testTheUndefinedRestrictionTypeIsReported(): void
    {
        $model = new TcModel(cmpId: 1, cmpVersion: 1, publisherRestrictions: [
            new PublisherRestriction(2, RestrictionType::UNDEFINED, [7]),
        ]);

        $violations = $model->policyViolations();

        self::assertCount(1, $violations);
        self::assertStringContainsString('UNDEFINED', $violations[0]);
    }

    public function testAVendorRestrictedTwoWaysForOnePurposeIsReported(): void
    {
        $model = new TcModel(cmpId: 1, cmpVersion: 1, publisherRestrictions: [
            new PublisherRestriction(2, RestrictionType::NOT_ALLOWED, [7, 8]),
            new PublisherRestriction(2, RestrictionType::REQUIRE_CONSENT, [8, 9]),
            // Same vendor, different purpose: fine.
            new PublisherRestriction(3, RestrictionType::REQUIRE_CONSENT, [7]),
        ]);

        $violations = $model->policyViolations();

        self::assertCount(1, $violations);
        self::assertStringContainsString('Vendor 8', $violations[0]);
        self::assertStringContainsString('purpose 2', $violations[0]);
        self::assertStringContainsString('NOT_ALLOWED, REQUIRE_CONSENT', $violations[0]);
    }

    public function testCreatedAfterLastUpdatedIsReported(): void
    {
        $model = new TcModel(
            cmpId: 1,
            cmpVersion: 1,
            created: new \DateTimeImmutable('2026-02-01T00:00:00Z'),
            lastUpdated: new \DateTimeImmutable('2026-01-01T00:00:00Z'),
        );

        $violations = $model->policyViolations();

        self::assertCount(1, $violations);
        self::assertStringContainsString('after lastUpdated', $violations[0]);
    }
}
