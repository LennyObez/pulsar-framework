<?php

declare(strict_types=1);

namespace Pulsar\Queue\Middleware;

use Closure;
use Pulsar\Api\Internal;
use Pulsar\Queue\Envelope\JobEnvelope;

use function array_reverse;

/**
 * Executes a chain of job middleware around a final handler.
 *
 * Middleware is applied in FIFO order: the first middleware added is the
 * outermost layer. The pipeline builds a nested closure chain and invokes
 * it with the given envelope.
 *
 * The composed middleware chain is built once at construction. process()
 * binds the per-call terminal handler into that pre-built chain, so no
 * per-layer closures are allocated on each invocation — only the chain's
 * entry point is invoked.
 */
#[Internal(reason: 'Pipeline orchestration is an implementation detail of the queue system')]
final readonly class MiddlewarePipeline
{
    /**
     * Pre-composed chain: given an envelope and a terminal handler, runs every
     * middleware layer (outermost first) and finally invokes the handler.
     *
     * @var Closure(JobEnvelope, Closure(JobEnvelope): mixed): mixed
     */
    private Closure $chain;

    /**
     * @param list<JobMiddlewareInterface> $middleware Middleware in execution order.
     */
    public function __construct(array $middleware = [])
    {
        // Compose once: start from a passthrough that calls the per-call
        // destination, then wrap each layer (innermost last) so the first
        // middleware added becomes the outermost layer at invocation time.
        $chain = static fn(JobEnvelope $envelope, Closure $destination): mixed => $destination($envelope);

        foreach (array_reverse($middleware) as $layer) {
            $next = $chain;
            $chain = static function (JobEnvelope $envelope, Closure $destination) use ($layer, $next): mixed {
                $forward = static fn(JobEnvelope $e): mixed => $next($e, $destination);

                return $layer->handle($envelope, $forward);
            };
        }

        $this->chain = $chain;
    }

    /**
     * Send the envelope through the middleware chain, terminating with the given handler.
     *
     * @param Closure(JobEnvelope): mixed $destination The final handler after all middleware.
     */
    public function process(JobEnvelope $envelope, Closure $destination): mixed
    {
        return ($this->chain)($envelope, $destination);
    }
}
