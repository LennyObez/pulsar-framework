<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Internal\Export;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pulsar\Api\Internal;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\LogsRequestBuilder;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\OtlpLogRecord;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\ResourceInfo;
use Pulsar\Extension\OpenTelemetry\Internal\Transport\OtlpTransportInterface;

/**
 * Batch exporter for log records.
 *
 * Wraps BatchExporter with LogsRequestBuilder serialization and
 * the standard OTLP /v1/logs endpoint path.
 */
#[Internal(reason: 'Logs-specific batch export wiring')]
final readonly class LogsBatchExporter
{
    /** @var BatchExporter<OtlpLogRecord> */
    private BatchExporter $batchExporter;

    public function __construct(
        OtlpTransportInterface $transport,
        private ResourceInfo $resource,
        LogsRequestBuilder $builder = new LogsRequestBuilder(),
        int $maxBatchSize = 512,
        int $maxQueueSize = 2048,
        LoggerInterface $logger = new NullLogger(),
    ) {
        /** @var BatchExporter<OtlpLogRecord> $batchExporter */
        $batchExporter = new BatchExporter(
            transport: $transport,
            serializer: function (array $records) use ($builder): string {
                /** @var list<OtlpLogRecord> $records */
                return $builder->build($records, $this->resource);
            },
            signalPath: '/v1/logs',
            maxBatchSize: $maxBatchSize,
            maxQueueSize: $maxQueueSize,
            logger: $logger,
        );
        $this->batchExporter = $batchExporter;
    }

    public function enqueue(OtlpLogRecord $record): void
    {
        $this->batchExporter->enqueue($record);
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
