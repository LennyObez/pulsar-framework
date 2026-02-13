<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Monitor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Monitor\HealthStatus;

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
