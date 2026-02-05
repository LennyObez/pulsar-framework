<?php

declare(strict_types=1);

namespace Pulsar\Observability\Tracing;

use NoDiscard;
use Override;

use function count;
use function ctype_xdigit;
use function explode;
use function hexdec;
use function sprintf;
use function strlen;
use function strtolower;

/**
 * Parses and serializes W3C Trace Context `traceparent` headers.
 *
 * Format: `{version}-{traceId}-{spanId}-{traceFlags}`
 * Example: `00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01`
 *
 * @see https://www.w3.org/TR/trace-context/
 */
final readonly class W3CTraceContextParser implements TraceContextParserInterface
{
    /**
     * Parse a traceparent header value into a TraceContext.
     *
     * Returns null if the header is malformed.
     */
    #[NoDiscard]
    #[Override]
    public function parse(string $header): ?TraceContext
    {
        $parts = explode('-', strtolower($header));

        if (count($parts) < 4) {
            return null;
        }

        [$version, $traceId, $spanId, $flags] = $parts;

        // Version must be 2 hex chars
        if (strlen($version) !== 2 || !ctype_xdigit($version)) {
            return null;
        }

        // Trace ID must be 32 hex chars and not all zeros
        if (strlen($traceId) !== 32 || !ctype_xdigit($traceId) || $traceId === '00000000000000000000000000000000') {
            return null;
        }

        // Span ID must be 16 hex chars and not all zeros
        if (strlen($spanId) !== 16 || !ctype_xdigit($spanId) || $spanId === '0000000000000000') {
            return null;
        }

        // Flags must be 2 hex chars
        if (strlen($flags) !== 2 || !ctype_xdigit($flags)) {
            return null;
        }

        return new TraceContext(
            traceId: new TraceId($traceId),
            spanId: new SpanId($spanId),
            traceFlags: (int) hexdec($flags),
        );
    }

    /**
     * Serialize a TraceContext to a traceparent header value.
     */
    #[NoDiscard]
    #[Override]
    public function serialize(TraceContext $context): string
    {
        return sprintf(
            '00-%s-%s-%02x',
            $context->traceId->value,
            $context->spanId->value,
            $context->traceFlags,
        );
    }
}
