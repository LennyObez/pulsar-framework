<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Console\Event\Payload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Event\Payload\SchedulerRunPayload;

#[CoversClass(SchedulerRunPayload::class)]
final class SchedulerRunPayloadTest extends TestCase
{
    #[Test]
    public function eventTypeReturnsSchedulerRun(): void
    {
        $payload = new SchedulerRunPayload(
            jobName: 'cleanup:logs',
            status: 'completed',
            durationMs: 250.0,
        );

        self::assertSame(EventType::SchedulerRun, $payload->eventType());
    }

    #[Test]
    public function schemaVersionReturnsV1(): void
    {
        $payload = new SchedulerRunPayload(
            jobName: 'test',
            status: 'completed',
            durationMs: 0.0,
        );

        self::assertSame(EventVersion::V1, $payload->schemaVersion());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $payload = new SchedulerRunPayload(
            jobName: 'send:reports',
            status: 'failed',
            durationMs: 5000.0,
            errorMessage: 'Connection timeout',
            missed: true,
        );

        $data = $payload->toArray();

        self::assertSame('send:reports', $data['job_name']);
        self::assertSame('failed', $data['status']);
        self::assertSame(5000.0, $data['duration_ms']);
        self::assertSame('Connection timeout', $data['error_message']);
        self::assertTrue($data['missed']);
    }
}
