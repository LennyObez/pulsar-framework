<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tests\Unit\Export\Otlp\Protobuf;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Observability\Export\Otlp\Protobuf\AttributeEncoder;
use Pulsar\Extension\Observability\Export\Otlp\Protobuf\OtlpFieldNumbers;
use Pulsar\Extension\Observability\Export\Otlp\Protobuf\OtlpSpan;
use Pulsar\Extension\Observability\Export\Otlp\Protobuf\ProtobufWriter;
use Pulsar\Extension\Observability\Export\Otlp\Protobuf\ResourceInfo;
use Pulsar\Extension\Observability\Export\Otlp\Protobuf\TraceRequestBuilder;

use function hex2bin;
use function strlen;

#[CoversClass(TraceRequestBuilder::class)]
#[CoversClass(AttributeEncoder::class)]
#[CoversClass(OtlpFieldNumbers::class)]
#[CoversClass(ProtobufWriter::class)]
#[CoversClass(OtlpSpan::class)]
#[CoversClass(ResourceInfo::class)]
final class TraceRequestBuilderTest extends TestCase
{
    #[Test]
    public function buildReturnsEmptyStringForNoSpans(): void
    {
        $builder = new TraceRequestBuilder();

        self::assertSame('', $builder->build([], new ResourceInfo()));
    }

    #[Test]
    public function buildProducesNonEmptyBinaryForSingleSpan(): void
    {
        $builder = new TraceRequestBuilder();

        $span = new OtlpSpan(
            traceId: (string) hex2bin('0af7651916cd43dd8448eb211c80319c'),
            spanId: (string) hex2bin('b7ad6b7169203331'),
            parentSpanId: null,
            name: 'test-span',
            kind: OtlpFieldNumbers::SPAN_KIND_INTERNAL,
            startTimeUnixNano: 1_700_000_000_000_000_000,
            endTimeUnixNano: 1_700_000_001_000_000_000,
            attributes: [],
            statusCode: OtlpFieldNumbers::STATUS_CODE_UNSET,
            statusMessage: '',
        );

        $binary = $builder->build([$span], new ResourceInfo());

        self::assertNotSame('', $binary);
        self::assertGreaterThan(0, strlen($binary));
    }

    #[Test]
    public function buildIncludesSpanNameInOutput(): void
    {
        $builder = new TraceRequestBuilder();

        $span = new OtlpSpan(
            traceId: (string) hex2bin('0af7651916cd43dd8448eb211c80319c'),
            spanId: (string) hex2bin('b7ad6b7169203331'),
            parentSpanId: null,
            name: 'HTTP GET /users',
            kind: OtlpFieldNumbers::SPAN_KIND_INTERNAL,
            startTimeUnixNano: 1_700_000_000_000_000_000,
            endTimeUnixNano: 1_700_000_001_000_000_000,
            attributes: [],
            statusCode: OtlpFieldNumbers::STATUS_CODE_UNSET,
            statusMessage: '',
        );

        $binary = $builder->build([$span], new ResourceInfo());

        // The span name should appear in the binary output
        self::assertStringContainsString('HTTP GET /users', $binary);
    }

    #[Test]
    public function buildIncludesResourceAttributes(): void
    {
        $builder = new TraceRequestBuilder();

        $span = new OtlpSpan(
            traceId: (string) hex2bin('0af7651916cd43dd8448eb211c80319c'),
            spanId: (string) hex2bin('b7ad6b7169203331'),
            parentSpanId: null,
            name: 'test',
            kind: OtlpFieldNumbers::SPAN_KIND_INTERNAL,
            startTimeUnixNano: 1_700_000_000_000_000_000,
            endTimeUnixNano: 1_700_000_001_000_000_000,
            attributes: [],
            statusCode: OtlpFieldNumbers::STATUS_CODE_UNSET,
            statusMessage: '',
        );

        $resource = new ResourceInfo(['service.name' => 'my-service']);
        $binary = $builder->build([$span], $resource);

        self::assertStringContainsString('service.name', $binary);
        self::assertStringContainsString('my-service', $binary);
    }

    #[Test]
    public function buildIncludesSpanAttributes(): void
    {
        $builder = new TraceRequestBuilder();

        $span = new OtlpSpan(
            traceId: (string) hex2bin('0af7651916cd43dd8448eb211c80319c'),
            spanId: (string) hex2bin('b7ad6b7169203331'),
            parentSpanId: null,
            name: 'test',
            kind: OtlpFieldNumbers::SPAN_KIND_INTERNAL,
            startTimeUnixNano: 1_700_000_000_000_000_000,
            endTimeUnixNano: 1_700_000_001_000_000_000,
            attributes: ['http.method' => 'GET', 'http.status_code' => 200],
            statusCode: OtlpFieldNumbers::STATUS_CODE_OK,
            statusMessage: '',
        );

        $binary = $builder->build([$span], new ResourceInfo());

        self::assertStringContainsString('http.method', $binary);
        self::assertStringContainsString('GET', $binary);
        self::assertStringContainsString('http.status_code', $binary);
    }

    #[Test]
    public function buildIncludesParentSpanId(): void
    {
        $builder = new TraceRequestBuilder();
        $parentSpanId = (string) hex2bin('00f067aa0ba902b7');

        $span = new OtlpSpan(
            traceId: (string) hex2bin('0af7651916cd43dd8448eb211c80319c'),
            spanId: (string) hex2bin('b7ad6b7169203331'),
            parentSpanId: $parentSpanId,
            name: 'child-span',
            kind: OtlpFieldNumbers::SPAN_KIND_INTERNAL,
            startTimeUnixNano: 1_700_000_000_000_000_000,
            endTimeUnixNano: 1_700_000_001_000_000_000,
            attributes: [],
            statusCode: OtlpFieldNumbers::STATUS_CODE_UNSET,
            statusMessage: '',
        );

        $binary = $builder->build([$span], new ResourceInfo());

        // Parent span ID bytes should appear in output
        self::assertStringContainsString($parentSpanId, $binary);
    }

    #[Test]
    public function buildIncludesErrorStatus(): void
    {
        $builder = new TraceRequestBuilder();

        $span = new OtlpSpan(
            traceId: (string) hex2bin('0af7651916cd43dd8448eb211c80319c'),
            spanId: (string) hex2bin('b7ad6b7169203331'),
            parentSpanId: null,
            name: 'failing-span',
            kind: OtlpFieldNumbers::SPAN_KIND_INTERNAL,
            startTimeUnixNano: 1_700_000_000_000_000_000,
            endTimeUnixNano: 1_700_000_001_000_000_000,
            attributes: [],
            statusCode: OtlpFieldNumbers::STATUS_CODE_ERROR,
            statusMessage: 'Something went wrong',
        );

        $binary = $builder->build([$span], new ResourceInfo());

        self::assertStringContainsString('Something went wrong', $binary);
    }

    #[Test]
    public function buildIncludesScopeInformation(): void
    {
        $builder = new TraceRequestBuilder(scopeName: 'my-lib', scopeVersion: '2.0.0');

        $span = new OtlpSpan(
            traceId: (string) hex2bin('0af7651916cd43dd8448eb211c80319c'),
            spanId: (string) hex2bin('b7ad6b7169203331'),
            parentSpanId: null,
            name: 'test',
            kind: OtlpFieldNumbers::SPAN_KIND_INTERNAL,
            startTimeUnixNano: 1_700_000_000_000_000_000,
            endTimeUnixNano: 1_700_000_001_000_000_000,
            attributes: [],
            statusCode: OtlpFieldNumbers::STATUS_CODE_UNSET,
            statusMessage: '',
        );

        $binary = $builder->build([$span], new ResourceInfo());

        self::assertStringContainsString('my-lib', $binary);
        self::assertStringContainsString('2.0.0', $binary);
    }

    #[Test]
    public function buildHandlesMultipleSpans(): void
    {
        $builder = new TraceRequestBuilder();

        $span1 = new OtlpSpan(
            traceId: (string) hex2bin('0af7651916cd43dd8448eb211c80319c'),
            spanId: (string) hex2bin('b7ad6b7169203331'),
            parentSpanId: null,
            name: 'span-one',
            kind: OtlpFieldNumbers::SPAN_KIND_INTERNAL,
            startTimeUnixNano: 1_700_000_000_000_000_000,
            endTimeUnixNano: 1_700_000_001_000_000_000,
            attributes: [],
            statusCode: OtlpFieldNumbers::STATUS_CODE_UNSET,
            statusMessage: '',
        );

        $span2 = new OtlpSpan(
            traceId: (string) hex2bin('0af7651916cd43dd8448eb211c80319c'),
            spanId: (string) hex2bin('00f067aa0ba902b7'),
            parentSpanId: (string) hex2bin('b7ad6b7169203331'),
            name: 'span-two',
            kind: OtlpFieldNumbers::SPAN_KIND_INTERNAL,
            startTimeUnixNano: 1_700_000_000_500_000_000,
            endTimeUnixNano: 1_700_000_000_900_000_000,
            attributes: [],
            statusCode: OtlpFieldNumbers::STATUS_CODE_OK,
            statusMessage: '',
        );

        $binary = $builder->build([$span1, $span2], new ResourceInfo());

        self::assertStringContainsString('span-one', $binary);
        self::assertStringContainsString('span-two', $binary);
    }

    #[Test]
    public function buildHandlesBooleanAttributes(): void
    {
        $builder = new TraceRequestBuilder();

        $span = new OtlpSpan(
            traceId: (string) hex2bin('0af7651916cd43dd8448eb211c80319c'),
            spanId: (string) hex2bin('b7ad6b7169203331'),
            parentSpanId: null,
            name: 'test',
            kind: OtlpFieldNumbers::SPAN_KIND_INTERNAL,
            startTimeUnixNano: 1_700_000_000_000_000_000,
            endTimeUnixNano: 1_700_000_001_000_000_000,
            attributes: ['cache.hit' => true, 'error' => false],
            statusCode: OtlpFieldNumbers::STATUS_CODE_UNSET,
            statusMessage: '',
        );

        $binary = $builder->build([$span], new ResourceInfo());

        self::assertStringContainsString('cache.hit', $binary);
        self::assertStringContainsString('error', $binary);
    }

    #[Test]
    public function buildHandlesFloatAttributes(): void
    {
        $builder = new TraceRequestBuilder();

        $span = new OtlpSpan(
            traceId: (string) hex2bin('0af7651916cd43dd8448eb211c80319c'),
            spanId: (string) hex2bin('b7ad6b7169203331'),
            parentSpanId: null,
            name: 'test',
            kind: OtlpFieldNumbers::SPAN_KIND_INTERNAL,
            startTimeUnixNano: 1_700_000_000_000_000_000,
            endTimeUnixNano: 1_700_000_001_000_000_000,
            attributes: ['duration_ms' => 42.5],
            statusCode: OtlpFieldNumbers::STATUS_CODE_UNSET,
            statusMessage: '',
        );

        $binary = $builder->build([$span], new ResourceInfo());

        self::assertStringContainsString('duration_ms', $binary);
        // Binary should have grown to include the double value
        self::assertGreaterThan(50, strlen($binary));
    }
}
