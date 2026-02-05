<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Api;
use Pulsar\Observability\ErrorTracking\ErrorFingerprint;
use Pulsar\Observability\ErrorTracking\Exception\ErrorTrackingException;
use Pulsar\Observability\Log\Exception\LogException;
use Pulsar\Observability\Log\LogEntry;
use Pulsar\Observability\Log\LogLevel;
use Pulsar\Observability\Log\LogSinkInterface;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Observability\Metrics\MetricType;
use Pulsar\Observability\Tracing\Exception\TracingException;
use Pulsar\Observability\Tracing\Span;
use Pulsar\Observability\Tracing\SpanProcessorInterface;
use Pulsar\Observability\Tracing\SpanStatus;
use Pulsar\Observability\Tracing\TraceContext;

#[CoversClass(Api::class)]
final class ObservabilityApiTest extends TestCase
{
    use ApiAssertionsTrait;

    #[Test]
    public function logSinkInterfaceIsPublicApi(): void
    {
        self::assertHasApiAttribute(LogSinkInterface::class);
    }

    #[Test]
    public function logEntryIsPublicApi(): void
    {
        self::assertHasApiAttribute(LogEntry::class);
        self::assertClassIsReadonly(LogEntry::class);
    }

    #[Test]
    public function logLevelIsPublicApi(): void
    {
        self::assertHasApiAttribute(LogLevel::class);
    }

    #[Test]
    public function spanProcessorInterfaceIsPublicApi(): void
    {
        self::assertHasApiAttribute(SpanProcessorInterface::class);
    }

    #[Test]
    public function spanIsPublicApi(): void
    {
        self::assertHasApiAttribute(Span::class);
    }

    #[Test]
    public function traceContextIsPublicApi(): void
    {
        self::assertHasApiAttribute(TraceContext::class);
    }

    #[Test]
    public function spanStatusIsPublicApi(): void
    {
        self::assertHasApiAttribute(SpanStatus::class);
    }

    #[Test]
    public function metricTypeIsPublicApi(): void
    {
        self::assertHasApiAttribute(MetricType::class);
    }

    #[Test]
    public function metricRegistryIsPublicApi(): void
    {
        self::assertHasApiAttribute(MetricRegistry::class);
    }

    #[Test]
    public function errorFingerprintIsPublicApi(): void
    {
        self::assertHasApiAttribute(ErrorFingerprint::class);
        self::assertClassIsReadonly(ErrorFingerprint::class);
    }

    #[Test]
    public function observabilityExceptionsArePublicApi(): void
    {
        self::assertHasApiAttribute(LogException::class);
        self::assertHasApiAttribute(TracingException::class);
        self::assertHasApiAttribute(ErrorTrackingException::class);
    }
}
