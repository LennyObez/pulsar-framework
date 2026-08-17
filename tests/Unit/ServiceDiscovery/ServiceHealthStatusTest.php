<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\ServiceDiscovery;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\ServiceDiscovery\ServiceHealthStatus;

#[CoversNothing]
final class ServiceHealthStatusTest extends TestCase
{
    #[Test]
    public function allCasesHaveExpectedValues(): void
    {
        self::assertSame('healthy', ServiceHealthStatus::Healthy->value);
        self::assertSame('degraded', ServiceHealthStatus::Degraded->value);
        self::assertSame('unhealthy', ServiceHealthStatus::Unhealthy->value);
        self::assertSame('unknown', ServiceHealthStatus::Unknown->value);
    }

    #[Test]
    public function fromValidString(): void
    {
        self::assertSame(ServiceHealthStatus::Healthy, ServiceHealthStatus::from('healthy'));
        self::assertSame(ServiceHealthStatus::Degraded, ServiceHealthStatus::from('degraded'));
    }
}
