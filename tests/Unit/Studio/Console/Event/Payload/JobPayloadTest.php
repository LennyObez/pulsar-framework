<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Event\Payload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Event\Payload\JobPayload;

#[CoversClass(JobPayload::class)]
final class JobPayloadTest extends TestCase
{
    #[Test]
    public function implementsConsoleEventInterface(): void
    {
        $payload = new JobPayload(
            jobClass: 'App\\Jobs\\SendEmail',
            status: 'completed',
        );

        self::assertInstanceOf(ConsoleEvent::class, $payload);
    }

    #[Test]
    public function constructorSetsRequiredProperties(): void
    {
        $payload = new JobPayload(
            jobClass: 'App\\Jobs\\ProcessPayment',
            status: 'processing',
        );

        self::assertSame('App\\Jobs\\ProcessPayment', $payload->jobClass);
        self::assertSame('processing', $payload->status);
    }

    #[Test]
    public function constructorSetsOptionalPropertiesToDefaults(): void
    {
        $payload = new JobPayload(
            jobClass: 'App\\Jobs\\SendEmail',
            status: 'queued',
        );

        self::assertNull($payload->queue);
        self::assertNull($payload->durationMs);
        self::assertNull($payload->errorMessage);
        self::assertSame(1, $payload->attempts);
        self::assertNull($payload->connection);
    }

    #[Test]
    public function constructorSetsAllOptionalProperties(): void
    {
        $payload = new JobPayload(
            jobClass: 'App\\Jobs\\GenerateReport',
            status: 'failed',
            queue: 'high',
            durationMs: 1500.75,
            errorMessage: 'Connection timeout',
            attempts: 3,
            connection: 'redis',
        );

        self::assertSame('App\\Jobs\\GenerateReport', $payload->jobClass);
        self::assertSame('failed', $payload->status);
        self::assertSame('high', $payload->queue);
        self::assertSame(1500.75, $payload->durationMs);
        self::assertSame('Connection timeout', $payload->errorMessage);
        self::assertSame(3, $payload->attempts);
        self::assertSame('redis', $payload->connection);
    }

    #[Test]
    public function eventTypeReturnsJobQueuedForQueuedStatus(): void
    {
        $payload = new JobPayload(
            jobClass: 'App\\Jobs\\SendEmail',
            status: 'queued',
        );

        self::assertSame(EventType::JobQueued, $payload->eventType());
    }

    #[Test]
    public function eventTypeReturnsJobProcessingForProcessingStatus(): void
    {
        $payload = new JobPayload(
            jobClass: 'App\\Jobs\\SendEmail',
            status: 'processing',
        );

        self::assertSame(EventType::JobProcessing, $payload->eventType());
    }

    #[Test]
    public function eventTypeReturnsJobCompletedForCompletedStatus(): void
    {
        $payload = new JobPayload(
            jobClass: 'App\\Jobs\\SendEmail',
            status: 'completed',
        );

        self::assertSame(EventType::JobCompleted, $payload->eventType());
    }

    #[Test]
    public function eventTypeReturnsJobFailedForFailedStatus(): void
    {
        $payload = new JobPayload(
            jobClass: 'App\\Jobs\\SendEmail',
            status: 'failed',
        );

        self::assertSame(EventType::JobFailed, $payload->eventType());
    }

    #[Test]
    public function eventTypeReturnsJobFailedForUnknownStatus(): void
    {
        $payload = new JobPayload(
            jobClass: 'App\\Jobs\\SendEmail',
            status: 'unknown_status',
        );

        self::assertSame(EventType::JobFailed, $payload->eventType());
    }

    #[Test]
    public function schemaVersionReturnsV1(): void
    {
        $payload = new JobPayload(
            jobClass: 'App\\Jobs\\SendEmail',
            status: 'queued',
        );

        self::assertSame(EventVersion::V1, $payload->schemaVersion());
    }

    #[Test]
    public function toArrayContainsAllExpectedKeys(): void
    {
        $payload = new JobPayload(
            jobClass: 'App\\Jobs\\SendEmail',
            status: 'completed',
        );

        $array = $payload->toArray();

        self::assertArrayHasKey('job_class', $array);
        self::assertArrayHasKey('status', $array);
        self::assertArrayHasKey('queue', $array);
        self::assertArrayHasKey('duration_ms', $array);
        self::assertArrayHasKey('error_message', $array);
        self::assertArrayHasKey('attempts', $array);
        self::assertArrayHasKey('connection', $array);
    }

    #[Test]
    public function toArrayReturnsCorrectValues(): void
    {
        $payload = new JobPayload(
            jobClass: 'App\\Jobs\\ProcessPayment',
            status: 'failed',
            queue: 'critical',
            durationMs: 250.5,
            errorMessage: 'Insufficient funds',
            attempts: 2,
            connection: 'database',
        );

        $array = $payload->toArray();

        self::assertSame('App\\Jobs\\ProcessPayment', $array['job_class']);
        self::assertSame('failed', $array['status']);
        self::assertSame('critical', $array['queue']);
        self::assertSame(250.5, $array['duration_ms']);
        self::assertSame('Insufficient funds', $array['error_message']);
        self::assertSame(2, $array['attempts']);
        self::assertSame('database', $array['connection']);
    }

    #[Test]
    public function toArrayHandlesNullOptionalFields(): void
    {
        $payload = new JobPayload(
            jobClass: 'App\\Jobs\\Cleanup',
            status: 'queued',
        );

        $array = $payload->toArray();

        self::assertNull($array['queue']);
        self::assertNull($array['duration_ms']);
        self::assertNull($array['error_message']);
        self::assertSame(1, $array['attempts']);
        self::assertNull($array['connection']);
    }

    #[Test]
    public function handlesZeroDuration(): void
    {
        $payload = new JobPayload(
            jobClass: 'App\\Jobs\\NoOp',
            status: 'completed',
            durationMs: 0.0,
        );

        self::assertSame(0.0, $payload->durationMs);
        self::assertSame(0.0, $payload->toArray()['duration_ms']);
    }

    #[Test]
    public function handlesZeroAttempts(): void
    {
        $payload = new JobPayload(
            jobClass: 'App\\Jobs\\NoOp',
            status: 'queued',
            attempts: 0,
        );

        self::assertSame(0, $payload->attempts);
        self::assertSame(0, $payload->toArray()['attempts']);
    }

    #[Test]
    public function handlesDifferentQueueNames(): void
    {
        $queues = ['default', 'high', 'low', 'critical', 'emails', 'reports'];

        foreach ($queues as $queue) {
            $payload = new JobPayload(
                jobClass: 'App\\Jobs\\Test',
                status: 'queued',
                queue: $queue,
            );

            self::assertSame($queue, $payload->queue);
            self::assertSame($queue, $payload->toArray()['queue']);
        }
    }

    #[Test]
    public function handlesDifferentConnectionNames(): void
    {
        $connections = ['redis', 'database', 'sqs', 'beanstalkd', 'sync'];

        foreach ($connections as $connection) {
            $payload = new JobPayload(
                jobClass: 'App\\Jobs\\Test',
                status: 'processing',
                connection: $connection,
            );

            self::assertSame($connection, $payload->connection);
            self::assertSame($connection, $payload->toArray()['connection']);
        }
    }
}
