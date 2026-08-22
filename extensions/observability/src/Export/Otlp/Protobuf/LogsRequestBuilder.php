<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Export\Otlp\Protobuf;

use Pulsar\Api\Internal;

/**
 * Builds an ExportLogsServiceRequest protobuf binary from OtlpLogRecord DTOs.
 *
 * Wraps log records in the ResourceLogs -> ScopeLogs envelope required by OTLP.
 */
#[Internal(reason: 'Protobuf serializer for OTLP logs export')]
final readonly class LogsRequestBuilder
{
    public function __construct(
        private string $scopeName = 'pulsar',
        private string $scopeVersion = '1.0.0',
    ) {}

    /**
     * Serialize log records into an ExportLogsServiceRequest binary.
     *
     * @param list<OtlpLogRecord> $logRecords Log records to export
     * @param ResourceInfo        $resource   Resource metadata
     */
    public function build(array $logRecords, ResourceInfo $resource): string
    {
        if ($logRecords === []) {
            return '';
        }

        $root = new ProtobufWriter();
        $rlWriter = new ProtobufWriter();
        $this->writeResource($rlWriter, $resource);

        $slWriter = new ProtobufWriter();
        $this->writeScope($slWriter);

        foreach ($logRecords as $record) {
            $recordWriter = $this->encodeLogRecord($record);
            $slWriter->writeEmbeddedMessage(OtlpFieldNumbers::SL_LOG_RECORDS, $recordWriter);
        }

        $rlWriter->writeEmbeddedMessage(OtlpFieldNumbers::RL_SCOPE_LOGS, $slWriter);
        $root->writeEmbeddedMessage(OtlpFieldNumbers::RESOURCE_LOGS, $rlWriter);

        return $root->toBytes();
    }

    private function encodeLogRecord(OtlpLogRecord $record): ProtobufWriter
    {
        $w = new ProtobufWriter();
        $w->writeFixed64Field(OtlpFieldNumbers::LR_TIME_UNIX_NANO, $record->timeUnixNano);
        $w->writeVarintField(OtlpFieldNumbers::LR_SEVERITY_NUMBER, $record->severityNumber);

        $bodyWriter = new ProtobufWriter();
        $bodyWriter->writeStringField(OtlpFieldNumbers::AV_STRING, $record->body);
        $w->writeEmbeddedMessage(OtlpFieldNumbers::LR_BODY, $bodyWriter);

        AttributeEncoder::encode($w, OtlpFieldNumbers::LR_ATTRIBUTES, $record->attributes);

        if ($record->traceId !== null) {
            $w->writeLengthDelimitedField(OtlpFieldNumbers::LR_TRACE_ID, $record->traceId);
        }

        if ($record->spanId !== null) {
            $w->writeLengthDelimitedField(OtlpFieldNumbers::LR_SPAN_ID, $record->spanId);
        }

        if ($record->severityText !== '') {
            $w->writeStringField(OtlpFieldNumbers::LR_SEVERITY_TEXT, $record->severityText);
        }

        return $w;
    }

    private function writeResource(ProtobufWriter $writer, ResourceInfo $resource): void
    {
        if ($resource->attributes === []) {
            return;
        }

        $resourceWriter = new ProtobufWriter();
        AttributeEncoder::encode($resourceWriter, OtlpFieldNumbers::RESOURCE_ATTRIBUTES, $resource->attributes);
        $writer->writeEmbeddedMessage(OtlpFieldNumbers::RL_RESOURCE, $resourceWriter);
    }

    private function writeScope(ProtobufWriter $writer): void
    {
        $scopeWriter = new ProtobufWriter();
        $scopeWriter->writeStringField(OtlpFieldNumbers::SCOPE_NAME, $this->scopeName);
        $scopeWriter->writeStringField(OtlpFieldNumbers::SCOPE_VERSION, $this->scopeVersion);
        $writer->writeEmbeddedMessage(OtlpFieldNumbers::SL_SCOPE, $scopeWriter);
    }
}
