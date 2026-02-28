<?php

declare(strict_types=1);

namespace Pulsar\Extension\ObservabilityExport\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\ObservabilityExport\Schema\SpanSchema;
use Pulsar\Observability\Tracing\Span;
use Pulsar\Observability\Tracing\SpanId;
use Pulsar\Observability\Tracing\SpanStatus;
use Pulsar\Observability\Tracing\TraceContext;
use Pulsar\Observability\Tracing\TraceId;

final class SpanSchemaTest extends TestCase
{
    #[Test]
    public function toArrayIncludesSchemaVersion(): void
    {
        $span = $this->createSpan();
        $array = SpanSchema::toArray($span);

        self::assertSame('1.0.0', $array['schema_version']);
    }

    #[Test]
    public function toArrayIncludesTraceAndSpanIds(): void
    {
        $span = $this->createSpan();
        $array = SpanSchema::toArray($span);

        self::assertSame('a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4', $array['trace_id']);
        self::assertSame('1234567890abcdef', $array['span_id']);
    }

    #[Test]
    public function toArrayIncludesNullParentSpanIdWhenAbsent(): void
    {
        $span = $this->createSpan();
        $array = SpanSchema::toArray($span);

        self::assertNull($array['parent_span_id']);
    }

    #[Test]
    public function toArrayIncludesParentSpanIdWhenPresent(): void
    {
        $parentSpanId = new SpanId('fedcba0987654321');
        $span = $this->createSpan(parentSpanId: $parentSpanId);
        $array = SpanSchema::toArray($span);

        self::assertSame('fedcba0987654321', $array['parent_span_id']);
    }

    #[Test]
    public function toArrayIncludesSpanNameAndStatus(): void
    {
        $span = $this->createSpan();
        $span->status = SpanStatus::Ok;
        $array = SpanSchema::toArray($span);

        self::assertSame('test.operation', $array['name']);
        self::assertSame('ok', $array['status']);
    }

    #[Test]
    public function toArrayIncludesTimingFields(): void
    {
        $span = $this->createSpan();
        $span->end();
        $array = SpanSchema::toArray($span);

        self::assertIsInt($array['start_time_ns']);
        self::assertIsInt($array['end_time_ns']);
        self::assertIsInt($array['duration_ns']);
        self::assertGreaterThanOrEqual(0, $array['duration_ns']);
    }

    #[Test]
    public function toArrayIncludesAttributes(): void
    {
        $span = $this->createSpan();
        $span->setAttribute('http.method', 'GET');
        $span->setAttribute('http.status_code', 200);
        $array = SpanSchema::toArray($span);

        self::assertSame(['http.method' => 'GET', 'http.status_code' => 200], $array['attributes']);
    }

    #[Test]
    public function toJsonReturnsValidJson(): void
    {
        $span = $this->createSpan();
        $span->end();
        $json = SpanSchema::toJson($span);

        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('test.operation', $decoded['name']);
        self::assertSame('1.0.0', $decoded['schema_version']);
    }

    private function createSpan(?SpanId $parentSpanId = null): Span
    {
        $traceContext = new TraceContext(
            traceId: new TraceId('a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4'),
            spanId: new SpanId('1234567890abcdef'),
        );

        return new Span(
            name: 'test.operation',
            context: $traceContext,
            parentSpanId: $parentSpanId,
        );
    }
}
