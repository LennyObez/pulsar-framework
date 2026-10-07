<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Event\Payload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Event\Payload\RuntimeSchedulerMetricPayload;

#[CoversClass(RuntimeSchedulerMetricPayload::class)]
final class RuntimeSchedulerMetricPayloadTest extends TestCase
{
    #[Test]
    public function eventTypeReturnsRuntimeSchedulerMetric(): void
    {
        self::assertSame(EventType::RuntimeSchedulerMetric, $this->createPayload()->eventType());
    }

    #[Test]
    public function schemaVersionReturnsV1(): void
    {
        self::assertSame(EventVersion::V1, $this->createPayload()->schemaVersion());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $array = $this->createPayload()->toArray();

        self::assertSame(5, $array['active_fibers']);
        self::assertSame(100, $array['total_spawned']);
        self::assertSame(95, $array['total_completed']);
        self::assertSame(3600.0, $array['uptime_seconds']);
    }

    #[Test]
    public function zeroValuesAreValid(): void
    {
        $payload = new RuntimeSchedulerMetricPayload(
            activeFibers: 0,
            totalSpawned: 0,
            totalCompleted: 0,
            uptimeSeconds: 0.0,
        );

        $array = $payload->toArray();

        self::assertSame(0, $array['active_fibers']);
        self::assertSame(0, $array['total_spawned']);
        self::assertSame(0.0, $array['uptime_seconds']);
    }

    private function createPayload(): RuntimeSchedulerMetricPayload
    {
        return new RuntimeSchedulerMetricPayload(
            activeFibers: 5,
            totalSpawned: 100,
            totalCompleted: 95,
            uptimeSeconds: 3600.0,
        );
    }
}
