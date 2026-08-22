<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Export\Otlp\Protobuf;

use Pulsar\Api\Internal;

/**
 * Builds an ExportTraceServiceRequest protobuf binary from OtlpSpan DTOs.
 *
 * Wraps spans in the ResourceSpans -> ScopeSpans envelope required by OTLP.
 */
#[Internal(reason: 'Protobuf serializer for OTLP trace export')]
final readonly class TraceRequestBuilder
{
    public function __construct(
        private string $scopeName = 'pulsar',
        private string $scopeVersion = '1.0.0',
    ) {}

    /**
     * Serialize spans into an ExportTraceServiceRequest binary.
     *
     * @param list<OtlpSpan> $spans    Spans to export
     * @param ResourceInfo   $resource Resource metadata
     */
    public function build(array $spans, ResourceInfo $resource): string
    {
        if ($spans === []) {
            return '';
        }

        $root = new ProtobufWriter();

        // ExportTraceServiceRequest.resource_spans (field 1, repeated)
        $resourceSpansWriter = new ProtobufWriter();

        // ResourceSpans.resource (field 1)
        $this->writeResource($resourceSpansWriter, $resource);

        // ResourceSpans.scope_spans (field 2, repeated)
        $scopeSpansWriter = new ProtobufWriter();

        // ScopeSpans.scope (field 1)
        $this->writeScope($scopeSpansWriter);

        // ScopeSpans.spans (field 2, repeated)
        foreach ($spans as $span) {
            $spanWriter = $this->encodeSpan($span);
            $scopeSpansWriter->writeEmbeddedMessage(OtlpFieldNumbers::SS_SPANS, $spanWriter);
        }

        $resourceSpansWriter->writeEmbeddedMessage(OtlpFieldNumbers::RS_SCOPE_SPANS, $scopeSpansWriter);
        $root->writeEmbeddedMessage(OtlpFieldNumbers::RESOURCE_SPANS, $resourceSpansWriter);

        return $root->toBytes();
    }

    private function encodeSpan(OtlpSpan $span): ProtobufWriter
    {
        $w = new ProtobufWriter();

        // Span.trace_id (field 1, bytes)
        $w->writeLengthDelimitedField(OtlpFieldNumbers::SPAN_TRACE_ID, $span->traceId);

        // Span.span_id (field 2, bytes)
        $w->writeLengthDelimitedField(OtlpFieldNumbers::SPAN_SPAN_ID, $span->spanId);

        // Span.parent_span_id (field 4, bytes)
        if ($span->parentSpanId !== null) {
            $w->writeLengthDelimitedField(OtlpFieldNumbers::SPAN_PARENT_SPAN_ID, $span->parentSpanId);
        }

        // Span.name (field 5, string)
        $w->writeStringField(OtlpFieldNumbers::SPAN_NAME, $span->name);

        // Span.kind (field 6, enum/varint)
        $w->writeVarintField(OtlpFieldNumbers::SPAN_KIND, $span->kind);

        // Span.start_time_unix_nano (field 7, fixed64)
        $w->writeFixed64Field(OtlpFieldNumbers::SPAN_START_TIME, $span->startTimeUnixNano);

        // Span.end_time_unix_nano (field 8, fixed64)
        $w->writeFixed64Field(OtlpFieldNumbers::SPAN_END_TIME, $span->endTimeUnixNano);

        // Span.attributes (field 9, repeated KeyValue)
        AttributeEncoder::encode($w, OtlpFieldNumbers::SPAN_ATTRIBUTES, $span->attributes);

        // Span.status (field 15, embedded Status)
        $statusWriter = new ProtobufWriter();

        if ($span->statusMessage !== '') {
            $statusWriter->writeStringField(OtlpFieldNumbers::STATUS_MESSAGE, $span->statusMessage);
        }

        if ($span->statusCode !== OtlpFieldNumbers::STATUS_CODE_UNSET) {
            $statusWriter->writeVarintField(OtlpFieldNumbers::STATUS_CODE, $span->statusCode);
        }

        if ($statusWriter->toBytes() !== '') {
            $w->writeEmbeddedMessage(OtlpFieldNumbers::SPAN_STATUS, $statusWriter);
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
        $writer->writeEmbeddedMessage(OtlpFieldNumbers::RS_RESOURCE, $resourceWriter);
    }

    private function writeScope(ProtobufWriter $writer): void
    {
        $scopeWriter = new ProtobufWriter();
        $scopeWriter->writeStringField(OtlpFieldNumbers::SCOPE_NAME, $this->scopeName);
        $scopeWriter->writeStringField(OtlpFieldNumbers::SCOPE_VERSION, $this->scopeVersion);
        $writer->writeEmbeddedMessage(OtlpFieldNumbers::SS_SCOPE, $scopeWriter);
    }
}
