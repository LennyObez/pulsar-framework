<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Tracing\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Tracing\Exception\TracingException;
use RuntimeException;

#[CoversClass(TracingException::class)]
final class TracingExceptionTest extends TestCase
{
    #[Test]
    public function invalidTraceIdIncludesValue(): void
    {
        $exception = TracingException::invalidTraceId('tooshort');

        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertStringContainsString('tooshort', $exception->getMessage());
        self::assertStringContainsString('32 hex', $exception->getMessage());
    }

    #[Test]
    public function invalidSpanIdIncludesValue(): void
    {
        $exception = TracingException::invalidSpanId('bad');

        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertStringContainsString('bad', $exception->getMessage());
        self::assertStringContainsString('16 hex', $exception->getMessage());
    }

    #[Test]
    public function factoriesReturnDistinctInstances(): void
    {
        $a = TracingException::invalidTraceId('a');
        $b = TracingException::invalidTraceId('b');

        self::assertNotSame($a, $b);
    }

    #[Test]
    public function invalidSpanIdAndTraceIdAreDifferentMessages(): void
    {
        $trace = TracingException::invalidTraceId('x');
        $span = TracingException::invalidSpanId('x');

        self::assertNotSame($trace->getMessage(), $span->getMessage());
    }
}
