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
 * The middleware list is reversed once at construction so that process()
 * can iterate without allocating a reversed copy on every call.
 */
#[Internal(reason: 'Pipeline orchestration is an implementation detail of the queue system')]
final readonly class MiddlewarePipeline
{
    /** @var list<JobMiddlewareInterface> Middleware in reversed (nesting) order. */
    private array $reversed;

    /**
     * @param list<JobMiddlewareInterface> $middleware Middleware in execution order.
     */
    public function __construct(array $middleware = [])
    {
        $this->reversed = array_reverse($middleware);
    }

    /**
     * Send the envelope through the middleware chain, terminating with the given handler.
     *
     * @param Closure(JobEnvelope): mixed $destination The final handler after all middleware.
     */
    public function process(JobEnvelope $envelope, Closure $destination): mixed
    {
        /** @var Closure(JobEnvelope): mixed $next */
        $next = $destination;

        foreach ($this->reversed as $layer) {
            $next = static fn(JobEnvelope $e): mixed => $layer->handle($e, $next);
        }

        return $next($envelope);
    }
}
