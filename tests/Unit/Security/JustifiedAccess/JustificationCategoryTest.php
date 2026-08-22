<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\JustifiedAccess;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\JustifiedAccess\JustificationCategory;

#[CoversNothing]
final class JustificationCategoryTest extends TestCase
{
    #[Test]
    public function hasEightCases(): void
    {
        self::assertCount(8, JustificationCategory::cases());
    }

    #[Test]
    #[DataProvider('categoryProvider')]
    public function backedValues(JustificationCategory $category, string $expected): void
    {
        self::assertSame($expected, $category->value);
    }

    /**
     * @return iterable<string, array{JustificationCategory, string}>
     */
    public static function categoryProvider(): iterable
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
    public function fromBackedValueRoundTrips(): void
    {
        foreach (JustificationCategory::cases() as $cat) {
            self::assertSame($cat, JustificationCategory::from($cat->value));
        }
    }

    #[Test]
    public function tryFromReturnsNullForInvalid(): void
    {
        self::assertNull(JustificationCategory::tryFrom('personal_curiosity'));
    }
}
