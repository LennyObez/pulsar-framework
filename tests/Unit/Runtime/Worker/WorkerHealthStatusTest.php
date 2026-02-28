<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Runtime\Worker\HealthStatus;

#[CoversClass(HealthStatus::class)]
final class HealthStatusTest extends TestCase
{
    #[Test]
    public function it_has_all_expected_cases(): void
    {
        self::assertSame('healthy', HealthStatus::Healthy->value);
        self::assertSame('draining', HealthStatus::Draining->value);
        self::assertSame('shutting_down', HealthStatus::ShuttingDown->value);
    }

    #[Test]
    public function it_creates_from_string_value(): void
    {
        self::assertSame(HealthStatus::Healthy, HealthStatus::from('healthy'));
        self::assertSame(HealthStatus::Draining, HealthStatus::from('draining'));
        self::assertSame(HealthStatus::ShuttingDown, HealthStatus::from('shutting_down'));
    }

    #[Test]
    public function it_has_three_cases(): void
    {
        self::assertCount(3, HealthStatus::cases());
    }
}
