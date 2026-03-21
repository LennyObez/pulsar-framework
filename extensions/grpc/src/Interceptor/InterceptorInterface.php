<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Interceptor;

use Closure;
use Pulsar\Api\Api;

/**
 * Contract for gRPC interceptors.
 *
 * Interceptors form a fixed-order pipeline: Tracing -> Auth -> RateLimit ->
 * Validation -> Logging. Each interceptor receives a call context and a
 * next handler, and may short-circuit by returning a result directly.
 * @api
 */
#[Api(since: '1.0.0')]
interface InterceptorInterface
{
    /**
     * Process the gRPC call.
     *
     * Call $next($context) to pass to the next interceptor. Return an
     * InterceptorResult directly to short-circuit the pipeline.
     *
     * @param Closure(CallContext): InterceptorResult $next
     */
    public function handle(CallContext $context, Closure $next): InterceptorResult;
}
