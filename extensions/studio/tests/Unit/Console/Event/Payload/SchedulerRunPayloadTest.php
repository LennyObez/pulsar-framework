<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Event\Payload;

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
        self::assertSame(EventType::SchedulerRun, $this->createPayload()->eventType());
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

        self::assertSame('cleanup:logs', $array['job_name']);
        self::assertSame('success', $array['status']);
        self::assertSame(250.5, $array['duration_ms']);
        self::assertNull($array['error_message']);
        self::assertFalse($array['missed']);
    }

    #[Test]
    public function failedJobWithErrorMessage(): void
    {
        $payload = new SchedulerRunPayload(
            jobName: 'sync:users',
            status: 'failure',
            durationMs: 5000.0,
            errorMessage: 'Connection refused',
            missed: false,
        );

        $array = $payload->toArray();

        self::assertSame('failure', $array['status']);
        self::assertSame('Connection refused', $array['error_message']);
    }

    #[Test]
    public function missedJobFlag(): void
    {
        $payload = new SchedulerRunPayload(
            jobName: 'report:daily',
            status: 'skipped',
            durationMs: 0.0,
            missed: true,
        );

        self::assertTrue($payload->toArray()['missed']);
        self::assertTrue($payload->missed);
    }

    private function createPayload(): SchedulerRunPayload
    {
        return new SchedulerRunPayload(
            jobName: 'cleanup:logs',
            status: 'success',
            durationMs: 250.5,
        );
    }
}
