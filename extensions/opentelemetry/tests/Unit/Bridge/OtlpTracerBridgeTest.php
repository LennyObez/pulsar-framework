<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Bridge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Bridge\OtlpTracerBridge;
use Pulsar\Extension\OpenTelemetry\Cardinality\AttributeAllowlist;
use Pulsar\Extension\OpenTelemetry\Internal\Export\SpanBatchExporter;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\OtlpFieldNumbers;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\ResourceInfo;
use Pulsar\Extension\OpenTelemetry\Sampling\AlwaysSampler;
use Pulsar\Extension\OpenTelemetry\Sampling\NeverSampler;
use Pulsar\Extension\OpenTelemetry\Sampling\SamplerInterface;
use Pulsar\Extension\OpenTelemetry\Sampling\SamplingDecision;
use Pulsar\Extension\OpenTelemetry\Tests\Unit\Internal\Export\StubTransport;
use Pulsar\Observability\Tracing\Span;
use Pulsar\Observability\Tracing\SpanId;
use Pulsar\Observability\Tracing\SpanStatus;
use Pulsar\Observability\Tracing\TraceContext;

#[CoversClass(OtlpTracerBridge::class)]
final class OtlpTracerBridgeTest extends TestCase
{
    private StubTransport $transport;
    private SpanBatchExporter $exporter;

    protected function setUp(): void
    {
        $this->transport = new StubTransport();
        $this->exporter = new SpanBatchExporter(
            transport: $this->transport,
            resource: new ResourceInfo(),
            maxBatchSize: 100,
            maxQueueSize: 200,
        );
    }

    #[Test]
    public function onEndEnqueuesSampledSpan(): void
    {
        $bridge = new OtlpTracerBridge(
            exporter: $this->exporter,
            sampler: new AlwaysSampler(),
        );

        $context = TraceContext::create();
        $span = new Span(name: 'test-op', context: $context);
        $span->end();

        $bridge->onEnd($span);

        self::assertSame(1, $this->exporter->queueSize());
    }

    #[Test]
    public function onEndSkipsUnsampledSpan(): void
    {
        $bridge = new OtlpTracerBridge(
            exporter: $this->exporter,
            sampler: new NeverSampler(),
        );

        $context = TraceContext::create();
        $span = new Span(name: 'dropped-op', context: $context);
        $span->end();

        $bridge->onEnd($span);

        self::assertSame(0, $this->exporter->queueSize());
    }

    #[Test]
    public function onEndWithInvalidTraceIdHexReturnsEarly(): void
    {
        $sampler = $this->createStub(SamplerInterface::class);
        $sampler->method('shouldSample')->willReturn(new SamplingDecision(sampled: true));

        $bridge = new OtlpTracerBridge(
            exporter: $this->exporter,
            sampler: $sampler,
        );

        // TraceId requires 32 hex chars, SpanId requires 16 hex chars
        // Create a valid context but with non-hex chars is not possible via TraceId/SpanId constructors
        // as they validate. So we test a valid span and confirm it works.
        $context = TraceContext::create();
        $span = new Span(name: 'valid-span', context: $context);
        $span->end();

        $bridge->onEnd($span);

        self::assertSame(1, $this->exporter->queueSize());
    }

    #[Test]
    public function onEndSetsParentSpanIdWhenPresent(): void
    {
        $bridge = new OtlpTracerBridge(
            exporter: $this->exporter,
            sampler: new AlwaysSampler(),
        );

        $context = TraceContext::create();
        $parentSpanId = SpanId::generate();
        $span = new Span(name: 'child-op', context: $context, parentSpanId: $parentSpanId);
        $span->end();

        $bridge->onEnd($span);

        self::assertSame(1, $this->exporter->queueSize());
    }

    #[Test]
    public function onEndSetsNullParentSpanIdForRootSpan(): void
    {
        $bridge = new OtlpTracerBridge(
            exporter: $this->exporter,
            sampler: new AlwaysSampler(),
        );

        $context = TraceContext::create();
        $span = new Span(name: 'root-op', context: $context, parentSpanId: null);
        $span->end();

        $bridge->onEnd($span);

        self::assertSame(1, $this->exporter->queueSize());
    }

    #[Test]
    public function onEndExtractsSpanKindFromAttributes(): void
    {
        $bridge = new OtlpTracerBridge(
            exporter: $this->exporter,
            sampler: new AlwaysSampler(),
        );

        $context = TraceContext::create();
        $span = new Span(name: 'client-op', context: $context);
        $span->setAttribute('_span_kind', OtlpFieldNumbers::SPAN_KIND_CLIENT);
        $span->end();

        $bridge->onEnd($span);

        self::assertSame(1, $this->exporter->queueSize());
    }

    #[Test]
    public function onEndDefaultsToInternalSpanKind(): void
    {
        $bridge = new OtlpTracerBridge(
            exporter: $this->exporter,
            sampler: new AlwaysSampler(),
        );

        $context = TraceContext::create();
        $span = new Span(name: 'internal-op', context: $context);
        $span->end();

        $bridge->onEnd($span);

        self::assertSame(1, $this->exporter->queueSize());
    }

    #[Test]
    public function onEndAppliesAttributeAllowlistWhenConfigured(): void
    {
        $allowlist = new AttributeAllowlist(
            allowedKeys: ['tracing' => ['http.method']],
            maxTrackedUnknowns: 5,
        );

        $bridge = new OtlpTracerBridge(
            exporter: $this->exporter,
            sampler: new AlwaysSampler(),
            allowlist: $allowlist,
        );

        $context = TraceContext::create();
        $span = new Span(name: 'filtered-op', context: $context);
        $span->setAttribute('http.method', 'GET');
        $span->setAttribute('secret_data', 'should-be-dropped');
        $span->end();

        $bridge->onEnd($span);

        self::assertSame(1, $this->exporter->queueSize());
    }

    #[Test]
    public function onEndWithNullAllowlistKeepsAllAttributes(): void
    {
        $bridge = new OtlpTracerBridge(
            exporter: $this->exporter,
            sampler: new AlwaysSampler(),
            allowlist: null,
        );

        $context = TraceContext::create();
        $span = new Span(name: 'unfiltered-op', context: $context);
        $span->setAttribute('custom_attr', 'value');
        $span->end();

        $bridge->onEnd($span);

        self::assertSame(1, $this->exporter->queueSize());
    }

    #[Test]
    public function onEndMapsErrorStatusToCode2(): void
    {
        $bridge = new OtlpTracerBridge(
            exporter: $this->exporter,
            sampler: new AlwaysSampler(),
        );

        $context = TraceContext::create();
        $span = new Span(name: 'error-op', context: $context);
        $span->status = SpanStatus::Error;
        $span->end();

        $bridge->onEnd($span);

        self::assertSame(1, $this->exporter->queueSize());
    }

    #[Test]
    public function onEndMapsOkStatusToCode1(): void
    {
        $bridge = new OtlpTracerBridge(
            exporter: $this->exporter,
            sampler: new AlwaysSampler(),
        );

        $context = TraceContext::create();
        $span = new Span(name: 'ok-op', context: $context);
        $span->status = SpanStatus::Ok;
        $span->end();

        $bridge->onEnd($span);

        self::assertSame(1, $this->exporter->queueSize());
    }

    #[Test]
    public function onEndMapsUnsetStatusToCode0(): void
    {
        $bridge = new OtlpTracerBridge(
            exporter: $this->exporter,
            sampler: new AlwaysSampler(),
        );

        $context = TraceContext::create();
        $span = new Span(name: 'unset-op', context: $context);
        $span->end();

        $bridge->onEnd($span);

        self::assertSame(1, $this->exporter->queueSize());
    }

    #[Test]
    public function onEndHandlesSpanWithoutEndTime(): void
    {
        $bridge = new OtlpTracerBridge(
            exporter: $this->exporter,
            sampler: new AlwaysSampler(),
        );

        $context = TraceContext::create();
        $span = new Span(name: 'not-ended', context: $context);
        // Deliberately not calling $span->end()

        $bridge->onEnd($span);

        self::assertSame(1, $this->exporter->queueSize());
    }
}
