<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Internal\Export;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pulsar\Api\Internal;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\OtlpSpan;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\ResourceInfo;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\TraceRequestBuilder;
use Pulsar\Extension\OpenTelemetry\Internal\Transport\OtlpTransportInterface;

/**
 * Batch exporter for trace spans.
 *
 * Wraps BatchExporter with TraceRequestBuilder serialization and
 * the standard OTLP /v1/traces endpoint path.
 */
#[Internal(reason: 'Span-specific batch export wiring')]
final readonly class SpanBatchExporter
{
    /** @var BatchExporter<OtlpSpan> */
    private BatchExporter $batchExporter;

    public function __construct(
        OtlpTransportInterface $transport,
        private ResourceInfo $resource,
        TraceRequestBuilder $builder = new TraceRequestBuilder(),
        int $maxBatchSize = 512,
        int $maxQueueSize = 2048,
        LoggerInterface $logger = new NullLogger(),
    ) {
        /** @var BatchExporter<OtlpSpan> $batchExporter */
        $batchExporter = new BatchExporter(
            transport: $transport,
            serializer: function (array $spans) use ($builder): string {
                /** @var list<OtlpSpan> $spans */
                return $builder->build($spans, $this->resource);
            },
            signalPath: '/v1/traces',
            maxBatchSize: $maxBatchSize,
            maxQueueSize: $maxQueueSize,
            logger: $logger,
        );
        $this->batchExporter = $batchExporter;
    }

    public function enqueue(OtlpSpan $span): void
    {
        $this->batchExporter->enqueue($span);
    }

    public function flush(): void
    {
        $this->batchExporter->flush();
    }

    public function shutdown(): void
    {
        $this->batchExporter->shutdown();
    }

    public function queueSize(): int
    {
        return $this->batchExporter->queueSize();
    }
}
