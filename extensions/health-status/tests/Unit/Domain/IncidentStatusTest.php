<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\HealthStatus\Domain\IncidentStatus;

#[CoversNothing]
final class IncidentStatusTest extends TestCase
{
    /**
     * @return iterable<string, array{IncidentStatus, string}>
     */
    public static function statusValuesProvider(): iterable
    {
        yield 'open' => [IncidentStatus::Open, 'open'];
        yield 'acknowledged' => [IncidentStatus::Acknowledged, 'acknowledged'];
        yield 'resolved' => [IncidentStatus::Resolved, 'resolved'];
    }

    #[Test]
    #[DataProvider('statusValuesProvider')]
    public function enumHasExpectedBackingValue(IncidentStatus $status, string $expected): void
    {
        self::assertSame($expected, $status->value);
    }

    #[Test]
    public function enumHasExactlyThreeCases(): void
    {
        self::assertCount(3, IncidentStatus::cases());
    }

    #[Test]
    public function fromValueCreatesCorrectCase(): void
    {
        self::assertSame(IncidentStatus::Open, IncidentStatus::from('open'));
        self::assertSame(IncidentStatus::Acknowledged, IncidentStatus::from('acknowledged'));
        self::assertSame(IncidentStatus::Resolved, IncidentStatus::from('resolved'));
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(IncidentStatus::tryFrom('invalid'));
        self::assertNull(IncidentStatus::tryFrom(''));
    }
}
