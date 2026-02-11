<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Console\Event\Payload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Event\Payload\JobPayload;

#[CoversClass(JobPayload::class)]
final class JobPayloadTest extends TestCase
{
    #[Test]
    public function eventTypeReturnsJobQueuedForQueuedStatus(): void
    {
        $payload = new JobPayload(jobClass: 'App\\Jobs\\SendEmail', status: 'queued');

        self::assertSame(EventType::JobQueued, $payload->eventType());
    }

    #[Test]
    public function eventTypeReturnsJobProcessingForProcessingStatus(): void
    {
        $payload = new JobPayload(jobClass: 'App\\Jobs\\SendEmail', status: 'processing');

        self::assertSame(EventType::JobProcessing, $payload->eventType());
    }

    #[Test]
    public function eventTypeReturnsJobCompletedForCompletedStatus(): void
    {
        $payload = new JobPayload(jobClass: 'App\\Jobs\\SendEmail', status: 'completed');

        self::assertSame(EventType::JobCompleted, $payload->eventType());
    }

    #[Test]
    public function eventTypeReturnsJobFailedForFailedStatus(): void
    {
        $payload = new JobPayload(jobClass: 'App\\Jobs\\SendEmail', status: 'failed');

        self::assertSame(EventType::JobFailed, $payload->eventType());
    }

    #[Test]
    public function eventTypeReturnsJobFailedForUnknownStatus(): void
    {
        $payload = new JobPayload(jobClass: 'App\\Jobs\\SendEmail', status: 'unknown');

        self::assertSame(EventType::JobFailed, $payload->eventType());
    }

    #[Test]
    public function schemaVersionReturnsV1(): void
    {
        $payload = new JobPayload(jobClass: 'App\\Jobs\\SendEmail', status: 'queued');

        self::assertSame(EventVersion::V1, $payload->schemaVersion());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $payload = new JobPayload(
            jobClass: 'App\\Jobs\\SendEmail',
            status: 'completed',
            queue: 'emails',
            durationMs: 150.5,
            errorMessage: null,
            attempts: 2,
            connection: 'redis',
        );

        $data = $payload->toArray();

        self::assertSame('App\\Jobs\\SendEmail', $data['job_class']);
        self::assertSame('completed', $data['status']);
        self::assertSame('emails', $data['queue']);
        self::assertSame(150.5, $data['duration_ms']);
        self::assertNull($data['error_message']);
        self::assertSame(2, $data['attempts']);
        self::assertSame('redis', $data['connection']);
    }
}
