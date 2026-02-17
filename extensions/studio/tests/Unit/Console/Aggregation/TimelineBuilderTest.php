<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Aggregation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Aggregation\TimelineBuilder;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;

#[CoversClass(TimelineBuilder::class)]
final class TimelineBuilderTest extends TestCase
{
    #[Test]
    public function buildSortsByTimestampAscending(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $builder = new TimelineBuilder($store);

        $events = [
            ['event_id' => 'e3', 'timestamp_us' => 3000],
            ['event_id' => 'e1', 'timestamp_us' => 1000],
            ['event_id' => 'e2', 'timestamp_us' => 2000],
        ];

        $result = $builder->build($events);

        self::assertSame('e1', $result[0]['event_id']);
        self::assertSame('e2', $result[1]['event_id']);
        self::assertSame('e3', $result[2]['event_id']);
    }

    #[Test]
    public function buildHandlesEmptyList(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $builder = new TimelineBuilder($store);

        self::assertSame([], $builder->build([]));
    }

    #[Test]
    public function buildHandlesStringTimestamps(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $builder = new TimelineBuilder($store);

        $events = [
            ['event_id' => 'e2', 'timestamp_us' => '2000'],
            ['event_id' => 'e1', 'timestamp_us' => '1000'],
        ];

        $result = $builder->build($events);

        self::assertSame('e1', $result[0]['event_id']);
    }

    #[Test]
    public function buildHandlesMissingTimestamp(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $builder = new TimelineBuilder($store);

        $events = [
            ['event_id' => 'e2', 'timestamp_us' => 2000],
            ['event_id' => 'e1'],
        ];

        $result = $builder->build($events);

        self::assertSame('e1', $result[0]['event_id']);
        self::assertSame('e2', $result[1]['event_id']);
    }

    #[Test]
    public function forRequestDelegatesToStoreAndSorts(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([
            ['event_id' => 'e2', 'timestamp_us' => 2000],
            ['event_id' => 'e1', 'timestamp_us' => 1000],
        ]);

        $builder = new TimelineBuilder($store);
        $result = $builder->forRequest('req-123');

        self::assertSame('e1', $result[0]['event_id']);
        self::assertSame('e2', $result[1]['event_id']);
    }

    #[Test]
    public function forJobDelegatesToStore(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([
            ['event_id' => 'j1', 'timestamp_us' => 1000],
        ]);

        $builder = new TimelineBuilder($store);
        $result = $builder->forJob('job-abc');

        self::assertCount(1, $result);
        self::assertSame('j1', $result[0]['event_id']);
    }

    #[Test]
    public function forTraceDelegatesToStore(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $builder = new TimelineBuilder($store);

        self::assertSame([], $builder->forTrace('trace-xyz'));
    }

    #[Test]
    public function forCorrelationMergesWithoutDuplicates(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $callCount = 0;
        $store->method('query')->willReturnCallback(function () use (&$callCount): array {
            $callCount++;
            if ($callCount === 1) {
                return [
                    ['event_id' => 'e1', 'timestamp_us' => 1000],
                    ['event_id' => 'e2', 'timestamp_us' => 2000],
                ];
            }
            return [
                ['event_id' => 'e2', 'timestamp_us' => 2000],
                ['event_id' => 'e3', 'timestamp_us' => 3000],
            ];
        });

        $builder = new TimelineBuilder($store);
        $result = $builder->forCorrelation(requestId: 'req-1', jobId: 'job-1');

        self::assertCount(3, $result);
        self::assertSame('e1', $result[0]['event_id']);
        self::assertSame('e2', $result[1]['event_id']);
        self::assertSame('e3', $result[2]['event_id']);
    }

    #[Test]
    public function forCorrelationWithNullParameters(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $builder = new TimelineBuilder($store);

        $result = $builder->forCorrelation();

        self::assertSame([], $result);
    }
}
