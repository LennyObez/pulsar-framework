<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\ErrorTracking;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\ErrorTracking\ErrorEvent;
use Pulsar\Observability\Tracing\TraceId;
use RuntimeException;

#[CoversClass(ErrorEvent::class)]
final class ErrorEventTest extends TestCase
{
    #[Test]
    public function fromThrowableCreatesEvent(): void
    {
        $exception = new RuntimeException('test error');
        $event = ErrorEvent::fromThrowable($exception);

        self::assertSame(RuntimeException::class, $event->exceptionClass);
        self::assertSame('test error', $event->message);
        self::assertSame(__FILE__, $event->file);
        self::assertNotEmpty($event->stackTrace);
        self::assertNull($event->traceId);
    }

    #[Test]
    public function fromThrowableIncludesContextAndTraceId(): void
    {
        $exception = new RuntimeException('test');
        $traceId = TraceId::generate();

        $event = ErrorEvent::fromThrowable(
            $exception,
            ['user_id' => 42],
            $traceId,
        );

        self::assertSame(42, $event->context['user_id']);
        self::assertSame($traceId->value, $event->traceId?->value);
    }

    #[Test]
    public function stackTraceContainsExpectedFrames(): void
    {
        $exception = new RuntimeException('trace test');
        $event = ErrorEvent::fromThrowable($exception);

        self::assertNotEmpty($event->stackTrace);
        self::assertArrayHasKey('file', $event->stackTrace[0]);
        self::assertArrayHasKey('line', $event->stackTrace[0]);
    }
}
