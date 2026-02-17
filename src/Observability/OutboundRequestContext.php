<?php

declare(strict_types=1);

namespace Pulsar\Observability;

use Pulsar\Api\Api;

/**
 * Context for an in-flight outbound HTTP request being instrumented.
 *
 * Created by HttpClientInstrumentation::start() and consumed by
 * finish() or error() to calculate duration and record metrics.
 */
#[Api(since: '1.0.0')]
final readonly class OutboundRequestContext
{
    public function __construct(
        public string $method,
        public string $url,
        public string $host,
        public string $scheme,
        public int $startTimeNs,
    ) {}
}
