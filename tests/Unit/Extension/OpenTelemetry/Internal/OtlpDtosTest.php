<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\OpenTelemetry\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Internal\Exception\OpenTelemetryException;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\OtlpFieldNumbers;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\OtlpLogRecord;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\OtlpMetric;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\OtlpSpan;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\ResourceInfo;
use Pulsar\Extension\OpenTelemetry\Internal\Transport\TransportResult;
use Pulsar\Observability\Metrics\MetricType;

#[CoversClass(OtlpSpan::class)]
#[CoversClass(OtlpLogRecord::class)]
#[CoversClass(OtlpMetric::class)]
#[CoversClass(OtlpFieldNumbers::class)]
#[CoversClass(ResourceInfo::class)]
#[CoversClass(TransportResult::class)]
#[CoversClass(OpenTelemetryException::class)]
final class OtlpDtosTest extends TestCase
{
    // --- OtlpSpan ---

    #[Test]
    public function otlpSpanConstruction(): void
    {
        $span = new OtlpSpan(
            traceId: str_repeat("\x01", 16),
            spanId: str_repeat("\x02", 8),
            parentSpanId: str_repeat("\x03", 8),
            name: 'POST /api/v1/accounts/transfer',
            startTimeUnixNano: 1_709_827_200_000_000_000,
            endTimeUnixNano: 1_709_827_200_050_000_000,
            attributes: ['http.method' => 'POST', 'http.status_code' => 200],
            statusCode: OtlpFieldNumbers::STATUS_CODE_OK,
            statusMessage: '',
            kind: OtlpFieldNumbers::SPAN_KIND_INTERNAL,
        );

        self::assertSame(str_repeat("\x01", 16), $span->traceId);
        self::assertSame(str_repeat("\x02", 8), $span->spanId);
        self::assertSame(str_repeat("\x03", 8), $span->parentSpanId);
        self::assertSame('POST /api/v1/accounts/transfer', $span->name);
        self::assertSame(1_709_827_200_000_000_000, $span->startTimeUnixNano);
        self::assertSame(1_709_827_200_050_000_000, $span->endTimeUnixNano);
        self::assertSame(200, $span->attributes['http.status_code']);
        self::assertSame(OtlpFieldNumbers::STATUS_CODE_OK, $span->statusCode);
        self::assertSame(OtlpFieldNumbers::SPAN_KIND_INTERNAL, $span->kind);
    }

    #[Test]
    public function otlpSpanNullParent(): void
    {
        $span = new OtlpSpan(
            traceId: str_repeat("\x01", 16),
            spanId: str_repeat("\x02", 8),
            parentSpanId: null,
            name: 'root-span',
            startTimeUnixNano: 0,
            endTimeUnixNano: 1_000_000,
            attributes: [],
            statusCode: OtlpFieldNumbers::STATUS_CODE_UNSET,
            statusMessage: '',
        );

        self::assertNull($span->parentSpanId);
    }

    // --- OtlpLogRecord ---

    #[Test]
    public function otlpLogRecordConstruction(): void
    {
        $record = new OtlpLogRecord(
            timeUnixNano: 1_709_827_200_000_000_000,
            severityNumber: 13,
            severityText: 'WARN',
            body: 'Unusual transaction pattern detected for account AC-001234',
            attributes: ['account.id' => 'AC-001234', 'transaction.amount' => 50000],
            traceId: str_repeat("\x01", 16),
            spanId: str_repeat("\x02", 8),
        );

        self::assertSame(1_709_827_200_000_000_000, $record->timeUnixNano);
        self::assertSame(13, $record->severityNumber);
        self::assertSame('WARN', $record->severityText);
        self::assertStringContainsString('AC-001234', $record->body);
        self::assertSame('AC-001234', $record->attributes['account.id']);
        self::assertSame(str_repeat("\x01", 16), $record->traceId);
    }

    #[Test]
    public function otlpLogRecordNullTraceContext(): void
    {
        $record = new OtlpLogRecord(
            timeUnixNano: 0,
            severityNumber: 17,
            severityText: 'ERROR',
            body: 'Database connection pool exhausted',
            attributes: [],
            traceId: null,
            spanId: null,
        );

        self::assertNull($record->traceId);
        self::assertNull($record->spanId);
    }

    // --- OtlpMetric ---

    #[Test]
    public function otlpMetricConstruction(): void
    {
        $metric = new OtlpMetric(
            name: 'http.server.request.duration',
            description: 'Duration of inbound HTTP requests',
            unit: 'ms',
            type: MetricType::Histogram,
            dataPoints: [
                ['value' => 45.3, 'count' => 100, 'sum' => 4530.0],
            ],
        );

        self::assertSame('http.server.request.duration', $metric->name);
        self::assertSame('Duration of inbound HTTP requests', $metric->description);
        self::assertSame('ms', $metric->unit);
        self::assertSame(MetricType::Histogram, $metric->type);
        self::assertCount(1, $metric->dataPoints);
    }

    // --- ResourceInfo (protobuf) ---

    #[Test]
    public function protobufResourceInfoConstruction(): void
    {
        $resource = new ResourceInfo(
            attributes: ['service.name' => 'payment-gateway', 'service.version' => '2.1.0'],
        );

        self::assertSame('payment-gateway', $resource->attributes['service.name']);
        self::assertSame('2.1.0', $resource->attributes['service.version']);
    }

    #[Test]
    public function protobufResourceInfoDefaults(): void
    {
        $resource = new ResourceInfo();

        self::assertSame([], $resource->attributes);
    }

    // --- TransportResult ---

    #[Test]
    public function transportResultSuccess(): void
    {
        $result = TransportResult::success(200);

        self::assertTrue($result->success);
        self::assertSame(200, $result->httpStatus);
        self::assertSame('', $result->errorMessage);
        self::assertFalse($result->retryable);
    }

    #[Test]
    public function transportResultFailure(): void
    {
        $result = TransportResult::failure(503, 'Service Unavailable', true);

        self::assertFalse($result->success);
        self::assertSame(503, $result->httpStatus);
        self::assertSame('Service Unavailable', $result->errorMessage);
        self::assertTrue($result->retryable);
    }

    #[Test]
    public function transportResultFailureNotRetryable(): void
    {
        $result = TransportResult::failure(400, 'Bad Request');

        self::assertFalse($result->success);
        self::assertSame(400, $result->httpStatus);
        self::assertFalse($result->retryable);
    }

    // --- OtlpFieldNumbers ---

    #[Test]
    public function fieldNumbersHaveExpectedValues(): void
    {
        self::assertSame(1, OtlpFieldNumbers::RESOURCE_SPANS);
        self::assertSame(1, OtlpFieldNumbers::SPAN_TRACE_ID);
        self::assertSame(2, OtlpFieldNumbers::SPAN_SPAN_ID);
        self::assertSame(5, OtlpFieldNumbers::SPAN_NAME);
        self::assertSame(9, OtlpFieldNumbers::SPAN_ATTRIBUTES);
        self::assertSame(1, OtlpFieldNumbers::SPAN_KIND_INTERNAL);
        self::assertSame(0, OtlpFieldNumbers::STATUS_CODE_UNSET);
        self::assertSame(1, OtlpFieldNumbers::STATUS_CODE_OK);
        self::assertSame(2, OtlpFieldNumbers::STATUS_CODE_ERROR);
        self::assertSame(2, OtlpFieldNumbers::AGGREGATION_TEMPORALITY_CUMULATIVE);
    }

    // --- OpenTelemetryException ---

    #[Test]
    public function exportFailedException(): void
    {
        $exception = OpenTelemetryException::exportFailed('traces', 'connection refused');

        self::assertStringContainsString('traces', $exception->getMessage());
        self::assertStringContainsString('connection refused', $exception->getMessage());
    }

    #[Test]
    public function queueOverflowException(): void
    {
        $exception = OpenTelemetryException::queueOverflow('spans', 2048);

        self::assertStringContainsString('spans', $exception->getMessage());
        self::assertStringContainsString('2048', $exception->getMessage());
    }

    #[Test]
    public function invalidConfigurationException(): void
    {
        $exception = OpenTelemetryException::invalidConfiguration('endpoint URL is required');

        self::assertStringContainsString('endpoint URL is required', $exception->getMessage());
    }
}
