<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\JustifiedAccess;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\DataClassification;
use Pulsar\Security\JustifiedAccess\JustificationCategory;
use Pulsar\Security\JustifiedAccess\RequiresJustification;
use Pulsar\Security\JustifiedAccess\ReviewStatus;

#[CoversClass(JustificationCategory::class)]
#[CoversClass(ReviewStatus::class)]
#[CoversClass(RequiresJustification::class)]
final class JustifiedAccessEnumTest extends TestCase
{
    // ── JustificationCategory ───────────────────────────────────────

    #[Test]
    public function justificationCategoryHasEightCases(): void
    {
        self::assertCount(8, JustificationCategory::cases());
    }

    #[Test]
    #[DataProvider('justificationCategoryProvider')]
    public function justificationCategoryBackedValues(JustificationCategory $cat, string $expected): void
    {
        self::assertSame($expected, $cat->value);
    }

    /**
     * @return iterable<string, array{JustificationCategory, string}>
     */
    public static function justificationCategoryProvider(): iterable
    {
        yield 'CustomerRequest' => [JustificationCategory::CustomerRequest, 'customer_request'];
        yield 'RegulatoryObligation' => [JustificationCategory::RegulatoryObligation, 'regulatory'];
        yield 'InternalAudit' => [JustificationCategory::InternalAudit, 'audit'];
        yield 'DisputeResolution' => [JustificationCategory::DisputeResolution, 'dispute'];
        yield 'AccountMaintenance' => [JustificationCategory::AccountMaintenance, 'maintenance'];
        yield 'Emergency' => [JustificationCategory::Emergency, 'emergency'];
        yield 'LawEnforcement' => [JustificationCategory::LawEnforcement, 'law_enforcement'];
        yield 'FraudInvestigation' => [JustificationCategory::FraudInvestigation, 'fraud_investigation'];
    }

    #[Test]
    public function justificationCategoryFromBackedValue(): void
    {
        self::assertSame(JustificationCategory::Emergency, JustificationCategory::from('emergency'));
        self::assertSame(JustificationCategory::FraudInvestigation, JustificationCategory::from('fraud_investigation'));
    }

    // ── ReviewStatus ────────────────────────────────────────────────

    #[Test]
    public function reviewStatusHasFiveCases(): void
    {
        self::assertCount(5, ReviewStatus::cases());
    }

    #[Test]
    #[DataProvider('reviewStatusProvider')]
    public function reviewStatusBackedValues(ReviewStatus $status, string $expected): void
    {
        self::assertSame($expected, $status->value);
    }

    /**
     * @return iterable<string, array{ReviewStatus, string}>
     */
    public static function reviewStatusProvider(): iterable
    {
        yield 'Pending' => [ReviewStatus::Pending, 'pending'];
        yield 'Approved' => [ReviewStatus::Approved, 'approved'];
        yield 'Flagged' => [ReviewStatus::Flagged, 'flagged'];
        yield 'Reviewed' => [ReviewStatus::Reviewed, 'reviewed'];
        yield 'Escalated' => [ReviewStatus::Escalated, 'escalated'];
    }

    #[Test]
    public function reviewStatusFromBackedValue(): void
    {
        self::assertSame(ReviewStatus::Pending, ReviewStatus::from('pending'));
        self::assertSame(ReviewStatus::Escalated, ReviewStatus::from('escalated'));
    }

    // ── RequiresJustification ───────────────────────────────────────

    #[Test]
    public function requiresJustificationDefaults(): void
    {
        $attr = new RequiresJustification();

        self::assertSame(DataClassification::Confidential, $attr->dataClassification);
        self::assertFalse($attr->requireSupervisorApproval);
        self::assertNull($attr->allowedCategories);
    }

    #[Test]
    public function requiresJustificationCustomValues(): void
    {
        $attr = new RequiresJustification(
            dataClassification: DataClassification::Restricted,
            requireSupervisorApproval: true,
            allowedCategories: [
                JustificationCategory::Emergency,
                JustificationCategory::LawEnforcement,
            ],
        );

        self::assertSame(DataClassification::Restricted, $attr->dataClassification);
        self::assertTrue($attr->requireSupervisorApproval);
        self::assertNotNull($attr->allowedCategories);
        self::assertCount(2, $attr->allowedCategories);
        self::assertSame(JustificationCategory::Emergency, $attr->allowedCategories[0]);
        self::assertSame(JustificationCategory::LawEnforcement, $attr->allowedCategories[1]);
    }
}
