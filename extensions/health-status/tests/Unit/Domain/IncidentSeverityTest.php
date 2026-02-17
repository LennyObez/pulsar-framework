<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\HealthStatus\Domain\IncidentSeverity;

#[CoversClass(IncidentSeverity::class)]
final class IncidentSeverityTest extends TestCase
{
    /**
     * @return iterable<string, array{IncidentSeverity, string}>
     */
    public static function severityValuesProvider(): iterable
    {
        yield 'minor' => [IncidentSeverity::Minor, 'minor'];
        yield 'major' => [IncidentSeverity::Major, 'major'];
        yield 'critical' => [IncidentSeverity::Critical, 'critical'];
    }

    #[Test]
    #[DataProvider('severityValuesProvider')]
    public function enumHasExpectedBackingValue(IncidentSeverity $severity, string $expected): void
    {
        self::assertSame($expected, $severity->value);
    }

    #[Test]
    public function enumHasExactlyThreeCases(): void
    {
        self::assertCount(3, IncidentSeverity::cases());
    }

    #[Test]
    public function fromValueCreatesCorrectCase(): void
    {
        self::assertSame(IncidentSeverity::Minor, IncidentSeverity::from('minor'));
        self::assertSame(IncidentSeverity::Major, IncidentSeverity::from('major'));
        self::assertSame(IncidentSeverity::Critical, IncidentSeverity::from('critical'));
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(IncidentSeverity::tryFrom('invalid'));
        self::assertNull(IncidentSeverity::tryFrom(''));
        self::assertNull(IncidentSeverity::tryFrom('warning'));
    }
}
