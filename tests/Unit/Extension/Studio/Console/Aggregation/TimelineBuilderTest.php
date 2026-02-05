<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Console\Aggregation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Aggregation\TimelineBuilder;
use Pulsar\Extension\Studio\Console\Event\EventEnvelope;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Storage\SqliteEventStore;

use function json_encode;
use function microtime;

use const JSON_THROW_ON_ERROR;

#[CoversClass(TimelineBuilder::class)]
final class TimelineBuilderTest extends TestCase
{
    private SqliteEventStore $store;
    private TimelineBuilder $builder;

    protected function setUp(): void
    {
        $this->store = SqliteEventStore::inMemory();
        $this->builder = new TimelineBuilder($this->store);
    }

    private function nowUs(): int
    {
        return (int) (microtime(true) * 1_000_000.0);
    }

    /** @param array<string, mixed> $payload */
    private function insertEvent(
        string $eventType,
        array $payload,
        ?string $requestId = null,
        ?string $jobId = null,
        ?string $traceId = null,
        ?int $timestampUs = null,
    ): string {
        $timestampUs ??= $this->nowUs();
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);
        $eventId = bin2hex(random_bytes(16));

        $envelope = new EventEnvelope(
            eventId: $eventId,
            eventType: EventType::from($eventType),
            schemaVersion: EventVersion::V1,
            timestampUs: $timestampUs,
            requestId: $requestId,
            traceId: $traceId,
            spanId: null,
            jobId: $jobId,
            appEnv: 'test',
            hostname: 'localhost',
            payload: $payload,
            payloadHash: hash('sha256', $payloadJson),
        );

        $this->store->store($envelope, $payloadJson);

        return $eventId;
    }

    #[Test]
    public function buildSortsEventsByTimestamp(): void
    {
        $events = [
            ['timestamp_us' => 3000, 'event_id' => 'c', 'data' => 'third'],
            ['timestamp_us' => 1000, 'event_id' => 'a', 'data' => 'first'],
            ['timestamp_us' => 2000, 'event_id' => 'b', 'data' => 'second'],
        ];

        $result = $this->builder->build($events);

        self::assertCount(3, $result);
        self::assertSame(1000, $result[0]['timestamp_us']);
        self::assertSame(2000, $result[1]['timestamp_us']);
        self::assertSame(3000, $result[2]['timestamp_us']);
    }

    #[Test]
    public function buildHandlesEmptyArray(): void
    {
        $result = $this->builder->build([]);

        self::assertSame([], $result);
    }

    #[Test]
    public function buildHandlesMissingTimestampUs(): void
    {
        $events = [
            ['event_id' => 'a', 'data' => 'no timestamp'],
            ['timestamp_us' => 1000, 'event_id' => 'b', 'data' => 'has timestamp'],
        ];

        $result = $this->builder->build($events);

        self::assertCount(2, $result);
        self::assertSame('a', $result[0]['event_id']);
        self::assertSame('b', $result[1]['event_id']);
    }

    #[Test]
    public function buildHandlesStringTimestampUs(): void
    {
        $events = [
            ['timestamp_us' => '3000', 'event_id' => 'b'],
            ['timestamp_us' => '1000', 'event_id' => 'a'],
        ];

        $result = $this->builder->build($events);

        self::assertSame('a', $result[0]['event_id']);
        self::assertSame('b', $result[1]['event_id']);
    }

    #[Test]
    public function forRequestReturnsEventsForRequestId(): void
    {
        $baseTs = $this->nowUs();
        $this->insertEvent('http.request', ['method' => 'GET'], requestId: 'req-1', timestampUs: $baseTs + 2);
        $this->insertEvent('db.query', ['sql' => 'SELECT 1'], requestId: 'req-1', timestampUs: $baseTs + 1);
        $this->insertEvent('http.request', ['method' => 'POST'], requestId: 'req-2', timestampUs: $baseTs + 3);

        $result = $this->builder->forRequest('req-1');

        self::assertCount(2, $result);
        // Should be sorted by timestamp ascending
        self::assertSame($baseTs + 1, is_numeric($result[0]['timestamp_us']) ? (int) $result[0]['timestamp_us'] : 0);
        self::assertSame($baseTs + 2, is_numeric($result[1]['timestamp_us']) ? (int) $result[1]['timestamp_us'] : 0);
    }

    #[Test]
    public function forJobReturnsEventsForJobId(): void
    {
        $this->insertEvent('job.queued', ['job_class' => 'SendEmail'], jobId: 'job-1');
        $this->insertEvent('job.completed', ['job_class' => 'SendEmail'], jobId: 'job-1');
        $this->insertEvent('job.queued', ['job_class' => 'SendSms'], jobId: 'job-2');

        $result = $this->builder->forJob('job-1');

        self::assertCount(2, $result);
    }

    #[Test]
    public function forTraceReturnsEventsForTraceId(): void
    {
        $this->insertEvent('http.request', ['method' => 'GET'], traceId: 'trace-abc');
        $this->insertEvent('db.query', ['sql' => 'SELECT 1'], traceId: 'trace-abc');
        $this->insertEvent('http.request', ['method' => 'GET'], traceId: 'trace-xyz');

        $result = $this->builder->forTrace('trace-abc');

        self::assertCount(2, $result);
    }

    #[Test]
    public function forCorrelationCombinesMultipleDimensions(): void
    {
        $eventId1 = $this->insertEvent('http.request', ['method' => 'GET'], requestId: 'req-1', traceId: 'trace-1');
        $eventId2 = $this->insertEvent('db.query', ['sql' => 'SELECT 1'], requestId: 'req-1');
        $eventId3 = $this->insertEvent('job.queued', ['job_class' => 'X'], jobId: 'job-1');

        $result = $this->builder->forCorrelation(requestId: 'req-1', jobId: 'job-1', traceId: 'trace-1');

        self::assertCount(3, $result);
    }

    #[Test]
    public function forCorrelationDeduplicatesEvents(): void
    {
        $this->insertEvent('http.request', ['method' => 'GET'], requestId: 'req-1', traceId: 'trace-1');

        // Same event has both request_id and trace_id, should appear once
        $result = $this->builder->forCorrelation(requestId: 'req-1', traceId: 'trace-1');

        self::assertCount(1, $result);
    }

    #[Test]
    public function forCorrelationHandlesAllNullParameters(): void
    {
        $this->insertEvent('http.request', ['method' => 'GET'], requestId: 'req-1');

        $result = $this->builder->forCorrelation();

        self::assertSame([], $result);
    }

    #[Test]
    public function forRequestReturnsEmptyForUnknownId(): void
    {
        $result = $this->builder->forRequest('nonexistent');

        self::assertSame([], $result);
    }

    #[Test]
    public function forJobReturnsEmptyForUnknownId(): void
    {
        $result = $this->builder->forJob('nonexistent');

        self::assertSame([], $result);
    }

    #[Test]
    public function forTraceReturnsEmptyForUnknownId(): void
    {
        $result = $this->builder->forTrace('nonexistent');

        self::assertSame([], $result);
    }
}
