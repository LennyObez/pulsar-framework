<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Failover;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Failover\FailoverConfig;

#[CoversClass(FailoverConfig::class)]
final class FailoverConfigTest extends TestCase
{
    #[Test]
    public function fromArrayWithDefaults(): void
    {
        $config = FailoverConfig::fromArray([]);

        self::assertFalse($config->enabled);
        self::assertSame(3, $config->failureThreshold);
        self::assertSame(5, $config->retryIntervalSeconds);
        self::assertSame('dns', $config->strategy);
        self::assertFalse($config->complianceEventsEnabled);
    }

    #[Test]
    public function fromArrayWithCustomValues(): void
    {
        $config = FailoverConfig::fromArray([
            'enabled' => true,
            'failure_threshold' => 5,
            'retry_interval_seconds' => 10,
            'strategy' => 'callback',
            'compliance_events_enabled' => true,
        ]);

        self::assertTrue($config->enabled);
        self::assertSame(5, $config->failureThreshold);
        self::assertSame(10, $config->retryIntervalSeconds);
        self::assertSame('callback', $config->strategy);
        self::assertTrue($config->complianceEventsEnabled);
    }
}
