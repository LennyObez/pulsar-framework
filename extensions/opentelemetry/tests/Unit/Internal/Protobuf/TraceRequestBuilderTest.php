<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Internal\Protobuf;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\OtlpFieldNumbers;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\OtlpSpan;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\ResourceInfo;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\TraceRequestBuilder;

#[CoversClass(TraceRequestBuilder::class)]
final class TraceRequestBuilderTest extends TestCase
{
    #[Test]
    public function buildWithEmptySpansReturnsEmpty(): void
    {
        $builder = new TraceRequestBuilder();
        $resource = new ResourceInfo(['service.name' => 'test']);

        self::assertSame('', $builder->build([], $resource));
    }

    #[Test]
    public function buildWithSingleSpanProducesNonEmptyBinary(): void
    {
        $builder = new TraceRequestBuilder();
        $resource = new ResourceInfo(['service.name' => 'test']);

        $span = new OtlpSpan(
            traceId: str_repeat("\x01", 16),
            spanId: str_repeat("\x02", 8),
            parentSpanId: null,
            name: 'GET /api',
            startTimeUnixNano: 1000000000,
            endTimeUnixNano: 2000000000,
            attributes: ['http.method' => 'GET'],
            statusCode: OtlpFieldNumbers::STATUS_CODE_OK,
            statusMessage: '',
            kind: OtlpFieldNumbers::SPAN_KIND_CLIENT,
        );

        $result = $builder->build([$span], $resource);

        self::assertNotSame('', $result);
        // Binary should contain the span name and service name
        self::assertStringContainsString('GET /api', $result);
        self::assertStringContainsString('test', $result);
        // Should contain scope name
        self::assertStringContainsString('pulsar', $result);
    }

    #[Test]
    public function buildWithMultipleSpans(): void
    {
        $builder = new TraceRequestBuilder();
        $resource = new ResourceInfo(['service.name' => 'svc']);

        $spans = [
            new OtlpSpan(
                traceId: str_repeat("\x01", 16),
                spanId: str_repeat("\x02", 8),
                parentSpanId: null,
                name: 'span-1',
                startTimeUnixNano: 0,
                endTimeUnixNano: 0,
                attributes: [],
                statusCode: 0,
                statusMessage: '',
            ),
            new OtlpSpan(
                traceId: str_repeat("\x01", 16),
                spanId: str_repeat("\x03", 8),
                parentSpanId: str_repeat("\x02", 8),
                name: 'span-2',
                startTimeUnixNano: 0,
                endTimeUnixNano: 0,
                attributes: [],
                statusCode: 0,
                statusMessage: '',
            ),
        ];

        $result = $builder->build($spans, $resource);

        self::assertStringContainsString('span-1', $result);
        self::assertStringContainsString('span-2', $result);
    }

    #[Test]
    public function buildWithErrorStatusIncludesStatusMessage(): void
    {
        $builder = new TraceRequestBuilder();
        $resource = new ResourceInfo();

        $span = new OtlpSpan(
            traceId: str_repeat("\x01", 16),
            spanId: str_repeat("\x02", 8),
            parentSpanId: null,
            name: 'failing-op',
            startTimeUnixNano: 0,
            endTimeUnixNano: 0,
            attributes: [],
            statusCode: OtlpFieldNumbers::STATUS_CODE_ERROR,
            statusMessage: 'error',
        );

        $result = $builder->build([$span], $resource);

        self::assertStringContainsString('error', $result);
    }

    #[Test]
    public function buildWithCustomScopeNameAndVersion(): void
    {
        $builder = new TraceRequestBuilder(
            scopeName: 'my-lib',
            scopeVersion: '2.0.0',
        );
        $resource = new ResourceInfo();

        $span = new OtlpSpan(
            traceId: str_repeat("\x01", 16),
            spanId: str_repeat("\x02", 8),
            parentSpanId: null,
            name: 'op',
            startTimeUnixNano: 0,
            endTimeUnixNano: 0,
            attributes: [],
            statusCode: 0,
            statusMessage: '',
        );

        $result = $builder->build([$span], $resource);

        self::assertStringContainsString('my-lib', $result);
        self::assertStringContainsString('2.0.0', $result);
    }

    #[Test]
    public function buildWithEmptyResourceAttributes(): void
    {
        $builder = new TraceRequestBuilder();
        $resource = new ResourceInfo(); // empty attributes

        $span = new OtlpSpan(
            traceId: str_repeat("\x01", 16),
            spanId: str_repeat("\x02", 8),
            parentSpanId: null,
            name: 'op',
            startTimeUnixNano: 0,
            endTimeUnixNano: 0,
            attributes: [],
            statusCode: 0,
            statusMessage: '',
        );

        // Should not crash with empty resource
        $result = $builder->build([$span], $resource);
        self::assertNotSame('', $result);
    }
}
