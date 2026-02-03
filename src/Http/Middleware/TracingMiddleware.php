<?php

declare(strict_types=1);

namespace Pulsar\Http\Middleware;

use Override;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Observability\Tracing\InMemorySpanCollector;
use Pulsar\Observability\Tracing\Span;
use Pulsar\Observability\Tracing\SpanStatus;
use Pulsar\Observability\Tracing\TraceContext;
use Pulsar\Observability\Tracing\W3CTraceContextParser;
use Random\Engine\Secure;
use Random\RandomException;
use Random\Randomizer;

use function sprintf;

/**
 * Middleware that creates a root span for each HTTP request.
 *
 * Parses incoming `traceparent` header for distributed trace propagation.
 * Attaches trace context and root span to request attributes. Sets span
 * status from response status code and adds `traceparent` to response.
 */
final readonly class TracingMiddleware implements MiddlewareInterface
{
    private Randomizer $randomizer;

    public function __construct(
        private InMemorySpanCollector $collector,
        private float $samplingRate = 1.0,
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer(new Secure());
    }

    /**
     * @throws RandomException
     */
    #[Override]
    public function process(Request $request, callable $next): Response
    {
        // Parse incoming traceparent or create new context
        $parentContext = null;
        $traceparent = $request->headers->first('traceparent');

        if ($traceparent !== null) {
            $parentContext = W3CTraceContextParser::parse($traceparent);
        }

        // Determine if this request should be sampled
        if (!$this->shouldSample($parentContext)) {
            return $next($request);
        }

        // Create trace context: child of incoming or new root
        $context = $parentContext !== null
            ? $parentContext->createChild()
            : TraceContext::create();

        // Create root span
        $spanName = sprintf('HTTP %s %s', $request->method->value, $request->path);
        $span = new Span(
            name: $spanName,
            context: $context,
            parentSpanId: $parentContext?->spanId,
        );

        $span->setAttribute('http.method', $request->method->value);
        $span->setAttribute('http.path', $request->path);
        $span->setAttribute('http.url', $request->uri);

        // Attach to request attributes for downstream use
        $request = $request
            ->withAttribute('_trace_context', $context)
            ->withAttribute('_root_span', $span);

        try {
            /** @var Response $response */
            $response = $next($request);

            $span->setAttribute('http.status_code', $response->status->value);
            $span->status = $this->resolveSpanStatus($response->status->value);

            // Add traceparent to response
            return $response->withHeader(
                'traceparent',
                W3CTraceContextParser::serialize($context),
            );
        } finally {
            $span->end();
            $this->collector->onEnd($span);
        }
    }

    /**
     * @throws RandomException
     */
    private function shouldSample(?TraceContext $parentContext): bool
    {
        // If parent is sampled, always sample
        if ($parentContext !== null) {
            return $parentContext->isSampled();
        }

        // Probabilistic sampling
        if ($this->samplingRate >= 1.0) {
            return true;
        }

        if ($this->samplingRate <= 0.0) {
            return false;
        }

        $random = $this->randomizer->getInt(0, 999);

        $threshold = (int) ($this->samplingRate * 1000.0);

        return $random < $threshold;
    }

    private function resolveSpanStatus(int $statusCode): SpanStatus
    {
        if ($statusCode >= 500) {
            return SpanStatus::Error;
        }

        if ($statusCode >= 400) {
            return SpanStatus::Unset;
        }

        return SpanStatus::Ok;
    }
}
