<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\ObservabilityExport\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\ObservabilityExport\Schema\SpanSchema;
use Pulsar\Observability\Tracing\Span;
use Pulsar\Observability\Tracing\SpanId;
use Pulsar\Observability\Tracing\SpanStatus;
use Pulsar\Observability\Tracing\TraceContext;
use Pulsar\Observability\Tracing\TraceId;

#[CoversClass(SpanSchema::class)]
final class SpanSchemaTest extends TestCase
{
    #[Test]
    public function toArrayIncludesSchemaVersion(): void
    {
        $span = $this->createSpan('test-op');
        $span->end();

        $data = SpanSchema::toArray($span);

        self::assertSame('1.0.0', $data['schema_version']);
        self::assertSame('test-op', $data['name']);
        self::assertArrayHasKey('trace_id', $data);
        self::assertArrayHasKey('span_id', $data);
        self::assertArrayHasKey('start_time_ns', $data);
        self::assertArrayHasKey('end_time_ns', $data);
        self::assertArrayHasKey('duration_ns', $data);
    }

    #[Test]
    public function toArrayIncludesParentSpanId(): void
    {
        $parentSpanId = new SpanId('aaaaaaaaaaaaaaaa');
        $context = new TraceContext(
            new TraceId('0af7651916cd43dd8448eb211c80319c'),
            new SpanId('b7ad6b7169203331'),
        );

        $span = new Span('child-op', $context, $parentSpanId);
        $span->end();

        $data = SpanSchema::toArray($span);

        self::assertSame('aaaaaaaaaaaaaaaa', $data['parent_span_id']);
    }

    #[Test]
    public function toArrayWithNullParentSpanId(): void
    {
        $span = $this->createSpan('root-op');
        $span->end();

        $data = SpanSchema::toArray($span);

        self::assertNull($data['parent_span_id']);
    }

    #[Test]
    public function toArrayIncludesAttributes(): void
    {
        $span = $this->createSpan('attributed-op');
        $span->setAttribute('http.method', 'POST');
        $span->setAttribute('http.status_code', 201);
        $span->end();

        $data = SpanSchema::toArray($span);

        /** @var array<string, mixed> $attrs */
        $attrs = $data['attributes'];
        self::assertSame('POST', $attrs['http.method']);
        self::assertSame(201, $attrs['http.status_code']);
    }

    #[Test]
    public function toJsonProducesValidJson(): void
    {
        $span = $this->createSpan('json-op');
        $span->status = SpanStatus::Ok;
        $span->end();

        $json = SpanSchema::toJson($span);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('json-op', $decoded['name']);
        self::assertSame('ok', $decoded['status']);
        self::assertStringNotContainsString('\\/', $json);
    }

    private function createSpan(string $name): Span
    {
        $context = new TraceContext(
            new TraceId('0af7651916cd43dd8448eb211c80319c'),
            new SpanId('b7ad6b7169203331'),
        );

        return new Span($name, $context);
    }
}
