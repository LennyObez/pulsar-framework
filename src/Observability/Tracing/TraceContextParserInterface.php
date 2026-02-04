<?php

declare(strict_types=1);

namespace Pulsar\Observability\Tracing;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Contract for parsing and serializing trace context propagation headers.
 */
#[Api(since: '1.0.0')]
interface TraceContextParserInterface
{
    /**
     * Parse a trace propagation header value into a TraceContext.
     *
     * Returns null if the header is malformed.
     */
    #[NoDiscard]
    public function parse(string $header): ?TraceContext;

    /**
     * Serialize a TraceContext to a trace propagation header value.
     */
    #[NoDiscard]
    public function serialize(TraceContext $context): string;
}
