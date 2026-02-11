<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Resilience\HealthCheck;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Resilience\HealthCheck\HealthStatus;

#[CoversClass(HealthStatus::class)]
final class HealthStatusTest extends TestCase
{
    #[Test]
    public function casesHaveExpectedValues(): void
    {
        self::assertSame('healthy', HealthStatus::Healthy->value);
        self::assertSame('degraded', HealthStatus::Degraded->value);
        self::assertSame('unhealthy', HealthStatus::Unhealthy->value);
    }
}
