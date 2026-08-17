<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Incident;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Incident\IncidentSeverity;

#[CoversNothing]
final class IncidentSeverityTest extends TestCase
{
    #[Test]
    public function hasFourCases(): void
    {
        self::assertCount(4, IncidentSeverity::cases());
    }

    #[Test]
    #[DataProvider('severityProvider')]
    public function backedValues(IncidentSeverity $severity, string $expected): void
    {
        self::assertSame($expected, $severity->value);
    }

    /**
     * @return iterable<string, array{IncidentSeverity, string}>
     */
    public static function severityProvider(): iterable
    {
        yield 'Low' => [IncidentSeverity::Low, 'low'];
        yield 'Medium' => [IncidentSeverity::Medium, 'medium'];
        yield 'High' => [IncidentSeverity::High, 'high'];
        yield 'Critical' => [IncidentSeverity::Critical, 'critical'];
    }

    #[Test]
    public function fromBackedValueRoundTrips(): void
    {
        foreach (IncidentSeverity::cases() as $severity) {
            self::assertSame($severity, IncidentSeverity::from($severity->value));
        }
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(IncidentSeverity::tryFrom('catastrophic'));
    }
}
