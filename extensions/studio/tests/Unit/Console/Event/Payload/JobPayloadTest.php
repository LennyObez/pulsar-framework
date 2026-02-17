<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Event\Payload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Event\Payload\JobPayload;

#[CoversClass(JobPayload::class)]
final class JobPayloadTest extends TestCase
{
    #[Test]
    #[DataProvider('statusToEventTypeProvider')]
    public function eventTypeMapsStatusCorrectly(string $status, EventType $expected): void
    {
        $payload = new JobPayload(
            jobClass: 'App\\Jobs\\SendEmail',
            status: $status,
        );

        self::assertSame($expected, $payload->eventType());
    }

    /**
     * @return iterable<string, array{string, EventType}>
     */
    public static function statusToEventTypeProvider(): iterable
    {
        yield 'queued' => ['queued', EventType::JobQueued];
        yield 'processing' => ['processing', EventType::JobProcessing];
        yield 'completed' => ['completed', EventType::JobCompleted];
        yield 'failed' => ['failed', EventType::JobFailed];
        yield 'unknown defaults to failed' => ['unknown', EventType::JobFailed];
    }

    #[Test]
    public function schemaVersionReturnsV1(): void
    {
        self::assertSame(EventVersion::V1, $this->createPayload()->schemaVersion());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $payload = $this->createPayload();
        $array = $payload->toArray();

        self::assertSame('App\\Jobs\\SendEmail', $array['job_class']);
        self::assertSame('completed', $array['status']);
        self::assertSame('emails', $array['queue']);
        self::assertSame(150.5, $array['duration_ms']);
        self::assertNull($array['error_message']);
        self::assertSame(1, $array['attempts']);
        self::assertSame('redis', $array['connection']);
    }

    #[Test]
    public function defaultsForOptionalFields(): void
    {
        $payload = new JobPayload(
            jobClass: 'App\\Jobs\\Cleanup',
            status: 'queued',
        );

        self::assertNull($payload->queue);
        self::assertNull($payload->durationMs);
        self::assertNull($payload->errorMessage);
        self::assertSame(1, $payload->attempts);
        self::assertNull($payload->connection);
    }

    #[Test]
    public function failedJobWithErrorMessage(): void
    {
        $payload = new JobPayload(
            jobClass: 'App\\Jobs\\ProcessPayment',
            status: 'failed',
            queue: 'payments',
            durationMs: 5000.0,
            errorMessage: 'Gateway timeout',
            attempts: 3,
            connection: 'sqs',
        );

        $array = $payload->toArray();

        self::assertSame('Gateway timeout', $array['error_message']);
        self::assertSame(3, $array['attempts']);
        self::assertSame(EventType::JobFailed, $payload->eventType());
    }

    private function createPayload(): JobPayload
    {
        return new JobPayload(
            jobClass: 'App\\Jobs\\SendEmail',
            status: 'completed',
            queue: 'emails',
            durationMs: 150.5,
            connection: 'redis',
        );
    }
}
