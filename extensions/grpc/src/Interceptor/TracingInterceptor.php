<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Interceptor;

use Closure;
use Pulsar\Api\Internal;
use Pulsar\Observability\Tracing\Span;
use Pulsar\Observability\Tracing\SpanStatus;
use Pulsar\Observability\Tracing\TraceContext;
use Pulsar\Observability\Tracing\TraceContextParserInterface;

/**
 * Interceptor that creates trace spans for gRPC calls.
 *
 * Extracts W3C Trace Context from the "traceparent" metadata header,
 * creates a child span for the call, and sets standard RPC attributes.
 */
#[Internal(reason: 'Pipeline implementation detail — use InterceptorPipeline')]
final readonly class TracingInterceptor implements InterceptorInterface
{
    public function __construct(
        private TraceContextParserInterface $parser,
    ) {}

    public function handle(CallContext $context, Closure $next): InterceptorResult
    {
        $traceContext = $this->resolveTraceContext($context);
        $childContext = $traceContext->createChild();
        $span = new Span('grpc.call', $childContext, $traceContext->spanId);

        $span->setAttribute('rpc.system', 'grpc');
        $span->setAttribute('rpc.method', $context->method->name);
        $span->setAttribute('rpc.service', $this->extractServiceName($context->method->fullName));

        $contextWithSpan = $context->withAttribute('tracing.span', $span);

        try {
            $result = $next($contextWithSpan);

            $span->status = $result->isOk() ? SpanStatus::Ok : SpanStatus::Error;
            $span->setAttribute('rpc.grpc.status_code', $result->status->value);

            return $result;
        } finally {
            $span->end();
        }
    }

    private function resolveTraceContext(CallContext $context): TraceContext
    {
        $traceparent = $context->getMetadataValue('traceparent');

        if ($traceparent !== null) {
            $parsed = $this->parser->parse($traceparent);

            if ($parsed !== null) {
                return $parsed;
            }
        }

        return TraceContext::create();
    }

    private function extractServiceName(string $fullMethodName): string
    {
        // Format: "/package.Service/Method" — extract "package.Service"
        $trimmed = ltrim($fullMethodName, '/');
        $slashPos = strpos($trimmed, '/');

        if ($slashPos === false) {
            return $trimmed;
        }

        return substr($trimmed, 0, $slashPos);
    }
}
