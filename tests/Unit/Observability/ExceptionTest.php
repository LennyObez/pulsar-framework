<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\ErrorTracking\Exception\ErrorTrackingException;
use Pulsar\Observability\Log\Exception\LogException;
use Pulsar\Observability\Metrics\Exception\MetricsException;
use Pulsar\Observability\Tracing\Exception\TracingException;

#[CoversClass(ErrorTrackingException::class)]
#[CoversClass(LogException::class)]
#[CoversClass(MetricsException::class)]
#[CoversClass(TracingException::class)]
final class ExceptionTest extends TestCase
{
    #[Test]
    public function errorTrackingMaxGroupsExceeded(): void
    {
        $e = ErrorTrackingException::maxGroupsExceeded(500);

        self::assertStringContainsString('500', $e->getMessage());
    }

    #[Test]
    public function logSinkWriteFailed(): void
    {
        $e = LogException::sinkWriteFailed('file', 'permission denied');

        self::assertStringContainsString('file', $e->getMessage());
        self::assertStringContainsString('permission denied', $e->getMessage());
    }

    #[Test]
    public function logInvalidDriver(): void
    {
        $e = LogException::invalidDriver('unknown');

        self::assertStringContainsString('unknown', $e->getMessage());
    }

    #[Test]
    public function metricsNegativeIncrement(): void
    {
        $e = MetricsException::negativeIncrement(-1.5);

        self::assertStringContainsString('-1.5', $e->getMessage());
    }

    #[Test]
    public function metricsTypeMismatch(): void
    {
        $e = MetricsException::typeMismatch('http_requests', 'counter', 'gauge');

        self::assertStringContainsString('http_requests', $e->getMessage());
        self::assertStringContainsString('counter', $e->getMessage());
        self::assertStringContainsString('gauge', $e->getMessage());
    }

    #[Test]
    public function tracingInvalidTraceId(): void
    {
        $e = TracingException::invalidTraceId('abc');

        self::assertStringContainsString('abc', $e->getMessage());
        self::assertStringContainsString('32 hex', $e->getMessage());
    }

    #[Test]
    public function tracingInvalidSpanId(): void
    {
        $e = TracingException::invalidSpanId('xyz');

        self::assertStringContainsString('xyz', $e->getMessage());
        self::assertStringContainsString('16 hex', $e->getMessage());
    }
}
