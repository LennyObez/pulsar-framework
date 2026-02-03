<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Event;

use function count;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Studio\Console\Event\EventType;

#[CoversClass(EventType::class)]
final class EventTypeTest extends TestCase
{
    #[Test]
    public function httpRequestCaseExists(): void
    {
        self::assertSame('http.request', EventType::HttpRequest->value);
    }

    #[Test]
    public function httpResponseCaseExists(): void
    {
        self::assertSame('http.response', EventType::HttpResponse->value);
    }

    #[Test]
    public function databaseQueryCaseExists(): void
    {
        self::assertSame('db.query', EventType::DatabaseQuery->value);
    }

    #[Test]
    public function cacheOperationCaseExists(): void
    {
        self::assertSame('cache.operation', EventType::CacheOperation->value);
    }

    #[Test]
    public function jobQueuedCaseExists(): void
    {
        self::assertSame('job.queued', EventType::JobQueued->value);
    }

    #[Test]
    public function jobProcessingCaseExists(): void
    {
        self::assertSame('job.processing', EventType::JobProcessing->value);
    }

    #[Test]
    public function jobCompletedCaseExists(): void
    {
        self::assertSame('job.completed', EventType::JobCompleted->value);
    }

    #[Test]
    public function jobFailedCaseExists(): void
    {
        self::assertSame('job.failed', EventType::JobFailed->value);
    }

    #[Test]
    public function schedulerRunCaseExists(): void
    {
        self::assertSame('scheduler.run', EventType::SchedulerRun->value);
    }

    #[Test]
    public function outgoingHttpCaseExists(): void
    {
        self::assertSame('outgoing_http.request', EventType::OutgoingHttp->value);
    }

    #[Test]
    public function notificationCaseExists(): void
    {
        self::assertSame('notification.sent', EventType::Notification->value);
    }

    #[Test]
    public function exceptionCaseExists(): void
    {
        self::assertSame('exception', EventType::Exception->value);
    }

    #[Test]
    public function logEntryCaseExists(): void
    {
        self::assertSame('log.entry', EventType::LogEntry->value);
    }

    #[Test]
    public function featureFlagEvalCaseExists(): void
    {
        self::assertSame('feature_flag.eval', EventType::FeatureFlagEval->value);
    }

    #[Test]
    public function heartbeatCaseExists(): void
    {
        self::assertSame('heartbeat', EventType::Heartbeat->value);
    }

    #[Test]
    public function allCasesHaveUniqueValues(): void
    {
        $values = array_map(
            fn(EventType $type) => $type->value,
            EventType::cases(),
        );

        self::assertCount(count(EventType::cases()), array_unique($values));
    }

    #[Test]
    public function totalCaseCount(): void
    {
        // If this fails, a new case was added - update the test accordingly
        self::assertCount(20, EventType::cases());
    }

    #[Test]
    #[DataProvider('eventTypeProvider')]
    public function eventTypeCanBeCreatedFromString(string $value, EventType $expectedType): void
    {
        $type = EventType::from($value);

        self::assertSame($expectedType, $type);
    }

    /**
     * @return iterable<string, array{string, EventType}>
     */
    public static function eventTypeProvider(): iterable
    {
        yield 'http.request' => ['http.request', EventType::HttpRequest];
        yield 'http.response' => ['http.response', EventType::HttpResponse];
        yield 'db.query' => ['db.query', EventType::DatabaseQuery];
        yield 'cache.operation' => ['cache.operation', EventType::CacheOperation];
        yield 'job.queued' => ['job.queued', EventType::JobQueued];
        yield 'job.processing' => ['job.processing', EventType::JobProcessing];
        yield 'job.completed' => ['job.completed', EventType::JobCompleted];
        yield 'job.failed' => ['job.failed', EventType::JobFailed];
        yield 'scheduler.run' => ['scheduler.run', EventType::SchedulerRun];
        yield 'outgoing_http.request' => ['outgoing_http.request', EventType::OutgoingHttp];
        yield 'notification.sent' => ['notification.sent', EventType::Notification];
        yield 'exception' => ['exception', EventType::Exception];
        yield 'log.entry' => ['log.entry', EventType::LogEntry];
        yield 'feature_flag.eval' => ['feature_flag.eval', EventType::FeatureFlagEval];
        yield 'heartbeat' => ['heartbeat', EventType::Heartbeat];
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        $result = EventType::tryFrom('invalid.type');

        self::assertNull($result);
    }

    #[Test]
    public function tryFromReturnsTypeForValidValue(): void
    {
        $result = EventType::tryFrom('http.request');

        self::assertSame(EventType::HttpRequest, $result);
    }

    #[Test]
    public function enumIsStringBacked(): void
    {
        // Verify enum is string-backed by checking value type
        foreach (EventType::cases() as $case) {
            self::assertIsString($case->value);
        }
    }

    #[Test]
    public function casesCanBeIterated(): void
    {
        $cases = EventType::cases();

        self::assertNotEmpty($cases);

        foreach ($cases as $case) {
            self::assertInstanceOf(EventType::class, $case);
        }
    }

    #[Test]
    public function valuePropertyReturnsStringValue(): void
    {
        $type = EventType::HttpRequest;

        self::assertIsString($type->value);
        self::assertSame('http.request', $type->value);
    }

    #[Test]
    public function namePropertyReturnsEnumName(): void
    {
        $type = EventType::HttpRequest;

        self::assertSame('HttpRequest', $type->name);
    }

    #[Test]
    public function httpRelatedTypesHaveHttpPrefix(): void
    {
        self::assertStringStartsWith('http.', EventType::HttpRequest->value);
        self::assertStringStartsWith('http.', EventType::HttpResponse->value);
    }

    #[Test]
    public function jobRelatedTypesHaveJobPrefix(): void
    {
        self::assertStringStartsWith('job.', EventType::JobQueued->value);
        self::assertStringStartsWith('job.', EventType::JobProcessing->value);
        self::assertStringStartsWith('job.', EventType::JobCompleted->value);
        self::assertStringStartsWith('job.', EventType::JobFailed->value);
    }
}
