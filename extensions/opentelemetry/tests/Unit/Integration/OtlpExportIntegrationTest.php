<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Internal\Export\BatchExporter;
use Pulsar\Extension\OpenTelemetry\Internal\Export\LogsBatchExporter;
use Pulsar\Extension\OpenTelemetry\Internal\Export\MetricsBatchExporter;
use Pulsar\Extension\OpenTelemetry\Internal\Export\SpanBatchExporter;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\OtlpFieldNumbers;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\OtlpLogRecord;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\OtlpMetric;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\OtlpSpan;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\ResourceInfo;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\TraceRequestBuilder;
use Pulsar\Extension\OpenTelemetry\Internal\Transport\OtlpTransportInterface;
use Pulsar\Extension\OpenTelemetry\Internal\Transport\TransportResult;
use Pulsar\Extension\OpenTelemetry\Noop\NoopLogSink;
use Pulsar\Extension\OpenTelemetry\Noop\NoopSpanProcessor;
use Pulsar\Extension\OpenTelemetry\Sampling\AlwaysSampler;
use Pulsar\Extension\OpenTelemetry\Sampling\NeverSampler;
use Pulsar\Observability\Log\LogEntry;
use Pulsar\Observability\Log\LogLevel;
use Pulsar\Observability\Metrics\MetricType;
use Pulsar\Observability\Tracing\InMemorySpanCollector;
use Pulsar\Observability\Tracing\Span;
use Pulsar\Observability\Tracing\SpanId;
use Pulsar\Observability\Tracing\SpanStatus;
use Pulsar\Observability\Tracing\TraceContext;
use Pulsar\Observability\Tracing\TraceId;

use function assert;
use function hex2bin;
use function ord;
use function str_contains;
use function str_repeat;

/**
 * Integration test covering the full OTLP export pipeline:
 * span/metric/log creation -> protobuf serialization -> batch export -> transport.
 *
 * Uses a recording transport stub to capture payloads without network I/O.
 */
#[CoversClass(SpanBatchExporter::class)]
#[CoversClass(MetricsBatchExporter::class)]
#[CoversClass(LogsBatchExporter::class)]
#[CoversClass(BatchExporter::class)]
#[CoversClass(TraceRequestBuilder::class)]
final class OtlpExportIntegrationTest extends TestCase
{
    private RecordingTransport $transport;
    private ResourceInfo $resource;

    protected function setUp(): void
    {
        $this->transport = new RecordingTransport();
        $this->resource = new ResourceInfo([
            'service.name' => 'pulsar-test',
            'service.version' => '1.0.0',
        ]);
    }

    // -----------------------------------------------------------------------
    // Trace round-trip
    // -----------------------------------------------------------------------

    #[Test]
    public function spanRoundTripProducesValidProtobuf(): void
    {
        $exporter = new SpanBatchExporter(
            transport: $this->transport,
            resource: $this->resource,
            maxBatchSize: 10,
        );

        $traceIdHex = str_repeat('ab', 16);
        $spanIdHex = str_repeat('cd', 8);

        $span = new OtlpSpan(
            traceId: self::hex($traceIdHex),
            spanId: self::hex($spanIdHex),
            parentSpanId: null,
            name: 'http.request',
            startTimeUnixNano: 1_000_000_000,
            endTimeUnixNano: 2_000_000_000,
            attributes: ['http.method' => 'GET', 'http.status_code' => 200],
            statusCode: OtlpFieldNumbers::STATUS_CODE_OK,
            statusMessage: '',
            kind: OtlpFieldNumbers::SPAN_KIND_INTERNAL,
        );

        $exporter->enqueue($span);
        $exporter->flush();

        self::assertCount(1, $this->transport->payloads);
        self::assertSame('/v1/traces', $this->transport->paths[0]);

        $payload = $this->transport->payloads[0];

        // Payload must be non-empty protobuf bytes
        self::assertNotEmpty($payload);
        // Protobuf starts with a tag byte: field 1, wire type 2 (length-delimited)
        self::assertSame(0x0A, ord($payload[0]), 'First byte should be tag for field 1, wire type 2');

        // Verify the span name 'http.request' appears in the serialized output
        self::assertTrue(
            str_contains($payload, 'http.request'),
            'Serialized payload should contain span name',
        );

        // Verify resource attributes are present
        self::assertTrue(
            str_contains($payload, 'pulsar-test'),
            'Serialized payload should contain resource service.name',
        );

        // Verify trace ID bytes appear
        self::assertTrue(
            str_contains($payload, self::hex($traceIdHex)),
            'Serialized payload should contain raw trace ID bytes',
        );
    }

    #[Test]
    public function multipleSpansFlushInSingleBatch(): void
    {
        $exporter = new SpanBatchExporter(
            transport: $this->transport,
            resource: $this->resource,
            maxBatchSize: 100,
        );

        for ($i = 0; $i < 5; $i++) {
            $exporter->enqueue(new OtlpSpan(
                traceId: self::hex(str_repeat('a' . $i, 16)),
                spanId: self::hex(str_repeat('b' . $i, 8)),
                parentSpanId: null,
                name: 'span-' . $i,
                startTimeUnixNano: 1_000_000_000,
                endTimeUnixNano: 2_000_000_000,
                attributes: [],
                statusCode: OtlpFieldNumbers::STATUS_CODE_UNSET,
                statusMessage: '',
                kind: OtlpFieldNumbers::SPAN_KIND_INTERNAL,
            ));
        }

        $exporter->flush();

        // All 5 spans should be in a single batch (maxBatchSize=100)
        self::assertCount(1, $this->transport->payloads);

        $payload = $this->transport->payloads[0];

        for ($i = 0; $i < 5; $i++) {
            self::assertTrue(
                str_contains($payload, 'span-' . $i),
                "Payload should contain span-$i",
            );
        }
    }

    #[Test]
    public function spanAutoFlushAtBatchSizeThreshold(): void
    {
        $exporter = new SpanBatchExporter(
            transport: $this->transport,
            resource: $this->resource,
            maxBatchSize: 2,
        );

        $exporter->enqueue($this->createTestSpan('first'));
        self::assertCount(0, $this->transport->payloads);

        $exporter->enqueue($this->createTestSpan('second'));
        // Auto-flush triggered at batch size 2
        self::assertCount(1, $this->transport->payloads);
        self::assertSame('/v1/traces', $this->transport->paths[0]);
    }

    // -----------------------------------------------------------------------
    // Metrics round-trip
    // -----------------------------------------------------------------------

    #[Test]
    public function metricRoundTripProducesValidProtobuf(): void
    {
        $exporter = new MetricsBatchExporter(
            transport: $this->transport,
            resource: $this->resource,
            maxBatchSize: 10,
        );

        $metric = new OtlpMetric(
            name: 'http_requests_total',
            description: 'Total HTTP requests',
            unit: '1',
            type: MetricType::Counter,
            dataPoints: [
                ['time_unix_nano' => 1_000_000_000, 'value' => 42, 'attributes' => ['method' => 'GET']],
            ],
        );

        $exporter->enqueue($metric);
        $exporter->flush();

        self::assertCount(1, $this->transport->payloads);
        self::assertSame('/v1/metrics', $this->transport->paths[0]);

        $payload = $this->transport->payloads[0];
        self::assertNotEmpty($payload);

        // Verify metric name and resource appear in serialized output
        self::assertTrue(str_contains($payload, 'http_requests_total'));
        self::assertTrue(str_contains($payload, 'pulsar-test'));
    }

    // -----------------------------------------------------------------------
    // Logs round-trip
    // -----------------------------------------------------------------------

    #[Test]
    public function logRecordRoundTripProducesValidProtobuf(): void
    {
        $exporter = new LogsBatchExporter(
            transport: $this->transport,
            resource: $this->resource,
            maxBatchSize: 10,
        );

        $logRecord = new OtlpLogRecord(
            timeUnixNano: 1_700_000_000_000_000_000,
            severityNumber: 9, // INFO
            severityText: 'INFO',
            body: 'User login succeeded',
            attributes: ['user.id' => 'u-123'],
            traceId: self::hex(str_repeat('aa', 16)),
            spanId: self::hex(str_repeat('bb', 8)),
        );

        $exporter->enqueue($logRecord);
        $exporter->flush();

        self::assertCount(1, $this->transport->payloads);
        self::assertSame('/v1/logs', $this->transport->paths[0]);

        $payload = $this->transport->payloads[0];
        self::assertNotEmpty($payload);

        self::assertTrue(str_contains($payload, 'User login succeeded'));
        self::assertTrue(str_contains($payload, 'pulsar-test'));
    }

    // -----------------------------------------------------------------------
    // Dual export (Studio InMemorySpanCollector + OTLP)
    // -----------------------------------------------------------------------

    #[Test]
    public function dualExportSendsToStudioAndOtlp(): void
    {
        $studioCollector = new InMemorySpanCollector();
        $otlpExporter = new SpanBatchExporter(
            transport: $this->transport,
            resource: $this->resource,
            maxBatchSize: 10,
        );

        // Simulate a span flowing through both paths
        $traceContext = new TraceContext(
            traceId: new TraceId(str_repeat('ab', 16)),
            spanId: new SpanId(str_repeat('cd', 8)),
        );

        $pulsarSpan = new Span('payment.process', $traceContext);
        $pulsarSpan->setAttribute('payment.amount', 99.99);
        $pulsarSpan->status = SpanStatus::Ok;
        $pulsarSpan->end();

        // Path 1: Studio's in-memory collector
        $studioCollector->onEnd($pulsarSpan);

        // Path 2: OTLP export (bridged span -> OtlpSpan -> batch)
        $endTime = $pulsarSpan->endTime();
        self::assertNotNull($endTime, 'Span must be ended before OTLP export');

        $otlpSpan = new OtlpSpan(
            traceId: self::hex($pulsarSpan->context->traceId->value),
            spanId: self::hex($pulsarSpan->context->spanId->value),
            parentSpanId: null,
            name: $pulsarSpan->name,
            startTimeUnixNano: $pulsarSpan->startTime(),
            endTimeUnixNano: $endTime,
            attributes: $pulsarSpan->attributes(),
            statusCode: OtlpFieldNumbers::STATUS_CODE_OK,
            statusMessage: '',
            kind: OtlpFieldNumbers::SPAN_KIND_INTERNAL,
        );

        $otlpExporter->enqueue($otlpSpan);
        $otlpExporter->flush();

        // Studio got the span
        self::assertSame(1, $studioCollector->count());
        self::assertSame('payment.process', $studioCollector->spans()[0]->name);

        // OTLP got the serialized payload
        self::assertCount(1, $this->transport->payloads);
        self::assertTrue(str_contains($this->transport->payloads[0], 'payment.process'));
    }

    // -----------------------------------------------------------------------
    // Disabled mode (no-ops)
    // -----------------------------------------------------------------------

    #[Test]
    public function disabledModeNoopSpanProcessorDoesNotAllocate(): void
    {
        $this->expectNotToPerformAssertions();

        $noop = new NoopSpanProcessor();

        $context = new TraceContext(
            traceId: new TraceId(str_repeat('00', 16)),
            spanId: new SpanId(str_repeat('11', 8)),
        );

        $span = new Span('should.be.discarded', $context);
        $span->end();

        // No-op processor accepts span without error and stores nothing
        $noop->onEnd($span);
    }

    #[Test]
    public function disabledModeNoopLogSinkDoesNotAllocate(): void
    {
        $this->expectNotToPerformAssertions();

        $noop = new NoopLogSink();

        $entry = LogEntry::create(
            level: LogLevel::Error,
            message: 'should be discarded',
            context: ['key' => 'value'],
        );

        // No-op sink accepts entry without error
        $noop->write($entry);
    }

    #[Test]
    public function neverSamplerRejectsAllTraces(): void
    {
        $sampler = new NeverSampler();

        $context = new TraceContext(
            traceId: new TraceId(str_repeat('ff', 16)),
            spanId: new SpanId(str_repeat('ee', 8)),
        );

        $decision = $sampler->shouldSample($context);

        self::assertFalse($decision->sampled);
    }

    #[Test]
    public function alwaysSamplerAcceptsAllTraces(): void
    {
        $sampler = new AlwaysSampler();

        $context = new TraceContext(
            traceId: new TraceId(str_repeat('ff', 16)),
            spanId: new SpanId(str_repeat('ee', 8)),
        );

        $decision = $sampler->shouldSample($context);

        self::assertTrue($decision->sampled);
    }

    // -----------------------------------------------------------------------
    // Shutdown behavior
    // -----------------------------------------------------------------------

    #[Test]
    public function shutdownFlushesRemainingAndRejectsNewItems(): void
    {
        $exporter = new SpanBatchExporter(
            transport: $this->transport,
            resource: $this->resource,
            maxBatchSize: 100,
        );

        $exporter->enqueue($this->createTestSpan('before-shutdown'));
        $exporter->shutdown();

        // Flush happened during shutdown
        self::assertCount(1, $this->transport->payloads);
        self::assertTrue(str_contains($this->transport->payloads[0], 'before-shutdown'));

        // After shutdown, new enqueues are silently dropped
        $exporter->enqueue($this->createTestSpan('after-shutdown'));
        $exporter->flush();

        // No additional payloads
        self::assertCount(1, $this->transport->payloads);
    }

    // -----------------------------------------------------------------------
    // Transport failure does not crash the pipeline
    // -----------------------------------------------------------------------

    #[Test]
    public function transportFailureDoesNotCrashExportPipeline(): void
    {
        $failingTransport = new RecordingTransport(
            result: TransportResult::failure(503, 'Service Unavailable', retryable: true),
        );

        $exporter = new SpanBatchExporter(
            transport: $failingTransport,
            resource: $this->resource,
            maxBatchSize: 10,
        );

        $exporter->enqueue($this->createTestSpan('will-fail'));
        $exporter->flush();

        // Transport was called twice: initial send + 1 retry (retryable failure)
        self::assertCount(2, $failingTransport->payloads);
        self::assertSame(0, $exporter->queueSize());
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function createTestSpan(string $name): OtlpSpan
    {
        return new OtlpSpan(
            traceId: self::hex(str_repeat('ab', 16)),
            spanId: self::hex(str_repeat('cd', 8)),
            parentSpanId: null,
            name: $name,
            startTimeUnixNano: 1_000_000_000,
            endTimeUnixNano: 2_000_000_000,
            attributes: [],
            statusCode: OtlpFieldNumbers::STATUS_CODE_UNSET,
            statusMessage: '',
        );
    }

    /**
     * Convert hex to binary with a type-safe assertion (all test hex strings are valid).
     */
    private static function hex(string $hexString): string
    {
        $bin = hex2bin($hexString);
        assert($bin !== false);

        return $bin;
    }
}

/**
 * Transport stub that records all send() calls for assertion.
 */
final class RecordingTransport implements OtlpTransportInterface
{
    /** @var list<string> */
    public array $payloads = [];

    /** @var list<string> */
    public array $paths = [];

    public function __construct(
        private readonly TransportResult $result = new TransportResult(
            success: true,
            httpStatus: 200,
            errorMessage: '',
            retryable: false,
        ),
    ) {}

    public function send(string $path, string $protobufPayload): TransportResult
    {
        $this->paths[] = $path;
        $this->payloads[] = $protobufPayload;

        return $this->result;
    }
}
