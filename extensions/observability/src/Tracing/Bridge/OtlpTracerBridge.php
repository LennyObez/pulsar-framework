<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tracing\Bridge;

use Override;
use Pulsar\Api\Api;
use Pulsar\Extension\Observability\Export\Otlp\Protobuf\OtlpFieldNumbers;
use Pulsar\Extension\Observability\Export\Otlp\Protobuf\OtlpSpan;
use Pulsar\Extension\Observability\Export\Otlp\SpanBatchExporter;
use Pulsar\Extension\Observability\Tracing\Cardinality\AttributeAllowlist;
use Pulsar\Extension\Observability\Tracing\Sampling\SamplerInterface;
use Pulsar\Observability\Tracing\Span;
use Pulsar\Observability\Tracing\SpanProcessorInterface;
use Pulsar\Observability\Tracing\SpanStatus;

use function hex2bin;
use function is_int;
use function microtime;

/**
 * Span processor that converts Pulsar spans to OTLP format and enqueues
 * them for batch export.
 *
 * Applies attribute allowlist filtering and sampling before export.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class OtlpTracerBridge implements SpanProcessorInterface
{
    public function __construct(
        private SpanBatchExporter $exporter,
        private SamplerInterface $sampler,
        private ?AttributeAllowlist $allowlist = null,
    ) {}

    #[Override]
    public function onEnd(Span $span): void
    {
        $decision = $this->sampler->shouldSample($span->context);

        if (!$decision->sampled) {
            return;
        }

        // H5: Validate hex2bin return values
        $traceIdBin = hex2bin($span->context->traceId->value);

        if ($traceIdBin === false) {
            return;
        }

        $spanIdBin = hex2bin($span->context->spanId->value);

        if ($spanIdBin === false) {
            return;
        }

        $parentSpanIdBin = null;

        if ($span->parentSpanId !== null) {
            $parentSpanIdBin = hex2bin($span->parentSpanId->value);

            if ($parentSpanIdBin === false) {
                $parentSpanIdBin = null;
            }
        }

        $attributes = $span->attributes();

        // Extract span kind before filtering (it's an internal hint, not exported)
        $spanKind = isset($attributes['_span_kind']) && is_int($attributes['_span_kind'])
            ? $attributes['_span_kind']
            : OtlpFieldNumbers::SPAN_KIND_INTERNAL;
        unset($attributes['_span_kind']);

        if ($this->allowlist !== null) {
            $attributes = $this->allowlist->filter('tracing', $attributes);
        }

        // C3: Convert hrtime nanos to Unix epoch nanos
        $nowUnixNano = (int) (microtime(true) * 1_000_000_000.0);
        $nowHrtime = hrtime(true);
        $startTimeUnixNano = $nowUnixNano - ($nowHrtime - $span->startTime());
        $endTimeUnixNano = $span->endTime() !== null
            ? $nowUnixNano - ($nowHrtime - $span->endTime())
            : $startTimeUnixNano;

        $otlpSpan = new OtlpSpan(
            traceId: $traceIdBin,
            spanId: $spanIdBin,
            parentSpanId: $parentSpanIdBin,
            name: $span->name,
            startTimeUnixNano: $startTimeUnixNano,
            endTimeUnixNano: $endTimeUnixNano,
            attributes: $attributes,
            statusCode: self::mapStatusCode($span->status),
            statusMessage: $span->status === SpanStatus::Error ? 'error' : '',
            kind: $spanKind,
        );

        $this->exporter->enqueue($otlpSpan);
    }

    private static function mapStatusCode(SpanStatus $status): int
    {
        return match ($status) {
            SpanStatus::Unset => 0,
            SpanStatus::Ok => 1,
            SpanStatus::Error => 2,
        };
    }
}
